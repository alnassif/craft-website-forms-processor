<?php

namespace slateos\formsprocessor\services;

use Craft;
use craft\db\Query;
use yii\base\Component;
use slateos\formsprocessor\records\SubmissionRecord;

class SubmissionService extends Component
{
    public function getSubmissionsByFormTypeId(int $formTypeId, int $limit = 100): array
    {
        return (new Query())
            ->from(SubmissionRecord::tableName())
            ->where(['formTypeId' => $formTypeId])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    public function saveSubmission(array $data): int
    {
        $record = new SubmissionRecord();
        $record->formTypeId        = $data['formTypeId'];
        $record->contactName       = $data['contactName'] ?? '';
        $record->contactEmail      = $data['contactEmail'] ?? '';
        $record->contactPhone      = $data['contactPhone'] ?? '';
        $record->payload           = json_encode($data['payload'] ?? []) ?: '{}';
        $record->slateSubmissionId = $data['slateSubmissionId'] ?? '';
        $record->status            = $data['status'] ?? 'pending';
        $record->save();
        return $record->id;
    }

    public function updateStatus(int $submissionId, string $status, string $slateSubmissionId = ''): void
    {
        $record = SubmissionRecord::findOne($submissionId);
        if ($record) {
            $record->status = $status;
            if ($slateSubmissionId) {
                $record->slateSubmissionId = $slateSubmissionId;
            }
            $record->save();
        }
    }
}
