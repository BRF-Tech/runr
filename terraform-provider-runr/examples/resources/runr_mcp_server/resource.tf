# The weather project (a FaaS project of the mcp-python runtime, http trigger) served at
# https://<console>/mcp/servers/weather; MCP clients sign in with OAuth or a RunR token.
resource "runr_mcp_server" "weather" {
  project_id  = runr_project.weather.id
  access_mode = "oauth"
  audit_calls = true
}

# The gateway key reaches the workload with a deploy.
resource "runr_deployment" "weather" {
  project_id = runr_project.weather.id
  triggers = {
    mcp_server = runr_mcp_server.weather.id
  }
}

output "mcp_url" {
  value = runr_mcp_server.weather.url
}
