<?php

namespace slateos\formsprocessor\models;

use craft\base\Model;

/**
 * Per-form-type configuration record.
 */
class FormTypeModel extends Model
{
    public ?int    $id             = null;
    public string  $name          = '';

    /** URL-safe handle used in frontend endpoints, e.g. "tile-calculator" */
    public string  $handle        = '';

    // ── Slate CMS integration ─────────────────────────────────────────────────

    public string  $slateEndpoint = '';
    public string  $slateApiKey   = '';

    /** Slate channel _source value, e.g. "tile-calculations" */
    public string  $slateSource   = '';

    // ── Templates ─────────────────────────────────────────────────────────────

    /** FK → PdfTemplateRecord.id */
    public ?int    $pdfTemplateId   = null;

    /** Twig template path for email body */
    public string  $emailTemplatePath = '';

    // ── Email ─────────────────────────────────────────────────────────────────

    /** Twig-capable subject, e.g. "Your Estimate — {{ siteName }}" */
    public string  $emailSubject   = '';
    public string  $emailBody      = '';

    /** Override global BCC for this form type */
    public string  $bccOverride    = '';

    // ── Rate limiting overrides ───────────────────────────────────────────────

    public ?int    $rateLimitMax    = null;
    public ?int    $rateLimitWindow = null;

    // ── Fields mapping (for CRM / Slate) ─────────────────────────────────────

    /** JSON object mapping form field names to CRM field names */
    public string  $fieldsMap      = '{}';

    public bool    $enabled        = true;

    public ?string $dateCreated    = null;
    public ?string $dateUpdated    = null;

    // ─────────────────────────────────────────────────────────────────────────

    public function rules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name', 'handle', 'slateEndpoint', 'slateApiKey', 'slateSource',
               'emailTemplatePath', 'emailSubject', 'emailBody', 'bccOverride', 'fieldsMap'], 'string'],
            [['pdfTemplateId', 'rateLimitMax', 'rateLimitWindow'], 'integer'],
            [['enabled'], 'boolean'],
            ['handle', 'match', 'pattern' => '/^[a-z0-9\-]+$/',
             'message' => 'Handle may only contain lowercase letters, numbers and hyphens.'],
        ];
    }
}
