data "runr_region" "eu" {
  name = "eu-1"
}

# A Python function; its code is uploaded with `runr deploy`.
resource "runr_project" "api" {
  name      = "orders-api"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id
  handler   = "main.handler"

  resources = {
    cpu             = "250m"
    memory_mb       = 256
    timeout_seconds = 30
  }

  scale = {
    min     = 0
    max     = 10
    to_zero = true
  }

  trigger = {
    type = "http"
  }
}

# A nightly job.
resource "runr_project" "report" {
  name      = "nightly-report"
  type      = "faas"
  runtime   = "nodejs"
  region_id = data.runr_region.eu.id

  trigger = {
    type     = "schedule"
    schedule = "0 2 * * *"
  }
}

# A container.
resource "runr_project" "web" {
  name      = "web"
  type      = "caas"
  region_id = data.runr_region.eu.id
  image     = "ghcr.io/acme/web:1.4.2"
  ports     = [8080]

  # Let runr_deployment (or CI) roll out new images.
  lifecycle {
    ignore_changes = [image]
  }
}
