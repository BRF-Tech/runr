package provider

import (
	"fmt"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func faasProject(srv *runrtest.Server, runtime string, memory, max int) string {
	return providerBlock(srv) + fmt.Sprintf(`
resource "runr_project" "hello" {
  name      = "hello"
  type      = "faas"
  runtime   = %q
  region_id = data.runr_region.eu.id
  handler   = "main.handler"

  resources = {
    memory_mb = %d
  }
  scale = {
    min = 0
    max = %d
  }
  trigger = {
    type = "http"
  }
}
`, runtime, memory, max)
}

func serverProject(srv *runrtest.Server, name string, check func(p map[string]any) error) resource.TestCheckFunc {
	return func(*terraform.State) error {
		p, ok := srv.Project(name)
		if !ok {
			return fmt.Errorf("project %s not on the API", name)
		}
		c := p.Config
		m := map[string]any{"image": c.Image, "handler": c.Handler, "trigger": c.Trigger()}
		if c.MemoryMB != nil {
			m["memory_mb"] = *c.MemoryMB
		}
		if c.MaxScale != nil {
			m["max_scale"] = *c.MaxScale
		}
		m["command"] = len(c.Command)
		m["version"] = 0
		if p.LatestVersion != nil {
			m["version"] = p.LatestVersion.Number
		}
		return check(m)
	}
}

func TestProjectFaasLifecycle(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "hello"),
		Steps: []resource.TestStep{
			{
				Config: faasProject(srv, "python", 256, 3),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttrSet("runr_project.hello", "id"),
					resource.TestCheckResourceAttr("runr_project.hello", "slug", "hello"),
					resource.TestCheckResourceAttr("runr_project.hello", "runtime_version", "3.13"),
					resource.TestCheckResourceAttr("runr_project.hello", "resources.memory_mb", "256"),
					resource.TestCheckResourceAttr("runr_project.hello", "resources.cpu", "100m"),
					resource.TestCheckResourceAttr("runr_project.hello", "scale.max", "3"),
					resource.TestCheckResourceAttr("runr_project.hello", "scale.to_zero", "true"),
					resource.TestCheckResourceAttr("runr_project.hello", "trigger.type", "http"),
					resource.TestCheckResourceAttr("runr_project.hello", "endpoint_url", "https://hello.eu-1.runr.test"),
					resource.TestCheckNoResourceAttr("runr_project.hello", "image"),
					serverProject(srv, "hello", func(p map[string]any) error {
						if p["memory_mb"] != 256 || p["max_scale"] != 3 {
							return fmt.Errorf("server config %v", p)
						}
						return nil
					}),
				),
			},
			{
				Config: faasProject(srv, "python", 512, 5),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_project.hello", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_project.hello", "resources.memory_mb", "512"),
					resource.TestCheckResourceAttrSet("runr_project.hello", "latest_version_id"),
					serverProject(srv, "hello", func(p map[string]any) error {
						if p["memory_mb"] != 512 || p["max_scale"] != 5 || p["version"] != 1 {
							return fmt.Errorf("server config after update %v", p)
						}
						return nil
					}),
				),
			},
			{
				ResourceName:      "runr_project.hello",
				ImportState:       true,
				ImportStateId:     "hello",
				ImportStateVerify: true,
			},
			{
				Config: faasProject(srv, "nodejs", 512, 5),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_project.hello", plancheck.ResourceActionReplace),
				}},
				Check: resource.TestCheckResourceAttr("runr_project.hello", "runtime", "nodejs"),
			},
		},
	})
}

func TestProjectCaas(t *testing.T) {
	srv := fake(t)
	config := func(image, command string) string {
		return providerBlock(srv) + fmt.Sprintf(`
resource "runr_project" "web" {
  name      = "web"
  type      = "caas"
  region_id = data.runr_region.eu.id
  image     = %q
  ports     = [8080]
  %s
  args      = ["-g", "daemon off;"]
  description = "web front end"
}
`, image, command)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "web"),
		Steps: []resource.TestStep{
			{
				Config: config("nginx:1.26", `command = ["nginx"]`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_project.web", "image", "nginx:1.26"),
					resource.TestCheckResourceAttr("runr_project.web", "ports.0", "8080"),
					resource.TestCheckResourceAttr("runr_project.web", "command.0", "nginx"),
					resource.TestCheckResourceAttr("runr_project.web", "args.#", "2"),
					resource.TestCheckResourceAttr("runr_project.web", "description", "web front end"),
					resource.TestCheckNoResourceAttr("runr_project.web", "handler"),
				),
			},
			{
				Config: config("nginx:1.27", ""),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_project.web", "image", "nginx:1.27"),
					resource.TestCheckNoResourceAttr("runr_project.web", "command.#"),
					serverProject(srv, "web", func(p map[string]any) error {
						if p["image"] != "nginx:1.27" || p["command"] != 0 {
							return fmt.Errorf("server config %v", p)
						}
						return nil
					}),
				),
			},
		},
	})
}

func TestProjectValidation(t *testing.T) {
	srv := fake(t)
	cases := map[string]string{
		`type = "faas"`: "runtime required",
		`type = "caas"
  runtime = "python"`: "runtime does not apply to caas",
		`type = "faas"
  runtime = "python"
  image = "nginx"`: "image applies to caas only",
		`type = "lambda"`: "value must be one of",
		`type = "faas"
  runtime = "python"
  resources = { memory_mb = 16 }`: "must be at least 64",
		`type = "faas"
  runtime = "python"
  scale = { min = 20 }`: "must be between 0 and 10",
	}
	for body, want := range cases {
		resource.UnitTest(t, resource.TestCase{
			ProtoV6ProviderFactories: providerFactories(),
			Steps: []resource.TestStep{{
				Config: providerBlock(srv) + `
resource "runr_project" "bad" {
  name      = "bad"
  region_id = data.runr_region.eu.id
  ` + body + `
}
`,
				ExpectError: phrase(want),
			}},
		})
	}
}

func TestProjectRemovedOutsideTerraform(t *testing.T) {
	srv := fake(t)
	cfg := faasProject(srv, "python", 128, 2)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{Config: cfg},
			{
				PreConfig: func() {
					p, _ := srv.Project("hello")
					c := client(t, srv)
					if err := c.DeleteProject(t.Context(), p.ID); err != nil {
						t.Fatal(err)
					}
				},
				Config: cfg,
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_project.hello", plancheck.ResourceActionCreate),
				}},
			},
		},
	})
}
