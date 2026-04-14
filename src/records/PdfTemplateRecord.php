<?php

namespace slateos\formsprocessor\records;

use craft\db\ActiveRecord;

/**
 * @property int    $id
 * @property string $name
 * @property string $bodyTwig
 * @property string $headerTwig
 * @property string $footerTwig
 * @property string $sampleData
 * @property string $paperSize
 * @property string $margins
 */
class PdfTemplateRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%formsprocessor_pdf_templates}}';
    }
}
