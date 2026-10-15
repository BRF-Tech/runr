data "runr_project" "api" {
  name = "orders-api"
}

output "api_url" {
  value = data.runr_project.api.endpoint_url
}
