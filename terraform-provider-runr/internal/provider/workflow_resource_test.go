package provider

import (
	"context"
	"fmt"
	"strings"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

const processOrder = `
resource "runr_workflow" "orders" {
  project_id  = %q
  name        = %q
  description = %q
  triggers = [
    { event = "order.created", condition = "event.data.total > 0 " },
    { queue = "orders" },
    { cron = "0 3 * * *", timezone = "Europe/Istanbul" },
  ]
  retries     = %d
  timeout     = "1d"
  concurrency = [{ limit = 5 }, { limit = 1, key = "event.data.customer_id" }]
  idempotency = "event.data.order_id"
  cancel_on   = [{ event = "order.cancelled", expression = "event.data.order_id == async.data.order_id" }]
%s
}
`

func workflowState(srv *runrtest.Server, name string) (runr.Workflow, error) {
	w, ok := srv.Workflow("flows", name)
	if !ok {
		return w, fmt.Errorf("workflow %s does not exist", name)
	}
	return w, nil
}

func TestWorkflow(t *testing.T) {
	srv := fake(t)
	p := srv.SeedProject("flows", runr.TypeFaaS, "python", "eu-1", map[string]any{"trigger_type": "workflow"})
	srv.SeedDeclaredWorkflow("flows", "other", runr.WorkflowTrigger{Event: "x.y"})
	full := func(name, description string, retries int, extra string) string {
		return providerBlock(srv) + fmt.Sprintf(processOrder, p.ID, name, description, retries, extra)
	}
	short := func(description string) string {
		return providerBlock(srv) + fmt.Sprintf(`
resource "runr_workflow" "orders" {
  project_id  = %q
  name        = "process-order"
  description = %q
  timeout     = "1d"
  concurrency = [{ limit = 5 }, { limit = 1, key = "event.data.customer_id" }]
  idempotency = "event.data.order_id"
  cancel_on   = [{ event = "order.cancelled", expression = "event.data.order_id == async.data.order_id" }]
}

resource "runr_workflow_trigger" "paid" {
  project_id = %[1]q
  workflow   = runr_workflow.orders.name
  event      = "order.paid"
}
`, p.ID, description)
	}
	otherKept := check(func() error {
		w, err := workflowState(srv, "other")
		if err != nil || w.Archived || len(w.Triggers) != 1 || w.Triggers[0].Event != "x.y" {
			return fmt.Errorf("the other workflow changed: %+v %v", w, err)
		}
		return nil
	})
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(*terraform.State) error {
			for _, n := range []string{"process-order", "orders-v2"} {
				if w, ok := srv.Workflow("flows", n); ok && !w.Archived {
					return fmt.Errorf("workflow %s was not archived", n)
				}
			}
			return otherKept(nil)
		},
		Steps: []resource.TestStep{
			{
				Config: full("process-order", "Orders", 5, ""),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_workflow.orders", "id", p.ID+"/process-order"),
					resource.TestCheckResourceAttr("runr_workflow.orders", "active", "true"),
					resource.TestCheckResourceAttr("runr_workflow.orders", "triggers.0.condition", "event.data.total > 0 "),
					resource.TestCheckResourceAttr("runr_workflow.orders", "triggers.1.queue", "orders"),
					resource.TestCheckNoResourceAttr("runr_workflow.orders", "triggers.1.event"),
					check(func() error {
						w, err := workflowState(srv, "process-order")
						if err != nil {
							return err
						}
						if w.Source != "api" || w.Description != "Orders" || w.Retries != 5 || w.Timeout != "1d" || len(w.Triggers) != 3 ||
							w.Triggers[0].If != "event.data.total > 0" || w.Triggers[1].Event != "queue/orders" ||
							w.Triggers[2].Timezone != "Europe/Istanbul" || len(w.Concurrency) != 2 || w.Concurrency[1].Key != "event.data.customer_id" ||
							w.Idempotency != "event.data.order_id" || w.CancelOn[0].Expression != "(event.data.order_id == async.data.order_id)" {
							return fmt.Errorf("workflow = %+v", w)
						}
						return nil
					}),
					otherKept,
				),
			},
			{
				// What the API normalised (queue triggers, cancel_on parentheses, trimmed strings) plans no change.
				Config: full("process-order", "Orders", 5, ""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectEmptyPlan(),
				}},
			},
			{
				Config: full("process-order", "All orders", 2, "  active = false"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_workflow.orders", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					w, _ := workflowState(srv, "process-order")
					if w.Description != "All orders" || w.Retries != 2 || w.IsActive {
						return fmt.Errorf("workflow = %+v", w)
					}
					return otherKept(nil)
				}),
			},
			{
				// triggers, retries and active left out: the workflow keeps them; runr_workflow_trigger
				// adds a trigger, which runr_workflow then keeps.
				Config: short("All orders"),
				Check: check(func() error {
					w, _ := workflowState(srv, "process-order")
					if len(w.Triggers) != 4 || w.Triggers[3].Event != "order.paid" || w.Retries != 2 || w.IsActive {
						return fmt.Errorf("workflow = %+v", w)
					}
					return nil
				}),
			},
			{
				Config: short("All orders"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectEmptyPlan(),
				}},
			},
			{
				// A change of the definition keeps the triggers of runr_workflow_trigger.
				Config: short("Every order"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_workflow.orders", plancheck.ResourceActionUpdate),
					plancheck.ExpectResourceAction("runr_workflow_trigger.paid", plancheck.ResourceActionNoop),
				}},
				Check: check(func() error {
					w, _ := workflowState(srv, "process-order")
					if w.Description != "Every order" || len(w.Triggers) != 4 {
						return fmt.Errorf("workflow = %+v", w)
					}
					return nil
				}),
			},
			{
				ResourceName:      "runr_workflow.orders",
				ImportState:       true,
				ImportStateId:     "flows/process-order",
				ImportStateVerify: true,
			},
			{
				// Resumed outside Terraform while active is left out: no change.
				PreConfig: func() {
					if _, err := client(t, srv).SetWorkflowActive(context.Background(), p.ID, "process-order", true); err != nil {
						t.Fatal(err)
					}
				},
				Config: short("Every order"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectEmptyPlan(),
				}},
			},
			{
				// The definition changed outside Terraform: put back.
				PreConfig: func() {
					w, _ := workflowState(srv, "process-order")
					w.Timeout = "2h"
					w.Idempotency = ""
					o, _ := workflowState(srv, "other")
					if _, err := client(t, srv).DeclareWorkflows(context.Background(), p.ID, []map[string]any{w.Definition(), o.Definition()}); err != nil {
						t.Fatal(err)
					}
				},
				Config: short("Every order"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_workflow.orders", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					w, _ := workflowState(srv, "process-order")
					if w.Timeout != "1d" || w.Idempotency != "event.data.order_id" || len(w.Triggers) != 4 {
						return fmt.Errorf("workflow = %+v", w)
					}
					return nil
				}),
			},
			{
				// Archived outside Terraform: declared again.
				PreConfig: func() {
					o, _ := workflowState(srv, "other")
					if _, err := client(t, srv).DeclareWorkflows(context.Background(), p.ID, []map[string]any{o.Definition()}); err != nil {
						t.Fatal(err)
					}
				},
				Config: full("process-order", "Orders", 5, ""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_workflow.orders", plancheck.ResourceActionCreate),
				}},
				Check: check(func() error {
					w, err := workflowState(srv, "process-order")
					if err != nil || w.Archived || len(w.Triggers) != 3 {
						return fmt.Errorf("workflow = %+v %v", w, err)
					}
					return nil
				}),
			},
			{
				// A new name is a new workflow; the old one is archived.
				Config: full("orders-v2", "Orders", 5, ""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_workflow.orders", plancheck.ResourceActionDestroyBeforeCreate),
				}},
				Check: check(func() error {
					if old, _ := workflowState(srv, "process-order"); !old.Archived {
						return fmt.Errorf("the old workflow was not archived")
					}
					if _, err := workflowState(srv, "orders-v2"); err != nil {
						return err
					}
					return otherKept(nil)
				}),
			},
		},
	})
}

