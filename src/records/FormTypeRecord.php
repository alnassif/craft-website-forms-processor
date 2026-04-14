<?php

namespace slateos\formsprocessor\records;

use craft\db\ActiveRecord;

/**
 * @property int    $id
 * @property string $name
 * @property string $handle
 * @property string $slateEndpoint
 * @property string $slateApiKey
 * @property string $slateSource
 * @property int    $pdfTemplateId
 * @property string $emailTemplatePath
 * @property string $emailSubject
 * @property string $emailBody
 * @property string $bccOverride
 * @property int    $rateLimitMax
 * @property int    $rateLimitWindow
 * @property string $fieldsMap
 * @property bool   $enabled
 */
class FormTypeRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%formsprocessor_form_types}}';
    }
}
