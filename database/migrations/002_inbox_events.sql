-- Webhook deduplication.
--
-- Every delivery is recorded here BEFORE the order is touched, and the unique
-- constraint is what makes the whole ingestion idempotent: a redelivery loses
-- the insert race and the handler returns the previous outcome instead of
-- re-running it. The sender decides when to retry; we decide to be boring
-- about it.

CREATE TABLE inbox_events (
    id {{PK}},

    source              VARCHAR(32)  NOT NULL,

    -- The sender's event id, NOT the order reference: one order legitimately
    -- produces several events (created, paid, cancelled) and each must be
    -- processed exactly once.
    external_event_id   VARCHAR(128) NOT NULL,

    event_type          VARCHAR(64)  NOT NULL,
    order_reference     VARCHAR(64)      NULL,

    -- What the first processing of this event decided, so a redelivery can be
    -- answered identically without replaying any side effect.
    outcome             VARCHAR(32)      NULL,

    received_at         {{DATETIME}} NOT NULL,

    CONSTRAINT uq_inbox_source_event UNIQUE (source, external_event_id)
) {{ENGINE}};

CREATE INDEX ix_inbox_order ON inbox_events (order_reference);