// Every environment has workflows and a workflow app of its own.
func TestWorkflowEnvironment(t *testing.T) {
	srv := fake(t)
	p := srv.SeedProject("flows", runr.TypeFaaS, "python", "eu-1", map[string]any{"trigger_type": "workflow"})
	srv.SeedDeclaredWorkflow("flows", "nightly", runr.WorkflowTrigger{Cron: "0 1 * * *"})
	srv.SeedEnvironment("flows", "staging", false)
	srv.SeedEnvironment("flows", "qa", false)
	cfg := func(env string) string {
		return providerBlock(srv) + fmt.Sprintf(`
resource "runr_workflow" "nightly" {
  project_id  = %q
  environment = %q
  name        = "nightly"
  triggers    = [{ cron = "0 3 * * *" }]
}

resource "runr_workflow_app" "staging" {
  project_id   = %[1]q
  environment  = %[2]q
  endpoint_url = "https://%[2]s.example.com/workflows"
}
`, p.ID, env)
	}
	productionKept := check(func() error {
		w, ok := srv.Workflow("flows", "nightly")
		if !ok || w.Archived || w.Triggers[0].Cron != "0 1 * * *" || srv.WorkflowEndpointOverride("flows") != "" {
			return fmt.Errorf("production changed: %+v, override %q", w, srv.WorkflowEndpointOverride("flows"))
		}
		return nil
	})
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(*terraform.State) error {
			for _, env := range []string{"staging", "qa"} {
				if w, ok := srv.WorkflowIn("flows", env, "nightly"); ok && !w.Archived {
					return fmt.Errorf("the %s workflow was not archived", env)
				}
				if o := srv.WorkflowEndpointOverrideIn("flows", env); o != "" {
					return fmt.Errorf("the %s endpoint override is still %s", env, o)
				}
			}
			return productionKept(nil)
		},
		Steps: []resource.TestStep{
			{
				Config: cfg("staging"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_workflow.nightly", "id", p.ID+"/staging/nightly"),
					resource.TestCheckResourceAttr("runr_workflow_app.staging", "id", p.ID+"/staging"),
					check(func() error {
						w, ok := srv.WorkflowIn("flows", "staging", "nightly")
						if !ok || w.Triggers[0].Cron != "0 3 * * *" {
							return fmt.Errorf("staging workflow = %+v", w)
						}
						if o := srv.WorkflowEndpointOverrideIn("flows", "staging"); o != "https://staging.example.com/workflows" {
							return fmt.Errorf("staging override = %q", o)
						}
						return nil
					}),
					productionKept,
				),
			},
			{
				ResourceName:      "runr_workflow.nightly",
				ImportState:       true,
				ImportStateId:     "flows/staging/nightly",
				ImportStateVerify: true,
			},
			{
				ResourceName:      "runr_workflow_app.staging",
				ImportState:       true,
				ImportStateId:     "flows/staging",
				ImportStateVerify: true,
			},
			{
				Config: cfg("qa"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_workflow.nightly", plancheck.ResourceActionDestroyBeforeCreate),
					plancheck.ExpectResourceAction("runr_workflow_app.staging", plancheck.ResourceActionDestroyBeforeCreate),
				}},
				Check: check(func() error {
					if w, _ := srv.WorkflowIn("flows", "staging", "nightly"); !w.Archived {
						return fmt.Errorf("the staging workflow was not archived")
					}
					if srv.WorkflowEndpointOverrideIn("flows", "staging") != "" {
						return fmt.Errorf("the staging override was not removed")
					}
					if w, ok := srv.WorkflowIn("flows", "qa", "nightly"); !ok || w.Archived {
						return fmt.Errorf("qa workflow = %+v", w)
					}
					return productionKept(nil)
				}),
			},
		},
	})
}

