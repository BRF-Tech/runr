package provider

import (
	"encoding/json"
	"fmt"
	"strings"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/knownvalue"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/statecheck"
	"github.com/hashicorp/terraform-plugin-testing/tfjsonpath"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func triggerProject(srv *runrtest.Server, triggers string) string {
	return providerBlock(srv) + `
resource "runr_project" "events" {
  name      = "events"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id
` + triggers + `
}
`
}

// serverTriggers checks the fake's list: "name:type[:disabled]" in order.
func serverTriggers(srv *runrtest.Server, project string, want ...string) func() error {
	return func() error {
		var got []string
		for _, t := range srv.Triggers(project) {
			s := t.Name + ":" + t.Type
			if !t.Enabled {
				s += ":disabled"
			}
			got = append(got, s)
		}
		if strings.Join(got, ",") != strings.Join(want, ",") {
			return fmt.Errorf("triggers %v, want %v", got, want)
		}
		return nil
	}
}

func serverTriggerConfig(srv *runrtest.Server, project, name string, want map[string]any) func() error {
	return func() error {
		for _, t := range srv.Triggers(project) {
			if t.Name != name {
				continue
			}
			got, _ := json.Marshal(t.Config)
			exp, _ := json.Marshal(want)
			if string(got) != string(exp) {
				return fmt.Errorf("trigger %s config %s, want %s", name, got, exp)
			}
			return nil
		}
		return fmt.Errorf("trigger %s missing", name)
	}
}

const triggersV1 = `
  triggers = [
    { type = "http" },
    {
      name   = "nightly"
      type   = "schedule"
      config = jsonencode({ cron = "0 2 * * *", timezone = "Europe/Berlin" })
    },
    {
      name    = "orders"
      type    = "kafka"
      config  = jsonencode({ brokers = ["kafka-1:9092"], topic = "orders", sasl = { mechanism = "plain", username = "svc" } })
      secrets = { sasl_password = "kafka-secret" }
    },
    {
      name    = "github"
      type    = "webhook"
      config  = jsonencode({ verify = "github" })
      secrets = { signing_secret = "whsec-0123456789" }
    },
  ]`

func TestProjectTriggerList(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "events"),
		Steps: []resource.TestStep{
			{
				Config: triggerProject(srv, triggersV1),
				Check: resource.ComposeAggregateTestCheckFunc(
					check(serverTriggers(srv, "events", "http:http", "nightly:schedule", "orders:kafka", "github:webhook")),
					// RunR stores the kafka settings with its defaults; the state keeps the configured JSON
					check(serverTriggerConfig(srv, "events", "orders", map[string]any{"brokers": []any{"kafka-1:9092"},
						"sasl": map[string]any{"mechanism": "plain", "username": "svc"}, "start_from": "latest", "topic": "orders"})),
					resource.TestCheckResourceAttr("runr_project.events", "triggers.2.config",
						`{"brokers":["kafka-1:9092"],"sasl":{"mechanism":"plain","username":"svc"},"topic":"orders"}`),
					check(func() error {
						if got := srv.TriggerSecrets("events", "orders")["sasl_password"]; got != "kafka-secret" {
							return fmt.Errorf("kafka secret %q", got)
						}
						if got := srv.TriggerSecrets("events", "github")["signing_secret"]; got != "whsec-0123456789" {
							return fmt.Errorf("webhook secret %q", got)
						}
						return nil
					}),
					resource.TestCheckResourceAttr("runr_project.events", "triggers.0.config", "{}"),
					resource.TestCheckResourceAttr("runr_project.events", "triggers.0.primary", "true"),
					resource.TestCheckResourceAttr("runr_project.events", "triggers.1.effective_timezone", "Europe/Berlin"),
					resource.TestCheckResourceAttr("runr_project.events", "triggers.2.runs_on", "gateway"),
					resource.TestCheckResourceAttr("runr_project.events", "triggers.2.secret_names.0", "sasl_password"),
					resource.TestCheckResourceAttrSet("runr_project.events", "triggers.3.url"),
					resource.TestCheckResourceAttr("runr_project.events", "trigger.type", "http"),
					resource.TestCheckResourceAttr("runr_project.events", "trigger.schedule", "0 2 * * *"),
				),
			},
			{
				ResourceName:      "runr_project.events",
				ImportState:       true,
				ImportStateId:     "events",
				ImportStateVerify: true,
				// RunR's normalized config and no secret values come with an import
				ImportStateVerifyIgnore: []string{"triggers.2.config", "triggers.2.secrets", "triggers.3.secrets"},
			},
			{
				// rename, reorder, disable, change a setting, drop a secret, remove a trigger
				Config: triggerProject(srv, `
  triggers = [
    {
      name   = "nightly"
      type   = "schedule"
      config = jsonencode({ cron = "0 3 * * *", timezone = "Europe/Berlin" })
    },
    { type = "http" },
    {
      name    = "orders"
      type    = "kafka"
      enabled = false
      config  = jsonencode({ brokers = ["kafka-1:9092", "kafka-2:9092"], topic = "orders" })
      secrets = {}
    },
  ]`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_project.events", plancheck.ResourceActionUpdate),
					plancheck.ExpectUnknownValue("runr_project.events", tfjsonpath.New("trigger")),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					check(serverTriggers(srv, "events", "nightly:schedule", "http:http", "orders:kafka:disabled")),
					check(serverTriggerConfig(srv, "events", "nightly", map[string]any{"cron": "0 3 * * *", "timezone": "Europe/Berlin"})),
					check(func() error {
						if s := srv.TriggerSecrets("events", "orders"); len(s) != 0 {
							return fmt.Errorf("secrets left: %v", s)
						}
						return nil
					}),
					resource.TestCheckResourceAttr("runr_project.events", "trigger.type", "schedule"),
					resource.TestCheckResourceAttr("runr_project.events", "triggers.0.primary", "true"),
					resource.TestCheckResourceAttr("runr_project.events", "triggers.2.enabled", "false"),
					resource.TestCheckResourceAttr("runr_project.events", "triggers.2.runs_on", "disabled"),
				),
			},
			{
				// left out: the list stays, also when it was changed outside Terraform
				PreConfig: func() {
					p, _ := srv.Project("events")
					if _, err := client(t, srv).CreateTrigger(t.Context(), p.ID, runr.TriggerInput{Type: runr.TriggerQueue,
						Config: map[string]any{"stream": "jobs"}}); err != nil {
						t.Fatal(err)
					}
				},
				Config: triggerProject(srv, ""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectEmptyPlan(),
				}},
				ConfigStateChecks: []statecheck.StateCheck{
					statecheck.ExpectKnownValue("runr_project.events", tfjsonpath.New("triggers").AtSliceIndex(3).AtMapKey("type"),
						knownvalue.StringExact("queue")),
				},
				Check: check(serverTriggers(srv, "events", "nightly:schedule", "http:http", "orders:kafka:disabled", "queue:queue")),
			},
			{
				// set again: the list is the configured one (the queue trigger goes)
				Config: triggerProject(srv, triggersV1),
				Check: resource.ComposeAggregateTestCheckFunc(
					check(serverTriggers(srv, "events", "http:http", "nightly:schedule", "orders:kafka", "github:webhook")),
					check(func() error {
						if got := srv.TriggerSecrets("events", "orders")["sasl_password"]; got != "kafka-secret" {
							return fmt.Errorf("kafka secret %q", got)
						}
						return nil
					}),
				),
			},
			{
				// a secret removed outside Terraform is set again
				PreConfig: func() {
					p, _ := srv.Project("events")
					if _, err := client(t, srv).UpdateTrigger(t.Context(), p.ID, "orders", runr.TriggerInput{
						Config:  map[string]any{"brokers": []any{"kafka-1:9092"}, "topic": "orders"},
						Secrets: map[string]string{"sasl_password": ""}}); err != nil {
						t.Fatal(err)
					}
				},
				Config: triggerProject(srv, triggersV1),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_project.events", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					if got := srv.TriggerSecrets("events", "orders")["sasl_password"]; got != "kafka-secret" {
						return fmt.Errorf("kafka secret %q", got)
					}
					return nil
				}),
			},
			{
				// nothing changes with the same list
				Config:   triggerProject(srv, triggersV1),
				PlanOnly: true,
			},
		},
	})
}

