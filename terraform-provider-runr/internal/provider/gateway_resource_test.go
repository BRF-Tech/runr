package provider

import (
	"fmt"
	"regexp"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/knownvalue"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"
	"github.com/hashicorp/terraform-plugin-testing/tfjsonpath"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func gatewayConfig(srv *runrtest.Server, attrs string) string {
	return providerBlock(srv) + fmt.Sprintf(`
resource "runr_gateway" "eu" {
  region_id = data.runr_region.eu.id
%s
}
`, attrs)
}

func strPtrOf(s string) *string { return &s }

// gatewayIn is the fake's gateway in a region.
func gatewayIn(srv *runrtest.Server, region string) (runr.Gateway, bool) {
	for _, g := range srv.Gateways() {
		if g.Region != nil && g.Region.Name == region {
			return g, true
		}
	}
	return runr.Gateway{}, false
}

// gatewayReset checks that the gateway of a region has nothing a runr_gateway manages.
func gatewayReset(srv *runrtest.Server, region string) func() error {
	return func() error {
		g, ok := gatewayIn(srv, region)
		if !ok {
			return fmt.Errorf("no gateway in %s", region)
		}
		if g.RateLimit != nil || g.DeveloperPage || g.Description != "" || g.DeveloperPageMetrics || g.CustomDomain != "" || g.CustomDomainPending != nil {
			return fmt.Errorf("gateway not reset: %+v", g)
		}
		return nil
	}
}

func TestGatewayResource(t *testing.T) {
	srv := fake(t)
	srv.AddRegion("eu-2", true)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(*terraform.State) error {
			if err := gatewayReset(srv, "eu-1")(); err != nil {
				return err
			}
			return gatewayReset(srv, "eu-2")()
		},
		Steps: []resource.TestStep{
			{
				Config: gatewayConfig(srv, `  rate_limit     = { average = 20 }
  developer_page = true
  description    = "Orders API"
  custom_domain  = "api.example.com"`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttrPair("runr_gateway.eu", "region_id", "data.runr_region.eu", "id"),
					resource.TestCheckResourceAttr("runr_gateway.eu", "host", "ada.eu-1.runr.test"),
					resource.TestCheckResourceAttr("runr_gateway.eu", "subdomain", "ada"),
					resource.TestCheckResourceAttr("runr_gateway.eu", "status", "active"),
					resource.TestCheckResourceAttr("runr_gateway.eu", "rate_limit.burst", "20"),
					resource.TestCheckResourceAttr("runr_gateway.eu", "developer_page_metrics", "false"),
					resource.TestCheckResourceAttr("runr_gateway.eu", "custom_domain_verified", "false"),
					resource.TestCheckResourceAttr("runr_gateway.eu", "custom_domain_verification.type", "TXT"),
					resource.TestCheckResourceAttr("runr_gateway.eu", "custom_domain_verification.name", "_runr-challenge.api.example.com"),
					resource.TestMatchResourceAttr("runr_gateway.eu", "custom_domain_verification.value", regexp.MustCompile(`^runr-verify=[0-9a-f]{32}$`)),
					resource.TestCheckResourceAttr("runr_gateway.eu", "custom_domain_dns_targets.0.type", "CNAME"),
					resource.TestCheckResourceAttr("runr_gateway.eu", "custom_domain_dns_targets.0.value", "ada.eu-1.runr.test"),
					func(s *terraform.State) error {
						id := s.RootModule().Resources["runr_gateway.eu"].Primary.ID
						return resource.ComposeAggregateTestCheckFunc(
							resource.TestCheckResourceAttr("runr_gateway.eu", "developer_page_key", "_."+id),
							resource.TestCheckResourceAttr("runr_gateway.eu", "developer_page_url", srv.URL+"/developers/_."+id),
						)(s)
					},
					check(func() error {
						g, ok := gatewayIn(srv, "eu-1")
						if !ok || g.RateLimit == nil || g.RateLimit.Average != 20 || !g.DeveloperPage || g.Description != "Orders API" ||
							g.CustomDomainPending == nil || g.CustomDomainPending.Domain != "api.example.com" {
							return fmt.Errorf("gateway = %+v", g)
						}
						return nil
					}),
				),
			},
			{
				ResourceName:            "runr_gateway.eu",
				ImportState:             true,
				ImportStateId:           "eu-1",
				ImportStateVerify:       true,
				ImportStateVerifyIgnore: []string{"verify", "verify_timeout"},
			},
			{
				// In place: the burst, the page's text and metrics; verify waits until the domain is verified
				PreConfig: func() { srv.VerifyDomains = true },
				Config: gatewayConfig(srv, `  rate_limit             = { average = 20, burst = 40 }
  developer_page         = true
  description            = "Orders and invoices"
  developer_page_metrics = true
  custom_domain          = "api.example.com"
  verify                 = true`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_gateway.eu", plancheck.ResourceActionUpdate),
					plancheck.ExpectKnownValue("runr_gateway.eu", tfjsonpath.New("developer_page_key"), knownvalue.NotNull()),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_gateway.eu", "rate_limit.burst", "40"),
					resource.TestCheckResourceAttr("runr_gateway.eu", "custom_domain_verified", "true"),
					resource.TestCheckNoResourceAttr("runr_gateway.eu", "custom_domain_verification.name"),
					resource.TestCheckNoResourceAttr("runr_gateway.eu", "custom_domain_dns_targets.#"),
					check(func() error {
						g, _ := gatewayIn(srv, "eu-1")
						if g.CustomDomain != "api.example.com" || g.CustomDomainPending != nil || g.RateLimit.Burst != 40 || !g.DeveloperPageMetrics {
							return fmt.Errorf("gateway = %+v", g)
						}
						return nil
					}),
				),
			},
			{
				// Left out: the page, its text and metrics stay as they are, also when changed outside Terraform
				PreConfig: func() {
					g, _ := gatewayIn(srv, "eu-1")
					if _, err := client(t, srv).UpdateGatewayPage(t.Context(), g.ID, true, strPtrOf("Changed in the console"), nil); err != nil {
						t.Fatal(err)
					}
				},
				Config: gatewayConfig(srv, `  rate_limit    = { average = 20, burst = 40 }
  custom_domain = "api.example.com"
  verify        = true`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
				Check:            resource.TestCheckResourceAttr("runr_gateway.eu", "description", "Changed in the console"),
			},
			{
				// The rate limit changed outside Terraform: planned back
				PreConfig: func() {
					g, _ := gatewayIn(srv, "eu-1")
					if _, err := client(t, srv).UpdateGateway(t.Context(), g.ID, map[string]any{"rate_limit": &runr.GatewayLimit{Average: 5}}); err != nil {
						t.Fatal(err)
					}
				},
				Config: gatewayConfig(srv, `  rate_limit    = { average = 20, burst = 40 }
  custom_domain = "api.example.com"
  verify        = true`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_gateway.eu", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					if g, _ := gatewayIn(srv, "eu-1"); g.RateLimit == nil || g.RateLimit.Average != 20 || g.RateLimit.Burst != 40 {
						return fmt.Errorf("rate limit = %+v", g.RateLimit)
					}
					return nil
				}),
			},
			{
				// Rate limit and domain left out: removed; the page stays
				Config: gatewayConfig(srv, `  description = ""`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_gateway.eu", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckNoResourceAttr("runr_gateway.eu", "rate_limit.average"),
					resource.TestCheckNoResourceAttr("runr_gateway.eu", "custom_domain"),
					resource.TestCheckResourceAttr("runr_gateway.eu", "custom_domain_verified", "false"),
					resource.TestCheckResourceAttr("runr_gateway.eu", "description", ""),
					resource.TestCheckResourceAttr("runr_gateway.eu", "developer_page", "true"),
					check(func() error {
						g, _ := gatewayIn(srv, "eu-1")
						if g.RateLimit != nil || g.CustomDomain != "" || g.Description != "" || !g.DeveloperPage {
							return fmt.Errorf("gateway = %+v", g)
						}
						return nil
					}),
				),
			},
			{
				// Another region: the gateway there (replacement); the one of eu-1 is reset
				Config: providerBlock(srv) + `
data "runr_region" "eu2" {
  name = "eu-2"
}

resource "runr_gateway" "eu" {
  region_id      = data.runr_region.eu2.id
  developer_page = true
}
`,
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_gateway.eu", plancheck.ResourceActionDestroyBeforeCreate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_gateway.eu", "host", "ada.eu-2.runr.test"),
					check(gatewayReset(srv, "eu-1")),
				),
			},
		},
	})
}

