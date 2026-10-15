# Deploy the latest uploaded version of a function and redeploy when its
# variables change.
resource "runr_deployment" "api" {
  project_id = runr_project.api.id

  triggers = {
    log_level = runr_env_var.log_level.value
  }
}

# Deploy a container image.
resource "runr_deployment" "web" {
  project_id = runr_project.web.id
  image      = "ghcr.io/acme/web:${var.web_version}"
  timeout    = "15m"
}

variable "web_version" {
  type    = string
  default = "1.4.2"
}

output "web_url" {
  value = runr_deployment.web.url
}
