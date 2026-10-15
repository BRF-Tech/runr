# Workflow protocol v1

The language-neutral HTTP + JSON protocol between the RunR workflow engine and a workflow
function. The Python (`runr-workflows`, `sdk/python`) and TypeScript (`@runr/workflows`,
`sdk/node`) SDKs implement it; this page is enough to implement it in any other runtime (Go,
PHP, C, Q, …). See [Durable workflows](workflows.md) for the concepts.

RunR deploys a `workflow`-trigger project with two environment variables for the SDK:
`RUNR_WORKFLOW_SIGNING_KEY` (see [Signatures](#signatures)) and `RUNR_WORKFLOW_PROTOCOL=v1`.

## Transport

The engine sends `POST` requests to the function URL (any path) with
`Content-Type: application/json` and these headers:

| Header | Value |
|--------|-------|
| `X-RunR-Signature` | `t=<unix seconds>,v1=<hex>[,v1=<hex>]` |
| `X-RunR-Workflow-Protocol` | `v1` |
| `X-RunR-Run-Id` | run id (execute requests) |
| `X-RunR-Workflow` | workflow name (execute requests) |

Body — the **envelope**:

```json
{"runr_workflow": "v1", "signature": "t=1700000000,v1=…", "request": "<JSON document as a string>"}
```

`request` is the actual request, JSON-encoded **as a string**: the signed bytes survive
runtimes that parse and re-encode the body (the RunR runtimes hand the parsed body to the
handler). `signature` repeats the header for runtimes that do not pass headers on; when both
are present they are equal.

The function answers `200` with a JSON body; every other status (and a timeout,
`WORKFLOWS_REQUEST_TIMEOUT`, default 300 s) is a failed function attempt that the engine
retries. A `{"statusCode", "headers", "body"}` object that a runtime failed to unwrap is
unwrapped by the engine.

## Signatures

```text
v1 = hex( HMAC-SHA256( key = signing key, message = "<t>.<request>" ) )
```

- `<t>` is the decimal unix time in the header, `<request>` the exact `request` string.
- One `v1` per key the engine signs with: the current key, plus the previous one during a key
  rotation (24 h). Accept the request when **any** `v1` equals the HMAC under **any** key the
  function holds (`RUNR_WORKFLOW_SIGNING_KEY`, optionally `RUNR_WORKFLOW_SIGNING_KEY_FALLBACK`).
- Reject when `|now − t| > 300` seconds.
- Compare in constant time.
- Answer `401` to a request that fails verification, `400` to a body that is not a v1
  envelope, `500` when no signing key is configured.

Test vectors (shared by the engine and SDK test suites):

| Key | t | request | v1 |
|-----|---|---------|----|
| `rwk_test_key` | `1700000000` | `{"type":"introspect","protocol":"v1","ts":1700000000}` | `838d3d8827a74b4ac397a08960c72044b191644d1fe0a873781bf6af995b239a` |
| `rwk_other_key` | `1700000000` | `{"type":"execute","workflow":"orders","steps":{}}` | `33a2197f7add68c82a3a73656e8025afed0a2f86401f31caa89bba5990b11ace` |

## Requests

### introspect

```json
{"type": "introspect", "protocol": "v1", "ts": 1700000000}
```

Answer the workflows the function serves:

```json
{
  "runr_workflow": "v1",
  "sdk": {"name": "my-sdk", "language": "go", "version": "1.0.0"},
  "workflows": [
    {
      "name": "process-order",
      "description": "…",
      "triggers": [{"event": "order.created", "if": "event.data.total > 0"},
                   {"cron": "0 9 * * *", "timezone": "Europe/Istanbul"},
                   {"queue": "orders"}],
      "retries": 3,
      "timeout": "7d",
      "concurrency": {"limit": 5, "key": "event.data.customer_id"},
      "idempotency": "event.data.order_id",
      "cancel_on": [{"event": "order.cancelled", "match": "data.order_id"}]
    }
  ]
}
```

Every field except `name` is optional (engine defaults apply). Names: `[A-Za-z0-9][A-Za-z0-9._-]{0,99}`,
not `app` or `sync`. The engine validates the whole list and refuses it as one.

### execute

```json
{
  "type": "execute",
  "protocol": "v1",
  "ts": 1700000000,
  "workflow": "process-order",
  "run": {"id": "01J…", "attempt": 0, "started_at": "2026-10-10T10:00:00.000Z",
          "source": "event", "replay": false, "parent_run_id": null},
  "event": {"id": "01J…", "name": "order.created", "data": {"order_id": "o-1"}, "ts": 1760000000000},
  "steps": {
    "reserve":     {"status": "completed", "output": {"reservation": "r-1"}},
    "charge":      {"status": "failed", "error": {"name": "Error", "message": "card declined"}},
    "cool-off":    {"status": "completed", "output": null}
  },
  "step_order": ["reserve", "charge", "cool-off"]
}
```

- `steps`: the **memoized** steps of the run by step id — `completed` with `output`, or
  `failed` with `error` (retries exhausted or non-retriable). A step that is being retried is
  absent, so the function runs it again.
- `run.attempt`: attempts made so far of the step about to run again (or, with no step being
  retried, of the function). Expose it to user code (`ctx.attempt`).
- `event`: the trigger event (`runr/api.start` for API starts, `runr/cron` for cron ticks,
  `runr/invoke` for invoked runs, `queue/<stream>` for the queue bridge).

## Replay rules for an SDK

Run the workflow function from the top on every execute request:

1. Assign every step call its **step id**: the user's id, plus `:<n>` for the n-th repeat of
   the same id within this invocation (`add`, `add:1`, `add:2`). At most 200 bytes before the
   suffix (the engine stores 255).
2. If `steps[id]` exists: `completed` → return its `output`; `failed` → raise a step error the
   user code can catch (uncaught, it ends the invocation with a non-retriable `run_failed`).
3. The **first** step call without a stored result ends the invocation:
   - `run`: execute the user function now, then answer `step_completed` (its JSON value) or
     `step_failed`;
   - `sleep`, `wait_for_event`, `invoke`, `send_event`: answer that op without running
     anything.
   Every later step call of the same invocation is ignored (it never resolves).
4. If the workflow returns without reaching an unmemoized step, answer `run_completed` with its
   return value; if it raises, answer `run_failed`.
5. Nested step calls (a step inside `run`'s function) are an error.

The unwinding mechanism is the SDK's choice: an exception the user cannot catch by accident
(Python: a `BaseException` subclass), a never-resolving promise raced against the workflow
(TypeScript), `panic`/`recover`, `longjmp`, …

## Response

```json
{"runr_workflow": "v1", "ops": [ { … one op … } ]}
```

Protocol v1 answers **exactly one** op per execute request.

| op | Fields | Engine |
|----|--------|--------|
| `step_completed` | `id`, `name`, `output`, `duration_ms` | memoizes the output, invokes again |
| `step_failed` | `id`, `name`, `error`, `retriable` (default `true`), `retry_after` | retries after `retry_after` or the backoff until the step's attempts are used, then memoizes the failure and invokes again |
| `sleep` | `id`, `name`, `duration` or `until` (ISO-8601 / epoch ms) | parks the run until then (a past time continues at once) |
| `wait_for_event` | `id`, `name`, `event`, `match`, `if`, `timeout` | parks the run until a matching event (output: the event) or the timeout (output: `null`) |
| `invoke` | `id`, `name`, `workflow`, `data`, `timeout` | starts the child run; output: the child's output; child failure / timeout → the step fails (`InvokeFailed`, `InvokeTimeout`) |
| `send_event` | `id`, `name`, `events` (list of `{name, data, id?, ts?}`) | ingests the events; output: `{"ids": [...]}` |
| `run_completed` | `output` | ends the run |
| `run_failed` | `error`, `retriable` (default `true`) | retries the function with the backoff, or ends the run as failed |

- `error`: `{"name": "TypeError", "message": "…", "stack": "…"}` (a plain string is accepted).
- `duration`, `timeout`, `retry_after`: `500ms`, `30s`, `15m`, `2h`, `1d`, `1w`, `1h30m`,
  ISO-8601 `PT1H`, or seconds as a number; `retry_after` may also be an absolute time.
- `name`: the display name of the step (the id without the counter suffix).
- Outputs are JSON values; keep objects as objects (`{}` stays `{}`).
- An op for a step the engine has already memoized is ignored (non-determinism guard).

## Example exchange

```text
→ execute  steps: {}
← {"op":"step_completed","id":"reserve","name":"reserve","output":{"r":1},"duration_ms":12}
→ execute  steps: {reserve}
← {"op":"sleep","id":"cool-off","name":"cool-off","duration":"10m"}
   … 10 minutes …
→ execute  steps: {reserve, cool-off}
← {"op":"wait_for_event","id":"shipped","name":"shipped","event":"order.shipped","match":"data.order_id","timeout":"3d"}
   … order.shipped arrives …
→ execute  steps: {reserve, cool-off, shipped: {output: {id, name, data, ts}}}
← {"op":"run_completed","output":{"status":"done"}}
```

## Versioning

`runr_workflow` carries the protocol version in both directions; the engine refuses answers
of another version (`ProtocolError`, retried as a function attempt). New optional fields may
appear in requests and ops of v1; implementations ignore fields they do not know.
