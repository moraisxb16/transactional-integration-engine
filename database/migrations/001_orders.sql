-- The queue and the order state live in ONE table.
--
-- A separate "queue" table holding order ids was the first design and it was
-- wrong: claiming from one table and writing status to another means the two can
-- disagree, and the disagreement is only visible when an order is both claimed
-- and cancelled. Here the claim is a conditional UPDATE on the row that already
-- holds the truth.

CREATE TABLE orders (
    id {{PK}},

    -- The storefront's own identifier. UNIQUE is what makes webhook replay safe:
    -- a second delivery of the same order collides here instead of inserting.
    reference           VARCHAR(64)  NOT NULL,
    source              VARCHAR(32)  NOT NULL,

    status              VARCHAR(32)  NOT NULL,

    currency            CHAR(3)      NOT NULL,
    total_cents         BIGINT       NOT NULL,

    customer_ref        VARCHAR(64)      NULL,
    customer_email      VARCHAR(255)     NULL,

    -- A digest of the normalized payload, never the payload itself. Storing raw
    -- webhook bodies is how a disk fills up at 03:00; the digest still answers
    -- "did this delivery carry different data than the last one?".
    payload_digest      CHAR(64)         NULL,

    -- Retry bookkeeping.
    attempts            INT          NOT NULL DEFAULT 0,
    next_attempt_at     {{DATETIME}}     NULL,
    last_error          VARCHAR(500)     NULL,

    -- Lease. A claim is only valid while lease_expires_at is in the future, so a
    -- worker that dies mid-order does not strand it: the row becomes claimable
    -- again on its own, with no cleanup process to forget to run.
    lease_token         CHAR(32)         NULL,
    lease_expires_at    {{DATETIME}}     NULL,
    leased_by           VARCHAR(64)      NULL,

    -- The downstream identifier, set once the system of record accepts the order.
    -- Its presence is the real proof of registration — the status column is our
    -- opinion, this is theirs.
    erp_reference       VARCHAR(64)      NULL,

    created_at          {{DATETIME}}  NOT NULL,
    updated_at          {{DATETIME}}  NOT NULL,

    CONSTRAINT uq_orders_reference UNIQUE (reference)
) {{ENGINE}};

-- The claim query's index. Column order matters: status narrows hardest, then
-- the two time predicates. Without this the claim degrades into a full scan
-- while holding row locks, which is the slowest possible way to hold a lock.
CREATE INDEX ix_orders_claim ON orders (status, next_attempt_at, lease_expires_at);

-- Supports the dead-letter sweep and the operator's "what is stuck" screen.
CREATE INDEX ix_orders_status_updated ON orders (status, updated_at);
