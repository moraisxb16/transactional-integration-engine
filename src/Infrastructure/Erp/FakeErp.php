<?php

declare(strict_types=1);

namespace Relay\Infrastructure\Erp;

/**
 * An in-memory Atlas ERP whose failures are scriptable.
 *
 * It lives in src/ rather than tests/ because the demo scripts in bin/ depend on
 * it: showing that a repeated webhook does not duplicate an order, or that an
 * outage is recovered from, requires being able to cause an outage on demand.
 * A test double that only the test suite can reach would leave the demos with
 * nothing to demonstrate.
 *
 * It models the one behaviour that matters most in a real counterpart: the same
 * idempotency key returns the SAME identifier instead of creating a second order.
 */
final class FakeErp implements ErpGateway
{
    /** @var array<string,string> our reference => downstream id */
    private array $orders = [];

    /** @var array<string,string> idempotency key => downstream id */
    private array $byIdempotencyKey = [];

    /** @var list<callable(array<string,mixed>,string):void> */
    private array $scriptedFailures = [];

    private int $calls = 0;
    private int $sequence = 0;
    private bool $down = false;

    /**
     * Simulate a lost response: the order IS created downstream, but the caller
     * gets a timeout and never learns the identifier.
     *
     * This is the scenario the whole idempotency and reconciliation design
     * exists for, and it is almost impossible to produce against a real system
     * on purpose.
     */
    public bool $loseNextResponse = false;

    public function __construct(private readonly string $prefix = 'ATLAS')
    {
    }

    public function registerOrder(array $order, string $idempotencyKey): string
    {
        $this->calls++;

        if ($this->down) {
            throw new ErpUnavailable('Atlas ERP is not responding.');
        }

        if ($this->scriptedFailures !== []) {
            $failure = array_shift($this->scriptedFailures);
            $failure($order, $idempotencyKey);
        }

        // The contract that makes retries safe: a key we have seen before gets
        // the original identifier back, and no second order is created.
        if (isset($this->byIdempotencyKey[$idempotencyKey])) {
            return $this->byIdempotencyKey[$idempotencyKey];
        }

        $reference = (string) ($order['reference'] ?? '');
        if ($reference !== '' && isset($this->orders[$reference])) {
            return $this->orders[$reference];
        }

        $downstreamId = sprintf('%s-%05d', $this->prefix, ++$this->sequence);
        $this->byIdempotencyKey[$idempotencyKey] = $downstreamId;
        if ($reference !== '') {
            $this->orders[$reference] = $downstreamId;
        }

        if ($this->loseNextResponse) {
            $this->loseNextResponse = false;
            // Created, then the wire drops. Exactly the state reconciliation
            // has to be able to discover later.
            throw new ErpUnavailable('Timed out waiting for Atlas ERP to answer.');
        }

        return $downstreamId;
    }

    public function findOrderReference(string $reference): ?string
    {
        $this->calls++;

        if ($this->down) {
            throw new ErpUnavailable('Atlas ERP is not responding.');
        }

        return $this->orders[$reference] ?? null;
    }

    // --- scripting -----------------------------------------------------------

    public function goDown(): void
    {
        $this->down = true;
    }

    public function comeBackUp(): void
    {
        $this->down = false;
    }

    public function isDown(): bool
    {
        return $this->down;
    }

    /** Fail the next $times calls as unavailable, then behave normally. */
    public function failNext(int $times, string $message = 'Temporary Atlas ERP failure.'): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->scriptedFailures[] = static function () use ($message): void {
                throw new ErpUnavailable($message);
            };
        }
    }

    /**
     * Fail the next $times calls with an UNCLASSIFIED error.
     *
     * Distinct from failNext() on purpose. An unavailable dependency is
     * blameless and costs no attempt; an unexpected error could be anything,
     * including something about this order in particular, so it does consume
     * the budget and can end in dead_letter. The two have to be separable to be
     * demonstrable.
     */
    public function breakNext(int $times, string $message = 'Unexpected Atlas ERP error.'): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->scriptedFailures[] = static function () use ($message): void {
                throw new \RuntimeException($message);
            };
        }
    }

    /** Reject the next call as a business-rule refusal. */
    public function rejectNext(string $message = 'Unknown SKU.', string $code = 'unknown_sku'): void
    {
        $this->scriptedFailures[] = static function () use ($message, $code): void {
            throw new ErpRejected($message, $code);
        };
    }

    public function callCount(): int
    {
        return $this->calls;
    }

    /** How many distinct orders exist downstream — the duplication check. */
    public function orderCount(): int
    {
        return count($this->orders);
    }

    public function hasOrder(string $reference): bool
    {
        return isset($this->orders[$reference]);
    }
}
