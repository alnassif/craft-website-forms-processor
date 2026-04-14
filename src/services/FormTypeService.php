<?php

namespace slateos\formsprocessor\services;

use Craft;
use craft\db\Query;
use yii\base\Component;
use slateos\formsprocessor\models\FormTypeModel;
use slateos\formsprocessor\records\FormTypeRecord;

class FormTypeService extends Component
{
    /** @return FormTypeModel[] */
    public function getAllFormTypes(): array
    {
        $rows = (new Query())
            ->from(FormTypeRecord::tableName())
            ->orderBy(['name' => SORT_ASC])
            ->all();

        return array_map(fn($row) => $this->populateModel($row), $rows);
    }

    public function getFormTypeById(int $id): ?FormTypeModel
    {
        $row = (new Query())
            ->from(FormTypeRecord::tableName())
            ->where(['id' => $id])
            ->one();

        return $row ? $this->populateModel($row) : null;
    }

    public function getFormTypeByHandle(string $handle): ?FormTypeModel
    {
        $row = (new Query())
            ->from(FormTypeRecord::tableName())
            ->where(['handle' => $handle])
            ->one();

        return $row ? $this->populateModel($row) : null;
    }

    public function saveFormType(FormTypeModel $model): bool
    {
        if (!$model->validate()) {
            return false;
        }

        $record = $model->id
            ? FormTypeRecord::findOne($model->id) ?? new FormTypeRecord()
            : new FormTypeRecord();

        $record->name              = $model->name;
        $record->handle            = $model->handle;
        $record->slateEndpoint     = $model->slateEndpoint;
        $record->slateApiKey       = $model->slateApiKey;
        $record->slateSource       = $model->slateSource;
        $record->pdfTemplateId     = $model->pdfTemplateId;
        $record->emailTemplatePath = $model->emailTemplatePath;
        $record->emailSubject      = $model->emailSubject;
        $record->emailBody         = $model->emailBody;
        $record->bccOverride       = $model->bccOverride;
        $record->rateLimitMax      = $model->rateLimitMax;
        $record->rateLimitWindow   = $model->rateLimitWindow;
        $record->fieldsMap         = $model->fieldsMap;
        $record->enabled           = $model->enabled;

        if (!$record->save()) {
            $model->addErrors($record->getErrors());
            return false;
        }

        $model->id = $record->id;
        return true;
    }

    public function deleteFormTypeById(int $id): bool
    {
        $record = FormTypeRecord::findOne($id);
        if (!$record) {
            return false;
        }
        return (bool) $record->delete();
    }

    private function populateModel(array $row): FormTypeModel
    {
        $model = new FormTypeModel();
        foreach ($row as $key => $value) {
            if (property_exists($model, $key)) {
                $model->$key = $value;
            }
        }
        return $model;
    }
}