// The event sources a project's event gateway reads, each with RunR's defaults filled in.
func TestProjectTriggerSources(t *testing.T) {
	srv := fake(t)
	srv.SeedTopic("billing", "eu-1")
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "events"),
		Steps: []resource.TestStep{
			{
				Config: triggerProject(srv, `
  triggers = [
    { name = "bus", type = "nats", config = jsonencode({ url = "nats://nats:4222", subject = "orders.>", auth = { mode = "token" } }), secrets = { token = "nats-token" } },
    { name = "amqp", type = "rabbitmq", config = jsonencode({ queue = "orders", prefetch = 10 }), secrets = { url = "amqps://u:p@rabbit/vh" } },
    { name = "iot", type = "mqtt", config = jsonencode({ broker = "mqtts://mqtt:8883", topic = "sensors/#", qos = 2 }) },
    { name = "db", type = "cdc", config = jsonencode({ publication = "runr_orders", tables = ["public.orders"], create_publication = true }), secrets = { dsn = "postgres://cdc@db/app" } },
    { name = "inbox", type = "email", config = jsonencode({ host = "imap.example.com", username = "orders@example.com" }), secrets = { password = "imap-secret" } },
    { name = "uploads", type = "s3", config = jsonencode({ endpoint = "s3.example.com", bucket = "uploads" }), secrets = { access_key = "AK", secret_key = "SK" } },
    { name = "billing", type = "topic", config = jsonencode({ topic = "billing" }) },
    { name = "deploys", type = "platform_event", config = jsonencode({ events = ["deployment.failed"] }) },
    { name = "jobs", type = "queue", config = jsonencode({ stream = "jobs" }) },
  ]`),
				Check: resource.ComposeAggregateTestCheckFunc(
					check(serverTriggers(srv, "events", "bus:nats", "amqp:rabbitmq", "iot:mqtt", "db:cdc", "inbox:email",
						"uploads:s3", "billing:topic", "deploys:platform_event", "jobs:queue")),
					check(serverTriggerConfig(srv, "events", "inbox", map[string]any{"after": "mark_seen", "host": "imap.example.com",
						"include_raw": false, "mailbox": "INBOX", "port": 993, "tls": "implicit", "username": "orders@example.com"})),
					check(serverTriggerConfig(srv, "events", "db", map[string]any{"create_publication": true, "create_slot": true,
						"delivery": map[string]any{"concurrency": 1}, "operations": []any{"insert", "update", "delete"},
						"publication": "runr_orders", "tables": []any{"public.orders"}})),
					resource.TestCheckResourceAttr("runr_project.events", "trigger.type", "nats"),
				),
			},
			{
				Config: triggerProject(srv, `
  triggers = [
    { name = "bus", type = "nats", config = jsonencode({ url = "nats://nats:4222", subject = "orders.>", auth = { mode = "token" } }), secrets = { token = "nats-token" } },
    { name = "amqp", type = "rabbitmq", config = jsonencode({ queue = "orders", prefetch = 10 }), secrets = { url = "amqps://u:p@rabbit/vh" } },
    { name = "iot", type = "mqtt", config = jsonencode({ broker = "mqtts://mqtt:8883", topic = "sensors/#", qos = 2 }) },
    { name = "db", type = "cdc", config = jsonencode({ publication = "runr_orders", tables = ["public.orders"], create_publication = true }), secrets = { dsn = "postgres://cdc@db/app" } },
    { name = "inbox", type = "email", config = jsonencode({ host = "imap.example.com", username = "orders@example.com" }), secrets = { password = "imap-secret" } },
    { name = "uploads", type = "s3", config = jsonencode({ endpoint = "s3.example.com", bucket = "uploads" }), secrets = { access_key = "AK", secret_key = "SK" } },
    { name = "billing", type = "topic", config = jsonencode({ topic = "billing" }) },
    { name = "deploys", type = "platform_event", config = jsonencode({ events = ["deployment.failed"] }) },
    { name = "jobs", type = "queue", config = jsonencode({ stream = "jobs" }) },
  ]`),
				PlanOnly: true,
			},
		},
	})
}

