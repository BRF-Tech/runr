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

  # Timed warmer: 3 containers warm on weekdays from 09:00, none from 18:00 (organization time zone)
  scale_schedule = [
    { cron = "0 9 * * 1-5", min_scale = 3 },
    { cron = "0 18 * * 1-5", min_scale = 0 },
  ]

  trigger = {
    type = "http"
  }
}

# A nightly job at 02:00 Berlin time ("" or left out on a new schedule: the organization's zone).
resource "runr_project" "report" {
  name      = "nightly-report"
  type      = "faas"
  runtime   = "nodejs"
  region_id = data.runr_region.eu.id

  trigger = {
    type     = "schedule"
    schedule = "0 2 * * *"
    timezone = "Europe/Berlin"
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

# The whole trigger list: HTTP requests, a schedule, a Kafka topic and a webhook inbox.
resource "runr_project" "orders" {
  name      = "orders"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id

  triggers = [
    { type = "http" },
    {
      name   = "nightly"
      type   = "schedule"
      config = jsonencode({ cron = "0 2 * * *", timezone = "Europe/Berlin" })
    },
    {
      name    = "orders"
      type    = "kafka"
      config  = jsonencode({ brokers = ["kafka-1:9092"], topic = "orders", sasl = { mechanism = "scram-sha-512", username = "orders" } })
      secrets = { sasl_password = var.kafka_password }
    },
    {
      name    = "github"
      type    = "webhook"
      config  = jsonencode({ verify = "github" })
      secrets = { signing_secret = var.github_webhook_secret }
    },
  ]

  # No public URL: only the orders-api project calls it, on the region's internal network.
  network = {
    access_mode = "internal"
    callers     = [runr_project.api.network.internal_name]
  }

  # New versions take 10 %, then 50 % of the traffic, five minutes each.
  deploy_strategy = {
    strategy = "canary"
    canary = {
      steps                = [10, 50]
      interval_minutes     = 5
      error_rate_threshold = 2
    }
  }
}

# A global project: the primary region (region_id) and a standby region behind one host name.
data "runr_region" "us" {
  name = "us-1"
}

resource "runr_project" "shop" {
  name      = "shop"
  type      = "caas"
  region_id = data.runr_region.eu.id
  image     = "ghcr.io/acme/shop:2.0.0"
  ports     = [8080]

  global = {
    regions  = [data.runr_region.eu.id, data.runr_region.us.id]
    mode     = "ha"
    failover = "automatic"
  }
}

variable "kafka_password" {
  type      = string
  sensitive = true
}

variable "github_webhook_secret" {
  type      = string
  sensitive = true
}