// runr_workflow and runr_workflow_trigger declare one definition at a time (PUT / DELETE
// /projects/{project}/workflows/{workflow}): what the function's SDK reported is never sent, so
// it stays source sdk — also through create, update, a trigger and destroy.
func TestWorkflowLeavesTheSDKsDefinitionsAlone(t *testing.T) {
	srv := fake(t)
	p := srv.SeedProject("flows", runr.TypeFaaS, "python", "eu-1", map[string]any{"trigger_type": "workflow"})
	sdk := srv.SeedWorkflow("flows", "from-code", runr.WorkflowTrigger{Event: "a.b"})
	config := func(description string) string {
		return providerBlock(srv) + fmt.Sprintf(`
resource "runr_workflow" "report" {
  project_id  = %q
  name        = "report"
  description = %q
}

resource "runr_workflow_trigger" "monthly" {
  project_id = %[1]q
  workflow   = runr_workflow.report.name
  cron       = "0 6 1 * *"
}
`, p.ID, description)
	}
	sdkKept := check(func() error {
		w, err := workflowState(srv, "from-code")
		if err != nil || w.Source != "sdk" || w.Archived || w.SyncedAt != sdk.SyncedAt {
			return fmt.Errorf("the SDK's workflow changed: %+v %v", w, err)
		}
		if n := len(srv.CallsTo("PUT /projects/{project}/workflows")); n != 0 {
			return fmt.Errorf("the set was declared %d times", n)
		}
		return nil
	})
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(*terraform.State) error {
			if w, _ := srv.Workflow("flows", "report"); !w.Archived {
				return fmt.Errorf("report was not archived: %+v", w)
			}
			if n := len(srv.CallsTo("DELETE /projects/{project}/workflows/{workflow}")); n != 1 {
				return fmt.Errorf("DELETE calls = %d", n)
			}
			return sdkKept(nil)
		},
		Steps: []resource.TestStep{
			{
				Config: config("Monthly report"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_workflow.report", "description", "Monthly report"),
					check(func() error {
						w, err := workflowState(srv, "report")
						if err != nil || w.Source != "api" || len(w.Triggers) != 1 || w.Triggers[0].Cron != "0 6 1 * *" {
							return fmt.Errorf("report = %+v %v", w, err)
						}
						// runr_workflow, then runr_workflow_trigger: one definition each time
						if n := len(srv.CallsTo("PUT /projects/{project}/workflows/{workflow}")); n != 2 {
							return fmt.Errorf("PUT calls = %d", n)
						}
						return nil
					}),
					sdkKept,
				),
			},
			{
				Config: config("Report of the month"),
				Check: resource.ComposeAggregateTestCheckFunc(
					check(func() error {
						if w, _ := workflowState(srv, "report"); w.Description != "Report of the month" || len(w.Triggers) != 1 {
							return fmt.Errorf("report = %+v", w)
						}
						return nil
					}),
					sdkKept,
				),
			},
		},
	})
}

