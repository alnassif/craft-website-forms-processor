<?php

namespace slateos\formsprocessor\models;

use craft\base\Model;

/**
 * Global plugin settings — stored in project config / plugin settings table.
 */
class Settings extends Model
{
    // ── Email sender ──────────────────────────────────────────────────────────

    public string $fromEmail  = '';
    public string $fromName   = '';
    public string $replyTo    = '';
    public string $bcc        = '';

    // ── Paths ─────────────────────────────────────────────────────────────────

    /** Temporary directory for generated PDF files */
    public string $attachmentTempDir = '@storage/runtime/pdf-attachments';

    // ── Rate limiting (defaults, overridable per form type) ───────────────────

    /** Max submissions per IP per window */
    public int $rateLimitMax    = 5;

    /** Rate limit window in seconds */
    public int $rateLimitWindow = 3600;

    // ── PDF defaults (overridable per form type) ──────────────────────────────

    public string $paperSize = 'A4';

    /** [top, right, bottom, left] in mm */
    public array $margins = [10, 10, 10, 10];

    // ─────────────────────────────────────────────────────────────────────────

    public function rules(): array
    {
        return [
            [['fromEmail', 'fromName', 'replyTo', 'bcc', 'attachmentTempDir', 'paperSize'], 'string'],
            [['rateLimitMax', 'rateLimitWindow'], 'integer'],
            [['margins'], 'safe'],
            [['fromEmail'], 'email'],
        ];
    }
}