// A setting RunR does not keep is an error that names what RunR stored.
func TestProjectTriggerConfigNotKept(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{{
			Config: triggerProject(srv, `
  triggers = [
    { type = "http", config = jsonencode({ path = "/hooks" }) },
  ]`),
			ExpectError: phrase("RunR stored another trigger config"),
		}},
	})
}

// trigger.type takes workflow; a changed primary trigger makes the list known after apply.
func TestProjectWorkflowTriggerType(t *testing.T) {
	srv := fake(t)
	cfg := func(trigger string) string {
		return triggerProject(srv, "  trigger = "+trigger)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "events"),
		Steps: []resource.TestStep{
			{
				Config: cfg(`{ type = "http" }`),
				Check:  check(serverTriggers(srv, "events", "http:http")),
			},
			{
				Config: cfg(`{ type = "workflow" }`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectUnknownValue("runr_project.events", tfjsonpath.New("triggers")),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					check(serverTriggers(srv, "events", "workflow:workflow")),
					resource.TestCheckResourceAttr("runr_project.events", "trigger.type", "workflow"),
					resource.TestCheckResourceAttr("runr_project.events", "triggers.0.type", "workflow"),
				),
			},
			{
				Config:   cfg(`{ type = "workflow" }`),
				PlanOnly: true,
			},
		},
	})
}

