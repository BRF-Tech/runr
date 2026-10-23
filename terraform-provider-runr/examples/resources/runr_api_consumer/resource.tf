# A customer of the gateway's API on the Pro plan; external_id is its number in the invoicing
# system that bills the usage.
resource "runr_api_consumer" "acme" {
  gateway_id  = runr_gateway.eu.id
  name        = "Acme Ltd"
  email       = "api@acme.example"
  external_id = "CUST-1042"
  plan_id     = runr_api_plan.pro.id
  metadata = {
    segment = "enterprise"
  }
}

# A partner identified by the subject of its JWTs on jwt routes, on every gateway of the
# organization (api_products.manage).
resource "runr_api_consumer" "partner" {
  name        = "Partner GmbH"
  jwt_subject = "partner-gmbh"
  plan_id     = runr_api_plan.trial.id
}
