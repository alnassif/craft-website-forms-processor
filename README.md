# craft-forms-processor

Craft CMS 5 CP plugin — zero-code form configuration. Add form types in the CP, no deployment required.

Depends on [craft-pdf-generator](https://github.com/alnassif/craft-website-pdf-generator) and [craft-site-processor](https://github.com/alnassif/craft-website-site-processor).

## CP Sections

### Settings (global)
- Email sender: from, reply-to, BCC
- PDF defaults: paper size, margins
- Rate limiting defaults
- PDF attachment temp directory

### Form Types (per-form)
- Form handle (e.g. `tile-calculator`)
- Slate CMS API endpoint + API key + channel `_source`
- PDF template (references a stored template record)
- Email template path, subject (Twig-capable), BCC override
- Rate limit overrides
- Fields mapping (JSON — for CRM/Slate payload construction)
- Submissions tab — local backup of all submissions

### PDF Templates
- CodeMirror Twig editor (body, header, footer)
- Sample data editor (editable JSON)
- "Preview PDF" button — POSTs sample data through the real generator, renders in iframe

## Installation

### 1. Add to your project's `composer.json`

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/alnassif/craft-forms-processor" }
],
"require": {
    "slateos/craft-forms-processor": "dev-main"
}
```

```bash
composer install
./craft plugin/install forms-processor
```

### 2. Prerequisites

Both modules must be installed and registered in `config/app.php`:

```php
'modules' => [
    'pdf-generator'  => \modules\pdfgenerator\PdfGeneratorModule::class,
    'site-processor' => \modules\siteprocessor\SiteProcessorModule::class,
],
```

## Architecture

```
Browser form submit (JSON)
  ↓
craft-forms-processor (CP plugin)
  → looks up FormType by handle
  → SlateSubmitJob      → POST /slate-forms/submit → get submissionId
  → PdfService          → generate PDF (sync, via craft-pdf-generator subprocess)
  → SendEmailJob        → send email + PDF attachment, POST _notify to Slate
  → SlateAttachJob      → POST /slate-forms/attach with submissionId + PDF URL
  → SubmissionRecord    → local backup saved to DB
```

## Job chain order

1. `SlateSubmitJob` — POST to Slate, receive `submissionId`
2. PDF generation (sync)
3. `SendEmailJob` — email + attachment → on complete, POST `_notify` to Slate
4. `SlateAttachJob` — POST PDF URL to Slate with `submissionId`
