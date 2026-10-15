package provider

import (
	"fmt"
	"strings"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

// rtTFManifest is an exec runtime on the bash image; id and the extra version vary per step.
func rtTFManifest(id, extraVersion string) string {
	m := `apiVersion: runr.sh/v1
kind: Runtime
id: ` + id + `
name: Bash
extensions: [sh]
versions:
  - version: "5"
    image: bash:5
    default: true
`
	if extraVersion != "" {
		m += `  - version: "` + extraVersion + `"
    image: bash:` + extraVersion + `
`
	}
	return m + `run:
  mode: exec
  input: raw
  command: ["bash", "/app/code/{file}"]
`
}

func rtTFConfig(srv *runrtest.Server, manifest, extra string) string {
	return providerBlock(srv) + fmt.Sprintf(`
resource "runr_runtime" "bash" {
  manifest = <<-EOT
%s
  EOT
%s
}
`, indent(manifest, "    "), extra)
}

func indent(s, prefix string) string {
	lines := strings.Split(strings.TrimRight(s, "\n"), "\n")
	for i, l := range lines {
		lines[i] = prefix + l
	}
	return strings.Join(lines, "\n")
}

func rtGone(srv *runrtest.Server, ids ...string) resource.TestCheckFunc {
	return func(*terraform.State) error {
		for _, id := range ids {
			if _, _, ok := srv.RegisteredRuntime(id); ok {
				return fmt.Errorf("runtime %s still registered", id)
			}
		}
		return nil
	}
}

func TestRuntimeResource(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             rtGone(srv, "bash", "sh"),
		Steps: []resource.TestStep{
			{
				Config: rtTFConfig(srv, rtTFManifest("bash", ""), ""),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_runtime.bash", "id", "bash"),
					resource.TestCheckResourceAttr("runr_runtime.bash", "name", "Bash"),
					resource.TestCheckResourceAttr("runr_runtime.bash", "source", "platform"),
					resource.TestCheckResourceAttr("runr_runtime.bash", "run_mode", "exec"),
					resource.TestCheckResourceAttr("runr_runtime.bash", "enabled", "true"),
					resource.TestCheckResourceAttr("runr_runtime.bash", "versions.#", "1"),
					resource.TestCheckResourceAttr("runr_runtime.bash", "versions.0.image", "bash:5"),
					resource.TestCheckNoResourceAttr("runr_runtime.bash", "regions"),
					check(func() error {
						text, _, ok := srv.RegisteredRuntime("bash")
						if !ok || text != rtTFManifest("bash", "") {
							return fmt.Errorf("registered manifest = %q", text)
						}
						return nil
					}),
				),
			},
			{
				// A new version and disabling change the runtime in place.
				Config: rtTFConfig(srv, rtTFManifest("bash", "5.2"), "  enabled = false"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_runtime.bash", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_runtime.bash", "versions.#", "2"),
					resource.TestCheckResourceAttr("runr_runtime.bash", "versions.1.version", "5.2"),
					resource.TestCheckResourceAttr("runr_runtime.bash", "enabled", "false"),
				),
			},
			{
				ResourceName:      "runr_runtime.bash",
				ImportState:       true,
				ImportStateId:     "bash",
				ImportStateVerify: true,
			},
			{
				// Another id is another runtime.
				Config: rtTFConfig(srv, rtTFManifest("sh", ""), ""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_runtime.bash", plancheck.ResourceActionReplace),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_runtime.bash", "id", "sh"),
					rtGone(srv, "bash"),
				),
			},
		},
	})
}

func TestRuntimeResourceFilesAndRegions(t *testing.T) {
	srv := fake(t)
	manifest := `apiVersion: runr.sh/v1
kind: Runtime
id: q2
name: Q2
extensions: [q]
versions:
  - version: "1.0"
    dockerfile: Dockerfile
run:
  mode: persistent
  command: ["q", "/app/code/{file}"]
`
	cfg := func(extra string) string {
		return providerBlock(srv) + fmt.Sprintf(`
resource "runr_runtime" "q" {
  manifest = <<-EOT
%s
  EOT
  files = {
    Dockerfile = "FROM golang:1.23-alpine\n"
  }
%s
}
`, indent(manifest, "    "), extra)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             rtGone(srv, "q2"),
		Steps: []resource.TestStep{
			{
				Config: cfg("  regions = [data.runr_region.eu.id]"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_runtime.q", "run_mode", "persistent"),
					resource.TestCheckResourceAttr("runr_runtime.q", "versions.0.built", "true"),
					resource.TestCheckNoResourceAttr("runr_runtime.q", "versions.0.image"),
					resource.TestCheckResourceAttr("runr_runtime.q", "files.Dockerfile", "FROM golang:1.23-alpine\n"),
					resource.TestCheckResourceAttrPair("runr_runtime.q", "regions.0", "data.runr_region.eu", "id"),
					check(func() error {
						_, files, _ := srv.RegisteredRuntime("q2")
						if files["Dockerfile"] == "" {
							return fmt.Errorf("files = %v", files)
						}
						return nil
					}),
				),
			},
			{
				// Without regions the runtime is offered everywhere (regions: null).
				Config: cfg(""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_runtime.q", plancheck.ResourceActionUpdate),
				}},
				Check: resource.TestCheckNoResourceAttr("runr_runtime.q", "regions.0"),
			},
		},
	})
}

func TestRuntimeResourceInvalidManifest(t *testing.T) {
	srv := fake(t)
	bad := strings.Replace(rtTFManifest("bash", ""), "  command: [\"bash\", \"/app/code/{file}\"]\n", "", 1)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{{
			Config:      rtTFConfig(srv, bad, ""),
			ExpectError: phrase("/run/command: is required in exec mode"),
		}},
	})
}

func TestRuntimeResourceInUse(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: rtTFConfig(srv, rtTFManifest("bash", ""), ""),
			},
			{
				// A project outside Terraform uses the runtime: deleting it is refused (409).
				PreConfig: func() {
					srv.SeedProject("script", "xaas", "bash", "eu-1", map[string]any{"filename": "main.sh"})
				},
				Config:      providerBlock(srv),
				ExpectError: phrase("projects use this runtime"),
			},
			{
				PreConfig: func() {
					p, _ := srv.Project("script")
					c := client(t, srv)
					if err := c.DeleteProject(t.Context(), p.ID); err != nil {
						t.Fatal(err)
					}
				},
				Config: providerBlock(srv),
				Check:  rtGone(srv, "bash"),
			},
		},
	})
}
