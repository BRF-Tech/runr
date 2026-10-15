package provider

import (
	"context"
	"fmt"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/runrsh/core/cli/pkg/runr"
	"github.com/runrsh/core/cli/pkg/runr/runrtest"
)

// wfTriggers returns the triggers of a workflow in the fake as strings.
func wfTriggers(srv *runrtest.Server, name string) []string {
	w, _ := srv.Workflow("flows", name)
	out := []string{}
	for _, t := range w.Triggers {
		out = append(out, t.String())
	}
	return out
}

func wfHasTriggers(srv *runrtest.Server, name string, want ...string) resource.TestCheckFunc {
	return func(*terraform.State) error {
		got := wfTriggers(srv, name)
		if fmt.Sprint(got) != fmt.Sprint(want) {
			return fmt.Errorf("triggers of %s = %q, want %q", name, got, want)
		}
		return nil
	}
}

func TestWorkflowTriggers(t *testing.T) {
	srv := fake(t)
	p := srv.SeedProject("flows", runr.TypeFaaS, "python", "eu-1", map[string]any{"trigger_type": "workflow"})
	srv.SeedDeclaredWorkflow("flows", "process-order", runr.WorkflowTrigger{Event: "order.created"})
	srv.SeedDeclaredWorkflow("flows", "nightly")
	cfg := func(condition string) string {
		return providerBlock(srv) + fmt.Sprintf(`
resource "runr_workflow_trigger" "paid" {
  project_id = %[1]q
  workflow   = "process-order"
  event      = "order.paid"
  condition  = %[2]q
}
resource "runr_workflow_trigger" "orders" {
  project_id = %[1]q
  workflow   = "process-order"
  queue      = "orders"
}
resource "runr_workflow_trigger" "nightly" {
  project_id = %[1]q
  workflow   = "nightly"
  cron       = "0 3 * * *"
  timezone   = "Europe/Istanbul"
}
`, p.ID, condition)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(*terraform.State) error {
			if err := wfHasTriggers(srv, "process-order", "event order.created")(nil); err != nil {
				return err
			}
			return wfHasTriggers(srv, "nightly")(nil)
		},
		Steps: []resource.TestStep{
			{
				// Parallel resources on one project: no update may be lost.
				Config: cfg("event.data.total == 1"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_workflow_trigger.orders", "id", p.ID+"/process-order/queue:orders"),
					resource.TestCheckResourceAttr("runr_workflow_trigger.nightly", "id", p.ID+"/nightly/cron:0 3 * * *@Europe/Istanbul"),
					check(func() error {
						got := wfTriggers(srv, "process-order")
						want := map[string]bool{"event order.created": true, "event order.paid if event.data.total == 1": true, "queue orders": true}
						if len(got) != 3 {
							return fmt.Errorf("process-order triggers = %q", got)
						}
						for _, g := range got {
							if !want[g] {
								return fmt.Errorf("process-order triggers = %q", got)
							}
						}
						return nil
					}),
					wfHasTriggers(srv, "nightly", "cron 0 3 * * * (Europe/Istanbul)"),
				),
			},
			{
				// A new condition replaces the trigger.
				Config: cfg("event.data.total == 2"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_workflow_trigger.paid", plancheck.ResourceActionDestroyBeforeCreate),
					plancheck.ExpectResourceAction("runr_workflow_trigger.orders", plancheck.ResourceActionNoop),
				}},
				Check: check(func() error {
					for _, g := range wfTriggers(srv, "process-order") {
						if g == "event order.paid if event.data.total == 1" {
							return fmt.Errorf("the old condition is still there: %q", wfTriggers(srv, "process-order"))
						}
					}
					return nil
				}),
			},
			{
				// Removed outside Terraform: the next apply adds it again.
				PreConfig: func() {
					c := client(t, srv)
					w, _ := srv.Workflow("flows", "nightly")
					w.Triggers = nil
					defs := []map[string]any{w.Definition()}
					pw, _ := srv.Workflow("flows", "process-order")
					defs = append(defs, pw.Definition())
					if _, err := c.DeclareWorkflows(context.Background(), p.ID, defs); err != nil {
						t.Fatal(err)
					}
				},
				Config: cfg("event.data.total == 2"),
				Check:  wfHasTriggers(srv, "nightly", "cron 0 3 * * * (Europe/Istanbul)"),
			},
			{
				ResourceName:      "runr_workflow_trigger.orders",
				ImportState:       true,
				ImportStateId:     "flows/process-order/queue:orders",
				ImportStateVerify: true,
			},
			{
				ResourceName:      "runr_workflow_trigger.paid",
				ImportState:       true,
				ImportStateId:     "flows/process-order/event:order.paid",
				ImportStateVerify: true,
			},
		},
	})
}

func TestWorkflowTriggerRefusals(t *testing.T) {
	srv := fake(t)
	p := srv.SeedProject("flows", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SeedWorkflow("flows", "from-code", runr.WorkflowTrigger{Event: "a.b"})
	trigger := func(body string) string {
		return providerBlock(srv) + fmt.Sprintf(`
resource "runr_workflow_trigger" "t" {
  project_id = %q
%s
}
`, p.ID, body)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{Config: trigger(`  workflow = "from-code"
  event    = "order.created"`), ExpectError: phrase("come from the project's code")},
			{Config: trigger(`  workflow = "ghost"
  event    = "order.created"`), ExpectError: phrase("does not exist")},
			{Config: trigger(`  workflow = "from-code"
  event    = "order.created"
  cron     = "0 3 * * *"`), ExpectError: phrase("Set exactly one of event, cron and queue")},
			{Config: trigger(`  workflow = "from-code"
  queue    = "orders"
  timezone = "UTC"`), ExpectError: phrase("timezone without cron")},
			{Config: trigger(`  workflow = "from-code"
  event    = "runr/cron"`), ExpectError: phrase("Reserved event name")},
			// The post-test destroy runs with the last configuration: a valid one.
			{Config: providerBlock(srv)},
		},
	})
	if got := wfTriggers(srv, "from-code"); len(got) != 1 {
		t.Fatalf("a refused trigger changed the workflow: %q", got)
	}
}
