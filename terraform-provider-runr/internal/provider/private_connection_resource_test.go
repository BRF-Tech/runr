package provider

import (
	"fmt"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/tfversion"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

// skupperToken is a Skupper v2 AccessToken as a managed PostgreSQL site issues it.
const skupperToken = `<<-EOT
    apiVersion: skupper.io/v2alpha1
    kind: AccessToken
    metadata:
      name: orders
    spec:
      url: https://skupper.db.example.com:9090/redeem
      code: c0de
      ca: |
        -----BEGIN CERTIFICATE-----
        MIIB
        -----END CERTIFICATE-----
  EOT`

func privateConnectionConfig(srv *runrtest.Server, body string) string {
	return providerBlock(srv) + appProject + `
resource "runr_private_connection" "orders" {
  project_id = runr_project.app.id
  name       = "orders"
  database   = "orders"
  username   = "app"
  link_token = ` + skupperToken + `
` + body + `
}
`
}

func connectionOf(srv *runrtest.Server, project, name string) (runr.PrivateConnection, bool) {
	for _, c := range srv.PrivateConnections(project) {
		if c.Name == name {
			return c, true
		}
	}
	return runr.PrivateConnection{}, false
}

// A private connection of the project's own workload: created with the region's status,
// imported without its credentials, replaced when an argument changes (the API cannot change
// one), created again when it is removed outside Terraform.
func TestPrivateConnectionResource(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "app"),
		Steps: []resource.TestStep{
			{
				Config: privateConnectionConfig(srv, `
  routing_key = "pg-orders"
  env_prefix  = "ORDERS_"
  password    = "s3cret"
`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_private_connection.orders", "env_names.0", "ORDERS_DATABASE_URL"),
					resource.TestCheckResourceAttr("runr_private_connection.orders", "port", "5432"),
					resource.TestCheckResourceAttr("runr_private_connection.orders", "sslmode", "require"),
					resource.TestCheckResourceAttr("runr_private_connection.orders", "link_kind", "access_token"),
					resource.TestCheckResourceAttr("runr_private_connection.orders", "status", "ready"),
					resource.TestCheckNoResourceAttr("runr_private_connection.orders", "environment"),
					resource.TestCheckResourceAttrSet("runr_private_connection.orders", "resource_name"),
					resource.TestCheckResourceAttrPair("runr_private_connection.orders", "host", "runr_private_connection.orders", "resource_name"),
					check(func() error {
						c, ok := connectionOf(srv, "app", "orders")
						if !ok || c.EnvironmentID != "" || c.RoutingKey != "pg-orders" {
							return fmt.Errorf("connection = %+v", c)
						}
						body := srv.CallsTo("POST /projects/{project}/private-connections")[0].Body
						if body["password"] != "s3cret" || body["environment"] != nil {
							return fmt.Errorf("create body = %v", body)
						}
						return nil
					}),
				),
			},
			{
				ResourceName:            "runr_private_connection.orders",
				ImportState:             true,
				ImportStateId:           "app/orders",
				ImportStateVerify:       true,
				ImportStateVerifyIgnore: []string{"link_token", "database", "username", "password"},
			},
			{
				// The region reports another status: read with the refresh, nothing to change
				PreConfig: func() { srv.SetPrivateConnectionState("app", "orders", "pending") },
				Config: privateConnectionConfig(srv, `
  routing_key = "pg-orders"
  env_prefix  = "ORDERS_"
  password    = "s3cret"
`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
				Check:            resource.TestCheckResourceAttr("runr_private_connection.orders", "status", "pending"),
			},
			{
				// A new password replaces the connection
				Config: privateConnectionConfig(srv, `
  routing_key = "pg-orders"
  env_prefix  = "ORDERS_"
  password    = "n3w"
`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_private_connection.orders", plancheck.ResourceActionReplace),
				}},
				Check: check(func() error {
					if n := len(srv.PrivateConnections("app")); n != 1 {
						return fmt.Errorf("%d connections", n)
					}
					return nil
				}),
			},
			{
				// Removed outside Terraform: created again
				PreConfig: func() {
					c := client(t, srv)
					p, _ := c.FindProject(t.Context(), "app")
					pc, err := c.FindPrivateConnection(t.Context(), p.ID, "", "orders")
					if err != nil {
						t.Fatal(err)
					}
					if err := c.DeletePrivateConnection(t.Context(), p.ID, pc.ID); err != nil {
						t.Fatal(err)
					}
				},
				Config: privateConnectionConfig(srv, `
  routing_key = "pg-orders"
  env_prefix  = "ORDERS_"
  password    = "n3w"
`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_private_connection.orders", plancheck.ResourceActionCreate),
				}},
			},
			{
				// Removed with the resource
				Config: providerBlock(srv) + appProject,
				Check: check(func() error {
					if n := len(srv.PrivateConnections("app")); n != 0 {
						return fmt.Errorf("%d connections left", n)
					}
					return nil
				}),
			},
		},
	})
}

