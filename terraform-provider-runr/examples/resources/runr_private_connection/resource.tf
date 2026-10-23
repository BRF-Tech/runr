# production reaches its managed PostgreSQL privately: ORDERS_DATABASE_URL, ORDERS_PGHOST, …
resource "runr_private_connection" "orders" {
  project_id  = runr_project.api.id
  name        = "orders"
  env_prefix  = "ORDERS_"
  routing_key = "pg-orders"
  link_token  = file("${path.module}/orders-access-token.yaml") # the Skupper v2 AccessToken of the database's site
  database    = "orders"
  username    = "orders_app"
  password    = var.orders_password
}

# staging reaches its own database, never production's. Terraform 1.11 and later: the token and
# the password stay out of the state; bump credentials_wo_version to connect with new ones.
resource "runr_private_connection" "orders_staging" {
  project_id             = runr_project.api.id
  environment            = runr_environment.staging.name
  name                   = "orders"
  env_prefix             = "ORDERS_"
  routing_key            = "pg-orders-staging"
  link_token_wo          = var.orders_staging_token
  database               = "orders"
  username               = "orders_app"
  password_wo            = var.orders_staging_password
  credentials_wo_version = "1"
}

output "orders_connection" {
  value = { host = runr_private_connection.orders.host, status = runr_private_connection.orders.status }
}

variable "orders_password" {
  type      = string
  sensitive = true
}

variable "orders_staging_token" {
  type      = string
  sensitive = true
  ephemeral = true
}

variable "orders_staging_password" {
  type      = string
  sensitive = true
  ephemeral = true
}
