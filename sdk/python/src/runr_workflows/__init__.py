"""runr-workflows — durable workflows for RunR.

    from runr_workflows import Workflows

    wf = Workflows()

    @wf.workflow("process-order", triggers=[{"event": "order.created"}], retries=3)
    def process_order(ctx, step):
        charge = step.run("charge", lambda: charge_card(ctx.event["data"]))
        step.sleep("cool-off", "1h")
        shipped = step.wait_for_event("shipped", "order.shipped", match="data.order_id", timeout="7d")
        return {"charge": charge, "shipped": shipped is not None}

    handler = wf.handler()   # the RunR python runtime calls handler(event, context)
"""

from .client import EventsClient
from .errors import NonRetriableError, RetryAfterError, SignatureError, StepError
from .protocol import sign, verify
from .step import AsyncStep, Step
from .workflows import Context, Workflows, __version__, verify_request

__all__ = [
    "AsyncStep",
    "Context",
    "EventsClient",
    "NonRetriableError",
    "RetryAfterError",
    "SignatureError",
    "Step",
    "StepError",
    "Workflows",
    "__version__",
    "sign",
    "verify",
    "verify_request",
]
