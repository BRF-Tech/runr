# /orders leads to the orders project and asks for an API key (runr_api_key); the project's own
# URL stops serving, so callers cannot get around the keys.
resource "runr_gateway_route" "orders" {
  gateway_id   = runr_gateway.eu.id
  project_id   = runr_project.orders.id
  path         = "/orders"
  methods      = ["GET", "POST"]
  auth_mode    = "key"
  gateway_only = true
}

# /partners checks a JWT of the partners' identity provider; its sub claim names the consumer.
resource "runr_gateway_route" "partners" {
  gateway_id = runr_gateway.eu.id
  project_id = runr_project.partners.id
  path       = "/partners"
  auth_mode  = "jwt"
  jwt = {
    jwks_url         = "https://id.example.com/.well-known/jwks.json"
    issuer           = "https://id.example.com/"
    audience         = "partners-api"
    require_consumer = true
  }
}

# The staging environment of the orders project, on its own path.
resource "runr_gateway_route" "orders_staging" {
  gateway_id  = runr_gateway.eu.id
  project_id  = runr_project.orders.id
  environment = "staging"
  path        = "/orders-staging"
}