// An API before 0.6.0 has no single-definition endpoints: the resources declare the set again,
// and a definition sent back unchanged keeps its source there too.
func TestWorkflowOnAnEarlierAPI(t *testing.T) {
	srv := fake(t)
	srv.Without("PUT /projects/{project}/workflows/{workflow}", "DELETE /projects/{project}/workflows/{workflow}")
	p := srv.SeedProject("flows", runr.TypeFaaS, "python", "eu-1", map[string]any{"trigger_type": "workflow"})
	srv.SeedWorkflow("flows", "from-code", runr.WorkflowTrigger{Event: "a.b"})
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(*terraform.State) error {
			if w, _ := srv.Workflow("flows", "report"); !w.Archived {
				return fmt.Errorf("report was not archived: %+v", w)
			}
			if w, _ := srv.Workflow("flows", "from-code"); w.Archived || w.Source != "sdk" {
				return fmt.Errorf("from-code = %+v", w)
			}
			return nil
		},
		Steps: []resource.TestStep{
			{
				Config: providerBlock(srv) + fmt.Sprintf(`
resource "runr_workflow" "report" {
  project_id  = %q
  name        = "report"
  description = "Monthly report"
}

resource "runr_workflow_trigger" "requested" {
  project_id = %[1]q
  workflow   = runr_workflow.report.name
  event      = "report.requested"
}
`, p.ID),
				Check: check(func() error {
					if w, _ := srv.Workflow("flows", "from-code"); w.Source != "sdk" || w.Archived {
						return fmt.Errorf("from-code = %+v", w)
					}
					if w, _ := srv.Workflow("flows", "report"); w.Source != "api" || len(w.Triggers) != 1 {
						return fmt.Errorf("report = %+v", w)
					}
					if n := len(srv.CallsTo("PUT /projects/{project}/workflows")); n != 2 {
						return fmt.Errorf("set declarations = %d", n)
					}
					return nil
				}),
			},
		},
	})
}

func TestWorkflowRefusals(t *testing.T) {
	srv := fake(t)
	p := srv.SeedProject("flows", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SeedWorkflow("flows", "from-code", runr.WorkflowTrigger{Event: "a.b"})
	srv.SeedDeclaredWorkflow("flows", "declared")
	workflow := func(body string) string {
		return providerBlock(srv) + fmt.Sprintf(`
resource "runr_workflow" "w" {
  project_id = %q
%s
}
`, p.ID, body)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{Config: workflow(`  name = "from-code"`), ExpectError: phrase("Workflow defined by the code")},
			{Config: workflow(`  name = "declared"`), ExpectError: phrase("Workflow already declared")},
			{Config: workflow(`  name     = "w"
  triggers = [{ event = "a.b", cron = "0 3 * * *" }]`), ExpectError: phrase("Set exactly one of event, cron and queue")},
			{Config: workflow(`  name     = "w"
  triggers = [{ event = "a.b", timezone = "UTC" }]`), ExpectError: phrase("timezone without cron")},
			{Config: workflow(`  name     = "w"
  triggers = [{ cron = "0 3 * * *", condition = "true" }]`), ExpectError: phrase("condition on a cron trigger")},
			{Config: workflow(`  name     = "w"
  triggers = [{ event = "queue/orders" }]`), ExpectError: phrase(`Set queue = "orders"`)},
			{Config: workflow(`  name     = "w"
  triggers = [{ event = "runr/cron" }]`), ExpectError: phrase("Reserved event name")},
			{Config: workflow(`  name = "App"`), ExpectError: phrase("none of")},
			{Config: workflow(`  name        = "w"
  concurrency = [{ limit = 1, key = "a" }, { limit = 2, key = "b" }]`), ExpectError: phrase("Two keyed concurrency limits")},
			{Config: workflow(`  name    = "w"
  retries = 21`), ExpectError: phrase("retries")},
			{Config: workflow(`  name    = "w"
  timeout = "soon"`), ExpectError: phrase("must be a duration")},
			{Config: workflow(`  name        = "w"
  environment = "nope"`), ExpectError: phrase(`The project has no environment "nope"`)},
			// The post-test destroy runs with the last configuration: a valid one.
			{Config: providerBlock(srv)},
		},
	})
	if w, _ := srv.Workflow("flows", "from-code"); w.Archived || w.Source != "sdk" {
		t.Fatalf("a refused workflow changed the code's: %+v", w)
	}
}

