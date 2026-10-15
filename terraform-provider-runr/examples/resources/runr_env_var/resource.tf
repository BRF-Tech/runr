resource "runr_env_var" "log_level" {
  project_id = runr_project.api.id
  name       = "LOG_LEVEL"
  value      = "info"
}

# A secret variable: stored write-only, listed by name only. Terraform keeps the value it set
# (sensitive) and recreates the variable when it is removed outside Terraform.
resource "runr_env_var" "database_url" {
  project_id = runr_project.api.id
  name       = "DATABASE_URL"
  value      = var.database_url
  secret     = true
}

# Terraform 1.11 and later: value_wo never reaches the state; bump value_wo_version to write a
# new value (for example after a rotation).
resource "runr_env_var" "signing_key" {
  project_id       = runr_project.api.id
  name             = "SIGNING_KEY"
  value_wo         = var.signing_key
  value_wo_version = "2026-10"
  secret           = true
}

variable "database_url" {
  type      = string
  sensitive = true
}

variable "signing_key" {
  type      = string
  sensitive = true
  ephemeral = true
}
