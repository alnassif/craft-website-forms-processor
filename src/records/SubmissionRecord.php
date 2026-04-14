<?php

namespace slateos\formsprocessor\records;

use craft\db\ActiveRecord;

/**
 * @property int    $id
 * @property int    $formTypeId
 * @property string $contactName
 * @property string $contactEmail
 * @property string $contactPhone
 * @property string $payload       JSON — full submission payload
 * @property string $slateSubmissionId
 * @property string $status        pending | sent | failed
 * @property string $dateCreated
 */
class SubmissionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%formsprocessor_submissions}}';
    }
}
