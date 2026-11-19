<?php

declare(strict_types=1);

namespace Relay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Relay\Domain\Order\InvalidTransition;
use Relay\Domain\Order\OrderStatus;
use Relay\Domain\Order\StateMachine;

final class StateMachineTest extends TestCase
{
    #[Test]
    #[DataProvider('legalTransitions')]
    public function it_allows_legal_transitions(OrderStatus $from, OrderStatus $to): void
    {
        self::assertTrue(StateMachine::allows($from, $to), "{$from->value} -> {$to->value} should be allowed");
    }

    /** @return iterable<string,array{OrderStatus,OrderStatus}> */
    public static function legalTransitions(): iterable
    {
        yield 'capture an unpaid order' => [OrderStatus::RECEIVED, OrderStatus::PAYMENT_PENDING];
        yield 'payment confirmed' => [OrderStatus::PAYMENT_PENDING, OrderStatus::READY];
        yield 'claimed by a worker' => [OrderStatus::READY, OrderStatus::DISPATCHING];
        yield 'accepted downstream' => [OrderStatus::DISPATCHING, OrderStatus::REGISTERED];
        yield 'transient failure' => [OrderStatus::DISPATCHING, OrderStatus::FAILED];
        yield 'retry after backoff' => [OrderStatus::FAILED, OrderStatus::DISPATCHING];
        yield 'budget exhausted' => [OrderStatus::DISPATCHING, OrderStatus::DEAD_LETTER];
        yield 'operator retries a dead letter' => [OrderStatus::DEAD_LETTER, OrderStatus::DISPATCHING];
        yield 'reconciler repairs a dead letter' => [OrderStatus::DEAD_LETTER, OrderStatus::REGISTERED];
        yield 'blocked order is fixed' => [OrderStatus::BLOCKED, OrderStatus::READY];
    }

    #[Test]
    #[DataProvider('illegalTransitions')]
    public function it_refuses_illegal_transitions(OrderStatus $from, OrderStatus $to): void
    {
        self::assertFalse(StateMachine::allows($from, $to), "{$from->value} -> {$to->value} should be refused");

        $this->expectException(InvalidTransition::class);
        StateMachine::assert($from, $to, 'NW-1');
    }

    /** @return iterable<string,array{OrderStatus,OrderStatus}> */
    public static function illegalTransitions(): iterable
    {
        // The one that matters most: marking an order as existing downstream
        // without ever having dispatched it.
        yield 'registered without dispatching' => [OrderStatus::READY, OrderStatus::REGISTERED];
        yield 'unpaid order dispatched' => [OrderStatus::PAYMENT_PENDING, OrderStatus::DISPATCHING];
        yield 'terminal order revived' => [OrderStatus::REGISTERED, OrderStatus::DISPATCHING];
        yield 'cancelled order revived' => [OrderStatus::CANCELLED, OrderStatus::READY];
        yield 'blocked order dispatched directly' => [OrderStatus::BLOCKED, OrderStatus::DISPATCHING];
        yield 'dispatching back to ready' => [OrderStatus::DISPATCHING, OrderStatus::READY];
    }

    #[Test]
    public function terminal_states_have_no_way_out(): void
    {
        foreach (OrderStatus::terminal() as $status) {
            self::assertSame(
                [],
                StateMachine::allowedFrom($status),
                "{$status->value} is terminal and must have no outgoing transitions",
            );
        }
    }

    /**
     * The distinction this project cares most about. These two states stop
     * processing and wait for a person, but nothing reached the downstream
     * system, so they must stay reachable — otherwise the system refuses the
     * very action the state exists to invite.
     */
    #[Test]
    public function states_awaiting_a_human_are_not_terminal(): void
    {
        self::assertFalse(OrderStatus::DEAD_LETTER->isTerminal());
        self::assertFalse(OrderStatus::BLOCKED->isTerminal());

        self::assertTrue(OrderStatus::DEAD_LETTER->awaitsHuman());
        self::assertTrue(OrderStatus::BLOCKED->awaitsHuman());

        self::assertNotSame([], StateMachine::allowedFrom(OrderStatus::DEAD_LETTER));
        self::assertNotSame([], StateMachine::allowedFrom(OrderStatus::BLOCKED));
    }

    #[Test]
    public function an_unpaid_order_is_never_claimable(): void
    {
        self::assertNotContains(OrderStatus::PAYMENT_PENDING, OrderStatus::claimable());
        self::assertNotContains(OrderStatus::DEAD_LETTER, OrderStatus::claimable());
        self::assertNotContains(OrderStatus::BLOCKED, OrderStatus::claimable());

        self::assertSame(
            [OrderStatus::READY, OrderStatus::FAILED],
            OrderStatus::claimable(),
        );
    }

    /**
     * Guards against adding a state, wiring it as a target and forgetting to
     * give it any way out. Without this the symptom appears in production as a
     * stuck order rather than here as a red test.
     */
    #[Test]
    public function the_table_has_no_dead_ends(): void
    {
        self::assertSame([], StateMachine::deadEnds());
    }

    #[Test]
    public function every_status_is_either_terminal_or_has_transitions(): void
    {
        foreach (OrderStatus::cases() as $status) {
            if ($status->isTerminal()) {
                continue;
            }

            self::assertNotSame(
                [],
                StateMachine::allowedFrom($status),
                "{$status->value} is not terminal, so it needs at least one transition",
            );
        }
    }
}
