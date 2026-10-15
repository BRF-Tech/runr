package provider

import (
	"fmt"
	"regexp"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"
	"github.com/hashicorp/terraform-plugin-testing/tfversion"

	"github.com/runrsh/core/cli/pkg/runr"
)

func secretEnvConfig(srvBlock, value string) string {
	return srvBlock + appProject + fmt.Sprintf(`
resource "runr_env_var" "token" {
  project_id = runr_project.app.id
  name       = "API_TOKEN"
  value      = %q
  secret     = true
}
`, value)
}

// A secret variable is written through the secrets API, never into the project config, and
// Terraform notices when it is removed or turned plain outside Terraform.
func TestEnvVarSecretLifecycle(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(*terraform.State) error {
			if len(srv.Secrets("app")) != 0 {
				return fmt.Errorf("secrets left: %v", srv.Secrets("app"))
			}
			return nil
		},
		Steps: []resource.TestStep{
			{
				Config: secretEnvConfig(providerBlock(srv), "first"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_env_var.token", "secret", "true"),
					check(func() error {
						p, _ := srv.Project("app")
						if _, plain := p.Config.EnvVars["API_TOKEN"]; plain || srv.Secrets("app")["API_TOKEN"] != "first" {
							return fmt.Errorf("env %v secrets %v", p.Config.EnvVars, srv.Secrets("app"))
						}
						if n := len(srv.CallsTo("PUT /projects/{project}/secrets/{name}")); n != 1 {
							return fmt.Errorf("%d secret writes", n)
						}
						for _, c := range srv.CallsTo("PUT /projects/{project}") {
							if cfg, ok := c.Body["config"].(map[string]any); ok && cfg["secret_env_vars"] != nil {
								return fmt.Errorf("a secret-only change went through the project config: %v", cfg)
							}
						}
						return nil
					}),
				),
			},
			{
				// A new value is written again (the API cannot compare it).
				Config: secretEnvConfig(providerBlock(srv), "second"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_env_var.token", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					if srv.Secrets("app")["API_TOKEN"] != "second" {
						return fmt.Errorf("secrets %v", srv.Secrets("app"))
					}
					return nil
				}),
			},
			{
				// Removed outside Terraform: created again.
				PreConfig: func() {
					c := client(t, srv)
					p, _ := c.FindProject(t.Context(), "app")
					if err := c.DeleteSecret(t.Context(), p.ID, "API_TOKEN"); err != nil {
						t.Fatal(err)
					}
				},
				Config: secretEnvConfig(providerBlock(srv), "second"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_env_var.token", plancheck.ResourceActionCreate),
				}},
			},
			{
				// Turned into a plain variable outside Terraform: replaced by the secret again.
				PreConfig: func() {
					c := client(t, srv)
					p, _ := c.FindProject(t.Context(), "app")
					if _, err := c.ApplyEnv(t.Context(), p, runr.EnvChange{Set: map[string]string{"API_TOKEN": "plain"}}, "outside"); err != nil {
						t.Fatal(err)
					}
				},
				Config: secretEnvConfig(providerBlock(srv), "second"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_env_var.token", plancheck.ResourceActionReplace),
				}},
				Check: check(func() error {
					p, _ := srv.Project("app")
					if _, plain := p.Config.EnvVars["API_TOKEN"]; plain || srv.Secrets("app")["API_TOKEN"] != "second" {
						return fmt.Errorf("env %v secrets %v", p.Config.EnvVars, srv.Secrets("app"))
					}
					return nil
				}),
			},
			{
				// Import: secret = true, the value stays out of the state (the API never returns it).
				ResourceName:            "runr_env_var.token",
				ImportState:             true,
				ImportStateId:           "app/API_TOKEN",
				ImportStateVerify:       true,
				ImportStateVerifyIgnore: []string{"value"},
				ImportStateCheck: func(states []*terraform.InstanceState) error {
					if len(states) != 1 || states[0].Attributes["secret"] != "true" || states[0].Attributes["value"] != "" {
						return fmt.Errorf("imported = %+v", states)
					}
					return nil
				},
			},
		},
	})
}

// value_wo keeps the secret out of the state as well; value_wo_version decides when it is
// written again.
func TestEnvVarSecretWriteOnlyValue(t *testing.T) {
	srv := fake(t)
	cfg := func(value, version string) string {
		return providerBlock(srv) + appProject + fmt.Sprintf(`
resource "runr_env_var" "key" {
  project_id       = runr_project.app.id
  name             = "SIGNING_KEY"
  value_wo         = %q
  value_wo_version = %q
  secret           = true
}
`, value, version)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		TerraformVersionChecks:   []tfversion.TerraformVersionCheck{tfversion.SkipBelow(tfversion.Version1_11_0)},
		Steps: []resource.TestStep{
			{
				Config: cfg("k1", "1"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckNoResourceAttr("runr_env_var.key", "value"),
					resource.TestCheckNoResourceAttr("runr_env_var.key", "value_wo"),
					resource.TestCheckResourceAttr("runr_env_var.key", "value_wo_version", "1"),
					check(func() error {
						if srv.Secrets("app")["SIGNING_KEY"] != "k1" {
							return fmt.Errorf("secrets %v", srv.Secrets("app"))
						}
						return nil
					}),
				),
			},
			{
				// Same version: Terraform cannot see the new value, nothing is written.
				Config: cfg("k2", "1"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_env_var.key", plancheck.ResourceActionNoop),
				}},
				Check: check(func() error {
					if srv.Secrets("app")["SIGNING_KEY"] != "k1" {
						return fmt.Errorf("secrets %v", srv.Secrets("app"))
					}
					return nil
				}),
			},
			{
				Config: cfg("k2", "2"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_env_var.key", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					if srv.Secrets("app")["SIGNING_KEY"] != "k2" {
						return fmt.Errorf("secrets %v", srv.Secrets("app"))
					}
					return nil
				}),
			},
		},
	})
}

func TestEnvVarValueRules(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		TerraformVersionChecks:   []tfversion.TerraformVersionCheck{tfversion.SkipBelow(tfversion.Version1_11_0)},
		Steps: []resource.TestStep{
			{
				Config: providerBlock(srv) + appProject + `
resource "runr_env_var" "none" {
  project_id = runr_project.app.id
  name       = "NONE"
}
`,
				ExpectError: regexp.MustCompile(`(?s)value.*value_wo`),
			},
			{
				Config: providerBlock(srv) + appProject + `
resource "runr_env_var" "plain_wo" {
  project_id = runr_project.app.id
  name       = "PLAIN"
  value_wo   = "x"
}
`,
				ExpectError: phrase("value_wo needs secret = true"),
			},
		},
	})
}
