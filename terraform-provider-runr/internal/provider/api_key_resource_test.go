package provider

import (
	"fmt"
	"regexp"
	"strings"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

// keyBase: a gateway with two key routes, a plan and a consumer.
const keyBase = appProject + gatewayEU + proPlan + `
resource "runr_project" "billing" {
  name      = "billing"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id
}

resource "runr_gateway_route" "orders" {
  gateway_id = runr_gateway.eu.id
  project_id = runr_project.app.id
  path       = "/orders"
  auth_mode  = "key"
}

resource "runr_gateway_route" "billing" {
  gateway_id = runr_gateway.eu.id
  project_id = runr_project.billing.id
  path       = "/billing"
  auth_mode  = "key"
}

resource "runr_api_consumer" "acme" {
  gateway_id = runr_gateway.eu.id
  name       = "Acme Ltd"
  plan_id    = runr_api_plan.pro.id
}
`

func keyConfig(srv *runrtest.Server, attrs string) string {
	return providerBlock(srv) + keyBase + fmt.Sprintf(`
resource "runr_api_key" "live" {
  consumer_id = runr_api_consumer.acme.id
%s
}
`, attrs)
}

// fakeKey is the fake's key with the id.
func fakeKey(srv *runrtest.Server, id string) (runr.APIKey, bool) {
	for _, k := range srv.APIKeys() {
		if k.ID == id {
			return k, true
		}
	}
	return runr.APIKey{}, false
}

func attrOf(s *terraform.State, address, attr string) string {
	return s.RootModule().Resources[address].Primary.Attributes[attr]
}

func TestAPIKeyResource(t *testing.T) {
	srv := fake(t)
	var keyID, plain string
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(*terraform.State) error {
			// destroying the key revokes it (then the consumer goes, and its keys with it)
			for _, c := range srv.CallsTo("POST /api-keys/{apiKey}/revoke") {
				if c.Path == "/api-keys/"+keyID+"/revoke" {
					return nil
				}
			}
			return fmt.Errorf("key %s was not revoked", keyID)
		},
		Steps: []resource.TestStep{
			{
				Config: keyConfig(srv, `  name       = "production"
  scopes     = [{ route_id = runr_gateway_route.orders.id, methods = ["GET"] }]
  plan_id    = runr_api_plan.pro.id
  expires_at = "2099-01-01T00:00:00Z"`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestMatchResourceAttr("runr_api_key.live", "key", regexp.MustCompile(`^rk_[0-9a-f]{43}$`)),
					resource.TestCheckResourceAttr("runr_api_key.live", "status", "active"),
					resource.TestCheckResourceAttr("runr_api_key.live", "expires_at", "2099-01-01T00:00:00Z"),
					resource.TestCheckResourceAttrPair("runr_api_key.live", "scopes.0.route_id", "runr_gateway_route.orders", "id"),
					func(s *terraform.State) error {
						keyID, plain = attrOf(s, "runr_api_key.live", "id"), attrOf(s, "runr_api_key.live", "key")
						if prefix := attrOf(s, "runr_api_key.live", "prefix"); prefix != plain[:11] {
							return fmt.Errorf("prefix %s of %s", prefix, plain)
						}
						k, ok := fakeKey(srv, keyID)
						if !ok || len(k.Scopes) != 1 || k.Scopes[0].RouteID != attrOf(s, "runr_gateway_route.orders", "id") ||
							k.PlanID != attrOf(s, "runr_api_plan.pro", "id") || !strings.HasPrefix(k.ExpiresAt, "2099-01-01T00:00:00") {
							return fmt.Errorf("key = %+v", k)
						}
						return nil
					},
				),
			},
			{
				ResourceName: "runr_api_key.live",
				ImportState:  true,
				ImportStateIdFunc: func(s *terraform.State) (string, error) {
					return attrOf(s, "runr_api_consumer.acme", "id") + "/" + keyID, nil
				},
				ImportStateVerify:       true,
				ImportStateVerifyIgnore: []string{"key"},
			},
			{
				// In place: name, scopes, plan, expiry; the key itself stays
				Config: keyConfig(srv, `  name       = "prod"
  scopes     = [
    { route_id = runr_gateway_route.orders.id, methods = ["GET", "POST"] },
    { route_id = runr_gateway_route.billing.id },
  ]
  expires_at = "2098-06-30T12:00:00+02:00"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_api_key.live", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttrWith("runr_api_key.live", "id", func(v string) error {
						if v != keyID {
							return fmt.Errorf("the key was replaced")
						}
						return nil
					}),
					resource.TestCheckResourceAttrWith("runr_api_key.live", "key", func(v string) error {
						if v != plain {
							return fmt.Errorf("the key changed")
						}
						return nil
					}),
					resource.TestCheckResourceAttr("runr_api_key.live", "expires_at", "2098-06-30T12:00:00+02:00"),
					check(func() error {
						k, _ := fakeKey(srv, keyID)
						if k.Name != "prod" || k.PlanID != "" || len(k.Scopes) != 2 || k.Scopes[1].Methods != nil ||
							!strings.HasPrefix(k.ExpiresAt, "2098-06-30T10:00:00") {
							return fmt.Errorf("key = %+v", k)
						}
						return nil
					}),
				),
			},
			{
				// No scopes, no expiry: every route, forever
				Config: keyConfig(srv, `  name = "prod"`),
				Check: check(func() error {
					if k, _ := fakeKey(srv, keyID); k.Scopes != nil || k.ExpiresAt != "" {
						return fmt.Errorf("key = %+v", k)
					}
					return nil
				}),
			},
			{
				// Expired: it stays in the state; a new expiry makes it active again
				PreConfig: func() { srv.ExpireAPIKey(keyID) },
				Config:    keyConfig(srv, `  name = "prod"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_api_key.live", plancheck.ResourceActionUpdate),
				}},
				Check: resource.TestCheckResourceAttr("runr_api_key.live", "status", "active"),
			},
			{
				PreConfig: func() { srv.ExpireAPIKey(keyID) },
				Config: keyConfig(srv, `  name       = "prod"
  expires_at = "2097-01-01T00:00:00Z"`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_api_key.live", "status", "active"),
					check(func() error {
						if k, _ := fakeKey(srv, keyID); k.Status != "active" {
							return fmt.Errorf("key = %+v", k)
						}
						return nil
					}),
				),
			},
			{
				// Rotated outside Terraform: the state follows the new key, without its secret
				PreConfig: func() {
					overlap := 60
					if _, err := client(t, srv).RotateAPIKey(t.Context(), keyID, &overlap); err != nil {
						t.Fatal(err)
					}
				},
				Config: keyConfig(srv, `  name       = "prod"
  expires_at = "2097-01-01T00:00:00Z"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckNoResourceAttr("runr_api_key.live", "key"),
					resource.TestCheckResourceAttrWith("runr_api_key.live", "id", func(v string) error {
						k, ok := fakeKey(srv, v)
						if v == keyID || !ok || k.RotatedFrom != keyID {
							return fmt.Errorf("the state holds key %s (%+v), rotated from %s", v, k, keyID)
						}
						keyID = v
						return nil
					}),
				),
			},
			{
				// Revoked outside Terraform: a new key
				PreConfig: func() {
					if _, err := client(t, srv).RevokeAPIKey(t.Context(), keyID); err != nil {
						t.Fatal(err)
					}
				},
				Config: keyConfig(srv, `  name       = "prod"
  expires_at = "2097-01-01T00:00:00Z"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_api_key.live", plancheck.ResourceActionCreate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestMatchResourceAttr("runr_api_key.live", "key", regexp.MustCompile(`^rk_`)),
					resource.TestCheckResourceAttrWith("runr_api_key.live", "id", func(v string) error {
						keyID = v
						return nil
					}),
				),
			},
		},
	})
}