func TestProjectTriggerListValidation(t *testing.T) {
	srv := fake(t)
	for body, want := range map[string]string{
		`trigger = { type = "http" }
  triggers = [{ type = "http" }]`: "trigger and triggers both set",
		`triggers = [{ type = "http" }, { type = "http", name = "web" }]`:                "more than one http trigger",
		`triggers = [{ type = "workflow" }, { type = "schedule", config = "{}" }]`:       "workflow trigger with others",
		`triggers = [{ type = "schedule", name = "a" }, { type = "queue", name = "a" }]`: "duplicate trigger name",
		`triggers = [{ type = "kafka", secrets = { password = "x" } }]`:                  "unknown trigger secret",
		`triggers = [{ type = "http", config = "[1]" }]`:                                 "config must be a JSON object",
		`triggers = [{ type = "http", name = "Web" }]`:                                   "lower-case letters",
		`trigger = { type = "webhook" }`:                                                 "value must be one of",
		`triggers = [{ type = "schedule", config = "{}" }]
  deploy_strategy = { strategy = "canary" }`: "strategy needs an http or workflow trigger",
	} {
		resource.UnitTest(t, resource.TestCase{
			ProtoV6ProviderFactories: providerFactories(),
			Steps: []resource.TestStep{{
				Config:      triggerProject(srv, "  "+body),
				ExpectError: phrase(want),
			}},
		})
	}
}

func TestJSONSubset(t *testing.T) {
	got := map[string]any{"brokers": []any{"a:1"}, "topic": "t", "start_from": "latest", "port": float64(993),
		"include_raw": false, "tls": map[string]any{"enabled": true, "insecure_skip_verify": false}}
	for want, ok := range map[string]bool{
		`{}`:                                     true,
		`{"topic":"t"}`:                          true,
		`{"topic":"t","group_id":""}`:            true,
		`{"port":"993"}`:                         true,
		`{"include_raw":0}`:                      true,
		`{"brokers":["a:1",""]}`:                 true,
		`{"tls":{"enabled":true}}`:               true,
		`{"tls":{"enabled":"1"}}`:                true,
		`{"topic":"u"}`:                          false,
		`{"brokers":["a:1","b:2"]}`:              false,
		`{"path":"/x"}`:                          false,
		`{"tls":{"ca_pem":"x"}}`:                 false,
		`{"sasl":{"mechanism":null}}`:            true,
		`{"start_from":"earliest"}`:              false,
		`not json`:                               false,
		`{"tls":{"insecure_skip_verify":false}}`: true,
	} {
		if jsonSubset(want, got) != ok {
			t.Errorf("jsonSubset(%s) = %v, want %v", want, !ok, ok)
		}
	}
}
