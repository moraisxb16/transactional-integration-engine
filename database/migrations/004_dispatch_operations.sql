-- Idempotency for calls that change something downstream.
--
-- The dangerous moment in this engine is not the failed call, it is the call
-- that succeeded and whose RESPONSE we lost. Retrying blindly creates a second
-- sales order. So every outbound write reserves a key here first; a retry finds
-- the reservation and asks the downstream system what happened instead of
-- repeating the write.

CREATE TABLE dispatch_operations (
    id {{PK}},

    order_id            BIGINT       NOT NULL,

    -- Deterministic: "register:NW-1001". The suffix exists for operations that
    -- legitimately repeat per order -- a refund split across three payment
    -- transactions needs three keys, or a failure on the second would make the
    -- retry refund the first one again.
    idempotency_key     VARCHAR(191) NOT NULL,

    kind                VARCHAR(32)  NOT NULL,

    -- pending -> ok | failed. A row stuck in 'pending' means we never learned
    -- the outcome, which is the case reconciliation exists to resolve.
    status              VARCHAR(16)  NOT NULL,

    attempts            INT          NOT NULL DEFAULT 0,
    response            {{JSON}}         NULL,

    created_at          {{DATETIME}} NOT NULL,
    updated_at          {{DATETIME}} NOT NULL,

    CONSTRAINT uq_dispatch_key UNIQUE (idempotency_key)
) {{ENGINE}};

CREATE INDEX ix_dispatch_order ON dispatch_operations (order_id);