func TestAPIKeyResourceChecks(t *testing.T) {
	srv := fake(t)
	other := srv.SeedGateway("eu-1", "/elsewhere")
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: keyConfig(srv, `  name       = "live"
  expires_at = "tomorrow"`),
				ExpectError: phrase("Use RFC 3339"),
			},
			{
				Config: keyConfig(srv, `  name   = "live"
  scopes = [{ route_id = runr_gateway_route.orders.id, methods = ["TRACE"] }]`),
				ExpectError: phrase("value must be one of"),
			},
			{
				Config: keyConfig(srv, `  name   = "live"
  scopes = []`),
				ExpectError: phrase("list must contain at least 1 elements"),
			},
			{
				// The API refuses a route of another gateway and an expiry in the past
				Config: keyConfig(srv, fmt.Sprintf(`  name   = "live"
  scopes = [{ route_id = %q }]`, other.Routes[0].ID)),
				ExpectError: phrase("This route is not a route of the consumer's gateway."),
			},
			{
				Config: keyConfig(srv, `  name       = "live"
  expires_at = "2020-01-01T00:00:00Z"`),
				ExpectError: phrase("must be a date after now"),
			},
		},
	})
}

// The whole API product in one configuration: a gateway, a key route, a plan, a consumer with
// the plan and a key for the route.
func TestAPIProductConfiguration(t *testing.T) {
	srv := fake(t)
	cfg := keyConfig(srv, `  name   = "production"
  scopes = [{ route_id = runr_gateway_route.orders.id, methods = ["GET"] }]`) + `
output "key" {
  value     = runr_api_key.live.key
  sensitive = true
}
`
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(s *terraform.State) error {
			if len(srv.APIConsumers()) != 0 || len(srv.APIPlans()) != 0 {
				return fmt.Errorf("left: %+v %+v", srv.APIConsumers(), srv.APIPlans())
			}
			return gatewayReset(srv, "eu-1")()
		},
		Steps: []resource.TestStep{
			{
				Config: cfg,
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_gateway_route.orders", "auth_mode", "key"),
					func(s *terraform.State) error {
						g, _ := gatewayIn(srv, "eu-1")
						consumers := srv.APIConsumers()
						keys := srv.APIKeys()
						if len(g.Routes) != 2 || len(consumers) != 1 || len(keys) != 1 {
							return fmt.Errorf("gateway %+v, consumers %+v, keys %+v", g, consumers, keys)
						}
						c, k := consumers[0], keys[0]
						if c.GatewayID != g.ID || c.Plan == nil || c.Plan.Name != "Pro" || k.ConsumerID != c.ID ||
							k.Scopes[0].RouteID != attrOf(s, "runr_gateway_route.orders", "id") || k.Prefix != attrOf(s, "runr_api_key.live", "key")[:11] {
							return fmt.Errorf("consumer %+v, key %+v", c, k)
						}
						return nil
					},
				),
			},
			{
				Config:           cfg,
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
			},
		},
	})
}
