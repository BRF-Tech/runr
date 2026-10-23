package provider

import (
	"fmt"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func networkProjects(srv *runrtest.Server, network string) string {
	return providerBlock(srv) + `
resource "runr_project" "billing" {
  name      = "billing"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id
}

resource "runr_project" "ledger" {
  name      = "ledger"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id
` + network + `
}
`
}

func serverNetwork(srv *runrtest.Server, project, mode string, cidrs []string, callers ...string) func() error {
	return func() error {
		n := srv.Network(project)
		if n == nil {
			return fmt.Errorf("no network for %s", project)
		}
		var got []string
		for _, c := range n.Callers {
			got = append(got, c.Caller)
		}
		if n.AccessMode != mode || !sameStrings(n.AllowedCIDRs, cidrs) || !sameStrings(got, callers) {
			return fmt.Errorf("network %s %v %v, want %s %v %v", n.AccessMode, n.AllowedCIDRs, got, mode, cidrs, callers)
		}
		return nil
	}
}

func TestProjectNetwork(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "billing", "ledger"),
		Steps: []resource.TestStep{
			{
				Config: networkProjects(srv, `
  network = {
    access_mode   = "allowlist"
    allowed_cidrs = ["203.0.113.0/24", "198.51.100.7"]
  }`),
				Check: resource.ComposeAggregateTestCheckFunc(
					check(serverNetwork(srv, "ledger", runr.AccessAllowlist, []string{"203.0.113.0/24", "198.51.100.7"})),
					resource.TestCheckResourceAttr("runr_project.ledger", "network.internal_name", "ledger.platform"),
					resource.TestCheckResourceAttr("runr_project.billing", "network.access_mode", "public"),
					resource.TestCheckResourceAttr("runr_project.billing", "network.internal_name", "billing.platform"),
				),
			},
			{
				ResourceName:      "runr_project.ledger",
				ImportState:       true,
				ImportStateId:     "ledger",
				ImportStateVerify: true,
			},
			{
				// internal: the IP ranges go, another project may call it
				Config: networkProjects(srv, `
  network = {
    access_mode = "internal"
    callers     = [runr_project.billing.network.internal_name]
  }`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_project.ledger", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					check(serverNetwork(srv, "ledger", runr.AccessInternal, nil, "billing.platform")),
					resource.TestCheckResourceAttr("runr_project.ledger", "network.allowed_cidrs.#", "0"),
					resource.TestCheckResourceAttr("runr_project.ledger", "network.callers.#", "1"),
				),
			},
			{
				// left out: the network stays, also a caller added outside Terraform
				PreConfig: func() {
					p, _ := srv.Project("ledger")
					if _, err := client(t, srv).AddNetworkCaller(t.Context(), p.ID, "reports.platform"); err != nil {
						t.Fatal(err)
					}
				},
				Config: networkProjects(srv, ""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectEmptyPlan(),
				}},
				Check: check(serverNetwork(srv, "ledger", runr.AccessInternal, nil, "billing.platform", "reports.platform")),
			},
			{
				// only the mode set: the callers stay; [] then removes them
				Config: networkProjects(srv, `
  network = {
    access_mode = "internal"
    callers     = []
  }`),
				Check: check(serverNetwork(srv, "ledger", runr.AccessInternal, nil)),
			},
			{
				Config: networkProjects(srv, `
  network = {
    access_mode = "public"
  }`),
				Check: check(serverNetwork(srv, "ledger", runr.AccessPublic, nil)),
			},
		},
	})
}

func TestProjectNetworkValidation(t *testing.T) {
	srv := fake(t)
	for network, want := range map[string]string{
		`network = { allowed_cidrs = ["10.0.0.0/8"] }`:                             `allowed_cidrs needs access_mode "allowlist"`,
		`network = { access_mode = "allowlist", allowed_cidrs = [] }`:              `allowlist without IP ranges`,
		`network = { access_mode = "allowlist", allowed_cidrs = ["10.0.0.0/33"] }`: `invalid IP range`,
		`network = { callers = ["billing"] }`:                                      `must be an internal name`,
		`network = { access_mode = "private" }`:                                    `value must be one of`,
	} {
		resource.UnitTest(t, resource.TestCase{
			ProtoV6ProviderFactories: providerFactories(),
			Steps: []resource.TestStep{{
				Config:      networkProjects(srv, "  "+network),
				ExpectError: phrase(want),
			}},
		})
	}
}

