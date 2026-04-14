<?php

namespace slateos\formsprocessor\controllers;

use Craft;
use craft\web\Controller;
use slateos\formsprocessor\FormsProcessor;
use slateos\formsprocessor\models\PdfTemplateModel;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class PdfTemplatesController extends Controller
{
    public function actionIndex(): Response
    {
        $templates = FormsProcessor::$plugin->pdfTemplates->getAllPdfTemplates();

        return $this->renderTemplate('forms-processor/pdf-templates/index', [
            'templates' => $templates,
        ]);
    }

    public function actionEdit(?int $id = null): Response
    {
        if ($id) {
            $template = FormsProcessor::$plugin->pdfTemplates->getPdfTemplateById($id);
            if (!$template) {
                throw new NotFoundHttpException('PDF template not found.');
            }
        } else {
            $template = new PdfTemplateModel();
        }

        return $this->renderTemplate('forms-processor/pdf-templates/edit', [
            'template'     => $template,
            'isNew'        => $id === null,
            'fullPageForm' => true,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $id      = $request->getBodyParam('id');

        $template = $id
            ? FormsProcessor::$plugin->pdfTemplates->getPdfTemplateById((int) $id)
            : new PdfTemplateModel();

        if (!$template) {
            throw new NotFoundHttpException('PDF template not found.');
        }

        $template->name       = $request->getBodyParam('name', '');
        $template->bodyTwig   = $request->getBodyParam('bodyTwig', '');
        $template->headerTwig = $request->getBodyParam('headerTwig', '');
        $template->footerTwig = $request->getBodyParam('footerTwig', '');
        $template->sampleData = $request->getBodyParam('sampleData', '{}');
        $template->paperSize  = $request->getBodyParam('paperSize', 'A4');

        $marginsRaw    = $request->getBodyParam('margins', '10,10,10,10');
        $template->margins = array_map('intval', explode(',', $marginsRaw));

        if (!FormsProcessor::$plugin->pdfTemplates->savePdfTemplate($template)) {
            Craft::$app->getSession()->setError('Could not save PDF template.');
            return $this->renderTemplate('forms-processor/pdf-templates/edit', [
                'template'     => $template,
                'isNew'        => !$id,
                'fullPageForm' => true,
            ]);
        }

        Craft::$app->getSession()->setNotice('PDF template saved.');
        return $this->redirect('forms-processor/pdf-templates/' . $template->id);
    }

    /**
     * POST /actions/forms-processor/pdf-templates/preview
     * Renders sample data through the real generator and returns PDF binary.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();

        $id = (int) Craft::$app->getRequest()->getRequiredBodyParam('id');
        $template = FormsProcessor::$plugin->pdfTemplates->getPdfTemplateById($id);

        if (!$template) {
            throw new NotFoundHttpException('PDF template not found.');
        }

        $sampleData = $template->getSampleDataArray();
        $view = Craft::$app->getView();

        // Render the Twig strings stored in the DB
        $body   = $view->renderString($template->bodyTwig,   ['data' => $sampleData]);
        $header = $view->renderString($template->headerTwig, ['data' => $sampleData]);
        $footer = $view->renderString($template->footerTwig, ['data' => $sampleData]);

        $pdfConfig = [
            'body'    => $body,
            'format'  => $template->paperSize,
            'margins' => $template->margins,
        ];
        if ($header) { $pdfConfig['header'] = $header; }
        if ($footer) { $pdfConfig['footer'] = $footer; }

        // Call the pdfgenerator subprocess directly
        $phpBin     = $this->findPhp82();
        $scriptPath = Craft::getAlias('@root') . '/modules/pdfgenerator/bin/html-to-pdf.php';

        $descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
        $process = proc_open(
            escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath),
            $descriptors, $pipes, Craft::getAlias('@root')
        );

        fwrite($pipes[0], json_encode($pdfConfig));
        fclose($pipes[0]);
        $pdf    = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0 || empty($pdf)) {
            return $this->asJson(['error' => 'PDF generation failed: ' . ($stderr ?: 'Unknown error')]);
        }

        $response = Craft::$app->getResponse();
        $response->format = \yii\web\Response::FORMAT_RAW;
        $response->getHeaders()->set('Content-Type', 'application/pdf');
        $response->data = $pdf;
        return $response;
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int) Craft::$app->getRequest()->getRequiredBodyParam('id');
        FormsProcessor::$plugin->pdfTemplates->deletePdfTemplateById($id);

        return $this->asJson(['success' => true]);
    }

    private function findPhp82(): string
    {
        foreach (['/RunCloud/Packages/php85rc/bin/php', '/RunCloud/Packages/php84rc/bin/php',
                  '/RunCloud/Packages/php83rc/bin/php', '/RunCloud/Packages/php82rc/bin/php',
                  '/usr/bin/php8.4', '/usr/bin/php8.3', '/usr/bin/php8.2', '/usr/bin/php'] as $p) {
            if (file_exists($p)) return $p;
        }
        throw new \RuntimeException('PHP 8.2+ binary not found');
    }
}
