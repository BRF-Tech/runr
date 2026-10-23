# runr-workflows

Durable workflows for [RunR](../../docs/workflows.md): write a workflow as a plain Python
function; every `step` is memoized, retried with backoff and survives sleeps of days.

```bash
pip install runr-workflows
```

Python 3.9 or newer, no dependencies. Deploy the function in a project with the trigger
**Workflow**; the SDK speaks the [workflow protocol v1](../../docs/workflows-protocol.md).

```python
from runr_workflows import Workflows, NonRetriableError

wf = Workflows()  # signing key from RUNR_WORKFLOW_SIGNING_KEY (set by RunR on deploy)

@wf.workflow("process-order",
             triggers=[{"event": "order.created"}],
             retries=3, timeout="7d",
             concurrency={"limit": 5, "key": "event.data.customer_id"},
             idempotency="event.data.order_id",
             cancel_on=[{"event": "order.cancelled", "match": "data.order_id"}])
def process_order(ctx, step):
    order = ctx.event["data"]
    charge = step.run("charge-card", charge_card, order)
    step.sleep("cool-off", "1h")
    shipped = step.wait_for_event("shipped", "order.shipped", match="data.order_id", timeout="3d")
    if shipped is None:
        step.run("refund", refund, charge)
        return {"status": "refunded"}
    step.send_event("notify", {"name": "order.completed", "data": {"order_id": order["order_id"]}})
    return {"status": "done"}

handler = wf.handler()      # RunR python runtime: handler(event, context)
# app = wf.wsgi()           # any WSGI server
# wf.serve(port=8080)       # standalone HTTP server (container projects)
```

## Step API

| Call | Result |
|------|--------|
| `step.run(id, fn, *args)` | `fn`'s return value (JSON); retried on exceptions |
| `step.sleep(id, "1h")` / `step.sleep_until(id, datetime)` | `None` once the time passed |
| `step.wait_for_event(id, name, match=, if_=, timeout=)` | the event `{id, name, data, ts}` or `None` on timeout |
| `step.invoke(id, workflow, data, timeout=)` | the invoked workflow's output |
| `step.send_event(id, event_or_events)` | `{"ids": [...]}` |

Raise `NonRetriableError` to fail without retries, `RetryAfterError(msg, "30s")` to choose the
delay. A step that failed for good raises `StepError` into the workflow — catch it to
compensate. `async def` workflows get the same methods as coroutines.

`EventsClient(base_url, token).send(name, data)` sends events to `POST /api/events`
(`send_many(events)` for several); without arguments it reads `RUNR_API_URL`, `RUNR_API_TOKEN`
and, on multi-tenant installations, `RUNR_TENANT_ID` (sent as `X-Tenant-ID`).

## Publishing to topics

A [topic](../../docs/triggers.md#topics) delivers each event to every project with a topic
trigger on it. Any function (not only workflows) publishes with `publish`:

```python
from runr_workflows import publish, PublishError

result = publish("orders", {"order_id": "o-1"}, type="order.created", subject="o-1")  # {"id", "stream_id"}
publish("logs", "plain text", content_type="text/plain")  # a str or bytes, sent as is
```

`publish(topic, data, *, type=None, subject=None, id=None, content_type=None)` POSTs to
`{RUNR_TOPICS_URL}/v1/topics/{topic}/events` with `Authorization: Bearer {RUNR_TOPICS_TOKEN}`;
RunR sets both for functions in a region where the organization has a topic. Data is sent as
JSON; with `content_type` a `str` (UTF-8) or bytes go as is. `type`, `subject` and `id` become
the CloudEvents headers `ce-type`, `ce-subject` and `ce-id`. It returns `{"id", "stream_id"}`
and raises `PublishError` (`status`, `body`, `topic`) on an answer that is not 2xx: 401 a bad
token, 403 a topic the token does not allow, 413 an event that is too large (256 KiB by
default). `Topics(url, token, timeout=10.0)` takes the settings explicitly. Only the standard
library is used (`urllib`).

Tests: `pip install -e .[test] && pytest`
