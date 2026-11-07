# A private Git repository of runtimes (runtimes/<id>/runtime.yaml on the main branch), read
# with an access token Terraform never stores (write-only, Terraform 1.11 and later).
variable "runtimes_token" {
  type      = string
  sensitive = true
  ephemeral = true
}

resource "runr_runtime_source" "acme" {
  name                   = "acme-runtimes"
  kind                   = "git"
  url                    = "https://gitlab.example.com/acme/runtimes.git"
  ref                    = "main"
  git_token_wo           = var.runtimes_token
  credentials_wo_version = "2026-11" # change it to write a rotated token
  interval_minutes       = 30

  # Read the repository now when a new runtimes release is tagged.
  sync_trigger = "v1.4.0"

  lifecycle {
    postcondition {
      condition     = self.status == "ok"
      error_message = "RunR could not read every runtime of acme-runtimes (${self.status}): ${coalesce(self.error, "see problems")}"
    }
  }
}

# A signed OCI catalog in a private registry, with its patch feed.
resource "runr_runtime_source" "catalog" {
  name              = "acme-catalog"
  kind              = "oci-catalog"
  url               = "registry.example.com/acme/runtimes:catalog"
  public_key        = file("${path.module}/cosign.pub")
  patch_feed        = "registry.example.com/acme/runtimes:patches"
  registry_username = "runr"
  registry_password = var.registry_password
}

variable "registry_password" {
  type      = string
  sensitive = true
}

# Projects use a runtime of a source by its id.
resource "runr_project" "script" {
  name      = "nightly-report"
  type      = "xaas"
  runtime   = "bash"
  region_id = data.runr_region.eu.id

  depends_on = [runr_runtime_source.acme]
}
