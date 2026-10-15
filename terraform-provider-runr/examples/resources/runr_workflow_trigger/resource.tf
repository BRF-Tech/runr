# The workflows are declared through the API, e.g. `runr workflows declare -f runr.workflows.json`.

# Start process-order for large orders.
resource "runr_workflow_trigger" "large_orders" {
  project_id = runr_project.orders.id
  workflow   = "process-order"
  event      = "order.created"
  condition  = "event.data.total > 1000"
}

# Every message of the project's queue stream "orders" starts a run.
resource "runr_workflow_trigger" "orders_queue" {
  project_id = runr_project.orders.id
  workflow   = "process-order"
  queue      = "orders"
}

# The nightly report at 03:00 Istanbul time.
resource "runr_workflow_trigger" "nightly" {
  project_id = runr_project.orders.id
  workflow   = "nightly-report"
  cron       = "0 3 * * *"
  timezone   = "Europe/Istanbul"
}
