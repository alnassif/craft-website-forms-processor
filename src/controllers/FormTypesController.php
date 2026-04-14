<?php

namespace slateos\formsprocessor\controllers;

use Craft;
use craft\web\Controller;
use slateos\formsprocessor\FormsProcessor;
use slateos\formsprocessor\models\FormTypeModel;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FormTypesController extends Controller
{
    public function actionIndex(): Response
    {
        $formTypes = FormsProcessor::$plugin->formTypes->getAllFormTypes();

        return $this->renderTemplate('forms-processor/form-types/index', [
            'formTypes' => $formTypes,
        ]);
    }

    public function actionEdit(?int $id = null): Response
    {
        if ($id) {
            $formType = FormsProcessor::$plugin->formTypes->getFormTypeById($id);
            if (!$formType) {
                throw new NotFoundHttpException('Form type not found.');
            }
        } else {
            $formType = new FormTypeModel();
        }

        $pdfTemplateOptions = FormsProcessor::$plugin->pdfTemplates->getPdfTemplatesForSelect();

        return $this->renderTemplate('forms-processor/form-types/edit', [
            'formType'           => $formType,
            'pdfTemplateOptions' => $pdfTemplateOptions,
            'isNew'              => $id === null,
            'fullPageForm'       => true,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $request  = Craft::$app->getRequest();
        $id       = $request->getBodyParam('id');

        $formType = $id
            ? FormsProcessor::$plugin->formTypes->getFormTypeById((int) $id)
            : new FormTypeModel();

        if (!$formType) {
            throw new NotFoundHttpException('Form type not found.');
        }

        $formType->name              = $request->getBodyParam('name', '');
        $formType->handle            = $request->getBodyParam('handle', '');
        $formType->slateEndpoint     = $request->getBodyParam('slateEndpoint', '');
        $formType->slateApiKey       = $request->getBodyParam('slateApiKey', '');
        $formType->slateSource       = $request->getBodyParam('slateSource', '');
        $formType->pdfTemplateId     = $request->getBodyParam('pdfTemplateId') ?: null;
        $formType->emailTemplatePath = $request->getBodyParam('emailTemplatePath', '');
        $formType->emailSubject      = $request->getBodyParam('emailSubject', '');
        $formType->emailBody         = $request->getBodyParam('emailBody', '');
        $formType->bccOverride       = $request->getBodyParam('bccOverride', '');
        $formType->rateLimitMax      = $request->getBodyParam('rateLimitMax') ?: null;
        $formType->rateLimitWindow   = $request->getBodyParam('rateLimitWindow') ?: null;
        $formType->fieldsMap         = $request->getBodyParam('fieldsMap', '{}');
        $formType->enabled           = (bool) $request->getBodyParam('enabled', true);

        if (!FormsProcessor::$plugin->formTypes->saveFormType($formType)) {
            Craft::$app->getSession()->setError('Could not save form type.');
            return $this->renderTemplate('forms-processor/form-types/edit', [
                'formType'           => $formType,
                'pdfTemplateOptions' => FormsProcessor::$plugin->pdfTemplates->getPdfTemplatesForSelect(),
                'isNew'              => !$id,
                'fullPageForm'       => true,
            ]);
        }

        Craft::$app->getSession()->setNotice('Form type saved.');
        return $this->redirect('forms-processor/form-types/' . $formType->id);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int) Craft::$app->getRequest()->getRequiredBodyParam('id');
        FormsProcessor::$plugin->formTypes->deleteFormTypeById($id);

        return $this->asJson(['success' => true]);
    }
}
