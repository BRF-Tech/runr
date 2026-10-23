data "runr_region" "eu" {
  name = "eu-1"
}

# What the Vault store has to trust for region eu-1.
data "runr_secret_store_trust" "vault_eu" {
  store_id  = runr_secret_store.vault.id
  region_id = data.runr_region.eu.id
}

# Vault's JWT auth for the region (hashicorp/vault provider).
resource "vault_jwt_auth_backend" "runr_eu" {
  path                   = "runr-eu"
  jwt_validation_pubkeys = data.runr_secret_store_trust.vault_eu.pem_keys
}

resource "vault_jwt_auth_backend_role" "runr" {
  backend         = vault_jwt_auth_backend.runr_eu.path
  role_name       = "runr"
  role_type       = "jwt"
  bound_audiences = [data.runr_secret_store_trust.vault_eu.audience]
  bound_subject   = data.runr_secret_store_trust.vault_eu.subject
  user_claim      = "sub"
  token_policies  = ["runr-read"]
}
