<?php

namespace slateos\formsprocessor\controllers;

use Craft;
use craft\web\Controller;
use slateos\formsprocessor\FormsProcessor;
use yii\web\Response;

class SubmissionsController extends Controller
{
    public function actionIndex(?int $formTypeId = null): Response
    {
        $formTypes = FormsProcessor::$plugin->formTypes->getAllFormTypes();

        // Default to first form type if none specified
        if (!$formTypeId && !empty($formTypes)) {
            $formTypeId = $formTypes[0]->id;
        }

        $submissions = $formTypeId
            ? FormsProcessor::$plugin->submissions->getSubmissionsByFormTypeId($formTypeId)
            : [];

        $currentFormType = $formTypeId
            ? FormsProcessor::$plugin->formTypes->getFormTypeById($formTypeId)
            : null;

        return $this->renderTemplate('forms-processor/submissions/index', [
            'formTypes'       => $formTypes,
            'currentFormType' => $currentFormType,
            'submissions'     => $submissions,
        ]);
    }
}
