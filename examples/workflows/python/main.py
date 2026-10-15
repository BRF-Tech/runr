"""Order fulfilment with durable workflows — RunR python runtime (FaaS, handler "main.handler").

Project settings: trigger "Workflow", optional queue stream "images".
The engine injects RUNR_WORKFLOW_SIGNING_KEY into the function on deploy.
"""

import random
import time

from runr_workflows import NonRetriableError, RetryAfterError, StepError, Workflows

wf = Workflows()


# ── Business logic (stand-ins for real services) ─────────────────────────────

def reserve_inventory(order):
    if not order.get("items"):
        raise NonRetriableError("order has no items")
    return {"reservation": f"res-{order['order_id']}", "items": len(order["items"])}


def charge_card(order, attempt):
    # A flaky payment gateway: the step is retried with exponential backoff
    if attempt == 0 and random.random() < 0.5:
        raise RuntimeError("payment gateway timeout")
    if order.get("total", 0) > 10_000:
        raise RetryAfterError("manual review queue is full", "15m")
    return {"charge_id": f"ch-{order['order_id']}-{int(time.time())}", "amount": order.get("total", 0)}


def refund(charge):
    return {"refunded": charge["charge_id"]}


# ── Workflows ─────────────────────────────────────────────────────────────────

@wf.workflow(
    "process-order",
    description="Reserve, charge, wait for the shipment, notify",
    triggers=[{"event": "order.created"}],
    retries=4,
    timeout="7d",
    concurrency={"limit": 10, "key": "event.data.customer_id"},
    idempotency="event.data.order_id",
    cancel_on=[{"event": "order.cancelled", "match": "data.order_id"}],
)
def process_order(ctx, step):
    order = ctx.event["data"]

    reservation = step.run("reserve-inventory", reserve_inventory, order)
    charge = step.run("charge-card", lambda: charge_card(order, ctx.attempt))

    # Give the fraud check a head start before shipping
    step.sleep("fraud-check-window", "10m")

    shipment = step.wait_for_event("wait-for-shipment", "order.shipped", match="data.order_id", timeout="3d")
    if shipment is None:
        step.run("refund", refund, charge)
        step.send_event("notify-refund", {"name": "order.refunded", "data": {"order_id": order["order_id"]}})
        return {"status": "refunded", "reservation": reservation["reservation"]}

    step.send_event("notify-completed", {
        "name": "order.completed",
        "data": {"order_id": order["order_id"], "tracking": shipment["data"].get("tracking")},
    })
    return {"status": "completed", "charge": charge["charge_id"]}


@wf.workflow("nightly-report", triggers=[{"cron": "0 2 * * *", "timezone": "Europe/Istanbul"}], retries=2)
def nightly_report(ctx, step):
    totals = step.run("collect", lambda: {"orders": random.randint(10, 100)})
    try:
        step.invoke("render", "render-report", data=totals, timeout="30m")
    except StepError as exc:
        return {"status": "render failed", "reason": exc.error_message}
    return {"status": "sent", **totals}


@wf.workflow("render-report", retries=1)
def render_report(ctx, step):
    return step.run("render", lambda: f"{ctx.event['data']['orders']} orders")


@wf.workflow("resize-image", triggers=[{"queue": "images"}], concurrency=5)
def resize_image(ctx, step):
    image = ctx.event["data"]
    return step.run("resize", lambda: {"key": image.get("key"), "sizes": [64, 256, 1024]})


# RunR python runtime entry point
handler = wf.handler()