// An existing gateway (made in the console) is taken over: what the configuration leaves out of
// the rate limit and domain goes, the page stays.
func TestGatewayResourceTakesTheExistingGateway(t *testing.T) {
	srv := fake(t)
	c := client(t, srv)
	region, _ := c.FindRegion(t.Context(), "eu-1")
	g, err := c.EnsureGateway(t.Context(), region.ID)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := c.UpdateGateway(t.Context(), g.ID, map[string]any{"rate_limit": &runr.GatewayLimit{Average: 7}, "developer_page": true}); err != nil {
		t.Fatal(err)
	}
	if _, err := c.SetGatewayDomain(t.Context(), g.ID, "old.example.com"); err != nil {
		t.Fatal(err)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{{
			Config: gatewayConfig(srv, ""),
			Check: resource.ComposeAggregateTestCheckFunc(
				resource.TestCheckResourceAttr("runr_gateway.eu", "id", g.ID),
				resource.TestCheckResourceAttr("runr_gateway.eu", "developer_page", "true"),
				resource.TestCheckNoResourceAttr("runr_gateway.eu", "rate_limit.average"),
				check(func() error {
					gw, _ := gatewayIn(srv, "eu-1")
					if gw.RateLimit != nil || gw.CustomDomainPending != nil || !gw.DeveloperPage {
						return fmt.Errorf("gateway = %+v", gw)
					}
					if n := len(srv.CallsTo("POST /gateways")); n != 2 {
						return fmt.Errorf("POST /gateways %d times", n)
					}
					return nil
				}),
			),
		}},
	})
}

