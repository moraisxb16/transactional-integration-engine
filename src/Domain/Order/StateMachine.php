<?php

declare(strict_types=1);

namespace Relay\Domain\Order;

/**
 * The transition table, and nothing else.
 *
 * Pure on purpose: no database, no clock, no logging. Whether
 * dispatching -> registered is legal is a question about the domain, and
 * answering it should not require a connection. The authority that *performs* a
 * transition — validating here, writing the row and the audit event in one
 * transaction — is OrderRepository::transition(). Two jobs, two places; see
 * docs/decisions/0001-single-transition-authority.md.
 *
 * Reading the table: a state's entry lists every state reachable from it. A
 * state absent as a key has no outgoing transitions, which is exactly what
 * terminal means.
 */
final class StateMachine
{
    /**
     * @return array<string, list<OrderStatus>>
     */
    private static function table(): array
    {
        return [
            // An order arrives either already paid or not. Nothing else can
            // happen to it yet.
            OrderStatus::RECEIVED->value => [
                OrderStatus::PAYMENT_PENDING,
                OrderStatus::READY,
                OrderStatus::CANCELLED,
            ],

            // The storefront confirms payment later, or the customer gives up.
            OrderStatus::PAYMENT_PENDING->value => [
                OrderStatus::READY,
                OrderStatus::CANCELLED,
            ],

            // READY is the queue's entry point. BLOCKED is reachable from here
            // because validation runs before the first downstream call: an
            // unknown SKU should stop the order without spending an attempt.
            OrderStatus::READY->value => [
                OrderStatus::DISPATCHING,
                OrderStatus::BLOCKED,
                OrderStatus::CANCELLED,
            ],

            // The four ways a dispatch ends. DEAD_LETTER is reachable directly
            // because the attempt that exhausts the limit lands here without
            // passing through FAILED first.
            OrderStatus::DISPATCHING->value => [
                OrderStatus::REGISTERED,
                OrderStatus::FAILED,
                OrderStatus::BLOCKED,
                OrderStatus::DEAD_LETTER,
            ],

            // A failed order is claimed again once its backoff elapses.
            OrderStatus::FAILED->value => [
                OrderStatus::DISPATCHING,
                OrderStatus::DEAD_LETTER,
                OrderStatus::CANCELLED,
            ],

            // Dead letters are not a graveyard. A human can retry one, and
            // reconciliation can promote one straight to REGISTERED on finding
            // that the downstream system had it all along — see
            // Application\Queue\ReconcileDeadLetters.
            OrderStatus::DEAD_LETTER->value => [
                OrderStatus::DISPATCHING,
                OrderStatus::REGISTERED,
                OrderStatus::CANCELLED,
            ],

            // Whoever fixes the underlying data puts the order back in the queue.
            OrderStatus::BLOCKED->value => [
                OrderStatus::READY,
                OrderStatus::CANCELLED,
            ],

            // REGISTERED and CANCELLED appear nowhere as keys. That absence is
            // the definition of terminal, so there is no second list to keep in
            // step with OrderStatus::terminal().
        ];
    }

    /** @return list<OrderStatus> */
    public static function allowedFrom(OrderStatus $from): array
    {
        return self::table()[$from->value] ?? [];
    }

    public static function allows(OrderStatus $from, OrderStatus $to): bool
    {
        return in_array($to, self::allowedFrom($from), true);
    }

    /**
     * @throws InvalidTransition
     */
    public static function assert(OrderStatus $from, OrderStatus $to, string $reference): void
    {
        if (!self::allows($from, $to)) {
            throw new InvalidTransition($from, $to, $reference);
        }
    }

    /**
     * Consistency check used by the test suite: every state named in the table's
     * values must be either a key or terminal.
     *
     * Without it, adding a state and forgetting to give it outgoing transitions
     * produces an order that silently cannot move, and the symptom shows up in
     * production as a stuck queue rather than as a failing test.
     *
     * @return list<string> names of states that are reachable but have no exit
     */
    public static function deadEnds(): array
    {
        $table = self::table();
        $found = [];

        foreach ($table as $targets) {
            foreach ($targets as $target) {
                if (!$target->isTerminal() && !array_key_exists($target->value, $table)) {
                    $found[$target->value] = true;
                }
            }
        }

        return array_keys($found);
    }
}
