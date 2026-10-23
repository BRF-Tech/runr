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

const proPlan = `
resource "runr_api_plan" "pro" {
  gateway_id      = runr_gateway.eu.id
  name            = "Pro"
  rate_per_second = 10
}
`

func consumerConfig(srv *runrtest.Server, attrs string) string {
	return providerBlock(srv) + gatewayEU + proPlan + fmt.Sprintf(`
resource "runr_api_consumer" "acme" {
%s
}
`, attrs)
}

// fakeConsumer is the fake's consumer with the name.
func fakeConsumer(srv *runrtest.Server, name string) (runr.APIConsumer, bool) {
	for _, c := range srv.APIConsumers() {
		if c.Name == name {
			return c, true
		}
	}
	return runr.APIConsumer{}, false
}

func TestAPIConsumerResource(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(*terraform.State) error {
			if n := len(srv.APIConsumers()); n != 0 {
				return fmt.Errorf("%d consumers left", n)
			}
			return nil
		},
		Steps: []resource.TestStep{
			{
				Config: consumerConfig(srv, `  gateway_id  = runr_gateway.eu.id
  name        = "Acme Ltd"
  email       = "api@acme.example"
  external_id = "CUST-1042"
  jwt_subject = "acme"
  plan_id     = runr_api_plan.pro.id
  metadata    = { tier = "gold", region = "emea" }`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttrPair("runr_api_consumer.acme", "plan_id", "runr_api_plan.pro", "id"),
					resource.TestCheckResourceAttr("runr_api_consumer.acme", "status", "active"),
					resource.TestCheckResourceAttr("runr_api_consumer.acme", "metadata.tier", "gold"),
					check(func() error {
						c, ok := fakeConsumer(srv, "Acme Ltd")
						if !ok || c.Scope != "gateway" || c.ExternalID != "CUST-1042" || c.Plan == nil || c.Plan.Name != "Pro" ||
							c.Metadata["region"] != "emea" || c.Email != "api@acme.example" {
							return fmt.Errorf("consumer = %+v", c)
						}
						return nil
					}),
				),
			},
			{
				ResourceName:      "runr_api_consumer.acme",
				ImportState:       true,
				ImportStateVerify: true,
			},
			{
				// In place: name, metadata, status; e-mail, subject and plan removed
				Config: consumerConfig(srv, `  gateway_id  = runr_gateway.eu.id
  name        = "Acme Group"
  external_id = "CUST-1042"
  status      = "suspended"
  metadata    = { tier = "silver" }`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_api_consumer.acme", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					c, ok := fakeConsumer(srv, "Acme Group")
					if !ok || c.Status != "suspended" || c.Email != "" || c.JWTSubject != "" || c.PlanID != "" ||
						len(c.Metadata) != 1 || c.Metadata["tier"] != "silver" {
						return fmt.Errorf("consumer = %+v", c)
					}
					return nil
				}),
			},
			{
				// Left out, the status stays — also when changed outside Terraform
				PreConfig: func() {
					c, _ := fakeConsumer(srv, "Acme Group")
					if _, err := client(t, srv).UpdateAPIConsumer(t.Context(), c.ID, map[string]any{"status": "active"}); err != nil {
						t.Fatal(err)
					}
				},
				Config: consumerConfig(srv, `  gateway_id  = runr_gateway.eu.id
  name        = "Acme Group"
  external_id = "CUST-1042"
  metadata    = { tier = "silver" }`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
				Check:            resource.TestCheckResourceAttr("runr_api_consumer.acme", "status", "active"),
			},
			{
				// Changed outside Terraform: planned back
				PreConfig: func() {
					c, _ := fakeConsumer(srv, "Acme Group")
					if _, err := client(t, srv).UpdateAPIConsumer(t.Context(), c.ID, map[string]any{"external_id": "CUST-9", "metadata": map[string]any{"seats": 12}}); err != nil {
						t.Fatal(err)
					}
				},
				Config: consumerConfig(srv, `  gateway_id  = runr_gateway.eu.id
  name        = "Acme Group"
  external_id = "CUST-1042"
  metadata    = { tier = "silver" }`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_api_consumer.acme", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					if c, _ := fakeConsumer(srv, "Acme Group"); c.ExternalID != "CUST-1042" || c.Metadata["tier"] != "silver" || len(c.Metadata) != 1 {
						return fmt.Errorf("consumer = %+v", c)
					}
					return nil
				}),
			},
			{
				// An empty metadata map stays empty
				Config: consumerConfig(srv, `  gateway_id = runr_gateway.eu.id
  name       = "Acme Group"
  metadata   = {}`),
				Check: resource.TestCheckResourceAttr("runr_api_consumer.acme", "metadata.%", "0"),
			},
			{
				Config: consumerConfig(srv, `  gateway_id = runr_gateway.eu.id
  name       = "Acme Group"
  metadata   = {}`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
			},
			{
				// Deleted outside Terraform: created again
				PreConfig: func() {
					c, _ := fakeConsumer(srv, "Acme Group")
					if err := client(t, srv).DeleteAPIConsumer(t.Context(), c.ID); err != nil {
						t.Fatal(err)
					}
				},
				Config: consumerConfig(srv, `  gateway_id = runr_gateway.eu.id
  name       = "Acme Group"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_api_consumer.acme", plancheck.ResourceActionCreate),
				}},
			},
			{
				// A consumer of the organization instead: a new consumer
				Config: consumerConfig(srv, `  name = "Acme Group"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_api_consumer.acme", plancheck.ResourceActionDestroyBeforeCreate),
				}},
				Check: check(func() error {
					if c, _ := fakeConsumer(srv, "Acme Group"); c.Scope != "organization" || len(srv.APIConsumers()) != 1 {
						return fmt.Errorf("consumers = %+v", srv.APIConsumers())
					}
					return nil
				}),
			},
		},
	})
}

func TestAPIConsumerResourceChecks(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: consumerConfig(srv, `  name  = "Acme"
  email = "acme"`),
				ExpectError: phrase("must be an e-mail address"),
			},
			{
				Config: consumerConfig(srv, `  name   = "Acme"
  status = "gone"`),
				ExpectError: phrase("value must be one of"),
			},
			{
				// An organization consumer can only use the organization's plans
				Config: consumerConfig(srv, `  name    = "Acme"
  plan_id = runr_api_plan.pro.id`),
				ExpectError: phrase("This plan belongs to another gateway."),
			},
			{
				// A JWT subject names one consumer among those a gateway accepts
				Config: consumerConfig(srv, `  gateway_id  = runr_gateway.eu.id
  name        = "Acme"
  jwt_subject = "acme"`) + `
resource "runr_api_consumer" "twin" {
  name        = "Acme Twin"
  jwt_subject = "acme"
  depends_on  = [runr_api_consumer.acme]
}
`,
				ExpectError: phrase("Another consumer of this gateway has this JWT subject."),
			},
		},
	})
}
