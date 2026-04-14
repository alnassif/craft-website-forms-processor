<?php

namespace slateos\formsprocessor\models;

use craft\base\Model;

/**
 * PDF template record — stored in the DB, editable in the CP.
 */
class PdfTemplateModel extends Model
{
    public ?int    $id          = null;
    public string  $name        = '';

    /** Twig source for the full HTML document */
    public string  $bodyTwig    = '';

    /** Twig source for the repeating page header (inline styles only) */
    public string  $headerTwig  = '';

    /** Twig source for the repeating page footer (inline styles only) */
    public string  $footerTwig  = '';

    /** JSON string — sample data used for live preview */
    public string  $sampleData  = '{}';

    public string  $paperSize   = 'A4';

    /** [top, right, bottom, left] in mm */
    public array   $margins     = [10, 10, 10, 10];

    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;

    // ─────────────────────────────────────────────────────────────────────────

    public function rules(): array
    {
        return [
            [['name'], 'required'],
            [['name', 'bodyTwig', 'headerTwig', 'footerTwig', 'sampleData', 'paperSize'], 'string'],
            [['margins'], 'safe'],
        ];
    }

    /**
     * Return sample data as an array (for preview rendering).
     */
    public function getSampleDataArray(): array
    {
        $decoded = json_decode($this->sampleData, true);
        return is_array($decoded) ? $decoded : [];
    }
}
