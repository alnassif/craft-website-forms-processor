<?php

namespace slateos\formsprocessor\jobs;

use Craft;
use craft\queue\BaseJob;
use slateos\formsprocessor\FormsProcessor;

/**
 * Async Slate attachment job.
 *
 * POSTs the PDF URL to Slate's /attach endpoint so the CRM record
 * is updated with a link to the generated document.
 *
 * Queued by ProcessEmailJob after email delivery succeeds.
 */
class SlateAttachJob extends BaseJob
{
    public string $slateEndpoint       = '';
    public string $slateApiKey         = '';
    public string $submissionId        = '';
    public string $pdfUrl              = '';
    public int    $submissionRecordId  = 0;

    public function execute($queue): void
    {
        if (empty($this->slateEndpoint) || empty($this->submissionId) || empty($this->pdfUrl)) {
            Craft::warning('SlateAttachJob skipped — missing endpoint, submissionId, or pdfUrl', __METHOD__);
            return;
        }

        $success = FormsProcessor::$plugin->slate->attach(
            $this->slateEndpoint,
            $this->slateApiKey,
            $this->submissionId,
            $this->pdfUrl
        );

        if ($success) {
            Craft::info(
                "SlateAttachJob: PDF attached to submission {$this->submissionId}",
                __METHOD__
            );
        } else {
            Craft::warning(
                "SlateAttachJob: failed to attach PDF to submission {$this->submissionId}",
                __METHOD__
            );
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Attaching PDF to Slate submission {$this->submissionId}";
    }
}