// An imported connection takes the configured credentials into the state without being
// replaced (RunR never returns them).
func TestPrivateConnectionImportKeepsTheConnection(t *testing.T) {
	srv := fake(t)
	p := srv.SeedProject("shop", runr.TypeFaaS, "python", "eu-1", nil)
	c := client(t, srv)
	if _, _, err := c.CreatePrivateConnection(t.Context(), p.ID, runr.PrivateConnectionInput{Name: "orders", RoutingKey: "pg-orders",
		Database: "orders", Username: "app", Password: "s3cret", LinkToken: "apiVersion: skupper.io/v2alpha1\nkind: Link\n"}); err != nil {
		t.Fatal(err)
	}
	cfg := providerBlock(srv) + `
data "runr_project" "shop" {
  name = "shop"
}
resource "runr_private_connection" "orders" {
  project_id  = data.runr_project.shop.id
  name        = "orders"
  routing_key = "pg-orders"
  database    = "orders"
  username    = "app"
  password    = "s3cret"
  link_token  = ` + skupperToken + `
}
`
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config:             cfg,
				ResourceName:       "runr_private_connection.orders",
				ImportState:        true,
				ImportStateId:      "shop/orders",
				ImportStatePersist: true,
			},
			{
				Config: cfg,
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_private_connection.orders", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					if n := len(srv.CallsTo("POST /projects/{project}/private-connections")); n != 1 {
						return fmt.Errorf("%d creates, want the one before the import", n)
					}
					return nil
				}),
			},
			{
				Config:           cfg,
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
			},
		},
	})
}

// link_token_wo and password_wo stay out of the state; credentials_wo_version replaces the
// connection with the current ones.
func TestPrivateConnectionWriteOnly(t *testing.T) {
	srv := fake(t)
	cfg := func(password, version string) string {
		return providerBlock(srv) + appProject + fmt.Sprintf(`
resource "runr_private_connection" "orders" {
  project_id             = runr_project.app.id
  name                   = "orders"
  routing_key            = "pg-orders"
  database               = "orders"
  username               = "app"
  link_token_wo          = %s
  password_wo            = %q
  credentials_wo_version = %q
}
`, skupperToken, password, version)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		TerraformVersionChecks:   []tfversion.TerraformVersionCheck{tfversion.SkipBelow(tfversion.Version1_11_0)},
		Steps: []resource.TestStep{
			{
				Config: cfg("p1", "1"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckNoResourceAttr("runr_private_connection.orders", "password"),
					resource.TestCheckNoResourceAttr("runr_private_connection.orders", "password_wo"),
					resource.TestCheckNoResourceAttr("runr_private_connection.orders", "link_token_wo"),
					check(func() error {
						body := srv.CallsTo("POST /projects/{project}/private-connections")[0].Body
						if body["password"] != "p1" || body["link_token"] == "" {
							return fmt.Errorf("create body = %v", body)
						}
						return nil
					}),
				),
			},
			{
				Config:           cfg("p2", "1"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
			},
			{
				Config: cfg("p2", "2"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_private_connection.orders", plancheck.ResourceActionReplace),
				}},
				Check: check(func() error {
					calls := srv.CallsTo("POST /projects/{project}/private-connections")
					if body := calls[len(calls)-1].Body; body["password"] != "p2" {
						return fmt.Errorf("create body = %v", body)
					}
					return nil
				}),
			},
		},
	})
}

// The API's refusals reach the plan or the apply: names and prefixes, a global project, a
// region without Skupper.
func TestPrivateConnectionRules(t *testing.T) {
	srv := fake(t)
	us := srv.AddRegion("us-1", true)
	srv.SetGlobalDNS("eu-1", runr.GlobalDNSReady)
	srv.SetGlobalDNS("us-1", runr.GlobalDNSReady)
	c := client(t, srv)
	regions, err := c.ListRegions(t.Context())
	if err != nil {
		t.Fatal(err)
	}
	eu := ""
	for _, r := range regions {
		if r.Name == "eu-1" {
			eu = r.ID
		}
	}
	if _, err := c.CreateProject(t.Context(), runr.CreateProjectInput{Name: "worldwide", ServiceType: runr.TypeFaaS, Runtime: "python",
		RegionID: eu, Placement: runr.PlacementGlobal, RegionIDs: []string{eu, us.ID}}); err != nil {
		t.Fatal(err)
	}
	global := providerBlock(srv) + `
data "runr_project" "worldwide" {
  name = "worldwide"
}
resource "runr_private_connection" "orders" {
  project_id  = data.runr_project.worldwide.id
  name        = "orders"
  routing_key = "pg-orders"
  database    = "orders"
  username    = "app"
  password    = "x"
  link_token  = ` + skupperToken + `
}
`
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{Config: privateConnectionConfig(srv, `
  routing_key = "pg orders"
  password    = "x"`), PlanOnly: true, ExpectError: phrase("must start with a letter or digit")},
			{Config: privateConnectionConfig(srv, `
  routing_key = "pg-orders"
  env_prefix  = "orders"
  password    = "x"`), PlanOnly: true, ExpectError: phrase("must be upper case letters")},
			{Config: privateConnectionConfig(srv, `
  routing_key = "pg-orders"
  sslmode     = "always"
  password    = "x"`), PlanOnly: true, ExpectError: phrase("sslmode")},
			{Config: privateConnectionConfig(srv, `  routing_key = "pg-orders"`), PlanOnly: true, ExpectError: phrase("password_wo")},
			{Config: global, ExpectError: phrase("refused for global projects")},
			{
				PreConfig: func() { srv.SetRegionSkupper("eu-1", false) },
				Config: privateConnectionConfig(srv, `
  routing_key = "pg-orders"
  password    = "x"`),
				ExpectError: phrase("Skupper v2 (skupper.io/v2alpha1) is not installed on region eu-1"),
			},
		},
	})
}
