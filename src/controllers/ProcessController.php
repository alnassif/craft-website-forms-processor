<?php

namespace slateos\formsprocessor\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use slateos\formsprocessor\FormsProcessor;
use slateos\formsprocessor\jobs\ProcessEmailJob;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Front-end form submission endpoint.
 *
 * POST /actions/forms-processor/process/submit
 *   Body: {
 *     handle:  'tile-calculator',   // FormType handle
 *     contact: { name, email, phone, suburb, postcode, cc? },
 *     data:    { ... }              // form-specific payload
 *   }
 *
 * Flow:
 *   1. Look up FormType by handle
 *   2. Validate email (format + MX + disposable)
 *   3. Rate limit + honeypot + timing
 *   4. Generate PDF (sync — user waits for this)
 *   5. Save PDF to temp storage → token
 *   6. POST to Slate (sync) → receive submissionId
 *   7. Save SubmissionRecord
 *   8. Queue ProcessEmailJob (email + _notify + SlateAttachJob)
 *   9. Return PDF binary to browser
 *
 * GET /actions/forms-processor/process/pdf?token=xxx
 *   Serves a stored PDF binary (for Slate to fetch via URL).
 */
class ProcessController extends Controller
{
    protected array|bool|int $allowAnonymous = ['submit', 'pdf'];

    public function beforeAction($action): bool
    {
        if (in_array($action->id, ['submit', 'pdf'])) {
            $this->enableCsrfValidation = false;
        }
        return parent::beforeAction($action);
    }

    // =========================================================================
    // POST /actions/forms-processor/process/submit
    // =========================================================================

