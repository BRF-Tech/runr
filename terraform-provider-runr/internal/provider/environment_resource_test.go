package provider

import (
	"fmt"
	"regexp"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"
	"github.com/hashicorp/terraform-plugin-testing/tfjsonpath"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

// stagingConfig is the project app with its environment staging (body: the environment's
// arguments besides project_id and name).
func stagingConfig(srv *runrtest.Server, name, body string) string {
	return providerBlock(srv) + appProject + fmt.Sprintf(`
resource "runr_environment" "staging" {
  project_id = runr_project.app.id
  name       = %q
%s
}
`, name, body)
}

const stagingFull = `
  git_branch        = "develop"
  requires_approval = %s
  overrides = {
    env_vars        = { DEBUG = "1", FEATURE_X = "on" }
    remove_env_vars = ["LEGACY"]
    cpu             = "250m"
    min_scale       = %d
    max_scale       = 3
  }
  scale_schedule = [{ cron = "0 9 * * 1-5", min_scale = 1 }, { cron = "0 18 * * 1-5", min_scale = 0 }]
  deploy_strategy = {
    strategy = "canary"
    canary   = { steps = [20, 100] }
  }
`

func environmentDeleted(srv *runrtest.Server, project, name string) resource.TestCheckFunc {
	return func(*terraform.State) error {
		if _, ok := srv.Environment(project, name); ok {
			return fmt.Errorf("environment %s of %s still exists", name, project)
		}
		return nil
	}
}

// An environment with its overrides, timed warmer and deploy strategy: created, imported, changed
// in place, kept when left out, planned again after changes and a deletion outside Terraform,
// replaced on a new name and deleted.
func TestEnvironmentResource(t *testing.T) {
	srv := fake(t)
	full := func(approval string, minScale int) string {
		return stagingConfig(srv, "staging", fmt.Sprintf(stagingFull, approval, minScale))
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             resource.ComposeAggregateTestCheckFunc(projectGone(srv, "app"), environmentDeleted(srv, "app", "qa")),
		Steps: []resource.TestStep{
			{
				Config: full("true", 0),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_environment.staging", "workload_name", "app-staging"),
					resource.TestCheckResourceAttr("runr_environment.staging", "url", "https://app-staging.eu-1.runr.test"),
					resource.TestCheckResourceAttr("runr_environment.staging", "default_url", "https://app-staging.eu-1.runr.test"),
					resource.TestCheckResourceAttrPair("runr_environment.staging", "region_id", "data.runr_region.eu", "id"),
					resource.TestCheckResourceAttr("runr_environment.staging", "own_region", "false"),
					resource.TestCheckResourceAttr("runr_environment.staging", "position", "1"),
					resource.TestCheckResourceAttr("runr_environment.staging", "auto_deploy", "true"),
					resource.TestCheckResourceAttr("runr_environment.staging", "inherit_secrets", "true"),
					resource.TestCheckResourceAttr("runr_environment.staging", "scale_schedule.#", "2"),
					resource.TestCheckResourceAttr("runr_environment.staging", "scale_schedule_inherited", "false"),
					resource.TestCheckResourceAttr("runr_environment.staging", "deploy_strategy.strategy", "canary"),
					resource.TestCheckResourceAttr("runr_environment.staging", "deploy_strategy.canary.steps.#", "2"),
					resource.TestCheckResourceAttr("runr_environment.staging", "deploy_strategy.canary.interval_minutes", "5"),
					resource.TestCheckNoResourceAttr("runr_environment.staging", "deploy_strategy.canary.error_rate_threshold"),
					resource.TestCheckResourceAttr("runr_environment.staging", "deploy_strategy_inherited", "false"),
					check(func() error {
						e, ok := srv.Environment("app", "staging")
						if !ok || e.GitBranch != "develop" || !e.RequiresApproval {
							return fmt.Errorf("environment = %+v", e)
						}
						o := e.Overrides
						if o.EnvVars["DEBUG"] == nil || *o.EnvVars["DEBUG"] != "1" || o.EnvVars["LEGACY"] != nil || len(o.EnvVars) != 3 ||
							*o.CPU != "250m" || *o.MinScale != 0 || *o.MaxScale != 3 {
							return fmt.Errorf("overrides = %+v", o)
						}
						if e.ScaleScheduleInherited || len(e.ScaleSchedule) != 2 || e.DeployStrategy != runr.StrategyCanary {
							return fmt.Errorf("warmer / strategy = %+v / %s", e.ScaleSchedule, e.DeployStrategy)
						}
						return nil
					}),
				),
			},
			{
				ResourceName:      "runr_environment.staging",
				ImportState:       true,
				ImportStateId:     "app/staging",
				ImportStateVerify: true,
			},
			{
				// In place: the overrides are sent as a whole, the warmer and the strategy stay
				Config: full("false", 1),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_environment.staging", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					e, _ := srv.Environment("app", "staging")
					if e.RequiresApproval || *e.Overrides.MinScale != 1 || *e.Overrides.CPU != "250m" || len(e.ScaleSchedule) != 2 ||
						e.DeployStrategy != runr.StrategyCanary {
						return fmt.Errorf("after the update = %+v", e)
					}
					return nil
				}),
			},
			{
				// Changed outside Terraform: planned again
				PreConfig: func() {
					c := client(t, srv)
					p, _ := c.FindProject(t.Context(), "app")
					yes, two := true, 2
					if _, err := c.UpdateEnvironment(t.Context(), p.ID, "staging", runr.EnvironmentInput{RequiresApproval: &yes,
						Overrides: &runr.EnvironmentOverrides{MinScale: &two}}); err != nil {
						t.Fatal(err)
					}
				},
				Config: full("false", 1),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_environment.staging", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					e, _ := srv.Environment("app", "staging")
					if e.RequiresApproval || *e.Overrides.MinScale != 1 || e.Overrides.EnvVars["DEBUG"] == nil || len(e.ScaleSchedule) != 2 {
						return fmt.Errorf("after the drift = %+v", e)
					}
					return nil
				}),
			},
			{
				// Left out: everything stays as it is
				Config: stagingConfig(srv, "staging", ""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectEmptyPlan(),
				}},
				Check: check(func() error {
					e, _ := srv.Environment("app", "staging")
					if e.GitBranch != "develop" || *e.Overrides.MinScale != 1 || len(e.ScaleSchedule) != 2 || e.ScaleScheduleInherited ||
						e.DeployStrategy != runr.StrategyCanary {
						return fmt.Errorf("left out = %+v", e)
					}
					return nil
				}),
			},
			{
				// The overrides as a whole: {} overrides nothing; "" maps no branch
				Config: stagingConfig(srv, "staging", `
  git_branch = ""
  overrides  = {}
`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_environment.staging", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					e, _ := srv.Environment("app", "staging")
					if e.GitBranch != "" || !e.Overrides.Empty() || len(e.ScaleSchedule) != 2 || e.ScaleScheduleInherited {
						return fmt.Errorf("cleared = %+v", e)
					}
					return nil
				}),
			},
			{
				// Deleted outside Terraform: created again
				PreConfig: func() {
					c := client(t, srv)
					p, _ := c.FindProject(t.Context(), "app")
					if _, err := c.DeleteEnvironment(t.Context(), p.ID, "staging"); err != nil {
						t.Fatal(err)
					}
				},
				Config: stagingConfig(srv, "staging", `  overrides = {}`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_environment.staging", plancheck.ResourceActionCreate),
				}},
			},
			{
				// A new name is a new environment
				Config: stagingConfig(srv, "qa", ""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_environment.staging", plancheck.ResourceActionReplace),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					environmentDeleted(srv, "app", "staging"),
					resource.TestCheckResourceAttr("runr_environment.staging", "workload_name", "app-qa"),
				),
			},
		},
	})
}