func TestWorkflowApp(t *testing.T) {
	srv := fake(t)
	p := srv.SeedProject("flows", runr.TypeFaaS, "python", "eu-1", map[string]any{"trigger_type": "workflow"})
	cfg := func(body string) string {
		return providerBlock(srv) + fmt.Sprintf(`
resource "runr_workflow_app" "flows" {
  project_id = %q
%s
}
`, p.ID, body)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(*terraform.State) error {
			if o := srv.WorkflowEndpointOverride("flows"); o != "" {
				return fmt.Errorf("override left: %s", o)
			}
			return nil
		},
		Steps: []resource.TestStep{
			{
				Config: cfg(`  endpoint_url = "https://workflows.example.com/runr"`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_workflow_app.flows", "id", p.ID),
					resource.TestCheckResourceAttr("runr_workflow_app.flows", "url", "https://workflows.example.com/runr"),
					resource.TestCheckResourceAttrWith("runr_workflow_app.flows", "key_fingerprint", func(v string) error {
						if !strings.HasPrefix(v, "sha256:") {
							return fmt.Errorf("fingerprint %q", v)
						}
						return nil
					}),
					check(func() error {
						if o := srv.WorkflowEndpointOverride("flows"); o != "https://workflows.example.com/runr" {
							return fmt.Errorf("override = %q", o)
						}
						return nil
					}),
				),
			},
			{
				ResourceName:      "runr_workflow_app.flows",
				ImportState:       true,
				ImportStateId:     "flows",
				ImportStateVerify: true,
			},
			{
				// The key is rotated (an action): no change.
				PreConfig: func() {
					if _, _, err := client(t, srv).RotateWorkflowKey(context.Background(), p.ID); err != nil {
						t.Fatal(err)
					}
				},
				Config: cfg(`  endpoint_url = "https://workflows.example.com/runr"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectEmptyPlan(),
				}},
			},
			{
				// Changed outside Terraform: put back.
				PreConfig: func() {
					if _, err := client(t, srv).SetWorkflowEndpoint(context.Background(), p.ID, "https://elsewhere.example.com"); err != nil {
						t.Fatal(err)
					}
				},
				Config: cfg(`  endpoint_url = "https://workflows.example.com/runr"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_workflow_app.flows", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					if o := srv.WorkflowEndpointOverride("flows"); o != "https://workflows.example.com/runr" {
						return fmt.Errorf("override = %q", o)
					}
					return nil
				}),
			},
			{
				// endpoint_url left out: the project's function.
				Config: cfg(""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_workflow_app.flows", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckNoResourceAttr("runr_workflow_app.flows", "endpoint_url"),
					resource.TestMatchResourceAttr("runr_workflow_app.flows", "url", phrase(".runr.test")),
					check(func() error {
						if o := srv.WorkflowEndpointOverride("flows"); o != "" {
							return fmt.Errorf("override = %q", o)
						}
						return nil
					}),
				),
			},
			{Config: cfg(`  endpoint_url = "https://workflows.example.com/runr"`)},
			{Config: cfg(`  endpoint_url = "ftp://workflows.example.com"`), ExpectError: phrase("must be an http or https URL")},
			{Config: cfg(`  endpoint_url = "http://127.0.0.1:8080"`), ExpectError: phrase("private or loopback")},
		},
	})
}
