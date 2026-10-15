package provider

import (
	"fmt"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

const appProject = `
resource "runr_project" "app" {
  name      = "app"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id
}
`

func updateEnv(env map[string]string) runr.UpdateProjectInput {
	return runr.UpdateProjectInput{Config: &runr.ConfigInput{EnvVars: &env}}
}

func check(f func() error) resource.TestCheckFunc {
	return func(*terraform.State) error { return f() }
}

func TestEnvVars(t *testing.T) {
	srv := fake(t)
	srv.SecretEnv = true
	cfg := func(a string) string {
		return providerBlock(srv) + appProject + fmt.Sprintf(`
resource "runr_env_var" "a" {
  project_id = runr_project.app.id
  name       = "A"
  value      = %q
}
resource "runr_env_var" "b" {
  project_id = runr_project.app.id
  name       = "B"
  value      = "2"
}
resource "runr_env_var" "token" {
  project_id = runr_project.app.id
  name       = "API_TOKEN"
  value      = "s3cr3t"
  secret     = true
}
`, a)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "app"),
		Steps: []resource.TestStep{
			{
				Config: cfg("1"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttrPair("runr_env_var.a", "project_id", "runr_project.app", "id"),
					resource.TestCheckResourceAttr("runr_env_var.a", "secret", "false"),
					check(func() error {
						p, _ := srv.Project("app")
						if p.Config.EnvVars["A"] != "1" || p.Config.EnvVars["B"] != "2" {
							return fmt.Errorf("env = %v (parallel updates lost a variable?)", p.Config.EnvVars)
						}
						if _, plain := p.Config.EnvVars["API_TOKEN"]; plain || srv.Secrets("app")["API_TOKEN"] != "s3cr3t" {
							return fmt.Errorf("secret stored as %v / %v", p.Config.EnvVars, srv.Secrets("app"))
						}
						return nil
					}),
				),
			},
			{
				Config: cfg("10"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_env_var.a", plancheck.ResourceActionUpdate),
					plancheck.ExpectResourceAction("runr_env_var.token", plancheck.ResourceActionNoop),
				}},
				Check: check(func() error {
					p, _ := srv.Project("app")
					if p.Config.EnvVars["A"] != "10" || p.Config.EnvVars["B"] != "2" {
						return fmt.Errorf("env = %v", p.Config.EnvVars)
					}
					return nil
				}),
			},
			{
				ResourceName:      "runr_env_var.a",
				ImportState:       true,
				ImportStateId:     "app/A",
				ImportStateVerify: true,
			},
			{
				// Removed outside Terraform: planned again.
				PreConfig: func() {
					c := client(t, srv)
					p, _ := c.FindProject(t.Context(), "app")
					if _, err := c.UpdateProject(t.Context(), p.ID, updateEnv(map[string]string{"A": "10"})); err != nil {
						t.Fatal(err)
					}
				},
				Config: cfg("10"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_env_var.b", plancheck.ResourceActionCreate),
				}},
			},
			{
				Config: providerBlock(srv) + appProject,
				Check: check(func() error {
					p, _ := srv.Project("app")
					if len(p.Config.EnvVars) != 0 || len(srv.Secrets("app")) != 0 {
						return fmt.Errorf("variables left after destroy: %v %v", p.Config.EnvVars, srv.Secrets("app"))
					}
					return nil
				}),
			},
		},
	})
}

func TestEnvVarSecretWithoutAPISupportWarns(t *testing.T) {
	srv := fake(t)
	srv.SecretEnv = false // an API without secret variables
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{{
			Config: providerBlock(srv) + appProject + `
resource "runr_env_var" "token" {
  project_id = runr_project.app.id
  name       = "API_TOKEN"
  value      = "s3cr3t"
  secret     = true
}
`,
			Check: check(func() error {
				p, _ := srv.Project("app")
				if p.Config.EnvVars["API_TOKEN"] != "s3cr3t" {
					return fmt.Errorf("env = %v", p.Config.EnvVars)
				}
				return nil
			}),
		}},
	})
}

