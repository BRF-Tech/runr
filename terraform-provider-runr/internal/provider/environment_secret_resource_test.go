package provider

import (
	"fmt"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"
	"github.com/hashicorp/terraform-plugin-testing/tfversion"

	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func environmentSecretConfig(srv *runrtest.Server, name, body string) string {
	return providerBlock(srv) + appProject + fmt.Sprintf(`
resource "runr_environment" "staging" {
  project_id = runr_project.app.id
  name       = "staging"
}

resource "runr_environment_secret" "key" {
  project_id  = runr_project.app.id
  environment = runr_environment.staging.name
  name        = %q
%s
}
`, name, body)
}

// An environment's secret variable: written into its region, written again when its value
// changes in the configuration or outside Terraform, created again when it is removed outside
// Terraform, imported without its value, removed with the resource.
func TestEnvironmentSecretResource(t *testing.T) {
	srv := fake(t)
	value := func(v string) string { return fmt.Sprintf("  value = %q", v) }
	setOutside := func(name, v string) func() {
		return func() {
			c := client(t, srv)
			p, _ := c.FindProject(t.Context(), "app")
			if _, err := c.SetEnvironmentSecret(t.Context(), p.ID, "staging", name, v); err != nil {
				t.Fatal(err)
			}
		}
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "app"),
		Steps: []resource.TestStep{
			{
				Config: environmentSecretConfig(srv, "API_KEY", value("first")),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_environment_secret.key", "version", "1"),
					resource.TestCheckResourceAttr("runr_environment_secret.key", "environment", "staging"),
					check(func() error {
						if got := srv.EnvironmentSecrets("app", "staging"); got["API_KEY"] != "first" || len(srv.Secrets("app")) != 0 {
							return fmt.Errorf("environment secrets %v, project secrets %v", got, srv.Secrets("app"))
						}
						return nil
					}),
				),
			},
			{
				Config: environmentSecretConfig(srv, "API_KEY", value("second")),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_environment_secret.key", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_environment_secret.key", "version", "2"),
					check(func() error {
						if got := srv.EnvironmentSecrets("app", "staging")["API_KEY"]; got != "second" {
							return fmt.Errorf("value = %q", got)
						}
						return nil
					}),
				),
			},
			{
				// Unchanged: nothing to write
				Config:           environmentSecretConfig(srv, "API_KEY", value("second")),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
			},
			{
				// Changed outside Terraform (a new version): written again
				PreConfig: setOutside("API_KEY", "outside"),
				Config:    environmentSecretConfig(srv, "API_KEY", value("second")),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_environment_secret.key", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					if got := srv.EnvironmentSecrets("app", "staging")["API_KEY"]; got != "second" {
						return fmt.Errorf("value = %q", got)
					}
					return nil
				}),
			},
			{
				// Removed outside Terraform: created again
				PreConfig: func() {
					c := client(t, srv)
					p, _ := c.FindProject(t.Context(), "app")
					if err := c.DeleteEnvironmentSecret(t.Context(), p.ID, "staging", "API_KEY"); err != nil {
						t.Fatal(err)
					}
				},
				Config: environmentSecretConfig(srv, "API_KEY", value("second")),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_environment_secret.key", plancheck.ResourceActionCreate),
				}},
			},
			{
				ResourceName:            "runr_environment_secret.key",
				ImportState:             true,
				ImportStateId:           "app/staging/API_KEY",
				ImportStateVerify:       true,
				ImportStateVerifyIgnore: []string{"value"},
				ImportStateCheck: func(states []*terraform.InstanceState) error {
					if len(states) != 1 || states[0].Attributes["value"] != "" || states[0].Attributes["environment"] != "staging" {
						return fmt.Errorf("imported = %+v", states)
					}
					return nil
				},
			},
			{
				// Another name: replaced, the old variable removed
				Config: environmentSecretConfig(srv, "TOKEN", value("second")),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_environment_secret.key", plancheck.ResourceActionReplace),
				}},
				Check: check(func() error {
					got := srv.EnvironmentSecrets("app", "staging")
					if _, old := got["API_KEY"]; old || got["TOKEN"] != "second" {
						return fmt.Errorf("secrets = %v", got)
					}
					return nil
				}),
			},
			{
				Config: providerBlock(srv) + appProject + `
resource "runr_environment" "staging" {
  project_id = runr_project.app.id
  name       = "staging"
}
`,
				Check: check(func() error {
					if got := srv.EnvironmentSecrets("app", "staging"); len(got) != 0 {
						return fmt.Errorf("secrets left: %v", got)
					}
					return nil
				}),
			},
		},
	})
}

// value_wo keeps the value out of the state; value_wo_version decides when it is written again.
func TestEnvironmentSecretWriteOnly(t *testing.T) {
	srv := fake(t)
	cfg := func(value, version string) string {
		return environmentSecretConfig(srv, "SIGNING_KEY", fmt.Sprintf(`
  value_wo         = %q
  value_wo_version = %q`, value, version))
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		TerraformVersionChecks:   []tfversion.TerraformVersionCheck{tfversion.SkipBelow(tfversion.Version1_11_0)},
		Steps: []resource.TestStep{
			{
				Config: cfg("k1", "1"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckNoResourceAttr("runr_environment_secret.key", "value"),
					resource.TestCheckNoResourceAttr("runr_environment_secret.key", "value_wo"),
					check(func() error {
						if got := srv.EnvironmentSecrets("app", "staging")["SIGNING_KEY"]; got != "k1" {
							return fmt.Errorf("value = %q", got)
						}
						return nil
					}),
				),
			},
			{
				Config:           cfg("k2", "1"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
			},
			{
				Config: cfg("k2", "2"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_environment_secret.key", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					if got := srv.EnvironmentSecrets("app", "staging")["SIGNING_KEY"]; got != "k2" {
						return fmt.Errorf("value = %q", got)
					}
					return nil
				}),
			},
			{
				// Changed outside Terraform: the configured write-only value is written again
				PreConfig: func() {
					c := client(t, srv)
					p, _ := c.FindProject(t.Context(), "app")
					if _, err := c.SetEnvironmentSecret(t.Context(), p.ID, "staging", "SIGNING_KEY", "outside"); err != nil {
						t.Fatal(err)
					}
				},
				Config: cfg("k2", "2"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_environment_secret.key", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					if got := srv.EnvironmentSecrets("app", "staging")["SIGNING_KEY"]; got != "k2" {
						return fmt.Errorf("value = %q", got)
					}
					return nil
				}),
			},
		},
	})
}

func TestEnvironmentSecretRules(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: providerBlock(srv) + appProject + `
resource "runr_environment_secret" "key" {
  project_id  = runr_project.app.id
  environment = "production"
  name        = "API_KEY"
  value       = "x"
}
`,
				PlanOnly:    true,
				ExpectError: phrase("use runr_env_var with secret = true"),
			},
			{
				Config:      environmentSecretConfig(srv, "API_KEY", ""),
				PlanOnly:    true,
				ExpectError: phrase("value_wo"),
			},
			{
				Config:      environmentSecretConfig(srv, "1KEY", `  value = "x"`),
				PlanOnly:    true,
				ExpectError: phrase("Invalid variable name"),
			},
		},
	})
}
