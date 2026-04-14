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
 */
class SlateService extends Component
{
    /**
     * Submit form data to Slate and return the submissionId.
     * Returns null (and logs) on failure — processing continues without a submissionId.
     */
    public function submit(string $endpoint, string $apiKey, string $source, array $contact, array $data): ?string
    {
        if (empty($endpoint) || empty($apiKey)) {
            return null;
        }

        $payload = array_merge($data, [
            '_source'  => $source,
            '_contact' => $contact,
        ]);

        $response = $this->post($endpoint, $apiKey, $payload);

        if ($response === null) {
            return null;
        }

        return $response['submissionId'] ?? $response['id'] ?? null;
    }

    /**
     * Attach a PDF URL to an existing Slate submission.
     */
    public function attach(string $endpoint, string $apiKey, string $submissionId, string $pdfUrl): bool
    {
        if (empty($endpoint) || empty($apiKey) || empty($submissionId)) {
            return false;
        }

        // Derive attach URL from submit URL: replace /submit with /attach
        $attachEndpoint = preg_replace('/\/submit$/', '/attach', $endpoint);

        $result = $this->post($attachEndpoint, $apiKey, [
            'submissionId' => $submissionId,
            'pdfUrl'       => $pdfUrl,
        ]);

        return $result !== null;
    }

    /**
     * Notify Slate of the email send outcome.
     * Called from ProcessEmailJob after the email is sent.
     */
    public function notify(string $endpoint, string $apiKey, string $submissionId, bool $emailSent, string $emailAddress = ''): bool
    {
        if (empty($endpoint) || empty($apiKey) || empty($submissionId)) {
            return false;
        }

        // Derive notify URL from submit URL: replace /submit with /notify
        $notifyEndpoint = preg_replace('/\/submit$/', '/notify', $endpoint);

        $result = $this->post($notifyEndpoint, $apiKey, [
            '_notify' => [
                'submissionId' => $submissionId,
                'emailSent'    => $emailSent,
                'emailAddress' => $emailAddress,
                'timestamp'    => date('c'),
            ],
        ]);

        return $result !== null;
    }

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
                'json'            => $payload,
                'http_errors'     => false,
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();
            $decoded = json_decode($body, true);

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
