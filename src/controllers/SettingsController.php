<?php

namespace slateos\formsprocessor\controllers;

use Craft;
use craft\web\Controller;
use slateos\formsprocessor\FormsProcessor;
use yii\web\Response;

class SettingsController extends Controller
{
    public function actionIndex(): Response
    {
        $settings = FormsProcessor::$plugin->getSettings();

        return $this->renderTemplate('forms-processor/settings/index', [
            'settings' => $settings,
            'fullPageForm' => true,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $settings = FormsProcessor::$plugin->getSettings();
        $request  = Craft::$app->getRequest();

        $settings->fromEmail        = $request->getBodyParam('fromEmail', '');
        $settings->fromName         = $request->getBodyParam('fromName', '');
        $settings->replyTo          = $request->getBodyParam('replyTo', '');
        $settings->bcc              = $request->getBodyParam('bcc', '');
        $settings->attachmentTempDir = $request->getBodyParam('attachmentTempDir', '@storage/runtime/pdf-attachments');
        $settings->rateLimitMax     = (int) $request->getBodyParam('rateLimitMax', 5);
        $settings->rateLimitWindow  = (int) $request->getBodyParam('rateLimitWindow', 3600);
        $settings->paperSize        = $request->getBodyParam('paperSize', 'A4');

        $marginsRaw = $request->getBodyParam('margins', '10,10,10,10');
        $settings->margins = array_map('intval', explode(',', $marginsRaw));

        if (!$settings->validate()) {
            Craft::$app->getSession()->setError('Could not save settings.');
            return $this->renderTemplate('forms-processor/settings/index', [
                'settings' => $settings,
                'fullPageForm' => true,
            ]);
        }

        if (!Craft::$app->getPlugins()->savePluginSettings(FormsProcessor::$plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError('Could not save settings.');
            return $this->redirect('forms-processor/settings');
        }

        Craft::$app->getSession()->setNotice('Settings saved.');
        return $this->redirect('forms-processor/settings');
    }
}
