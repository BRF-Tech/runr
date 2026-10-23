# staging reads its own API_KEY; the other environments the project's.
resource "runr_environment_secret" "staging_api_key" {
  project_id  = runr_project.api.id
  environment = runr_environment.staging.name
  name        = "API_KEY"
  value       = var.staging_api_key
}

# Terraform 1.11 and later: value_wo never reaches the state; bump value_wo_version to write a
# new value.
resource "runr_environment_secret" "staging_signing_key" {
  project_id       = runr_project.api.id
  environment      = runr_environment.staging.name
  name             = "SIGNING_KEY"
  value_wo         = var.staging_signing_key
  value_wo_version = "2026-10"
}

variable "staging_api_key" {
  type      = string
  sensitive = true
}

variable "staging_signing_key" {
  type      = string
  sensitive = true
  ephemeral = true
}