// verify gives up after verify_timeout: nothing is in the state, and the next apply checks the
// same request again (asking again would change the TXT token).
func TestGatewayResourceVerifyTimeout(t *testing.T) {
	srv := fake(t)
	cfg := gatewayConfig(srv, `  custom_domain  = "api.example.com"
  verify         = true
  verify_timeout = "30ms"`)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config:      cfg,
				ExpectError: phrase("Custom domain not verified"),
			},
			{
				PreConfig: func() { srv.VerifyDomains = true },
				Config:    cfg,
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_gateway.eu", "custom_domain_verified", "true"),
					check(func() error {
						if n := len(srv.CallsTo("PUT /gateways/{gateway}/custom-domain")); n != 1 {
							return fmt.Errorf("the domain was requested %d times", n)
						}
						return nil
					}),
				),
			},
		},
	})
}

// A pending domain with verify = true is checked again by every plan; once verified the plan is empty.
func TestGatewayResourceVerifiesAPendingDomain(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: gatewayConfig(srv, `  custom_domain = "api.example.com"`),
				Check:  resource.TestCheckResourceAttr("runr_gateway.eu", "custom_domain_verified", "false"),
			},
			{
				PreConfig: func() { srv.VerifyDomains = true },
				Config: gatewayConfig(srv, `  custom_domain = "api.example.com"
  verify        = true`),
				Check: resource.TestCheckResourceAttr("runr_gateway.eu", "custom_domain_verified", "true"),
			},
			{
				Config: gatewayConfig(srv, `  custom_domain = "api.example.com"
  verify        = true`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
			},
			{
				// Another name: requested, the verified one serves until it verifies
				PreConfig: func() { srv.VerifyDomains = false },
				Config:    gatewayConfig(srv, `  custom_domain = "orders.example.com"`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_gateway.eu", "custom_domain", "orders.example.com"),
					resource.TestCheckResourceAttr("runr_gateway.eu", "custom_domain_verified", "false"),
					resource.TestCheckResourceAttr("runr_gateway.eu", "custom_domain_verification.name", "_runr-challenge.orders.example.com"),
					check(func() error {
						g, _ := gatewayIn(srv, "eu-1")
						if g.CustomDomain != "api.example.com" || g.CustomDomainPending == nil {
							return fmt.Errorf("gateway = %+v", g)
						}
						return nil
					}),
				),
			},
		},
	})
}

func TestGatewayResourceChecks(t *testing.T) {
	srv := fake(t)
	srv.SeedProject("shop", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SeedDomain("shop", "shop.example.com")
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config:      gatewayConfig(srv, `  rate_limit = { average = 0 }`),
				ExpectError: regexp.MustCompile(`rate_limit.average`),
			},
			{
				Config:      gatewayConfig(srv, `  custom_domain = "API.example.com"`),
				ExpectError: phrase("must be a lower-case host name"),
			},
			{
				Config:      gatewayConfig(srv, `  verify_timeout = "soon"`),
				ExpectError: phrase("Invalid duration"),
			},
			{
				// The API refuses a domain a project uses
				Config:      gatewayConfig(srv, `  custom_domain = "shop.example.com"`),
				ExpectError: phrase("already used by a gateway or a project"),
			},
		},
	})
}