// scale_schedule_inherited and deploy_strategy_inherited = true drop the environment's own
// schedule and strategy: it follows the project's again.
func TestEnvironmentFollowsTheProjectAgain(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: stagingConfig(srv, "staging", `
  scale_schedule  = []
  deploy_strategy = { strategy = "blue_green" }
`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_environment.staging", "scale_schedule.#", "0"),
					resource.TestCheckResourceAttr("runr_environment.staging", "scale_schedule_inherited", "false"),
					resource.TestCheckResourceAttr("runr_environment.staging", "deploy_strategy.strategy", "blue_green"),
					resource.TestCheckResourceAttr("runr_environment.staging", "deploy_strategy_inherited", "false"),
				),
			},
			{
				// The project's settings change: the environment keeps its own strategy
				PreConfig: func() {
					c := client(t, srv)
					p, _ := c.FindProject(t.Context(), "app")
					if _, err := c.UpdateDeployStrategy(t.Context(), p.ID, runr.DeployStrategyInput{Strategy: runr.StrategyCanary}); err != nil {
						t.Fatal(err)
					}
				},
				Config: stagingConfig(srv, "staging", `
  scale_schedule  = []
  deploy_strategy = { strategy = "blue_green" }
`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
			},
			{
				Config: stagingConfig(srv, "staging", `
  scale_schedule_inherited  = true
  deploy_strategy_inherited = true
`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_environment.staging", plancheck.ResourceActionUpdate),
					plancheck.ExpectUnknownValue("runr_environment.staging", tfjsonpath.New("deploy_strategy")),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_environment.staging", "scale_schedule_inherited", "true"),
					resource.TestCheckResourceAttr("runr_environment.staging", "deploy_strategy_inherited", "true"),
					resource.TestCheckResourceAttr("runr_environment.staging", "deploy_strategy.strategy", "canary"),
					check(func() error {
						e, _ := srv.Environment("app", "staging")
						if !e.ScaleScheduleInherited || e.DeployStrategy != runr.StrategyCanary {
							return fmt.Errorf("environment = %+v", e)
						}
						return nil
					}),
				),
			},
			{
				Config: stagingConfig(srv, "staging", `
  scale_schedule_inherited  = true
  deploy_strategy_inherited = true
`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
			},
		},
	})
}

