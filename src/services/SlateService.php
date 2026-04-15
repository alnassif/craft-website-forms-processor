<?php

namespace slateos\formsprocessor\services;

use Craft;
use yii\base\Component;

/**
 * Slate CMS HTTP integration.
 *
 * Endpoints:
 *   POST /slate-forms/submit   — submit form data, returns submissionId
 *   POST /slate-forms/attach   — attach a PDF URL to an existing submission
 *   POST /slate-forms/notify   — report email send outcome back to Slate CP
 *
 * Payload format (v2):
 *   Nested arrays and objects are sent as rich { label, type, value } fields.
 *   - Associative arrays  → type: "object"
 *   - Sequential arrays   → type: "repeater"
 *   - Scalars             → passed through as-is (Slate auto-detects type/label)
 *   - _reserved keys      → passed through unchanged
 */
class SlateService extends Component
{
    /**
     * Submit form data to Slate and return the submissionId.
     *
     * @param string $title  Human-readable title shown in Slate CP (optional)
     * @param string $url    Source page URL (optional)
     *
     * Returns null (and logs) on failure — processing continues without a submissionId.
     */
    public function submit(
        string $endpoint,
        string $apiKey,
        string $source,
        array  $contact,
        array  $data,
        string $title    = '',
        string $url      = '',
        array  $fieldsMap = []
    ): ?string {
        if (empty($endpoint) || empty($apiKey)) {
            return null;
        }

        $payload = $this->buildSlatePayload($source, $contact, $data, $title, $url, $fieldsMap);

        $response = $this->post($endpoint, $apiKey, $payload);

        if ($response === null) {
            return null;
        }

        return $response['submissionId'] ?? $response['id'] ?? null;
    }

    /**
     * Attach a PDF URL to an existing Slate submission.
     */
    public function attach(
        string $endpoint,
        string $apiKey,
        string $submissionId,
        string $pdfUrl,
        string $filename = '',
        int    $size     = 0
    ): bool {
        if (empty($endpoint) || empty($apiKey) || empty($submissionId)) {
            return false;
        }

        $attachEndpoint = preg_replace('/\/submit$/', '/attach', $endpoint);

        $payload = [
            'submissionId' => $submissionId,
            'pdfUrl'       => $pdfUrl,
        ];

        if ($filename !== '') { $payload['filename'] = $filename; }
        if ($size > 0)        { $payload['size']     = $size; }

        $result = $this->post($attachEndpoint, $apiKey, $payload);

        return $result !== null;
    }

    /**
     * Notify Slate of the email send outcome.
     * Called from ProcessEmailJob after the email is sent.
     */
    public function notify(
        string $endpoint,
        string $apiKey,
        string $submissionId,
        bool   $emailSent,
        string $emailAddress = '',
        string $subject      = ''
    ): bool {
        if (empty($endpoint) || empty($apiKey) || empty($submissionId)) {
            return false;
        }

        $notifyEndpoint = preg_replace('/\/submit$/', '/notify', $endpoint);

        $notify = [
            'submissionId' => $submissionId,
            'emailSent'    => $emailSent,
            'emailAddress' => $emailAddress,
            'timestamp'    => date('c'),
        ];

        if ($subject !== '') {
            $notify['subject'] = $subject;
        }

        $result = $this->post($notifyEndpoint, $apiKey, ['_notify' => $notify]);

        return $result !== null;
    }

    // ── Payload builder ───────────────────────────────────────────────────────

    /**
     * Build the rich Slate v2 payload.
     *
     * fieldsMap per-field config (keyed by field name):
     *   skip  bool    — omit this field entirely
     *   label string  — human-readable label shown in Slate CP
     *   type  string  — text|number|email|url|date|object|repeater
     *                   (number also casts string values to numeric)
     *
     * Behaviour when no fieldsMap entry exists for a field:
     *   - Arrays   → auto-typed as repeater (list) or object (assoc)
     *   - Scalars  → pass through as-is; Slate auto-detects type and prettifies key
     *   - Empty arrays → always skipped
     *   - Underscore-prefixed keys → always skipped (internal fields)
     */
    private function buildSlatePayload(
        string $source,
        array  $contact,
        array  $data,
        string $title,
        string $url,
        array  $fieldsMap
    ): array {
        $payload = ['_source' => $source];

        if ($title !== '') { $payload['_title'] = $title; }
        if ($url   !== '') { $payload['_url']   = $url; }

        // Top-level name + email scalars for Slate CP search/index
        if (!empty($contact['name']))  { $payload['name']  = $contact['name']; }
        if (!empty($contact['email'])) { $payload['email'] = $contact['email']; }

        // Full contact as a labelled object field
        if (!empty($contact)) {
            $payload['contact'] = [
                'label' => 'Contact Details',
                'type'  => 'object',
                'value' => $contact,
            ];
        }

        // Data fields
        foreach ($data as $key => $value) {
            $strKey = (string) $key;
            $map    = $fieldsMap[$strKey] ?? null;

            // Always skip underscore-prefixed internal keys and explicitly skipped fields
            if (str_starts_with($strKey, '_') || ($map['skip'] ?? false)) {
                continue;
            }

            // Always skip empty arrays — they add noise with no value
            if (is_array($value) && empty($value)) {
                continue;
            }

            if (is_array($value)) {
                $type  = $map['type'] ?? (array_is_list($value) ? 'repeater' : 'object');
                $field = ['type' => $type, 'value' => $value];
                if ($map['label'] ?? '') { $field['label'] = $map['label']; }
                $payload[$strKey] = $field;
            } else {
                // Cast to number if configured and value is numeric
                if (($map['type'] ?? '') === 'number' && is_numeric($value)) {
                    $value = $value + 0;
                }

                if ($map) {
                    // Has fieldsMap entry — wrap as rich field
                    $field = ['value' => $value];
                    if ($map['label'] ?? '') { $field['label'] = $map['label']; }
                    if ($map['type']  ?? '') { $field['type']  = $map['type']; }
                    $payload[$strKey] = $field;
                } else {
                    $payload[$strKey] = $value;
                }
            }
        }

        return $payload;
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    /**
     * POST JSON to a Slate endpoint with Bearer auth.
     * Returns decoded response array, or null on failure.
     */
    private function post(string $url, string $apiKey, array $payload): ?array
    {
        try {
            $client = Craft::createGuzzleClient(['timeout' => 15, 'connect_timeout' => 5]);

            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'json'        => $payload,
                'http_errors' => false,
            ]);

            $statusCode = $response->getStatusCode();
            $body       = (string) $response->getBody();
            $decoded    = json_decode($body, true);

            if ($statusCode >= 200 && $statusCode < 300) {
                return $decoded ?? [];
            }

            Craft::warning(
                "Slate API error {$statusCode} for {$url}: {$body}",
                __METHOD__
            );
            return null;

        } catch (\Throwable $e) {
            Craft::error("Slate API request failed for {$url}: " . $e->getMessage(), __METHOD__);
            return null;
        }
    }
}
