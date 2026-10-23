# process-order, implemented by the project's code, declared through the API.
resource "runr_workflow" "process_order" {
  project_id  = runr_project.orders.id
  name        = "process-order"
  description = "Charge, reserve stock and ship an order"
  triggers = [
    { event = "order.created", condition = "event.data.total > 0" },
    { queue = "orders" },
  ]
  retries     = 5
  timeout     = "1d"
  concurrency = [{ limit = 1, key = "event.data.customer_id" }]
  idempotency = "event.data.order_id"
  cancel_on   = [{ event = "order.cancelled", expression = "event.data.order_id == async.data.order_id" }]
}

# The nightly report of the staging environment, paused for now.
resource "runr_workflow" "nightly_staging" {
  project_id  = runr_project.orders.id
  environment = "staging"
  name        = "nightly-report"
  triggers    = [{ cron = "0 3 * * *", timezone = "Europe/Istanbul" }]
  active      = false
}

# Triggers left out of runr_workflow can come from runr_workflow_trigger.
resource "runr_workflow" "refunds" {
  project_id = runr_project.orders.id
  name       = "refund"
}

resource "runr_workflow_trigger" "refund_requested" {
  project_id = runr_project.orders.id
  workflow   = runr_workflow.refunds.name
  event      = "refund.requested"
}
