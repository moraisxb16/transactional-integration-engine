-- The audit trail. One row per accepted state change, written in the SAME
-- transaction as the status update.
--
-- Same transaction is the whole point: a log line written after the commit is a
-- log line that goes missing exactly when the process dies between the two, and
-- that is precisely the case anyone investigating actually cares about.

CREATE TABLE order_transitions (
    id {{PK}},

    order_id            BIGINT       NOT NULL,
    order_reference     VARCHAR(64)  NOT NULL,

    from_status         VARCHAR(32)  NOT NULL,
    to_status           VARCHAR(32)  NOT NULL,

    -- Who caused it: "worker:a1b2", "webhook", "operator:42", "reconciler".
    actor               VARCHAR(64)  NOT NULL,
    reason              VARCHAR(255)     NULL,
    context             {{JSON}}         NULL,

    created_at          {{DATETIME}} NOT NULL
) {{ENGINE}};

CREATE INDEX ix_transitions_order ON order_transitions (order_id, id);
