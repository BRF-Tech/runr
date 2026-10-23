# A Vault (or OpenBao) store: the region logs in with its service account token (JWT auth at
# the mount runr-eu; data.runr_secret_store_trust gives what that auth method has to trust).
resource "runr_secret_store" "vault" {
  name = "vault-prod"
  type = "vault"
  auth = "jwt"
  settings = {
    server     = "https://vault.example.com:8200"
    role       = "runr"
    auth_mount = "runr-eu"
    mount      = "secret"
    kv_version = "v2"
  }
}

# AWS Secrets Manager through workload identity federation: the role trusts the region's issuer.
resource "runr_secret_store" "aws" {
  name             = "aws-eu"
  type             = "aws"
  refresh_interval = 900
  settings = {
    region   = "eu-central-1"
    role_arn = "arn:aws:iam::123456789012:role/runr-secrets"
  }
}
