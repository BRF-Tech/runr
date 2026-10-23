# A plan of the gateway: 10 requests per second (bursts of 20) and 100 000 a month.
resource "runr_api_plan" "pro" {
  gateway_id      = runr_gateway.eu.id
  name            = "Pro"
  description     = "For production use"
  rate_per_second = 10
  burst           = 20
  quota_per_month = 100000
}

# A plan of the organization, offered on every gateway (api_products.manage).
resource "runr_api_plan" "trial" {
  name          = "Trial"
  quota_per_day = 1000
}
