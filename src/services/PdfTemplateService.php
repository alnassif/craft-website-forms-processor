<?php

namespace slateos\formsprocessor\services;

use Craft;
use craft\db\Query;
use yii\base\Component;
use slateos\formsprocessor\models\PdfTemplateModel;
use slateos\formsprocessor\records\PdfTemplateRecord;

class PdfTemplateService extends Component
{
    /** @return PdfTemplateModel[] */
    public function getAllPdfTemplates(): array
    {
        $rows = (new Query())
            ->from(PdfTemplateRecord::tableName())
            ->orderBy(['name' => SORT_ASC])
            ->all();

        return array_map(fn($row) => $this->populateModel($row), $rows);
    }

    public function getPdfTemplateById(int $id): ?PdfTemplateModel
    {
        $row = (new Query())
            ->from(PdfTemplateRecord::tableName())
            ->where(['id' => $id])
            ->one();

        return $row ? $this->populateModel($row) : null;
    }

    /** Returns [id => name] for use in select fields */
    public function getPdfTemplatesForSelect(): array
    {
        $templates = $this->getAllPdfTemplates();
        $options = ['' => '— Select a template —'];
        foreach ($templates as $t) {
            $options[$t->id] = $t->name;
        }
        return $options;
    }

    public function savePdfTemplate(PdfTemplateModel $model): bool
    {
        if (!$model->validate()) {
            return false;
        }

        $record = $model->id
            ? PdfTemplateRecord::findOne($model->id) ?? new PdfTemplateRecord()
            : new PdfTemplateRecord();

        $record->name       = $model->name;
        $record->bodyTwig   = $model->bodyTwig   ?? '';
        $record->headerTwig = $model->headerTwig ?? '';
        $record->footerTwig = $model->footerTwig ?? '';
        $record->sampleData = $model->sampleData ?: '{}';
        $record->paperSize  = $model->paperSize  ?: 'A4';
        $record->margins    = json_encode($model->margins ?: [10, 10, 10, 10]);

        if (!$record->save()) {
            $model->addErrors($record->getErrors());
            return false;
        }

        $model->id = $record->id;
        return true;
    }

    public function deletePdfTemplateById(int $id): bool
    {
        $record = PdfTemplateRecord::findOne($id);
        return $record ? (bool) $record->delete() : false;
    }

    private function populateModel(array $row): PdfTemplateModel
    {
        $model = new PdfTemplateModel();
        foreach ($row as $key => $value) {
            if (property_exists($model, $key)) {
                $model->$key = $key === 'margins'
                    ? (json_decode($value, true) ?? [10, 10, 10, 10])
                    : $value;
            }
        }
        return $model;
    }
}
