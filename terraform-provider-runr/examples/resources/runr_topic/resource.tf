resource "runr_topic" "orders" {
  name        = "orders"
  region_id   = data.runr_region.eu.id
  description = "Every order event"
}

# A project subscribes to it with a topic trigger.
resource "runr_project" "billing" {
  name      = "billing"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id

  triggers = [
    { name = "orders", type = "topic", config = jsonencode({ topic = runr_topic.orders.name }) },
  ]
}
