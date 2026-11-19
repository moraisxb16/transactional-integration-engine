# Security

This is a portfolio project with no production deployment and no users. There is
nothing to report a vulnerability against. What follows is the reasoning behind
the security-relevant code, because that is the part worth reading.

## Webhook authentication is fail-closed

`Relay\Http\WebhookSignature` verifies HMAC-SHA256 over the **raw request body**,
base64-encoded, compared with `hash_equals`.

Every path that is not a successful verification returns `false`. There is no
flag that disables the check, no exemption when the header is missing, and no
fallback to checking the source IP or the sender's domain.

Three details, each of which has silently broken a real system somewhere:

**The raw body, before parsing.** Decoding JSON and re-encoding it changes key
order and whitespace, and the hash with it. An implementation that verifies
against re-encoded input rejects legitimate traffic — and the usual "fix" is to
loosen the check until it passes, at which point it verifies nothing.

**`hash_equals`, never `===`.** String comparison returns as soon as two bytes
differ. How long it took leaks how many leading bytes were correct, which is
enough to recover a valid signature byte by byte.

**An unconfigured secret stops the request.** It does not turn the check off.
A deployment that forgets to set `WEBHOOK_SECRET` must fail loudly rather than
quietly accept everything.

`WebhookSignatureTest` covers all of these, including each individual
fail-closed path.

## Two kinds of credential, never interchangeable

- `WEBHOOK_SECRET` proves a message came from the storefront.
- `INTERNAL_SERVICE_TOKEN` proves a caller is one of our own services.

They are checked by different methods and are not substitutable. Letting one
stand in for the other means a leaked service token can forge storefront
traffic.

## Secrets never reach the logs

`Relay\Kernel\Logger` redacts any context key containing `secret`, `token`,
`password`, `authorization`, `signature`, `api_key` or `apikey`, recursively.

A log line outlives the request, gets copied into tickets and pasted into chat.
A credential that reaches one has effectively been published. The list is
deliberately broad: redacting a field that did not need it costs nothing, the
reverse costs a rotation.

## Raw payloads are never stored

Only `sha256` of the normalised order object, in `orders.payload_digest`. Enough
to tell whether a later delivery carried different data; not a copy of every
webhook body ever received, which is both a disk-space problem and a pile of
personal data with no retention policy.

## SQL

Every query uses prepared statements with bound parameters. MySQL connections
set `PDO::ATTR_EMULATE_PREPARES => false`, so statements are prepared by the
server rather than interpolated client-side.

Two places build SQL by string: the `IN (...)` list of claimable statuses, and
the `IN (?,?,?)` placeholder list for claimed ids. Neither interpolates user
input — the first comes from an enum, the second is a generated run of `?` with
the values bound. The column names in `transition()`'s `$extraColumns` come from
call sites inside this repository, never from a request.

## Input validation

`IngestWebhookEvent` rejects a payload missing `event_id`, `event_type` or
`order.reference`. `Money::fromDecimalString()` rejects anything it cannot
represent exactly in cents rather than rounding — silently rounding is how a
total drifts away from what the customer was charged.

## What is deliberately not here

No HTTP server, no session handling, no user accounts, no rate limiting. The
repository demonstrates queue and integration mechanics; the surrounding web
layer would be framework-specific and would not have demonstrated anything.

`.env` is gitignored. `.env.example` contains only placeholders.
