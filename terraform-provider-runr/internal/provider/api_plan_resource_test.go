package provider

import (
	"fmt"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func planConfig(srv *runrtest.Server, attrs string) string {
	return providerBlock(srv) + gatewayEU + fmt.Sprintf(`
resource "runr_api_plan" "pro" {
  gateway_id = runr_gateway.eu.id
%s
}
`, attrs)
}

// fakePlan is the fake's plan with the name.
func fakePlan(srv *runrtest.Server, name string) (runr.APIPlan, bool) {
	for _, p := range srv.APIPlans() {
		if p.Name == name {
			return p, true
		}
	}
	return runr.APIPlan{}, false
}

func TestAPIPlanResource(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(*terraform.State) error {
			if n := len(srv.APIPlans()); n != 0 {
				return fmt.Errorf("%d plans left", n)
			}
			return nil
		},
		Steps: []resource.TestStep{
			{
				Config: planConfig(srv, `  name            = "Pro"
  description     = "For production"
  rate_per_second = 10
  quota_per_month = 100000`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttrPair("runr_api_plan.pro", "gateway_id", "runr_gateway.eu", "id"),
					resource.TestCheckResourceAttr("runr_api_plan.pro", "burst", "10"),
					resource.TestCheckNoResourceAttr("runr_api_plan.pro", "rate_per_minute"),
					check(func() error {
						p, ok := fakePlan(srv, "Pro")
						if !ok || p.Scope != "gateway" || *p.RatePerSecond != 10 || *p.QuotaPerMonth != 100000 || p.Description != "For production" {
							return fmt.Errorf("plan = %+v", p)
						}
						return nil
					}),
				),
			},
			{
				ResourceName:      "runr_api_plan.pro",
				ImportState:       true,
				ImportStateVerify: true,
			},
			{
				// In place: name, limits; the burst follows the rate; the description goes
				Config: planConfig(srv, `  name            = "Pro 2"
  rate_per_second = 20
  rate_per_minute = 600
  quota_per_month = 100000`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_api_plan.pro", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_api_plan.pro", "burst", "20"),
					resource.TestCheckNoResourceAttr("runr_api_plan.pro", "description"),
					check(func() error {
						p, ok := fakePlan(srv, "Pro 2")
						if !ok || *p.Burst != 20 || *p.RatePerMinute != 600 || p.Description != "" {
							return fmt.Errorf("plan = %+v", p)
						}
						return nil
					}),
				),
			},
			{
				Config: planConfig(srv, `  name            = "Pro 2"
  rate_per_second = 20
  burst           = 50
  rate_per_minute = 600
  quota_per_month = 100000`),
				Check: check(func() error {
					if p, _ := fakePlan(srv, "Pro 2"); *p.Burst != 50 {
						return fmt.Errorf("plan = %+v", p)
					}
					return nil
				}),
			},
			{
				// Left out, the burst the plan has stays
				Config: planConfig(srv, `  name            = "Pro 2"
  rate_per_second = 20
  rate_per_minute = 600
  quota_per_month = 100000`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
			},
			{
				// A quota added outside Terraform is planned away
				PreConfig: func() {
					p, _ := fakePlan(srv, "Pro 2")
					if _, err := client(t, srv).UpdateAPIPlan(t.Context(), p.ID, map[string]any{"quota_per_day": 5000}); err != nil {
						t.Fatal(err)
					}
				},
				Config: planConfig(srv, `  name            = "Pro 2"
  rate_per_second = 20
  rate_per_minute = 600
  quota_per_month = 100000`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_api_plan.pro", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					if p, _ := fakePlan(srv, "Pro 2"); p.QuotaPerDay != nil || *p.Burst != 50 {
						return fmt.Errorf("plan = %+v", p)
					}
					return nil
				}),
			},
			{
				// Without a per-second rate there is no burst
				Config: planConfig(srv, `  name          = "Pro 2"
  quota_per_day = 1000`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckNoResourceAttr("runr_api_plan.pro", "burst"),
					check(func() error {
						if p, _ := fakePlan(srv, "Pro 2"); p.RatePerSecond != nil || p.Burst != nil || *p.QuotaPerDay != 1000 {
							return fmt.Errorf("plan = %+v", p)
						}
						return nil
					}),
				),
			},
			{
				// Deleted outside Terraform: created again
				PreConfig: func() {
					p, _ := fakePlan(srv, "Pro 2")
					if err := client(t, srv).DeleteAPIPlan(t.Context(), p.ID); err != nil {
						t.Fatal(err)
					}
				},
				Config: planConfig(srv, `  name          = "Pro 2"
  quota_per_day = 1000`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_api_plan.pro", plancheck.ResourceActionCreate),
				}},
			},
			{
				// The organization's plan instead: a new plan
				Config: providerBlock(srv) + gatewayEU + `
resource "runr_api_plan" "pro" {
  name          = "Pro 2"
  quota_per_day = 1000
}
`,
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_api_plan.pro", plancheck.ResourceActionDestroyBeforeCreate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckNoResourceAttr("runr_api_plan.pro", "gateway_id"),
					check(func() error {
						if p, _ := fakePlan(srv, "Pro 2"); p.Scope != "organization" || len(srv.APIPlans()) != 1 {
							return fmt.Errorf("plans = %+v", srv.APIPlans())
						}
						return nil
					}),
				),
			},
			{
				ResourceName:      "runr_api_plan.pro",
				ImportState:       true,
				ImportStateVerify: true,
			},
		},
	})
}

func TestAPIPlanResourceChecks(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: planConfig(srv, `  name  = "Pro"
  burst = 5`),
				ExpectError: phrase("A burst needs a per-second rate."),
			},
			{
				Config: planConfig(srv, `  name            = "Pro"
  rate_per_second = 10
  burst           = 5`),
				ExpectError: phrase("The burst cannot be lower than the per-second rate."),
			},
			{
				Config: planConfig(srv, `  name            = "Pro"
  rate_per_second = 0`),
				ExpectError: phrase("value must be between 1 and 100000"),
			},
			{
				// The API refuses a name twice on a gateway
				Config: planConfig(srv, `  name = "Pro"`) + `
resource "runr_api_plan" "twin" {
  gateway_id = runr_gateway.eu.id
  name       = "Pro"
  depends_on = [runr_api_plan.pro]
}
`,
				ExpectError: phrase("A plan with this name already exists here."),
			},
		},
	})
}

// A plan a consumer uses cannot be deleted; the API's refusal comes through.
func TestAPIPlanResourceInUse(t *testing.T) {
	srv := fake(t)
	cfg := planConfig(srv, `  name = "Pro"`)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: cfg,
				Check: check(func() error {
					p, _ := fakePlan(srv, "Pro")
					_, err := client(t, srv).CreateAPIConsumer(t.Context(), runr.APIConsumerInput{GatewayID: p.GatewayID, Name: "Acme", PlanID: p.ID})
					return err
				}),
			},
			{
				Config:      providerBlock(srv) + gatewayEU,
				ExpectError: phrase("Consumers or active keys use this plan"),
			},
			{
				PreConfig: func() {
					for _, c := range srv.APIConsumers() {
						if err := client(t, srv).DeleteAPIConsumer(t.Context(), c.ID); err != nil {
							t.Fatal(err)
						}
					}
				},
				Config: providerBlock(srv) + gatewayEU,
				Check: check(func() error {
					if n := len(srv.APIPlans()); n != 0 {
						return fmt.Errorf("%d plans left", n)
					}
					return nil
				}),
			},
		},
	})
}
