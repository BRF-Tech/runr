# The project exists already: its id comes from a lookup by name (runr_project.api.id would
# make a cycle with the size below).
data "runr_project" "existing" {
  name = "orders-api"
}

data "runr_rightsizing" "api" {
  project_id = data.runr_project.existing.id
}

# Take the recommended size explicitly once there is one: the configuration owns the size.
resource "runr_project" "api" {
  name      = "orders-api"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id
  resources = data.runr_rightsizing.api.recommended == null ? null : {
    cpu       = data.runr_rightsizing.api.recommended.cpu
    memory_mb = data.runr_rightsizing.api.recommended.memory_mb
  }
}

output "rightsizing" {
  value = data.runr_rightsizing.api.status
}
