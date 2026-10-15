package provider

import (
	"fmt"
	"regexp"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func mcpServerConfig(attrs string) string {
	return appProject + fmt.Sprintf(`
resource "runr_mcp_server" "app" {
  project_id = runr_project.app.id
%s
}
`, attrs)
}

func TestMCPServerResource(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(s *terraform.State) error {
			if _, ok := srv.MCPServerOf("app"); ok {
				return fmt.Errorf("the MCP server still exists")
			}
			return projectGone(srv, "app")(s)
		},
		Steps: []resource.TestStep{
			{
				Config: providerBlock(srv) + mcpServerConfig(`  access_mode = "oauth"
  audit_calls = true`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_mcp_server.app", "access_mode", "oauth"),
					resource.TestCheckResourceAttr("runr_mcp_server.app", "path", "/mcp"),
					resource.TestCheckResourceAttr("runr_mcp_server.app", "handle", "app"),
					resource.TestCheckResourceAttr("runr_mcp_server.app", "url", srv.URL+"/mcp/servers/app"),
					resource.TestCheckResourceAttrSet("runr_mcp_server.app", "resource_metadata"),
					resource.TestCheckResourceAttrPair("runr_mcp_server.app", "project_id", "runr_project.app", "id"),
					check(func() error {
						s, ok := srv.MCPServerOf("app")
						if !ok || !s.AuditCalls || s.AccessMode != runr.MCPAccessOAuth {
							return fmt.Errorf("server = %+v (%v)", s, ok)
						}
						return nil
					}),
				),
			},
			{
				// Access mode and path change in place.
				Config: providerBlock(srv) + mcpServerConfig(`  access_mode = "public"
  path        = "/rpc"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_mcp_server.app", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_mcp_server.app", "path", "/rpc"),
					resource.TestCheckResourceAttr("runr_mcp_server.app", "audit_calls", "false"),
					resource.TestCheckResourceAttr("runr_mcp_server.app", "resource_metadata", ""),
					check(func() error {
						if s, _ := srv.MCPServerOf("app"); s.Path != "/rpc" || s.AccessMode != runr.MCPAccessPublic || s.AuditCalls {
							return fmt.Errorf("server = %+v", s)
						}
						return nil
					}),
				),
			},
			{
				ResourceName:      "runr_mcp_server.app",
				ImportState:       true,
				ImportStateId:     "app",
				ImportStateVerify: true,
			},
			{
				// Removed outside Terraform: served again.
				PreConfig: func() {
					c := client(t, srv)
					p, err := c.FindProject(t.Context(), "app")
					if err != nil {
						t.Fatal(err)
					}
					if err := c.DeleteHostedMCPServer(t.Context(), p.ID); err != nil {
						t.Fatal(err)
					}
				},
				Config: providerBlock(srv) + mcpServerConfig(`  access_mode = "public"
  path        = "/rpc"`),
				Check: check(func() error {
					if _, ok := srv.MCPServerOf("app"); !ok {
						return fmt.Errorf("the server was not created again")
					}
					return nil
				}),
			},
		},
	})
}

func TestMCPServerResourceDefaultsAndChecks(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config:      providerBlock(srv) + mcpServerConfig(`  access_mode = "everyone"`),
				ExpectError: regexp.MustCompile(`access_mode`),
			},
			{
				Config:      providerBlock(srv) + mcpServerConfig(`  path = "rpc"`),
				ExpectError: phrase("must be a URL path starting with /"),
			},
			{
				Config: providerBlock(srv) + mcpServerConfig(""),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_mcp_server.app", "access_mode", "token"),
					resource.TestCheckResourceAttr("runr_mcp_server.app", "audit_calls", "false"),
					resource.TestCheckResourceAttr("runr_mcp_server.app", "path", "/mcp"),
				),
			},
		},
	})
}

func TestMCPServerResourceNeedsTheHTTPTrigger(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{{
			Config: providerBlock(srv) + `
resource "runr_project" "nightly" {
  name      = "nightly"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id
  trigger   = { type = "schedule", schedule = "0 3 * * *" }
}

resource "runr_mcp_server" "nightly" {
  project_id = runr_project.nightly.id
}
`,
			ExpectError: phrase("An MCP server needs the http trigger"),
		}},
	})
}

func TestMCPServerResourceRefusesAnExistingServer(t *testing.T) {
	srv := fake(t)
	srv.SeedProject("weather", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SeedMCPServer("weather", runr.MCPAccessToken)
	p, _ := srv.Project("weather")
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{{
			Config: providerBlock(srv) + fmt.Sprintf(`
resource "runr_mcp_server" "weather" {
  project_id = %q
}
`, p.ID),
			ExpectError: phrase("already served as an MCP server"),
		}},
	})
}
