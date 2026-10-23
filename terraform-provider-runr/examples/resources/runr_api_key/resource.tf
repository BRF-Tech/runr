# A key of Acme for GET and POST on /orders, valid until the end of 2027.
resource "runr_api_key" "acme_production" {
  consumer_id = runr_api_consumer.acme.id
  name        = "production"
  scopes = [
    { route_id = runr_gateway_route.orders.id, methods = ["GET", "POST"] },
  ]
  expires_at = "2028-01-01T00:00:00Z"
}

# The key is shown only when it is created: hand it to the consumer, e.g. into a secret store.
# Rotate it with `runr gateway keys rotate <key id> --overlap 1440`; the resource follows the
# new key (its key is not in the state then).
output "acme_key" {
  value     = runr_api_key.acme_production.key
  sensitive = true
}
