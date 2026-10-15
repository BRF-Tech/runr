# The installation's brand. Attributes left out use the defaults; destroying the resource
# resets the brand.
resource "runr_branding" "brand" {
  product_name    = "Acme Cloud"
  company_name    = "Acme Corp."
  primary_color   = "#0f766e"
  accent_color    = "#f59e0b"
  login_headline  = "Welcome to Acme Cloud"
  support_email   = "support@acme.example"
  support_url     = "https://help.acme.example"
  email_from_name = "Acme Cloud"

  # Images as data: URIs from files next to the configuration (or https:// URLs).
  logo_light_url = "data:image/svg+xml;base64,${filebase64("${path.module}/logo-light.svg")}"
  logo_dark_url  = "data:image/svg+xml;base64,${filebase64("${path.module}/logo-dark.svg")}"
  favicon_url    = "data:image/png;base64,${filebase64("${path.module}/favicon.png")}"

  footer_links = [
    { label = "Status", url = "https://status.acme.example" },
    { label = "Privacy", url = "https://acme.example/privacy" },
  ]

  powered_by              = false
  tenant_branding_enabled = true # multi-tenant: organizations may set their own brand
}