func TestEnvVarRejectsReservedName(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{{
			Config: providerBlock(srv) + appProject + `
resource "runr_env_var" "port" {
  project_id = runr_project.app.id
  name       = "PORT"
  value      = "9000"
}
`,
			ExpectError: phrase("set by RunR"),
		}},
	})
}

func TestDomains(t *testing.T) {
	srv := fake(t)
	cfg := func(mode string) string {
		return providerBlock(srv) + appProject + fmt.Sprintf(`
resource "runr_domain" "api" {
  project_id = runr_project.app.id
  domain     = "api.example.com"
  tls_mode   = %q
}
`, mode)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "app"),
		Steps: []resource.TestStep{
			{
				Config: cfg("none"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_domain.api", "status", "pending"),
					resource.TestCheckResourceAttr("runr_domain.api", "verification_type", "TXT"),
					resource.TestCheckResourceAttr("runr_domain.api", "verification_name", "_runr-challenge.api.example.com"),
					resource.TestCheckResourceAttr("runr_domain.api", "dns_targets.0.type", "CNAME"),
					resource.TestCheckResourceAttr("runr_domain.api", "url", "http://api.example.com"),
				),
			},
			{
				Config: cfg("cert-manager"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_domain.api", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_domain.api", "tls_mode", "cert-manager"),
					resource.TestCheckResourceAttr("runr_domain.api", "url", "https://api.example.com"),
				),
			},
			{
				ResourceName:      "runr_domain.api",
				ImportState:       true,
				ImportStateId:     "app/api.example.com",
				ImportStateVerify: true,
			},
			{
				PreConfig: func() { srv.VerifyDomains = true },
				Config: cfg("cert-manager") + `
resource "runr_domain" "www" {
  project_id = runr_project.app.id
  domain     = "www.example.com"
  verify     = true
}
`,
				Check: resource.TestCheckResourceAttr("runr_domain.www", "status", "active"),
			},
		},
	})
}

func TestDomainValidation(t *testing.T) {
	srv := fake(t)
	for body, want := range map[string]string{
		`domain = "API.example.com"`: "lower-case host name",
		`domain = "api.example.com"
  tls_mode = "custom"`: "certificate and private_key required",
		`domain = "api.example.com"
  certificate = "x"`: "certificate given without tls_mode custom",
		`domain = "api.example.com"
  verify_timeout = "soon"`: "Invalid duration",
	} {
		resource.UnitTest(t, resource.TestCase{
			ProtoV6ProviderFactories: providerFactories(),
			Steps: []resource.TestStep{{
				Config: providerBlock(srv) + appProject + `
resource "runr_domain" "bad" {
  project_id = runr_project.app.id
  ` + body + `
}
`,
				ExpectError: phrase(want),
			}},
		})
	}
}

func TestDeploymentOfCodeVersions(t *testing.T) {
	srv := fake(t)
	files := func(n int) map[string]string {
		return map[string]string{"main.py": fmt.Sprintf("def handler(event, context):\n    return %d\n", n)}
	}
	deployment := func(triggers string) string {
		return providerBlock(srv) + appProject + `
resource "runr_deployment" "app" {
  project_id = runr_project.app.id
  ` + triggers + `
}
`
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "app"),
		Steps: []resource.TestStep{
			{Config: providerBlock(srv) + appProject},
			{
				PreConfig: func() { srv.SeedFiles("app", files(1)) },
				Config:    deployment(""),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_deployment.app", "status", "active"),
					resource.TestCheckResourceAttr("runr_deployment.app", "version_label", "v1"),
					resource.TestCheckResourceAttr("runr_deployment.app", "url", "https://app.eu-1.runr.test"),
					resource.TestCheckResourceAttrSet("runr_deployment.app", "ready_at"),
				),
			},
			{
				// New code uploaded (runr deploy in CI); a trigger change deploys the latest version.
				PreConfig: func() { srv.SeedFiles("app", files(2)) },
				Config:    deployment(`triggers = { revision = "2" }`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_deployment.app", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_deployment.app", "version_label", "v2"),
					check(func() error {
						deps := srv.Deployments("app")
						if len(deps) != 2 || deps[0].Status != runr.DeploymentActive || deps[1].Status != "shut_down" {
							return fmt.Errorf("deployments = %+v", deps)
						}
						return nil
					}),
				),
			},
			{
				ResourceName:            "runr_deployment.app",
				ImportState:             true,
				ImportStateId:           "app",
				ImportStateVerify:       true,
				ImportStateVerifyIgnore: []string{"triggers", "url"},
			},
			{
				Config: providerBlock(srv) + appProject,
				Check: check(func() error {
					if n := len(srv.Deployments("app")); n != 1 {
						return fmt.Errorf("%d deployments after destroying runr_deployment, want the shut-down one only", n)
					}
					return nil
				}),
			},
		},
	})
}

