# orders-api gets ORDERS_DATABASE_URL, ORDERS_PGHOST, … and REDIS_URL, KV_PREFIX.
resource "runr_binding_attachment" "orders_db" {
  project_id = runr_project.api.id
  binding_id = runr_binding.orders_db.id
  env_prefix = "ORDERS_"
}

resource "runr_binding_attachment" "cache" {
  project_id = runr_project.api.id
  binding_id = runr_binding.cache.id
}

output "binding_variables" {
  value = concat(runr_binding_attachment.orders_db.env_names, runr_binding_attachment.cache.env_names)
}

# staging gets a database of its own (bindings are never inherited), with a Postgres user of the
# attachment's own from the OpenBao of its region.
resource "runr_binding_attachment" "staging_db" {
  project_id  = runr_project.api.id
  environment = runr_environment.staging.name
  binding_id  = runr_binding.staging_db.id
  env_prefix  = "ORDERS_"
  credentials = "dynamic"
}
