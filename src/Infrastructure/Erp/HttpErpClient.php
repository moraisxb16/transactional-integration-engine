<?php

declare(strict_types=1);

namespace Relay\Infrastructure\Erp;

use Relay\Kernel\Logger;

/**
 * Talks to a real Atlas ERP over HTTP.
 *
 * Deliberately thin: its whole job is to turn transport outcomes into the two
 * exceptions the queue knows how to act on. Everything the engine does about
 * failure — retry, backoff, dead-letter, reconcile — is driven by which of the
 * two comes out of here, so the classification below is the most consequential
 * code in the class.
 *
 * Uses the cURL extension directly rather than a client library: one dependency
 * fewer in a repository whose point is the queue, not the HTTP stack.
 */
final class HttpErpClient implements ErpGateway
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly Logger $logger,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function registerOrder(array $order, string $idempotencyKey): string
    {
        $response = $this->request('POST', '/sales-orders', $order, [
            // Sent so the downstream system can collapse a retry into the
            // original write. If it ignores this header, a lost response means a
            // duplicate order and no amount of care on this side prevents it.
            'Idempotency-Key: ' . $idempotencyKey,
        ]);

        $id = $response['body']['id'] ?? null;
        if (!is_string($id) || $id === '') {
            // A 2xx with no identifier is worse than an error: something was
            // probably created and we cannot name it. Treat it as unavailable so
            // reconciliation goes and looks, rather than as rejected.
            throw new ErpUnavailable('Atlas ERP accepted the order but returned no identifier.');
        }

        return $id;
    }

    public function findOrderReference(string $reference): ?string
    {
        $response = $this->request('GET', '/sales-orders?external_reference=' . rawurlencode($reference));

        if ($response['status'] === 404) {
            return null;
        }

        $id = $response['body']['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param array<string,mixed>|null $payload
     * @param list<string> $extraHeaders
     * @return array{status:int,body:array<string,mixed>}
     */
    private function request(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
    {
        $handle = curl_init($this->baseUrl . $path);
        if ($handle === false) {
            throw new ErpUnavailable('Could not initialise the HTTP client.');
        }

        $headers = array_merge([
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ], $extraHeaders);

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
        ]);

        if ($payload !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        // No HTTP response at all: DNS, refused connection, timeout. Nothing was
        // created, or if it was we will never know from here.
        if ($raw === false) {
            throw new ErpUnavailable('Transport failure talking to Atlas ERP: ' . $error);
        }

        $body = json_decode((string) $raw, true);
        $body = is_array($body) ? $body : [];

        // 404 is an answer, not a failure, for the lookup path.
        if ($status === 404 && $method === 'GET') {
            return ['status' => $status, 'body' => $body];
        }

        // 5xx, 408 and 429 are all "try again later". 429 in particular must not
        // consume the attempt budget: being rate limited says nothing about
        // whether the order is valid.
        if ($status >= 500 || $status === 408 || $status === 429) {
            throw new ErpUnavailable(sprintf('Atlas ERP returned HTTP %d.', $status));
        }

        // 4xx is the system saying no. The payload is the problem.
        if ($status >= 400) {
            $message = is_string($body['message'] ?? null) ? $body['message'] : 'Atlas ERP rejected the request.';
            $code = is_string($body['code'] ?? null) ? $body['code'] : 'http_' . $status;

            $this->logger->warning('erp', 'Order rejected downstream', [
                'status' => $status,
                'code' => $code,
            ]);

            throw new ErpRejected($message, $code, permanent: $status === 422);
        }

        return ['status' => $status, 'body' => $body];
    }
}
