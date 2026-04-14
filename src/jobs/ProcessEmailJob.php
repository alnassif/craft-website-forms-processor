<?php

namespace slateos\formsprocessor\jobs;

use Craft;
use craft\queue\BaseJob;
use slateos\formsprocessor\FormsProcessor;

/**
 * Async email + PDF delivery job.
 *
 * Sends the email with PDF attachment, then:
 *   1. POSTs _notify back to Slate (email outcome)
 *   2. Queues SlateAttachJob (PDF URL → Slate)
 *   3. Updates the SubmissionRecord status
 */
class ProcessEmailJob extends BaseJob
{
    /** Pre-rendered HTML email body */
    public string $htmlBody = '';

    /** Email subject */
    public string $subject = '';

    /** Recipients: { to, cc?, bcc? } */
    public array $recipients = [];

    /** PDF binary, base64-encoded */
    public ?string $attachmentBase64 = null;

    /** PDF filename for attachment */
    public string $attachmentName = 'document.pdf';

    /** Token used to build the PDF serve URL for Slate attach */
    public string $pdfToken = '';

    // ── Slate callback data ───────────────────────────────────────────────────

    public string $slateEndpoint   = '';
    public string $slateApiKey     = '';
    public string $submissionId    = '';

    // ── Submission tracking ───────────────────────────────────────────────────

    public int    $submissionRecordId = 0;

    // ── Email sender config (read from plugin settings at runtime) ────────────

    public function execute($queue): void
    {
        $settings  = FormsProcessor::$plugin->getSettings();
        $slate     = FormsProcessor::$plugin->slate;

        // ── Send email ────────────────────────────────────────────────────────

        $fromEmail = $settings->fromEmail ?: (getenv('MAIL_FROM_EMAIL') ?: 'noreply@example.com');
        $fromName  = $settings->fromName  ?: (getenv('MAIL_FROM_NAME')  ?: 'Website');
        $replyTo   = $settings->replyTo   ?: $fromEmail;
        $bcc       = $settings->bcc       ?: (getenv('MAIL_BCC') ?: '');

        $message = Craft::$app->getMailer()->compose()
            ->setFrom([$fromEmail => $fromName])
            ->setReplyTo($replyTo)
            ->setSubject($this->subject)
            ->setHtmlBody($this->htmlBody)
            ->setTo($this->recipients['to']);

        if (!empty($this->recipients['cc'])) {
            $message->setCc($this->recipients['cc']);
        }

        $bccList = array_filter([$bcc]);
        if (!empty($this->recipients['bcc'])) {
            $bccList = array_unique(array_merge($bccList, (array) $this->recipients['bcc']));
        }
        if (!empty($bccList)) {
            $message->setBcc($bccList);
        }

        if ($this->attachmentBase64) {
            $message->attachContent(base64_decode($this->attachmentBase64), [
                'fileName'    => $this->attachmentName,
                'contentType' => 'application/pdf',
            ]);
        }

        $emailSent = $message->send();

        if (!$emailSent) {
            Craft::error('ProcessEmailJob: email delivery failed for ' . json_encode($this->recipients['to']), __METHOD__);
        } else {
            Craft::info('ProcessEmailJob: email sent to ' . json_encode($this->recipients['to']), __METHOD__);
        }

        // ── _notify → Slate ───────────────────────────────────────────────────

        if ($this->slateEndpoint && $this->slateApiKey && $this->submissionId) {
            $to = $this->recipients['to'];
            $emailAddress = is_array($to) ? implode(', ', array_keys($to)) : (string) $to;

            $slate->notify(
                $this->slateEndpoint,
                $this->slateApiKey,
                $this->submissionId,
                $emailSent,
                $emailAddress
            );
        }

        // ── Queue SlateAttachJob ──────────────────────────────────────────────

        if ($this->pdfToken && $this->submissionId && $this->slateEndpoint) {
            $pdfUrl = \craft\helpers\UrlHelper::actionUrl(
                'forms-processor/process/pdf',
                ['token' => $this->pdfToken]
            );

            Craft::$app->getQueue()->push(new SlateAttachJob([
                'slateEndpoint' => $this->slateEndpoint,
                'slateApiKey'   => $this->slateApiKey,
                'submissionId'  => $this->submissionId,
                'pdfUrl'        => $pdfUrl,
                'submissionRecordId' => $this->submissionRecordId,
            ]));
        }

        // ── Update submission record status ───────────────────────────────────

        if ($this->submissionRecordId) {
            FormsProcessor::$plugin->submissions->updateStatus(
                $this->submissionRecordId,
                $emailSent ? 'sent' : 'failed'
            );
        }

        if (!$emailSent) {
            throw new \RuntimeException('Email delivery failed');
        }
    }

    protected function defaultDescription(): ?string
    {
        $to = is_array($this->recipients['to'] ?? '')
            ? implode(', ', array_keys($this->recipients['to']))
            : ($this->recipients['to'] ?? 'unknown');

        return "Sending form email to {$to}";
    }
}