    public function actionSubmit(): Response
    {
        $this->requirePostRequest();

        $rawBody = Craft::$app->getRequest()->getRawBody();
        $payload = json_decode($rawBody, true);

        if (empty($payload)) {
            return $this->jsonError('request', 'Invalid request');
        }

        $handle  = trim($payload['handle'] ?? '');
        $contact = $payload['contact'] ?? [];
        $data    = $payload['data'] ?? $payload;

        unset($data['handle'], $data['contact']);

        // ── 1. Look up form type ──────────────────────────────────────────────

        if (empty($handle)) {
            return $this->jsonError('handle', 'Form type handle is required');
        }

        $formType = FormsProcessor::$plugin->formTypes->getFormTypeByHandle($handle);

        if (!$formType || !$formType->enabled) {
            return $this->jsonError('handle', 'Unknown or disabled form type');
        }

        // ── 2. Validate contact fields ────────────────────────────────────────

        $name  = trim($contact['name']  ?? '');
        $email = trim($contact['email'] ?? '');
        $cc    = trim($contact['cc']    ?? '');

        if (empty($name)) {
            return $this->jsonError('name', 'Please enter your name');
        }
        if (empty($email)) {
            return $this->jsonError('email', 'Please enter your email address');
        }

        // ── 3. Email validation (via craft-website-site-processor if present) ─

        if (class_exists(\modules\siteprocessor\SiteProcessorModule::class)) {
            $module = \modules\siteprocessor\SiteProcessorModule::getInstance();

            if ($module) {
                $v = $module->emailValidation->validate($email);
                if (!$v['valid']) {
                    return $this->jsonError($v['field'], $v['error']);
                }

                if ($cc !== '') {
                    $vcc = $module->emailValidation->validate($cc);
                    if (!$vcc['valid']) {
                        return $this->jsonError('cc', 'CC: ' . $vcc['error']);
                    }
                }

                // ── 4. Rate limit + honeypot + timing ─────────────────────────
                try {
                    $ip = Craft::$app->getRequest()->getUserIP() ?? '0.0.0.0';
                    $maxOverride    = $formType->rateLimitMax    ?? FormsProcessor::$plugin->getSettings()->rateLimitMax;
                    $windowOverride = $formType->rateLimitWindow ?? FormsProcessor::$plugin->getSettings()->rateLimitWindow;

                    // Temporarily patch the rate limiter limits for this call
                    $module->rateLimiter->check($ip, 'forms-processor-' . $handle, $payload);
                } catch (\RuntimeException $e) {
                    return $this->jsonError('rate_limit', $e->getMessage());
                }
            }
        }

        // ── 5. Generate PDF (sync) ─────────────────────────────────────────

        if (empty($data['_generatedAt'])) {
            $data['_generatedAt'] = date('c');
        }

        $tplVars = $this->buildTplVars($data, $contact);

        $pdf = null;

        if ($formType->pdfTemplateId) {
            $pdfTemplate = FormsProcessor::$plugin->pdfTemplates->getPdfTemplateById($formType->pdfTemplateId);

            if ($pdfTemplate) {
                try {
                    $pdf = $this->generatePdfFromTemplate($pdfTemplate, $tplVars);
                } catch (\Exception $e) {
                    Craft::error('PDF generation failed: ' . $e->getMessage(), __METHOD__);
                    return $this->jsonError('pdf', 'PDF generation failed — please try again');
                }
            }
        }

        // ── 6. Save PDF to temp storage → get serve token ──────────────────

        $pdfToken = '';
        if ($pdf) {
            $pdfToken = $this->storePdf($pdf);
        }

        // ── 7. POST to Slate (sync) → submissionId ─────────────────────────

        $submissionId = '';

        if ($formType->slateEndpoint && $formType->slateApiKey) {
            // _title: use caller-supplied value, otherwise build a generic fallback
            $slateTitle = $data['_title'] ?? ($name . ' — ' . $formType->name);

            // _url: use caller-supplied value, otherwise the HTTP referer
            $slateUrl = $data['_url'] ?? (Craft::$app->getRequest()->getReferrer() ?? '');

            $submissionId = FormsProcessor::$plugin->slate->submit(
                $formType->slateEndpoint,
                $formType->slateApiKey,
                $formType->slateSource,
                $contact,
                $data,
                $slateTitle,
                $slateUrl
            ) ?? '';
        }

        // ── 8. Save SubmissionRecord ───────────────────────────────────────

        $submissionRecordId = FormsProcessor::$plugin->submissions->saveSubmission([
            'formTypeId'        => $formType->id,
            'contactName'       => $name,
            'contactEmail'      => $email,
            'contactPhone'      => trim($contact['phone'] ?? ''),
            'payload'           => $data,
            'slateSubmissionId' => $submissionId,
            'status'            => 'pending',
        ]);

        // ── 9. Render email HTML ───────────────────────────────────────────

        $emailHtml = $this->buildEmailHtml($formType, $tplVars);

        // ── 10. Resolve email subject (Twig-capable) ───────────────────────

        $subject = $formType->emailSubject ?: 'Your Document — ' . Craft::$app->getSystemName();
        try {
            $subject = Craft::$app->getView()->renderString($subject, $tplVars);
        } catch (\Throwable $e) {
            // Subject rendering failed — use as-is
        }

        // ── 11. Resolve BCC ────────────────────────────────────────────────

        $bccAddress = $formType->bccOverride ?: FormsProcessor::$plugin->getSettings()->bcc;

        // ── 12. Queue ProcessEmailJob ──────────────────────────────────────

        $recipients = ['to' => $email];
        if ($cc !== '') {
            $recipients['cc'] = $cc;
        }
        if ($bccAddress) {
            $recipients['bcc'] = $bccAddress;
        }

        $pdfDate = date('d-m-Y', strtotime($data['_generatedAt']));
        $siteName = Craft::$app->getSystemName();

        try {
            Craft::$app->getQueue()->push(new ProcessEmailJob([
                'htmlBody'            => $emailHtml,
                'subject'             => $subject,
                'recipients'          => $recipients,
                'attachmentBase64'    => $pdf ? base64_encode($pdf) : null,
                'attachmentName'      => "{$siteName} - {$pdfDate}.pdf",
                'pdfToken'            => $pdfToken,
                'slateEndpoint'       => $formType->slateEndpoint,
                'slateApiKey'         => $formType->slateApiKey,
                'submissionId'        => $submissionId,
                'submissionRecordId'  => $submissionRecordId,
                'emailSubject'        => $subject,
            ]));
        } catch (\Exception $e) {
            Craft::error('Failed to queue ProcessEmailJob: ' . $e->getMessage(), __METHOD__);
        }

        // ── 13. Return PDF to browser ──────────────────────────────────────

        if ($pdf) {
            $response = Craft::$app->getResponse();
            $response->format = Response::FORMAT_RAW;
            $response->getHeaders()->set('Content-Type', 'application/pdf');
            $response->getHeaders()->set('Content-Disposition',
                "inline; filename=\"{$siteName} - {$pdfDate}.pdf\""
            );
            $response->data = $pdf;
            return $response;
        }

        // No PDF template configured — return JSON success
        return $this->asJson(['success' => true, 'submissionId' => $submissionId]);
    }

    // =========================================================================
    // GET /actions/forms-processor/process/pdf?token=xxx
    // Serves a stored PDF for Slate to fetch by URL.
    // =========================================================================