func strategyProject(srv *runrtest.Server, strategy string) string {
	return providerBlock(srv) + `
resource "runr_project" "shop" {
  name      = "shop"
  type      = "caas"
  region_id = data.runr_region.eu.id
  image     = "nginx:1.27"
` + strategy + `
}
`
}

func TestProjectDeployStrategy(t *testing.T) {
	srv := fake(t)
	strategy := func(t *testing.T) func(check func(s *runr.DeployStrategy) error) func() error {
		return func(check func(s *runr.DeployStrategy) error) func() error {
			return func() error {
				p, _ := srv.Project("shop")
				s, err := client(t, srv).GetDeployStrategy(t.Context(), p.ID)
				if err != nil {
					return err
				}
				return check(s)
			}
		}
	}(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "shop"),
		Steps: []resource.TestStep{
			{
				Config: strategyProject(srv, `
  deploy_strategy = {
    strategy = "canary"
    canary = {
      steps                = [10, 50]
      interval_minutes     = 5
      error_rate_threshold = 1.5
    }
  }`),
				Check: resource.ComposeAggregateTestCheckFunc(
					check(strategy(func(s *runr.DeployStrategy) error {
						c := s.Canary
						if s.Strategy != runr.StrategyCanary || fmt.Sprint(c.Steps) != "[10 50]" || c.IntervalMinutes != 5 ||
							c.ErrorRateThreshold == nil || *c.ErrorRateThreshold != 1.5 || c.P95ThresholdMs != nil {
							return fmt.Errorf("strategy %+v", s.DeployStrategySettings)
						}
						return nil
					})),
					resource.TestCheckResourceAttr("runr_project.shop", "deploy_strategy.blue_green.health_checks", "3"),
				),
			},
			{
				ResourceName:      "runr_project.shop",
				ImportState:       true,
				ImportStateId:     "shop",
				ImportStateVerify: true,
			},
			{
				// a configured canary block sets its thresholds: one it leaves out is not checked
				Config: strategyProject(srv, `
  deploy_strategy = {
    strategy = "canary"
    canary = {
      steps            = [10, 50]
      p95_threshold_ms = 800
    }
  }`),
				Check: check(strategy(func(s *runr.DeployStrategy) error {
					c := s.Canary
					if c.ErrorRateThreshold != nil || c.P95ThresholdMs == nil || *c.P95ThresholdMs != 800 || c.IntervalMinutes != 5 {
						return fmt.Errorf("canary %+v", c)
					}
					return nil
				})),
			},
			{
				// blue-green with a health path; the canary options stay
				Config: strategyProject(srv, `
  deploy_strategy = {
    strategy   = "blue_green"
    blue_green = { auto_promote = true, health_path = "/healthz", keep_previous_minutes = 30 }
  }`),
				Check: check(strategy(func(s *runr.DeployStrategy) error {
					b := s.BlueGreen
					if s.Strategy != runr.StrategyBlueGreen || !b.AutoPromote || b.HealthPath == nil || *b.HealthPath != "/healthz" ||
						b.KeepPreviousMinutes != 30 || s.Canary.P95ThresholdMs == nil {
						return fmt.Errorf("strategy %+v", s.DeployStrategySettings)
					}
					return nil
				})),
			},
			{
				// left out: the strategy stays, also one changed outside Terraform
				PreConfig: func() {
					p, _ := srv.Project("shop")
					if _, err := client(t, srv).UpdateDeployStrategy(t.Context(), p.ID, runr.DeployStrategyInput{Strategy: runr.StrategyDirect}); err != nil {
						t.Fatal(err)
					}
				},
				Config: strategyProject(srv, ""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectEmptyPlan(),
				}},
				Check: resource.TestCheckResourceAttr("runr_project.shop", "deploy_strategy.strategy", "direct"),
			},
			{
				// only the strategy set: the options stay as they are
				Config: strategyProject(srv, `
  deploy_strategy = { strategy = "blue_green" }`),
				Check: check(strategy(func(s *runr.DeployStrategy) error {
					if s.Strategy != runr.StrategyBlueGreen || s.BlueGreen.HealthPath == nil || *s.BlueGreen.HealthPath != "/healthz" {
						return fmt.Errorf("strategy %+v", s.DeployStrategySettings)
					}
					return nil
				})),
			},
		},
	})
}

