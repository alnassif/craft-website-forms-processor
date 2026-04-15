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
    public string $pdfFilename         = '';
    public string $pdfToken            = '';
    public int    $submissionRecordId  = 0;

    public function execute($queue): void
    {
        if (empty($this->slateEndpoint) || empty($this->submissionId) || empty($this->pdfUrl)) {
            Craft::warning('SlateAttachJob skipped — missing endpoint, submissionId, or pdfUrl', __METHOD__);
            return;
        }

        // Resolve file size from temp storage if token is available
        $size = 0;
        if ($this->pdfToken) {
            $settings = FormsProcessor::$plugin->getSettings();
            $base = Craft::getAlias($settings->attachmentTempDir ?: '@storage/runtime/pdf-attachments');
            $path = rtrim($base, '/') . '/' . $this->pdfToken . '.pdf';
            if (file_exists($path)) {
                $size = (int) filesize($path);
            }
        }

        $success = FormsProcessor::$plugin->slate->attach(
            $this->slateEndpoint,
            $this->slateApiKey,
            $this->submissionId,
            $this->pdfUrl,
            $this->pdfFilename,
            $size
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
