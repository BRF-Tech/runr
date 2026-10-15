package provider

import (
	"fmt"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/runrsh/core/cli/pkg/runr"
	"github.com/runrsh/core/cli/pkg/runr/runrtest"
)

func brandConfig(srv *runrtest.Server, body string) string {
	return providerBlock(srv) + `
resource "runr_branding" "brand" {
` + body + `
}
`
}

const brandFull = `
  product_name  = "Acme Cloud"
  primary_color = "#ff6600"
  support_url   = "https://help.acme.example"
  powered_by    = false
  footer_links = [
    { label = "Status", url = "https://status.acme.example" },
    { label = "Contact", url = "mailto:ops@acme.example" },
  ]
`

func TestBranding(t *testing.T) {
	srv := fake(t)
	stored := func() map[string]any { return srv.BrandingStored(runr.BrandingInstall) }

	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(*terraform.State) error {
			st := stored()
			for _, f := range []string{"product_name", "primary_color", "footer_links", "powered_by", "company_name"} {
				if _, ok := st[f]; ok {
					return fmt.Errorf("%s left after destroy: %v", f, st)
				}
			}
			// The image uploaded in the console stays.
			if st["logo_light_uploaded"] != true {
				return fmt.Errorf("uploaded logo removed: %v", st)
			}
			return nil
		},
		Steps: []resource.TestStep{
			{
				Config: brandConfig(srv, brandFull),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_branding.brand", "id", runr.BrandingInstall),
					resource.TestCheckResourceAttr("runr_branding.brand", "scope", runr.BrandingInstall),
					resource.TestCheckResourceAttr("runr_branding.brand", "footer_links.#", "2"),
					resource.TestCheckResourceAttr("runr_branding.brand", "tenant_branding_enabled", "false"),
					check(func() error {
						st := stored()
						if st["product_name"] != "Acme Cloud" || st["powered_by"] != false || st["primary_color"] != "#ff6600" {
							return fmt.Errorf("stored = %v", st)
						}
						if links, _ := st["footer_links"].([]any); len(links) != 2 {
							return fmt.Errorf("footer links = %v", st["footer_links"])
						}
						return nil
					}),
				),
			},
			{
				// A field left out goes back to the default.
				Config: brandConfig(srv, `
  product_name = "Acme"
  support_url  = "https://help.acme.example"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_branding.brand", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					st := stored()
					_, color := st["primary_color"]
					_, links := st["footer_links"]
					_, powered := st["powered_by"]
					if st["product_name"] != "Acme" || color || links || powered {
						return fmt.Errorf("stored = %v", st)
					}
					return nil
				}),
			},
			{
				// A logo uploaded in the console is not Terraform's.
				PreConfig: func() { srv.BrandingSeedUpload(runr.BrandingInstall, "logo_light") },
				Config: brandConfig(srv, `
  product_name = "Acme"
  support_url  = "https://help.acme.example"`),
				PlanOnly: true,
			},
			{
				// Set outside Terraform: removed again.
				PreConfig: func() {
					if _, err := client(t, srv).UpdateBranding(t.Context(), runr.BrandingInstall, map[string]any{"company_name": "Other"}); err != nil {
						t.Fatal(err)
					}
				},
				Config: brandConfig(srv, `
  product_name = "Acme"
  support_url  = "https://help.acme.example"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_branding.brand", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					if _, ok := stored()["company_name"]; ok {
						return fmt.Errorf("company_name kept: %v", stored())
					}
					return nil
				}),
			},
			{
				ResourceName:      "runr_branding.brand",
				ImportState:       true,
				ImportStateId:     runr.BrandingInstall,
				ImportStateVerify: true,
			},
		},
	})
}

func TestBrandingTenantSwitch(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: brandConfig(srv, `
  product_name            = "Acme"
  tenant_branding_enabled = true`),
				Check: resource.TestCheckResourceAttr("runr_branding.brand", "tenant_branding_enabled", "true"),
			},
			{
				// Left out: the switch stays as it is.
				Config:   brandConfig(srv, `product_name = "Acme"`),
				PlanOnly: true,
			},
		},
	})
}

func TestBrandingOrganization(t *testing.T) {
	srv := fake(t)
	srv.RBACMultiTenant()
	srv.BrandingAllowTenants(true)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: brandConfig(srv, `product_name = "Team"`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_branding.brand", "scope", runr.BrandingOrganization),
					check(func() error {
						if got := srv.BrandingStored(runr.BrandingOrganization)["product_name"]; got != "Team" {
							return fmt.Errorf("organization brand = %v", got)
						}
						if _, ok := srv.BrandingStored(runr.BrandingInstall)["product_name"]; ok {
							return fmt.Errorf("the install brand changed")
						}
						return nil
					}),
				),
			},
			{
				Config: brandConfig(srv, `
  scope      = "organization"
  powered_by = false`),
				ExpectError: phrase("powered_by is a platform decision"),
			},
			{
				// The refused configuration changed nothing (and the post-test destroy runs
				// with this valid one).
				Config:   brandConfig(srv, `product_name = "Team"`),
				PlanOnly: true,
			},
		},
	})
}

func TestBrandingRejected(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config:      brandConfig(srv, `primary_color = "orange"`),
				ExpectError: phrase("must be a hex colour"),
			},
			{
				Config:      brandConfig(srv, `footer_links = [{ label = "x", url = "ftp://x" }]`),
				ExpectError: phrase("must be an http(s) or mailto: link"),
			},
		},
	})
}