// production exists with the project: the resource adopts it for its approval and position and
// sets them back to production's defaults when it is destroyed.
func TestEnvironmentProduction(t *testing.T) {
	srv := fake(t)
	production := func(body string) string {
		return providerBlock(srv) + appProject + `
resource "runr_environment" "production" {
  project_id = runr_project.app.id
  name       = "production"
` + body + `
}
`
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: production(`  requires_approval = false`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_environment.production", "requires_approval", "false"),
					resource.TestCheckResourceAttr("runr_environment.production", "workload_name", "app"),
					resource.TestCheckResourceAttr("runr_environment.production", "position", "1000"),
					resource.TestCheckResourceAttr("runr_environment.production", "scale_schedule_inherited", "true"),
					resource.TestCheckResourceAttr("runr_environment.production", "deploy_strategy_inherited", "true"),
					resource.TestCheckNoResourceAttr("runr_environment.production", "deploy_strategy.strategy"),
					check(func() error {
						if e, _ := srv.Environment("app", "production"); e.RequiresApproval {
							return fmt.Errorf("production still requires approval")
						}
						return nil
					}),
				),
			},
			{
				ResourceName:      "runr_environment.production",
				ImportState:       true,
				ImportStateId:     "app/production",
				ImportStateVerify: true,
			},
			{
				Config: production(`
  requires_approval = false
  position          = 900
`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_environment.production", plancheck.ResourceActionUpdate),
				}},
				Check: resource.TestCheckResourceAttr("runr_environment.production", "position", "900"),
			},
			{
				Config:      production(`  overrides = { min_scale = 1 }`),
				ExpectError: phrase("overrides does not apply to production"),
			},
			{
				// Destroyed: production stays with the project, with its defaults
				Config: providerBlock(srv) + appProject,
				Check: check(func() error {
					e, ok := srv.Environment("app", "production")
					if !ok || !e.RequiresApproval || e.Position != 1000 {
						return fmt.Errorf("production = %+v", e)
					}
					return nil
				}),
			},
		},
	})
}