func TestProjectDeployStrategyValidation(t *testing.T) {
	srv := fake(t)
	for strategy, want := range map[string]string{
		`deploy_strategy = { strategy = "rolling" }`:                     "value must be one of",
		`deploy_strategy = { canary = { steps = [50, 10] } }`:            "canary steps must increase",
		`deploy_strategy = { canary = { steps = [10, 200] } }`:           "between 1 and 100",
		`deploy_strategy = { blue_green = { health_path = "healthz" } }`: "must be a path of the function",
		`deploy_strategy = { blue_green = { health_checks = 0 } }`:       "between 1 and 20",
		`deploy_strategy = { strategy = "canary" }
  trigger = { type = "schedule", schedule = "0 1 * * *" }`: "strategy needs an http or workflow trigger",
	} {
		resource.UnitTest(t, resource.TestCase{
			ProtoV6ProviderFactories: providerFactories(),
			Steps: []resource.TestStep{{
				Config:      strategyProject(srv, "  "+strategy),
				ExpectError: phrase(want),
			}},
		})
	}
}

func globalProject(srv *runrtest.Server, global string) string {
	return providerBlock(srv) + `
data "runr_region" "us" {
  name = "us-1"
}

data "runr_region" "ap" {
  name = "ap-1"
}

resource "runr_project" "shop" {
  name      = "shop"
  type      = "caas"
  region_id = data.runr_region.eu.id
  image     = "nginx:1.27"
` + global + `
}
`
}

func TestProjectGlobal(t *testing.T) {
	srv := fake(t)
	srv.AddRegion("us-1", true)
	srv.AddRegion("ap-1", true)
	for _, r := range []string{"eu-1", "us-1", "ap-1"} {
		srv.SetGlobalDNS(r, runr.GlobalDNSReady)
	}
	globalRegions := func(want ...string) func() error {
		return func() error {
			if got := srv.GlobalRegions("shop"); fmt.Sprint(got) != fmt.Sprint(want) {
				return fmt.Errorf("regions %v, want %v", got, want)
			}
			return nil
		}
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "shop"),
		Steps: []resource.TestStep{
			{
				Config: globalProject(srv, `
  global = {
    regions = [data.runr_region.eu.id, data.runr_region.us.id]
    mode    = "proximity"
  }`),
				Check: resource.ComposeAggregateTestCheckFunc(
					check(globalRegions("eu-1", "us-1")),
					resource.TestCheckResourceAttr("runr_project.shop", "global.mode", "proximity"),
					resource.TestCheckResourceAttr("runr_project.shop", "global.failover", "automatic"),
					resource.TestCheckResourceAttr("runr_project.shop", "global.hostname", "shop.g.runr.test"),
				),
			},
			{
				ResourceName:      "runr_project.shop",
				ImportState:       true,
				ImportStateId:     "shop",
				ImportStateVerify: true,
			},
			{
				// a third region and a manual failover, in place
				Config: globalProject(srv, `
  global = {
    regions  = [data.runr_region.eu.id, data.runr_region.us.id, data.runr_region.ap.id]
    mode     = "proximity"
    failover = "manual"
  }`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_project.shop", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					check(globalRegions("eu-1", "us-1", "ap-1")),
					resource.TestCheckResourceAttr("runr_project.shop", "global.failover", "manual"),
				),
			},
			{
				// left out: the global project stays as it is
				Config: globalProject(srv, ""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectEmptyPlan(),
				}},
			},
		},
	})
}

