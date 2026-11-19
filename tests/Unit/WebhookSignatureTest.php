<?php

declare(strict_types=1);

namespace Relay\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Relay\Http\WebhookSignature;

final class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'a-test-secret-value';
    private const BODY = '{"event_id":"evt_1","order":{"reference":"NW-1"}}';

    #[Test]
    public function it_accepts_a_correct_signature(): void
    {
        $signature = WebhookSignature::sign(self::BODY, self::SECRET);

        self::assertTrue(WebhookSignature::isValid(self::BODY, $signature, self::SECRET));
    }

    #[Test]
    public function it_rejects_a_signature_made_with_another_secret(): void
    {
        $signature = WebhookSignature::sign(self::BODY, 'a-different-secret');

        self::assertFalse(WebhookSignature::isValid(self::BODY, $signature, self::SECRET));
    }

    /**
     * The signature covers the raw bytes. Decoding and re-encoding JSON changes
     * key order and whitespace, so a handler that verifies against re-encoded
     * input rejects legitimate traffic — or, worse, is written to tolerate the
     * mismatch and ends up verifying nothing.
     */
    #[Test]
    public function it_rejects_a_body_that_changed_in_any_way(): void
    {
        $signature = WebhookSignature::sign(self::BODY, self::SECRET);

        self::assertFalse(WebhookSignature::isValid(self::BODY . ' ', $signature, self::SECRET));
        self::assertFalse(WebhookSignature::isValid(
            '{"order":{"reference":"NW-1"},"event_id":"evt_1"}',
            $signature,
            self::SECRET,
        ));
    }

    /**
     * Fail-closed. Every one of these is a path where a permissive
     * implementation would let the request through "just this once".
     */
    #[Test]
    public function every_missing_piece_fails_closed(): void
    {
        $signature = WebhookSignature::sign(self::BODY, self::SECRET);

        self::assertFalse(
            WebhookSignature::isValid(self::BODY, null, self::SECRET),
            'a missing signature header must not pass',
        );
        self::assertFalse(
            WebhookSignature::isValid(self::BODY, '', self::SECRET),
            'an empty signature must not pass',
        );
        self::assertFalse(
            WebhookSignature::isValid(self::BODY, $signature, ''),
            'an unconfigured secret must not pass - it must stop the request, not disable the check',
        );
        self::assertFalse(
            WebhookSignature::isValid('', $signature, self::SECRET),
            'an empty body must not pass',
        );
    }

    #[Test]
    public function service_tokens_are_checked_separately_and_fail_closed(): void
    {
        self::assertTrue(WebhookSignature::isValidServiceToken('token-abc', 'token-abc'));

        self::assertFalse(WebhookSignature::isValidServiceToken('token-abd', 'token-abc'));
        self::assertFalse(WebhookSignature::isValidServiceToken(null, 'token-abc'));
        self::assertFalse(WebhookSignature::isValidServiceToken('', 'token-abc'));
        self::assertFalse(
            WebhookSignature::isValidServiceToken('anything', ''),
            'an unconfigured token must not turn the check off',
        );
    }

    #[Test]
    public function a_prefix_of_the_correct_signature_is_rejected(): void
    {
        $signature = WebhookSignature::sign(self::BODY, self::SECRET);

        self::assertFalse(WebhookSignature::isValid(self::BODY, substr($signature, 0, 10), self::SECRET));
    }
}