    public function actionPdf(): Response
    {
        $token = Craft::$app->getRequest()->getRequiredQueryParam('token');

        // Sanitise — token must be alphanumeric/underscores only
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $token)) {
            throw new NotFoundHttpException();
        }

        $path = $this->getPdfTempPath($token);

        if (!file_exists($path)) {
            throw new NotFoundHttpException('PDF not found or expired');
        }

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'application/pdf');
        $response->data = file_get_contents($path);
        return $response;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function generatePdfFromTemplate(
        \slateos\formsprocessor\models\PdfTemplateModel $tpl,
        array $tplVars
    ): string {
        $view = Craft::$app->getView();

        $body   = $view->renderString($tpl->bodyTwig,   $tplVars);
        $header = $view->renderString($tpl->headerTwig, $tplVars);
        $footer = $view->renderString($tpl->footerTwig, $tplVars);

        $pdfConfig = [
            'body'    => $body,
            'format'  => $tpl->paperSize,
            'margins' => $tpl->margins,
        ];
        if (trim($header)) { $pdfConfig['header'] = $header; }
        if (trim($footer)) { $pdfConfig['footer'] = $footer; }

        $phpBin     = $this->findPhp82();
        $scriptPath = Craft::getAlias('@root') . '/modules/pdfgenerator/bin/html-to-pdf.php';

        $descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
        $process = proc_open(
            escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath),
            $descriptors, $pipes, Craft::getAlias('@root')
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start PDF subprocess');
        }

        fwrite($pipes[0], json_encode($pdfConfig));
        fclose($pipes[0]);
        $pdf    = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0 || empty($pdf)) {
            throw new \RuntimeException('PDF generation failed: ' . ($stderr ?: 'Unknown error'));
        }

        return $pdf;
    }

    private function storePdf(string $pdf): string
    {
        $token = 'pdf_' . bin2hex(random_bytes(16));
        $path  = $this->getPdfTempPath($token);
        $dir   = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $pdf);

        return $token;
    }

    private function getPdfTempPath(string $token): string
    {
        $settings = FormsProcessor::$plugin->getSettings();
        $base = Craft::getAlias($settings->attachmentTempDir ?: '@storage/runtime/pdf-attachments');
        return rtrim($base, '/') . '/' . $token . '.pdf';
    }

    private function buildTplVars(array $data, array $contact): array
    {
        return [
            'data'          => $data,
            'contact'       => $contact,
            'currency'      => $data['currency'] ?? '$',
            'formattedDate' => date('j F Y', strtotime($data['_generatedAt'] ?? 'now')),
            'siteName'      => Craft::$app->getSystemName(),
        ];
    }

    private function buildEmailHtml(\slateos\formsprocessor\models\FormTypeModel $formType, array $tplVars): string
    {
        $view = Craft::$app->getView();

        // If an email template path is set, try to render it
        if ($formType->emailTemplatePath) {
            try {
                return $view->renderTemplate($formType->emailTemplatePath, $tplVars);
            } catch (\Throwable $e) {
                Craft::warning('Email template not found: ' . $formType->emailTemplatePath, __METHOD__);
            }
        }

        // Fallback: render the emailBody field as a Twig string wrapped in basic shell
        $body = '';
        if ($formType->emailBody) {
            try {
                $body = $view->renderString($formType->emailBody, $tplVars);
            } catch (\Throwable $e) {
                $body = $formType->emailBody;
            }
        } else {
            $body = '<p>Thank you for your submission. Your document is attached.</p>';
        }

        $siteName = Craft::$app->getSystemName();

        return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>{$siteName}</title></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5">
<tr><td align="center" style="padding:24px 16px">
<table role="presentation" width="600" cellpadding="0" cellspacing="0"
       style="max-width:600px;width:100%;background:#fff;border-radius:8px;overflow:hidden">
<tr><td style="padding:24px 32px">{$body}</td></tr>
</table></td></tr></table>
</body></html>
HTML;
    }

    private function jsonError(string $field, string $message): Response
    {
        return $this->asJson(['success' => false, 'error' => $field, 'message' => $message]);
    }

    private function findPhp82(): string
    {
        foreach ([
            '/RunCloud/Packages/php85rc/bin/php',
            '/RunCloud/Packages/php84rc/bin/php',
            '/RunCloud/Packages/php83rc/bin/php',
            '/RunCloud/Packages/php82rc/bin/php',
            '/usr/bin/php8.4', '/usr/bin/php8.3', '/usr/bin/php8.2', '/usr/bin/php',
        ] as $p) {
            if (file_exists($p)) return $p;
        }
        throw new \RuntimeException('PHP 8.2+ binary not found');
    }
}
