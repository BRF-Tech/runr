resource "runr_domain" "api" {
  project_id = runr_project.api.id
  domain     = "api.example.com"
  tls_mode   = "cert-manager"
}

# Publish the records at your DNS provider, e.g. with the cloudflare provider:
#   TXT   runr_domain.api.verification_name  = runr_domain.api.verification_value
#   CNAME runr_domain.api.dns_targets[0].name = runr_domain.api.dns_targets[0].value
output "dns_records" {
  value = concat(
    [{ type = runr_domain.api.verification_type, name = runr_domain.api.verification_name, value = runr_domain.api.verification_value }],
    runr_domain.api.dns_targets,
  )
}

# A domain of the staging environment: it routes to staging's workload, with the DNS targets of
# the region staging runs in.
resource "runr_domain" "staging" {
  project_id  = runr_project.api.id
  environment = runr_environment.staging.name
  domain      = "staging.example.com"
}
