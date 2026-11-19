<?php

declare(strict_types=1);

namespace Relay\Application\Ingest;

/**
 * What the ingestion of one webhook delivery decided.
 *
 * Returned rather than thrown, including for the duplicate case, because a
 * redelivery is normal traffic and not an error. Senders retry on purpose: any
 * webhook endpoint that treats the second delivery as a failure will spend its
 * life logging failures that are the system working as designed.
 */
enum IngestResult: string
{
    /** First time we have seen this order. It was inserted. */
    case CREATED = 'created';

    /** Known order, payment now confirmed: moved into the claimable queue. */
    case PROMOTED = 'promoted';

    /** Known order, nothing to change. */
    case UNCHANGED = 'unchanged';

    /** This exact event was already processed. No side effect ran. */
    case DUPLICATE = 'duplicate';

    /** The order was cancelled upstream. */
    case CANCELLED = 'cancelled';

    /** A valid event this engine has no behaviour for. */
    case IGNORED = 'ignored';

    /**
     * Should the sender treat this as success?
     *
     * All of them, duplicates included. Answering anything else to a redelivery
     * invites the sender to redeliver again, forever.
     */
    public function isSuccess(): bool
    {
        return true;
    }
}
