# A KV store on the region's Redis: a user limited to its own key prefix.
resource "runr_binding" "cache" {
  name      = "cache"
  type      = "kv"
  region_id = data.runr_region.eu.id
  memory_mb = 128
}

# A Postgres database on the region's shared CloudNativePG cluster.
resource "runr_binding" "orders_db" {
  name              = "orders-db"
  type              = "postgres"
  region_id         = data.runr_region.eu.id
  storage_gb        = 2
  connection_limit  = 10
  statement_timeout = "15s"
}

# A dedicated Postgres cluster with a replica and daily backups.
resource "runr_binding" "reports_db" {
  name       = "reports-db"
  type       = "postgres"
  plan       = "dedicated"
  region_id  = data.runr_region.eu.id
  storage_gb = 20
  instances  = 2
  backups    = true
  timeout    = "20m"
}

# An S3 bucket with a quota and a key allowed on that bucket only.
resource "runr_binding" "files" {
  name      = "files"
  type      = "object"
  region_id = data.runr_region.eu.id
  quota_gb  = 10
}

# Credentials you bring, injected as they are (write-only).
resource "runr_binding" "payments" {
  name = "payments"
  type = "external"
  values = {
    PAYMENTS_URL   = "https://pay.example.com"
    PAYMENTS_TOKEN = var.payments_token
  }
}

variable "payments_token" {
  type      = string
  sensitive = true
}
