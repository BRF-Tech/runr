package provider

import (
	"fmt"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func topicConfig(srv *runrtest.Server, description string) string {
	return providerBlock(srv) + `
resource "runr_topic" "orders" {
  name      = "orders"
  region_id = data.runr_region.eu.id` + description + `
}
`
}

func serverTopic(srv *runrtest.Server, name, description string) func() error {
	return func() error {
		for _, t := range srv.Topics() {
			if t.Name == name {
				if t.Description != description {
					return fmt.Errorf("topic %s description %q, want %q", name, t.Description, description)
				}
				return nil
			}
		}
		return fmt.Errorf("topic %s missing", name)
	}
}

func TestTopic(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(*terraform.State) error {
			if n := len(srv.Topics()); n != 0 {
				return fmt.Errorf("%d topics left", n)
			}
			return nil
		},
		Steps: []resource.TestStep{
			{
				Config: topicConfig(srv, "\n  description = \"Order events\""),
				Check: resource.ComposeAggregateTestCheckFunc(
					check(serverTopic(srv, "orders", "Order events")),
					resource.TestCheckResourceAttr("runr_topic.orders", "stream", "runr.topic.orders"),
				),
			},
			{
				ResourceName:      "runr_topic.orders",
				ImportState:       true,
				ImportStateId:     "orders",
				ImportStateVerify: true,
			},
			{
				Config: topicConfig(srv, "\n  description = \"Every order event\""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_topic.orders", plancheck.ResourceActionUpdate),
				}},
				Check: check(serverTopic(srv, "orders", "Every order event")),
			},
			{
				Config: topicConfig(srv, ""),
				Check:  check(serverTopic(srv, "orders", "")),
			},
			{
				// a topic deleted outside Terraform is created again
				PreConfig: func() {
					if err := client(t, srv).DeleteTopic(t.Context(), "orders"); err != nil {
						t.Fatal(err)
					}
				},
				Config: topicConfig(srv, ""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_topic.orders", plancheck.ResourceActionCreate),
				}},
			},
			{
				// a topic trigger subscribes to it: the project's trigger list names it
				Config: topicConfig(srv, "") + `
resource "runr_project" "billing" {
  name      = "billing"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id
  triggers = [
    { name = "orders", type = "topic", config = jsonencode({ topic = runr_topic.orders.name }) },
  ]
}
`,
				Check: resource.TestCheckResourceAttr("runr_project.billing", "triggers.0.type", "topic"),
			},
		},
	})
}

func TestTopicValidation(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{{
			Config: providerBlock(srv) + `
resource "runr_topic" "bad" {
  name      = "-orders"
  region_id = data.runr_region.eu.id
}
`,
			ExpectError: phrase("must start with a letter or digit"),
		}},
	})
}
