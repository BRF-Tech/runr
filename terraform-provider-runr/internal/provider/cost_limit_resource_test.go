package provider

import (
	"fmt"
	"regexp"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func costLimitIs(srv *runrtest.Server, project string, amount float64, action string) resource.TestCheckFunc {
	return check(func() error {
		a, act, ok := srv.CostLimit(project)
		if !ok || a != amount || act != action {
			return fmt.Errorf("cost limit of %s = %v %s (%v), want %v %s", project, a, act, ok, amount, action)
		}
		return nil
	})
}

func TestCostLimit(t *testing.T) {
	srv := fake(t)
	billed := srv.SeedProject("billed", runr.TypeFaaS, "python", "eu-1", nil)
	other := srv.SeedProject("other", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SetMonthToDate("billed", 50)
	cfg := func(projectID, amount, action, extra string) string {
		return providerBlock(srv) + fmt.Sprintf(`
resource "runr_cost_limit" "limit" {
  project_id = %q
  amount     = %s
  action     = %q
%s
}
`, projectID, amount, action, extra)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(*terraform.State) error {
			for _, p := range []string{"billed", "other"} {
				if _, _, ok := srv.CostLimit(p); ok {
					return fmt.Errorf("the cost limit of %s is still there", p)
				}
			}
			return nil
		},
		Steps: []resource.TestStep{
			{
				Config: cfg(billed.ID, "150", "notify", ""),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_cost_limit.limit", "id", billed.ID),
					resource.TestCheckResourceAttr("runr_cost_limit.limit", "currency", "EUR"),
					costLimitIs(srv, "billed", 150, "notify"),
				),
			},
			{
				Config: cfg(billed.ID, "150.125", "stop", ""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_cost_limit.limit", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					costLimitIs(srv, "billed", 150.125, "stop"),
					check(func() error {
						if p, _ := srv.Project("billed"); p.Stopped != nil {
							return fmt.Errorf("a limit above the month so far stopped the project")
						}
						return nil
					}),
				),
			},
			{
				ResourceName:      "runr_cost_limit.limit",
				ImportState:       true,
				ImportStateId:     "billed",
				ImportStateVerify: true,
			},
			{
				// The currency given explicitly, then left out again: no change either time.
				Config: cfg(billed.ID, "150.125", "stop", `  currency = "EUR"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectEmptyPlan(),
				}},
			},
			{
				Config: cfg(billed.ID, "150.125", "stop", ""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectEmptyPlan(),
				}},
			},
			{
				// Changed outside Terraform: put back.
				PreConfig: func() {
					if _, err := client(t, srv).SetCostLimit(t.Context(), billed.ID, runr.CostLimitInput{Amount: 99, Action: "notify"}); err != nil {
						t.Fatal(err)
					}
				},
				Config: cfg(billed.ID, "150.125", "stop", ""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_cost_limit.limit", plancheck.ResourceActionUpdate),
				}},
				Check: costLimitIs(srv, "billed", 150.125, "stop"),
			},
			{
				// Removed outside Terraform: set again.
				PreConfig: func() {
					if _, err := client(t, srv).DeleteCostLimit(t.Context(), billed.ID); err != nil {
						t.Fatal(err)
					}
				},
				Config: cfg(billed.ID, "150.125", "stop", ""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_cost_limit.limit", plancheck.ResourceActionCreate),
				}},
				Check: costLimitIs(srv, "billed", 150.125, "stop"),
			},
			{
				// Another project: replaced.
				Config: cfg(other.ID, "20", "notify", ""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_cost_limit.limit", plancheck.ResourceActionDestroyBeforeCreate),
				}},
				Check: check(func() error {
					if _, _, ok := srv.CostLimit("billed"); ok {
						return fmt.Errorf("the old project kept its limit")
					}
					return costLimitIs(srv, "other", 20, "notify")(nil)
				}),
			},
		},
	})
}

// A stop limit below this month so far stops the project at once; removing the limit leaves it
// stopped.
func TestCostLimitStopsProject(t *testing.T) {
	srv := fake(t)
	p := srv.SeedProject("billed", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SetMonthToDate("billed", 50)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(*terraform.State) error {
			if _, _, ok := srv.CostLimit("billed"); ok {
				return fmt.Errorf("the limit is still there")
			}
			if got, _ := srv.Project("billed"); got.Stopped == nil {
				return fmt.Errorf("removing the limit resumed the project")
			}
			return nil
		},
		Steps: []resource.TestStep{{
			Config: providerBlock(srv) + fmt.Sprintf(`
resource "runr_cost_limit" "limit" {
  project_id = %q
  amount     = 40
  action     = "stop"
}

data "runr_cost_limit" "billed" {
  project_id = %[1]q
  depends_on = [runr_cost_limit.limit]
}
`, p.ID),
			Check: resource.ComposeAggregateTestCheckFunc(
				resource.TestCheckResourceAttr("data.runr_cost_limit.billed", "state", "reached"),
				resource.TestCheckResourceAttr("data.runr_cost_limit.billed", "stopped", "true"),
				resource.TestCheckResourceAttr("data.runr_cost_limit.billed", "can_resume", "false"),
				check(func() error {
					if got, _ := srv.Project("billed"); got.Stopped == nil {
						return fmt.Errorf("the project runs")
					}
					return nil
				}),
			),
		}},
	})
}

func TestCostLimitRefusals(t *testing.T) {
	srv := fake(t)
	p := srv.SeedProject("billed", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SeedProject("fixed", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SetFixedPrice("fixed", true)
	fixed, _ := srv.Project("fixed")
	limit := func(projectID, body string) string {
		return providerBlock(srv) + fmt.Sprintf(`
resource "runr_cost_limit" "limit" {
  project_id = %q
%s
}
`, projectID, body)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{Config: limit(p.ID, `  amount = 0
  action = "stop"`), ExpectError: phrase("Invalid amount")},
			{Config: limit(p.ID, `  amount = 1.2345
  action = "stop"`), ExpectError: phrase("at most three decimals")},
			{Config: limit(p.ID, `  amount = 10
  action = "pause"`), ExpectError: regexp.MustCompile(`(?s)action.*notify`)},
			{Config: limit(p.ID, `  amount   = 10
  action   = "stop"
  currency = "eur"`), ExpectError: phrase("must be an ISO 4217 code")},
			{Config: limit(p.ID, `  amount   = 10
  action   = "stop"
  currency = "USD"`), ExpectError: phrase("The limit is in the billing currency, EUR.")},
			{Config: limit(fixed.ID, `  amount = 10
  action = "stop"`), ExpectError: phrase("cost_limit_not_available")},
			{Config: providerBlock(srv)},
		},
	})
}

func TestCostLimitDataSource(t *testing.T) {
	srv := fake(t)
	p := srv.SeedProject("billed", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SeedProject("fixed", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SetFixedPrice("fixed", true)
	fixed, _ := srv.Project("fixed")
	srv.SetMonthToDate("billed", 85)
	if _, err := client(t, srv).SetCostLimit(t.Context(), p.ID, runr.CostLimitInput{Amount: 100, Action: "notify"}); err != nil {
		t.Fatal(err)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: providerBlock(srv) + fmt.Sprintf(`
data "runr_cost_limit" "billed" {
  project_id = %q
}
data "runr_cost_limit" "fixed" {
  project_id = %q
}
`, p.ID, fixed.ID),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("data.runr_cost_limit.billed", "available", "true"),
					resource.TestCheckResourceAttr("data.runr_cost_limit.billed", "amount", "100"),
					resource.TestCheckResourceAttr("data.runr_cost_limit.billed", "action", "notify"),
					resource.TestCheckResourceAttr("data.runr_cost_limit.billed", "month_to_date", "85"),
					resource.TestCheckResourceAttr("data.runr_cost_limit.billed", "percent", "85"),
					resource.TestCheckResourceAttr("data.runr_cost_limit.billed", "state", "warning"),
					resource.TestCheckResourceAttr("data.runr_cost_limit.billed", "warning_percent", "80"),
					resource.TestCheckResourceAttr("data.runr_cost_limit.billed", "currency", "EUR"),
					resource.TestCheckResourceAttrSet("data.runr_cost_limit.billed", "forecast"),
					resource.TestCheckResourceAttr("data.runr_cost_limit.fixed", "available", "false"),
					resource.TestCheckNoResourceAttr("data.runr_cost_limit.fixed", "amount"),
					resource.TestCheckNoResourceAttr("data.runr_cost_limit.fixed", "month_to_date"),
					resource.TestCheckResourceAttr("data.runr_cost_limit.fixed", "state", "none"),
				),
			},
			{
				Config: providerBlock(srv) + `
data "runr_cost_limit" "missing" {
  project_id = "01J99999999999999999999999"
}
`,
				ExpectError: phrase("404"),
			},
		},
	})
}

// Without the Billing module the API has no cost limits.
func TestCostLimitUnsupported(t *testing.T) {
	srv := fake(t)
	p := srv.SeedProject("billed", runr.TypeFaaS, "python", "eu-1", nil)
	srv.Without("GET /projects/{project}/billing/cost-limit", "PUT /projects/{project}/billing/cost-limit",
		"DELETE /projects/{project}/billing/cost-limit")
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: providerBlock(srv) + fmt.Sprintf(`
resource "runr_cost_limit" "limit" {
  project_id = %q
  amount     = 10
  action     = "notify"
}
`, p.ID),
				ExpectError: phrase("Cost limits not available"),
			},
			{
				Config: providerBlock(srv) + fmt.Sprintf(`
data "runr_cost_limit" "billed" {
  project_id = %q
}
`, p.ID),
				ExpectError: phrase("Cost limits not available"),
			},
		},
	})
}
