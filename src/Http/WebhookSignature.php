<?php

declare(strict_types=1);

namespace Relay\Http;

/**
 * HMAC-SHA256 verification of inbound webhooks, fail-closed.
 *
 * Fail-closed means every path that is not a successful verification returns
 * false. There is no "skip the check when the header is absent", no "allow it if
 * the source IP looks right", no configuration flag that turns it off. An
 * endpoint that creates orders from unauthenticated input is an endpoint that
 * creates orders for anyone who finds the URL.
 *
 * Two details that are easy to get wrong and both silently fatal:
 *
 *  - The signature is computed over the RAW body, before any parsing. Decoding
 *    and re-encoding JSON changes key order and whitespace, and the hash with it.
 *  - The comparison uses hash_equals, never ===. String comparison returns as
 *    soon as two bytes differ, and the time it took leaks how many leading bytes
 *    were right, which is enough to recover a signature byte by byte.
 */
final class WebhookSignature
{
    public static function isValid(string $rawBody, ?string $providedSignature, string $secret): bool
    {
        if ($secret === '' || $providedSignature === null || $providedSignature === '' || $rawBody === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($expected, $providedSignature);
    }

    /** Produce a signature. Used by the demo scripts and the test suite. */
    public static function sign(string $rawBody, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
    }

    /**
     * Constant-time check of the service-to-service bearer token.
     *
     * Kept separate from the HMAC path because the two authenticate different
     * things: HMAC proves a message came from the storefront, this proves a
     * caller is one of our own services. Letting one stand in for the other
     * means a leaked service token can forge storefront traffic.
     */
    public static function isValidServiceToken(?string $provided, string $expected): bool
    {
        if ($expected === '' || $provided === null || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }
}