// Plan-time refusals: the API's name and branch rules, a variable set and removed, warm rows above
// the environment's max_scale, the _inherited flags against their settings, a strategy the
// project's trigger cannot use.
func TestEnvironmentValidation(t *testing.T) {
	srv := fake(t)
	srv.SeedProject("cron", runr.TypeFaaS, "python", "eu-1", map[string]any{"trigger_type": runr.TriggerSchedule, "schedule": "*/5 * * * *"})
	cases := map[string]string{
		stagingConfig(srv, "Staging", ""):                                            "Invalid environment name",
		stagingConfig(srv, "trigger", ""):                                            "Reserved environment name",
		stagingConfig(srv, "pr-12", ""):                                              "Reserved environment name",
		stagingConfig(srv, "staging", `  git_branch = "refs/heads/main"`):            "Branch given as a ref",
		stagingConfig(srv, "staging", `  overrides = { cpu = "0m" }`):                "Invalid CPU quantity",
		stagingConfig(srv, "staging", `  overrides = { schedule = "@often" }`):       "five-field cron",
		stagingConfig(srv, "staging", `  overrides = { env_vars = { "1A" = "x" } }`): "Invalid variable name",
		stagingConfig(srv, "staging", `
  overrides = {
    env_vars        = { A = "1" }
    remove_env_vars = ["A"]
  }`): "Variable set and removed",
		stagingConfig(srv, "staging", `
  overrides      = { max_scale = 2 }
  scale_schedule = [{ cron = "0 9 * * *", min_scale = 3 }]`): "above overrides.max_scale",
		stagingConfig(srv, "staging", `
  scale_schedule           = []
  scale_schedule_inherited = true`): "scale_schedule_inherited conflicts with scale_schedule",
		stagingConfig(srv, "staging", `  deploy_strategy_inherited = false`): "deploy_strategy_inherited = false needs deploy_strategy",
		providerBlock(srv) + `
data "runr_project" "cron" {
  name = "cron"
}
resource "runr_environment" "staging" {
  project_id      = data.runr_project.cron.id
  name            = "staging"
  deploy_strategy = { strategy = "canary" }
}
`: "strategy needs an http or workflow trigger",
	}
	var steps []resource.TestStep
	for cfg, want := range cases {
		steps = append(steps, resource.TestStep{Config: cfg, PlanOnly: true, ExpectError: phrase(want)})
	}
	resource.UnitTest(t, resource.TestCase{ProtoV6ProviderFactories: providerFactories(), Steps: steps})
}

// Another region while nothing runs there; RunR's refusal once a deployment runs is reported.
func TestEnvironmentRegion(t *testing.T) {
	srv := fake(t)
	srv.AddRegion("us-1", true)
	regions := `
data "runr_region" "us" {
  name = "us-1"
}
`
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: stagingConfig(srv, "staging", "") + regions,
				Check:  resource.TestCheckResourceAttr("runr_environment.staging", "own_region", "false"),
			},
			{
				Config: stagingConfig(srv, "staging", `  region_id = data.runr_region.us.id`) + regions,
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_environment.staging", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_environment.staging", "own_region", "true"),
					resource.TestCheckResourceAttr("runr_environment.staging", "url", "https://app-staging.us-1.runr.test"),
					resource.TestCheckResourceAttrPair("runr_environment.staging", "region_id", "data.runr_region.us", "id"),
				),
			},
			{
				PreConfig: func() {
					v := srv.SeedFiles("app", map[string]string{"main.py": "def handler(e, c): pass\n"})
					srv.SeedEnvironmentDeployment("app", "staging", v.Number)
				},
				Config:      stagingConfig(srv, "staging", `  region_id = data.runr_region.eu.id`) + regions,
				ExpectError: regexp.MustCompile(`runs\s+a\s+deployment`),
			},
		},
	})
}

// A token without env.view reads the environment's variables masked: the configured ones are
// kept, and a change of other overrides keeps them on the server.
func TestEnvironmentHiddenVariables(t *testing.T) {
	srv := fake(t)
	scoped := srv.ScopedToken("no-env-view", "projects.view", "projects.update", "regions.view", "environments.manage")
	withToken := func(token, minScale string) string {
		return fmt.Sprintf(`
provider "runr" {
  api_url       = %q
  token         = %q
  poll_interval = "5ms"
}

data "runr_region" "eu" {
  name = "eu-1"
}
`, srv.URL, token) + appProject + `
resource "runr_environment" "staging" {
  project_id = runr_project.app.id
  name       = "staging"
  overrides = {
    env_vars  = { DEBUG = "1" }
    min_scale = ` + minScale + `
  }
}
`
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{Config: withToken(runrtest.DefaultToken, "0")},
			{
				Config:           withToken(scoped, "0"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
			},
			{
				Config: withToken(scoped, "1"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_environment.staging", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					e, _ := srv.Environment("app", "staging")
					if *e.Overrides.MinScale != 1 || e.Overrides.EnvVars["DEBUG"] == nil || *e.Overrides.EnvVars["DEBUG"] != "1" {
						return fmt.Errorf("overrides = %+v", e.Overrides)
					}
					return nil
				}),
			},
			{Config: withToken(runrtest.DefaultToken, "1")},
		},
	})
}
