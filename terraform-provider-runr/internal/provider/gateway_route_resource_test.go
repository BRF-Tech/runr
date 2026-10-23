package provider

import (
	"fmt"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

const gatewayEU = `
resource "runr_gateway" "eu" {
  region_id = data.runr_region.eu.id
}
`

func routeConfig(srv *runrtest.Server, attrs string) string {
	return providerBlock(srv) + appProject + gatewayEU + fmt.Sprintf(`
resource "runr_gateway_route" "orders" {
  gateway_id = runr_gateway.eu.id
  project_id = runr_project.app.id
%s
}
`, attrs)
}

// fakeRoute is the route of the fake's gateway in eu-1 on path.
func fakeRoute(srv *runrtest.Server, path string) (runr.GatewayRoute, bool) {
	g, _ := gatewayIn(srv, "eu-1")
	for _, r := range g.Routes {
		if r.Path == path {
			return r, true
		}
	}
	return runr.GatewayRoute{}, false
}

func TestGatewayRouteResource(t *testing.T) {
	srv := fake(t)
	var routeID string
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(s *terraform.State) error {
			for _, g := range srv.Gateways() {
				if len(g.Routes) > 0 {
					return fmt.Errorf("routes left: %+v", g.Routes)
				}
			}
			return projectGone(srv, "app")(s)
		},
		Steps: []resource.TestStep{
			{
				Config: routeConfig(srv, `  path    = "/orders"
  methods = ["GET", "POST"]`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttrPair("runr_gateway_route.orders", "gateway_id", "runr_gateway.eu", "id"),
					resource.TestCheckResourceAttr("runr_gateway_route.orders", "url", "https://ada.eu-1.runr.test/orders"),
					resource.TestCheckResourceAttr("runr_gateway_route.orders", "auth_mode", "public"),
					resource.TestCheckResourceAttr("runr_gateway_route.orders", "gateway_only", "false"),
					resource.TestCheckResourceAttr("runr_gateway_route.orders", "methods.#", "2"),
					resource.TestCheckNoResourceAttr("runr_gateway_route.orders", "environment"),
					resource.TestCheckNoResourceAttr("runr_gateway_route.orders", "jwt.jwks_url"),
					resource.TestCheckResourceAttrWith("runr_gateway_route.orders", "id", func(v string) error {
						routeID = v
						return nil
					}),
					check(func() error {
						r, ok := fakeRoute(srv, "/orders")
						if !ok || len(r.Methods) != 2 || r.Project == nil || r.Project.Name != "app" {
							return fmt.Errorf("route = %+v", r)
						}
						return nil
					}),
				),
			},
			{
				ResourceName:      "runr_gateway_route.orders",
				ImportState:       true,
				ImportStateId:     "eu-1/orders",
				ImportStateVerify: true,
			},
			{
				// In place: path, methods, JWT authentication and gateway-only; the route keeps its id
				Config: routeConfig(srv, `  path         = "/v1/orders"
  auth_mode    = "jwt"
  jwt          = { jwks_url = "https://id.example.com/jwks", audience = "orders" }
  gateway_only = true`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_gateway_route.orders", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_gateway_route.orders", "url", "https://ada.eu-1.runr.test/v1/orders"),
					resource.TestCheckResourceAttr("runr_gateway_route.orders", "jwt.subject_claim", "sub"),
					resource.TestCheckResourceAttr("runr_gateway_route.orders", "jwt.require_consumer", "false"),
					resource.TestCheckNoResourceAttr("runr_gateway_route.orders", "jwt.issuer"),
					resource.TestCheckNoResourceAttr("runr_gateway_route.orders", "methods.#"),
					resource.TestCheckResourceAttrWith("runr_gateway_route.orders", "id", func(v string) error {
						if v != routeID {
							return fmt.Errorf("the route was replaced: %s, was %s", v, routeID)
						}
						return nil
					}),
					check(func() error {
						r, ok := fakeRoute(srv, "/v1/orders")
						if !ok || r.AuthMode != "jwt" || r.JWT == nil || r.JWT.Audience != "orders" || !r.GatewayOnly || r.Methods != nil {
							return fmt.Errorf("route = %+v", r)
						}
						return nil
					}),
				),
			},
			{
				ResourceName:      "runr_gateway_route.orders",
				ImportState:       true,
				ImportStateId:     "eu-1/" + "/v1/orders",
				ImportStateVerify: true,
			},
			{
				// key authentication: the JWT settings go; gateway_only left out stays
				Config: routeConfig(srv, `  path      = "/v1/orders"
  auth_mode = "key"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_gateway_route.orders", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckNoResourceAttr("runr_gateway_route.orders", "jwt.jwks_url"),
					resource.TestCheckResourceAttr("runr_gateway_route.orders", "gateway_only", "true"),
					check(func() error {
						if b := srv.CallsTo("PATCH /gateways/{gateway}/routes/{route}"); len(b) == 0 || b[len(b)-1].Body["jwt"] != nil {
							return fmt.Errorf("jwt not cleared: %+v", b)
						}
						r, _ := fakeRoute(srv, "/v1/orders")
						if r.AuthMode != "key" || r.JWT != nil || !r.GatewayOnly {
							return fmt.Errorf("route = %+v", r)
						}
						return nil
					}),
				),
			},
			{
				// gateway_only changed outside Terraform: left out, it stays
				PreConfig: func() {
					g, _ := gatewayIn(srv, "eu-1")
					off := false
					if _, err := client(t, srv).UpdateRouteAuth(t.Context(), g.ID, routeID, runr.RouteAuthInput{GatewayOnly: &off}); err != nil {
						t.Fatal(err)
					}
				},
				Config: routeConfig(srv, `  path      = "/v1/orders"
  auth_mode = "key"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
				Check:            resource.TestCheckResourceAttr("runr_gateway_route.orders", "gateway_only", "false"),
			},
			{
				// The authentication changed outside Terraform: planned back
				PreConfig: func() {
					g, _ := gatewayIn(srv, "eu-1")
					if _, err := client(t, srv).UpdateRouteAuth(t.Context(), g.ID, routeID, runr.RouteAuthInput{AuthMode: "public"}); err != nil {
						t.Fatal(err)
					}
				},
				Config: routeConfig(srv, `  path      = "/v1/orders"
  auth_mode = "key"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_gateway_route.orders", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					if r, _ := fakeRoute(srv, "/v1/orders"); r.AuthMode != "key" {
						return fmt.Errorf("route = %+v", r)
					}
					return nil
				}),
			},
			{
				// Removed outside Terraform: added again
				PreConfig: func() {
					g, _ := gatewayIn(srv, "eu-1")
					if err := client(t, srv).RemoveGatewayRoute(t.Context(), g.ID, routeID); err != nil {
						t.Fatal(err)
					}
				},
				Config: routeConfig(srv, `  path      = "/v1/orders"
  auth_mode = "key"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_gateway_route.orders", plancheck.ResourceActionCreate),
				}},
				Check: check(func() error {
					if r, ok := fakeRoute(srv, "/v1/orders"); !ok || r.AuthMode != "key" {
						return fmt.Errorf("route = %+v (%v)", r, ok)
					}
					return nil
				}),
			},
			{
				// Another environment: a new route
				PreConfig: func() { srv.SeedEnvironment("app", "staging", false) },
				Config: routeConfig(srv, `  path        = "/v1/orders"
  environment = "staging"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_gateway_route.orders", plancheck.ResourceActionDestroyBeforeCreate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_gateway_route.orders", "environment", "staging"),
					resource.TestCheckResourceAttr("runr_gateway_route.orders", "auth_mode", "public"),
					check(func() error {
						if r, ok := fakeRoute(srv, "/v1/orders"); !ok || r.Environment != "staging" {
							return fmt.Errorf("route = %+v (%v)", r, ok)
						}
						return nil
					}),
				),
			},
		},
	})
}

// The environment keeps its spelling: production for the project's own workload, an id.
func TestGatewayRouteResourceEnvironmentSpelling(t *testing.T) {
	srv := fake(t)
	srv.SeedProject("shop", runr.TypeFaaS, "python", "eu-1", nil)
	qa := srv.SeedEnvironment("shop", "qa", false)
	p, _ := srv.Project("shop")
	cfg := providerBlock(srv) + gatewayEU + fmt.Sprintf(`
resource "runr_gateway_route" "own" {
  gateway_id  = runr_gateway.eu.id
  project_id  = %[1]q
  environment = "production"
  path        = "/shop"
}

resource "runr_gateway_route" "qa" {
  gateway_id  = runr_gateway.eu.id
  project_id  = %[1]q
  environment = %[2]q
  path        = "/shop-qa"
}
`, p.ID, qa.ID)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: cfg,
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_gateway_route.own", "environment", "production"),
					resource.TestCheckResourceAttr("runr_gateway_route.qa", "environment", qa.ID),
					check(func() error {
						if r, _ := fakeRoute(srv, "/shop-qa"); r.Environment != "qa" {
							return fmt.Errorf("route = %+v", r)
						}
						if r, _ := fakeRoute(srv, "/shop"); r.Environment != "" {
							return fmt.Errorf("route = %+v", r)
						}
						return nil
					}),
				),
			},
			{
				Config:           cfg,
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
			},
		},
	})
}

func TestGatewayRouteResourceChecks(t *testing.T) {
	srv := fake(t)
	srv.AddRegion("eu-2", true)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: routeConfig(srv, `  path      = "/orders"
  auth_mode = "jwt"`),
				ExpectError: phrase("jwt required"),
			},
			{
				Config: routeConfig(srv, `  path = "/orders"
  jwt  = { jwks_url = "https://id.example.com/jwks" }`),
				ExpectError: phrase("jwt without auth_mode jwt"),
			},
			{
				Config:      routeConfig(srv, `  path = "orders"`),
				ExpectError: phrase("must start with /"),
			},
			{
				Config: routeConfig(srv, `  path    = "/orders"
  methods = ["TRACE"]`),
				ExpectError: phrase("value must be one of"),
			},
			{
				// The gateway must be in the region the workload runs in
				Config: providerBlock(srv) + appProject + `
data "runr_region" "eu2" {
  name = "eu-2"
}

resource "runr_gateway" "eu2" {
  region_id = data.runr_region.eu2.id
}

resource "runr_gateway_route" "orders" {
  gateway_id = runr_gateway.eu2.id
  project_id = runr_project.app.id
  path       = "/orders"
}
`,
				ExpectError: phrase("runs in region eu-1: a route to it belongs to a gateway in that region"),
			},
			{
				// A path once per gateway; a workload has one route
				Config: routeConfig(srv, `  path = "/orders"`) + `
resource "runr_project" "billing" {
  name      = "billing"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id
}

resource "runr_gateway_route" "billing" {
  gateway_id = runr_gateway.eu.id
  project_id = runr_project.billing.id
  path       = "/orders"
  depends_on = [runr_gateway_route.orders]
}
`,
				ExpectError: phrase("The gateway already has a route on /orders."),
			},
			{
				Config: routeConfig(srv, `  path = "/orders"`) + `
resource "runr_gateway_route" "again" {
  gateway_id = runr_gateway.eu.id
  project_id = runr_project.app.id
  path       = "/orders-again"
  depends_on = [runr_gateway_route.orders]
}
`,
				ExpectError: phrase("environment of the project already has a gateway route"),
			},
		},
	})
}

// Gateway-only is refused for a workload with custom domains and for a global project; a global
// project's route needs a gateway in one of its regions.
func TestGatewayRouteResourceGatewayOnlyRefusals(t *testing.T) {
	srv := fake(t)
	srv.AddRegion("eu-2", true)
	srv.AddRegion("us-1", true)
	srv.SetGlobalDNS("eu-1", runr.GlobalDNSReady)
	srv.SetGlobalDNS("eu-2", runr.GlobalDNSReady)
	srv.SeedProject("shop", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SeedDomain("shop", "shop.example.com")
	shop, _ := srv.Project("shop")
	c := client(t, srv)
	eu1, _ := c.FindRegion(t.Context(), "eu-1")
	eu2, _ := c.FindRegion(t.Context(), "eu-2")
	world, err := c.CreateProject(t.Context(), runr.CreateProjectInput{Name: "world", ServiceType: runr.TypeFaaS, Runtime: "python",
		RegionID: eu1.ID, Placement: runr.PlacementGlobal, RegionIDs: []string{eu1.ID, eu2.ID}})
	if err != nil {
		t.Fatal(err)
	}
	route := func(project, region, extra string) string {
		return providerBlock(srv) + fmt.Sprintf(`
data "runr_region" "gw" {
  name = %q
}

resource "runr_gateway" "gw" {
  region_id = data.runr_region.gw.id
}

resource "runr_gateway_route" "r" {
  gateway_id = runr_gateway.gw.id
  project_id = %q
  path       = "/r"
%s
}
`, region, project, extra)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config:      route(shop.ID, "eu-1", `  gateway_only = true`),
				ExpectError: phrase("Remove the project's custom domains first"),
			},
			{
				Config:      route(world.ID, "us-1", ""),
				ExpectError: phrase("where this global project does not run"),
			},
			{
				// a gateway of any of its regions; not only through the gateway
				Config:      route(world.ID, "eu-2", `  gateway_only = true`),
				ExpectError: phrase("would close it"),
			},
			{
				Config: route(world.ID, "eu-2", ""),
				Check:  resource.TestCheckResourceAttr("runr_gateway_route.r", "url", "https://ada.eu-2.runr.test/r"),
			},
		},
	})
}

// A key or JWT route is created with its authentication in one call: the POST carries it, no
// PATCH follows, and the answer (the route as the gateway shows it) fills the state.
func TestGatewayRouteResourceCreatesItsAuthenticationInOneCall(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: routeConfig(srv, `  path         = "/orders"
  auth_mode    = "jwt"
  jwt          = { jwks_url = "https://id.example.com/jwks", audience = "orders", require_consumer = true }
  gateway_only = true`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_gateway_route.orders", "url", "https://ada.eu-1.runr.test/orders"),
					resource.TestCheckResourceAttr("runr_gateway_route.orders", "jwt.audience", "orders"),
					resource.TestCheckResourceAttr("runr_gateway_route.orders", "jwt.subject_claim", "sub"),
					resource.TestCheckResourceAttr("runr_gateway_route.orders", "gateway_only", "true"),
					check(func() error {
						posts := srv.CallsTo("POST /gateways/{gateway}/routes")
						if len(posts) != 1 || posts[0].Body["auth_mode"] != "jwt" || posts[0].Body["gateway_only"] != true {
							return fmt.Errorf("POST calls = %+v", posts)
						}
						if jwt, _ := posts[0].Body["jwt"].(map[string]any); jwt["jwks_url"] != "https://id.example.com/jwks" || jwt["require_consumer"] != true {
							return fmt.Errorf("POST jwt = %+v", posts[0].Body["jwt"])
						}
						if n := len(srv.CallsTo("PATCH /gateways/{gateway}/routes/{route}")); n != 0 {
							return fmt.Errorf("PATCH calls = %d: the route was public until them", n)
						}
						if r, _ := fakeRoute(srv, "/orders"); r.AuthMode != "jwt" || !r.GatewayOnly {
							return fmt.Errorf("route = %+v", r)
						}
						return nil
					}),
				),
			},
			{
				// a public route sends no settings
				Config: routeConfig(srv, `  path = "/orders"`) + `
resource "runr_project" "billing" {
  name      = "billing"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id
}

resource "runr_gateway_route" "billing" {
  gateway_id = runr_gateway.eu.id
  project_id = runr_project.billing.id
  path       = "/billing"
}
`,
				Check: check(func() error {
					posts := srv.CallsTo("POST /gateways/{gateway}/routes")
					last := posts[len(posts)-1].Body
					if _, ok := last["auth_mode"]; ok || last["gateway_only"] != nil || last["jwt"] != nil {
						return fmt.Errorf("POST body = %+v", last)
					}
					return nil
				}),
			},
		},
	})
}

// An API before 0.6.0 creates every route public and answers without its URL: the provider sets
// the authentication right after and reads the route from the gateway.
func TestGatewayRouteResourceOnAnEarlierAPI(t *testing.T) {
	srv := fake(t)
	srv.RouteSettings = false
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: routeConfig(srv, `  path      = "/orders"
  auth_mode = "key"`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_gateway_route.orders", "url", "https://ada.eu-1.runr.test/orders"),
					resource.TestCheckResourceAttr("runr_gateway_route.orders", "auth_mode", "key"),
					check(func() error {
						if b := srv.CallsTo("PATCH /gateways/{gateway}/routes/{route}"); len(b) != 1 || b[0].Body["auth_mode"] != "key" {
							return fmt.Errorf("PATCH calls = %+v", b)
						}
						if r, _ := fakeRoute(srv, "/orders"); r.AuthMode != "key" {
							return fmt.Errorf("route = %+v", r)
						}
						return nil
					}),
				),
			},
			{
				Config: routeConfig(srv, `  path      = "/v2/orders"
  auth_mode = "key"`),
				Check: resource.TestCheckResourceAttr("runr_gateway_route.orders", "url", "https://ada.eu-1.runr.test/v2/orders"),
			},
		},
	})
}
