# Your gateway in eu-1 (taken over when you have one there): an IP rate limit, the public
# developer page and a custom domain.
resource "runr_gateway" "eu" {
  region_id      = data.runr_region.eu.id
  rate_limit     = { average = 100, burst = 200 }
  developer_page = true
  description    = "The Orders API of Example Ltd."
  custom_domain  = "api.example.com"
}

# While the domain waits, publish these records at your DNS provider, then set verify = true
# (or run `runr gateway domain verify eu-1`).
output "domain_records" {
  value = {
    verification = runr_gateway.eu.custom_domain_verification
    targets      = runr_gateway.eu.custom_domain_dns_targets
  }
}

output "developer_page" {
  value = runr_gateway.eu.developer_page_url
}