// A switchover moves the primary (region_id) of a global project; adding global to a regional
// project creates it again.
func TestProjectGlobalSwitchoverAndPlacement(t *testing.T) {
	srv := fake(t)
	us := srv.AddRegion("us-1", true)
	srv.AddRegion("ap-1", true)
	for _, r := range []string{"eu-1", "us-1"} {
		srv.SetGlobalDNS(r, runr.GlobalDNSReady)
	}
	project := func(primary, global string) string {
		return providerBlock(srv) + fmt.Sprintf(`
data "runr_region" "us" {
  name = "us-1"
}

resource "runr_project" "shop" {
  name      = "shop"
  type      = "caas"
  region_id = data.runr_region.%s.id
  image     = "nginx:1.27"
%s
}
`, primary, global)
	}
	both := `  global = { regions = [data.runr_region.eu.id, data.runr_region.us.id] }`
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "shop"),
		Steps: []resource.TestStep{
			{
				Config: project("eu", ""),
				Check:  resource.TestCheckNoResourceAttr("runr_project.shop", "global.mode"),
			},
			{
				Config: project("eu", both),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_project.shop", plancheck.ResourceActionDestroyBeforeCreate),
				}},
				Check: resource.TestCheckResourceAttr("runr_project.shop", "global.mode", "ha"),
			},
			{
				PreConfig: func() { srv.SetGlobalRegionStatus("shop", "us-1", runr.GlobalRegionReady, "") },
				Config:    project("us", both),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_project.shop", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					p, _ := srv.Project("shop")
					if p.RegionID != us.ID {
						return fmt.Errorf("primary %s, want us-1 (%s)", p.RegionID, us.ID)
					}
					return nil
				}),
			},
		},
	})
}

func TestProjectGlobalValidation(t *testing.T) {
	srv := fake(t)
	srv.AddRegion("us-1", true)
	srv.AddRegion("ap-1", true)
	for global, want := range map[string]string{
		`global = { regions = [data.runr_region.us.id, data.runr_region.ap.id] }`:                   "region_id missing from global.regions",
		`global = { regions = [data.runr_region.eu.id] }`:                                           "list must contain at least 2",
		`global = { regions = [data.runr_region.eu.id, data.runr_region.us.id], mode = "nearest" }`: "value must be one of",
	} {
		resource.UnitTest(t, resource.TestCase{
			ProtoV6ProviderFactories: providerFactories(),
			Steps: []resource.TestStep{{
				Config:      globalProject(srv, "  "+global),
				ExpectError: phrase(want),
			}},
		})
	}
}

// data.runr_project reads the trigger list, the network, the deploy strategy and the placement.
func TestProjectDataSourceSettings(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{{
			Config: providerBlock(srv) + `
resource "runr_project" "hooks" {
  name      = "hooks"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id
  triggers = [
    { type = "http" },
    { name = "github", type = "webhook", config = jsonencode({ verify = "none" }) },
  ]
  network         = { access_mode = "internal" }
  deploy_strategy = { strategy = "blue_green" }
}

data "runr_project" "hooks" {
  name       = runr_project.hooks.name
  depends_on = [runr_project.hooks]
}
`,
			Check: resource.ComposeAggregateTestCheckFunc(
				resource.TestCheckResourceAttr("data.runr_project.hooks", "triggers.#", "2"),
				resource.TestCheckResourceAttr("data.runr_project.hooks", "triggers.1.type", "webhook"),
				resource.TestCheckResourceAttr("data.runr_project.hooks", "triggers.1.config", `{"verify":"none"}`),
				resource.TestCheckResourceAttrPair("data.runr_project.hooks", "triggers.1.url", "runr_project.hooks", "triggers.1.url"),
				resource.TestCheckResourceAttr("data.runr_project.hooks", "access_mode", "internal"),
				resource.TestCheckResourceAttr("data.runr_project.hooks", "internal_name", "hooks.platform"),
				resource.TestCheckResourceAttr("data.runr_project.hooks", "deploy_strategy", "blue_green"),
				resource.TestCheckResourceAttr("data.runr_project.hooks", "placement", "regional"),
				resource.TestCheckNoResourceAttr("data.runr_project.hooks", "global_regions"),
			),
		}},
	})
}