func TestDeploymentWithoutVersionFails(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{{
			Config: providerBlock(srv) + appProject + `
resource "runr_deployment" "app" {
  project_id = runr_project.app.id
}
`,
			ExpectError: phrase("has no version yet"),
		}},
	})
}

func TestDeploymentOfImages(t *testing.T) {
	srv := fake(t)
	cfg := func(image string) string {
		return providerBlock(srv) + fmt.Sprintf(`
resource "runr_project" "web" {
  name      = "web"
  type      = "caas"
  region_id = data.runr_region.eu.id
  image     = "nginx:1.26"

  # runr_deployment deploys the images from here on
  lifecycle {
    ignore_changes = [image]
  }
}

resource "runr_deployment" "web" {
  project_id = runr_project.web.id
  image      = %q
}
`, image)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "web"),
		Steps: []resource.TestStep{
			{
				Config: cfg("nginx:1.27"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_deployment.web", "status", "active"),
					resource.TestCheckResourceAttr("runr_deployment.web", "version_label", "v2"),
					check(func() error {
						if p, _ := srv.Project("web"); p.Config.Image != "nginx:1.27" {
							return fmt.Errorf("image = %s", p.Config.Image)
						}
						return nil
					}),
				),
			},
			{
				Config: cfg("nginx:1.28"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_deployment.web", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_deployment.web", "version_label", "v3"),
					check(func() error {
						if p, _ := srv.Project("web"); p.Config.Image != "nginx:1.28" {
							return fmt.Errorf("image = %s", p.Config.Image)
						}
						return nil
					}),
				),
			},
		},
	})
}

func TestDataSources(t *testing.T) {
	srv := fake(t)
	srv.AddRegion("us-1", false)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: providerBlock(srv) + appProject + `
data "runr_region" "only_active" {}

data "runr_region" "by_id" {
  id = data.runr_region.eu.id
}

data "runr_project" "app" {
  name = runr_project.app.name
}
`,
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("data.runr_region.only_active", "name", "eu-1"),
					resource.TestCheckResourceAttr("data.runr_region.eu", "status", "active"),
					resource.TestCheckResourceAttr("data.runr_region.eu", "has_redis", "true"),
					resource.TestCheckResourceAttr("data.runr_region.by_id", "name", "eu-1"),
					resource.TestCheckResourceAttrPair("data.runr_project.app", "id", "runr_project.app", "id"),
					resource.TestCheckResourceAttr("data.runr_project.app", "type", "faas"),
					resource.TestCheckResourceAttr("data.runr_project.app", "region_name", "eu-1"),
					resource.TestCheckResourceAttr("data.runr_project.app", "trigger_type", "http"),
					resource.TestCheckResourceAttr("data.runr_project.app", "endpoint_url", "https://app.eu-1.runr.test"),
				),
			},
			{
				Config: providerBlock(srv) + `
data "runr_project" "ghost" {
  name = "ghost"
}
`,
				ExpectError: phrase("not found"),
			},
			{
				Config: providerBlock(srv) + `
data "runr_region" "both" {
  id   = "x"
  name = "y"
}
`,
				ExpectError: phrase("cannot be configured together"),
			},
			{
				// The post-test destroy runs with the last configuration: a valid one.
				Config: providerBlock(srv),
			},
		},
	})
}
