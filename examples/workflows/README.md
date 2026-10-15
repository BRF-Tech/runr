# Durable workflow examples

Two implementations of the same order-fulfilment app — `python/` (SDK `runr-workflows`,
source in `sdk/python`) and `node/` (SDK `@runr/workflows`, source in `sdk/node`):

| Workflow | Trigger | Shows |
|----------|---------|-------|
| `process-order` | event `order.created` | steps with retries and `RetryAfterError`, a 10-minute sleep, `waitForEvent` with `match` and a timeout, compensation, `sendEvent`, keyed concurrency, idempotency, `cancel_on` |
| `nightly-report` | cron `0 2 * * *` (Europe/Istanbul) | `invoke` of another workflow, handling `StepError` |
| `render-report` | — (invoked) | a child workflow |
| `resize-image` | queue `images` | the queue bridge (`queue/images` events), a workflow-wide concurrency limit |

## Deploy

1. Create a FaaS project (runtime Python or Node.js) with the trigger **Workflow**; for
   `resize-image` set the queue stream `images` (the region needs Redis).
2. Add the files of `python/` or `node/` (handler `main.handler`) and deploy. RunR injects
   `RUNR_WORKFLOW_SIGNING_KEY`; the SDK answers only requests signed with it.
3. Open the project's **Workflows** tab: the definitions sync automatically once the
   deployment is active (or press **Sync definitions**). `runr.workflows.json` declares the same
   definitions for the case the function cannot be reached during a sync.

## Try it

```bash
TOKEN=...   # an API token (Settings → API Tokens)
API=https://runr.example.com/api

# Start a run through an event
curl -sS -X POST "$API/events" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"order.created","id":"order-o-1","data":{"order_id":"o-1","customer_id":"c-1","total":120,"items":["book"]}}'

# 10 minutes later the run waits for the shipment; resume it
curl -sS -X POST "$API/events" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"order.shipped","data":{"order_id":"o-1","tracking":"T-1"}}'

# Start a run directly
curl -sS -X POST "$API/projects/<project-id>/workflows/nightly-report/runs" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"data":{}}'
```

The events above fit the Python example. The node example reads camelCase fields: send
`{"orderId": "o-1", "customerId": "c-1", …}` as the event data there.

Without a cluster, `python/local_run.py` plays the engine against `main.py` through the real
protocol (`pip install -e sdk/python` first).
