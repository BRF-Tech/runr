package cmd

import (
	"encoding/json"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// trigHarness logs in and deploys hello-api (its http trigger is live).
func trigHarness(t *testing.T) *harness {
	t.Helper()
	h := newHarness(t).login()
	h.deployHello()
	return h
}

func TestTriggersList(t *testing.T) {
	h := trigHarness(t)
	h.srv.SeedTrigger("hello-api", runr.TriggerWebhook, "github", map[string]any{"verify": "github"},
		map[string]string{"signing_secret": "gh-s3cr3t-value"})
	h.srv.SeedTrigger("hello-api", runr.TriggerSchedule, "nightly", map[string]any{"cron": "0 3 * * *"}, nil)
	h.srv.SeedTrigger("hello-api", runr.TriggerSchedule, "morning", map[string]any{"cron": "0 9 * * *", "timezone": "Europe/Berlin"}, nil)

	r := h.mustRun("triggers", "ls", "-p", "hello-api")
	contains(t, r.out, "github")
	contains(t, r.out, "/api/hooks/org/")
	contains(t, r.out, "(verify github)")
	// a schedule's own zone, else the organization's
	contains(t, r.out, "cron 0 3 * * * (Europe/Istanbul, the organization's)")
	contains(t, r.out, "cron 0 9 * * * (Europe/Berlin)")
	contains(t, r.out, "https://hello-api.eu-1.runr.test")
	contains(t, r.out, "Deploys: service, public URL, event gateway.")
	notContains(t, r.out, "gh-s3cr3t-value")

	var list runr.TriggerList
	decodeJSON(t, h.mustRun("triggers", "-p", "hello-api", "-o", "json").out, &list)
	if len(list.Triggers) != 4 || list.Triggers[0].Type != runr.TriggerHTTP || !list.Triggers[0].Primary {
		t.Fatalf("triggers = %+v", list.Triggers)
	}
	if n := list.Find("nightly"); n == nil || n.EffectiveTimezone != "Europe/Istanbul" {
		t.Fatalf("nightly = %+v", n)
	}
	gh := list.Find("github")
	if gh == nil || !reflect.DeepEqual(gh.Secrets, []string{"signing_secret"}) || gh.HasSigningSecret == nil || !*gh.HasSigningSecret ||
		gh.RunsOn != "gateway" || gh.Delivery == nil || gh.Delivery.Concurrency != 4 || gh.RetentionDays == nil || *gh.RetentionDays != 7 {
		t.Fatalf("github = %+v", gh)
	}
	if !list.Plan.Public || !list.Plan.Gateway || list.Plan.Workload != "service" {
		t.Fatalf("plan = %+v", list.Plan)
	}
}

// The signing secret is read from stdin, sent once and never printed (not in the debug trace
// either).
func TestTriggersAddWebhookSecret(t *testing.T) {
	h := trigHarness(t)
	r := h.runStdin("s3cr3t-value\n", "triggers", "add", "--type", "webhook", "--name", "github", "--set", "verify=github",
		"--secret-stdin", "--no-deploy", "-p", "hello-api")
	if r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	contains(t, r.out, "Trigger github added.")
	contains(t, r.out, "/api/hooks/org/")
	contains(t, r.out, "Signing secret:")
	contains(t, r.err, "Saved; the next deployment applies it.")
	notContains(t, r.out+r.err, "s3cr3t-value")
	body := h.srv.CallsTo("POST /projects/{project}/triggers")[0].Body
	if body["type"] != "webhook" || body["name"] != "github" || body["config"].(map[string]any)["verify"] != "github" ||
		body["secrets"].(map[string]any)["signing_secret"] != "s3cr3t-value" {
		t.Fatalf("body = %v", body)
	}
	if got := h.srv.TriggerSecrets("hello-api", "github")["signing_secret"]; got != "s3cr3t-value" {
		t.Fatalf("stored secret = %q", got)
	}

	r = h.mustRun("--debug", "triggers", "add", "--type", "webhook", "--name", "stripe", "--set", "verify=stripe",
		"--secret", "signing_secret=an0ther-s3cr3t", "--no-deploy", "-p", "hello-api")
	notContains(t, r.err, "an0ther-s3cr3t")
	contains(t, r.err, `"secrets":"[redacted]"`)

	// A verifying webhook needs its secret: 422, exit code 5
	r = h.expectCode(ExitValidation, "triggers", "add", "--type", "webhook", "--name", "slack", "--set", "verify=slack", "--no-deploy", "-p", "hello-api")
	contains(t, r.err, "secrets.signing_secret")

	h.expectCode(ExitUsage, "triggers", "add", "--name", "x", "-p", "hello-api")
	h.expectCode(ExitUsage, "triggers", "add", "--type", "queue", "--set", "nokey", "-p", "hello-api")
}

func TestTriggersAddConfigValues(t *testing.T) {
	h := trigHarness(t)
	h.mustRun("triggers", "add", "--type", "queue", "--name", "orders", "--set", "stream=orders", "--set", "start_from=latest",
		"--set", "delivery.concurrency=8", "--set", "delivery.max_retries=10", "--primary", "--no-deploy", "-p", "hello-api")
	body := h.srv.CallsTo("POST /projects/{project}/triggers")[0].Body
	cfg := body["config"].(map[string]any)
	delivery := cfg["delivery"].(map[string]any)
	if cfg["stream"] != "orders" || cfg["start_from"] != "latest" || delivery["concurrency"] != float64(8) ||
		delivery["max_retries"] != float64(10) || body["position"] != float64(0) {
		t.Fatalf("body = %v", body)
	}
	list := h.srv.Triggers("hello-api")
	if list[0].Name != "orders" || !list[0].Primary || list[0].Delivery.Concurrency != 8 {
		t.Fatalf("triggers = %+v", list)
	}
	if p, _ := h.srv.Project("hello-api"); p.Config.Trigger() != runr.TriggerQueue || p.Config.QueueStream != "orders" {
		t.Fatalf("the primary trigger is not mirrored: %+v", p.Config)
	}

	h.srv.ResetCalls()
	h.mustRun("triggers", "add", "--type", "platform_event", "--set", "events=deployment.failed, alert.fired", "--no-deploy", "-p", "hello-api")
	cfg = h.srv.CallsTo("POST /projects/{project}/triggers")[0].Body["config"].(map[string]any)
	if !reflect.DeepEqual(cfg["events"], []any{"deployment.failed", "alert.fired"}) {
		t.Fatalf("events = %#v", cfg["events"])
	}
	if l := h.srv.Triggers("hello-api"); l[len(l)-1].Name != "platform-event" {
		t.Fatalf("default name = %q", l[len(l)-1].Name)
	}
}

func TestParseConfigValue(t *testing.T) {
	for _, c := range []struct {
		key, raw string
		want     any
	}{
		{"concurrency", "8", int64(8)},
		{"ratio", "0.5", 0.5},
		{"enabled", "true", true},
		{"stream", `"123"`, "123"},
		{"cron", "*/5 * * * *", "*/5 * * * *"},
		{"prefix", "sha256=", "sha256="},
		{"events", `["a","b"]`, []any{"a", "b"}},
		{"events", "a, b,,c", []any{"a", "b", "c"}},
		{"topic", "a,b", "a,b"},
		{"hmac", `{"header":"X-Sig","n":2}`, map[string]any{"header": "X-Sig", "n": int64(2)}},
		{"note", "", ""},
	} {
		if got := parseConfigValue(c.key, c.raw); !reflect.DeepEqual(got, c.want) {
			t.Errorf("parseConfigValue(%q, %q) = %#v, want %#v", c.key, c.raw, got, c.want)
		}
	}

	cfg, err := applySets(map[string]any{"hmac": map[string]any{"header": "X-Sig", "algorithm": "sha1"}, "verify": "hmac"},
		[]string{"hmac.prefix=sha1=", "delivery.concurrency=2"}, []string{"hmac.algorithm", "ghost.key"})
	if err != nil {
		t.Fatal(err)
	}
	want := map[string]any{"verify": "hmac", "hmac": map[string]any{"header": "X-Sig", "prefix": "sha1="},
		"delivery": map[string]any{"concurrency": int64(2)}}
	if !reflect.DeepEqual(cfg, want) {
		t.Fatalf("config = %#v", cfg)
	}
	if _, err := applySets(map[string]any{}, []string{"a..b=1"}, nil); err == nil {
		t.Fatal("an empty key segment was accepted")
	}
}

// update changes single keys: the stored config is the base, the secret stays unless given.
func TestTriggersUpdateMergesConfig(t *testing.T) {
	h := trigHarness(t)
	h.srv.SeedTrigger("hello-api", runr.TriggerWebhook, "billing",
		map[string]any{"verify": "hmac", "hmac": map[string]any{"header": "X-Sig", "algorithm": "sha1"}},
		map[string]string{"signing_secret": "billing-secret"})

	h.mustRun("triggers", "update", "billing", "--set", "hmac.prefix=sha1=", "--unset", "hmac.algorithm",
		"--set", "retention_days=14", "--no-deploy", "-p", "hello-api")
	body := h.srv.CallsTo("PATCH /projects/{project}/triggers/{trigger}")[0].Body
	want := map[string]any{"verify": "hmac", "hmac": map[string]any{"header": "X-Sig", "prefix": "sha1="}, "retention_days": float64(14)}
	if !reflect.DeepEqual(body["config"], want) {
		t.Fatalf("config = %#v", body["config"])
	}
	if _, sent := body["secrets"]; sent {
		t.Fatalf("secrets sent without --secret: %v", body)
	}
	if h.srv.TriggerSecrets("hello-api", "billing")["signing_secret"] != "billing-secret" {
		t.Fatal("the secret was lost")
	}

	h.srv.ResetCalls()
	r := h.runStdin("rotated-secret\n", "triggers", "update", "billing", "--secret-stdin", "--rename", "invoices", "--primary", "--no-deploy", "-p", "hello-api")
	if r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	body = h.srv.CallsTo("PATCH /projects/{project}/triggers/{trigger}")[0].Body
	if _, sent := body["config"]; sent || body["name"] != "invoices" || body["position"] != float64(0) {
		t.Fatalf("body = %v", body)
	}
	if h.srv.TriggerSecrets("hello-api", "invoices")["signing_secret"] != "rotated-secret" || !h.srv.Triggers("hello-api")[0].Primary {
		t.Fatalf("triggers = %+v", h.srv.Triggers("hello-api"))
	}

	h.expectCode(ExitUsage, "triggers", "update", "invoices", "-p", "hello-api")
	h.expectCode(ExitNotFound, "triggers", "update", "ghost", "--set", "a=b", "-p", "hello-api")
}

// enable / disable redeploy the live version unless --no-deploy.
func TestTriggersDisableRedeploys(t *testing.T) {
	h := trigHarness(t)
	h.srv.SeedTrigger("hello-api", runr.TriggerSchedule, "nightly", map[string]any{"cron": "0 3 * * *"}, nil)
	before := len(h.srv.Deployments("hello-api"))

	r := h.mustRun("triggers", "disable", "nightly", "-p", "hello-api")
	contains(t, r.out, "Trigger nightly disabled.")
	contains(t, r.err, "is live")
	if deps := h.srv.Deployments("hello-api"); len(deps) != before+1 || deps[0].Status != runr.DeploymentActive {
		t.Fatalf("not redeployed: %+v", deps)
	}
	if body := h.srv.CallsTo("PATCH /projects/{project}/triggers/{trigger}")[0].Body; body["enabled"] != false || len(body) != 1 {
		t.Fatalf("body = %v", body)
	}
	if l := h.srv.Triggers("hello-api"); l[1].Enabled || l[1].RunsOn != "disabled" {
		t.Fatalf("triggers = %+v", l)
	}

	h.mustRun("triggers", "enable", "nightly", "--no-deploy", "-p", "hello-api")
	if len(h.srv.Deployments("hello-api")) != before+1 || !h.srv.Triggers("hello-api")[1].Enabled {
		t.Fatal("enable --no-deploy deployed, or did not enable")
	}
}

func TestTriggersRemove(t *testing.T) {
	h := trigHarness(t)
	h.srv.SeedTrigger("hello-api", runr.TriggerSchedule, "nightly", map[string]any{"cron": "0 3 * * *"}, nil)

	h.expectCode(ExitUsage, "triggers", "rm", "nightly", "-p", "hello-api") // no --yes outside a terminal
	r := h.mustRun("triggers", "rm", "nightly", "--yes", "--no-deploy", "-p", "hello-api")
	contains(t, r.out, "Trigger nightly removed from hello-api.")
	if l := h.srv.Triggers("hello-api"); len(l) != 1 || l[0].Type != runr.TriggerHTTP {
		t.Fatalf("triggers = %+v", l)
	}

	r = h.expectCode(ExitValidation, "triggers", "rm", "http", "--yes", "-p", "hello-api")
	contains(t, r.err, "at least one trigger")
	h.expectCode(ExitNotFound, "triggers", "rm", "ghost", "--yes", "-p", "hello-api")
}

// A removed cdc trigger leaves its replication slot, which RunR drops later: the CLI says so.
func TestTriggersRemoveCdcSaysTheSlotIsDropped(t *testing.T) {
	h := trigHarness(t)
	h.srv.SeedTrigger("hello-api", "cdc", "orders", map[string]any{"publication": "runr_orders"}, map[string]string{"dsn": "postgres://u:p@db/shop"})

	r := h.mustRun("triggers", "rm", "orders", "--yes", "--no-deploy", "-p", "hello-api")
	contains(t, r.out+r.err, "orders: RunR drops the replication slot(s) runr_hello_api_orders")

	h.srv.SeedTrigger("hello-api", "cdc", "events", map[string]any{"publication": "p", "slot": "my_slot"}, nil)
	var out map[string]any
	decodeJSON(t, h.mustRun("triggers", "rm", "events", "--yes", "--no-deploy", "-p", "hello-api", "-o", "json").out, &out)
	cleanup, _ := out["cleanup"].([]any)
	if len(cleanup) != 1 || cleanup[0].(map[string]any)["slots"].([]any)[0] != "my_slot" {
		t.Fatalf("json = %v", out)
	}
}

func TestTriggerEventsAndReplay(t *testing.T) {
	h := trigHarness(t)
	h.srv.SeedTrigger("hello-api", runr.TriggerWebhook, "github", map[string]any{"verify": "none"}, nil)
	ok := h.srv.SeedTriggerEvent("hello-api", "github", runr.TriggerEventDelivered, `{"a":1}`)
	bad := h.srv.SeedTriggerEvent("hello-api", "github", runr.TriggerEventFailed, `{"b":2}`)

	r := h.mustRun("triggers", "events", "github", "-p", "hello-api")
	contains(t, r.out, ok.ID)
	contains(t, r.out, bad.ID)
	contains(t, r.out, "function answered 500")

	r = h.mustRun("triggers", "events", "github", "--status", "failed", "-p", "hello-api")
	notContains(t, r.out, ok.ID)
	if q := h.srv.CallsTo("GET /projects/{project}/triggers/{trigger}/events"); q[len(q)-1].Query != "per_page=25&status=failed" {
		t.Fatalf("query = %q", q[len(q)-1].Query)
	}
	h.expectCode(ExitUsage, "triggers", "events", "github", "--status", "lost", "-p", "hello-api")

	r = h.mustRun("triggers", "events", "github", bad.ID, "-p", "hello-api")
	contains(t, r.out, "user-agent: GitHub-Hookshot/abc")
	contains(t, r.out, `{"b":2}`)
	if r = h.mustRun("triggers", "events", "github", bad.ID, "--body", "-p", "hello-api"); r.out != `{"b":2}` {
		t.Fatalf("--body = %q", r.out)
	}
	var detail runr.TriggerEvent
	decodeJSON(t, h.mustRun("triggers", "events", "github", bad.ID, "-p", "hello-api", "-o", "json").out, &detail)
	if detail.BodyText == nil || *detail.BodyText != `{"b":2}` || detail.Headers["user-agent"] == "" {
		t.Fatalf("detail = %+v", detail)
	}

	// Only webhook and platform_event triggers store events
	h.expectCode(ExitNotFound, "triggers", "events", "http", "-p", "hello-api")

	r = h.mustRun("triggers", "replay", "github", bad.ID, "-p", "hello-api")
	contains(t, r.out, "queued for delivery again (status pending)")
	events := h.srv.TriggerEvents("hello-api", "github")
	if events[1].Status != runr.TriggerEventPending || events[1].Replays != 1 {
		t.Fatalf("events = %+v", events)
	}

	h.srv.SeedTriggerEvent("hello-api", "github", runr.TriggerEventFailed, `{"c":3}`)
	r = h.mustRun("triggers", "replay", "github", "--failed", "-p", "hello-api")
	contains(t, r.out, "1 failed event of github queued for delivery again.")
	if body := h.srv.CallsTo("POST /projects/{project}/triggers/{trigger}/events/replay")[0].Body; body["status"] != "failed" {
		t.Fatalf("body = %v", body)
	}
	var res map[string]int
	decodeJSON(t, h.mustRun("triggers", "replay", "github", "--all", "-p", "hello-api", "-o", "json").out, &res)
	if res["replayed"] != 1 { // the delivered one; pending events are not replayed again
		t.Fatalf("replayed = %v", res)
	}

	h.expectCode(ExitUsage, "triggers", "replay", "github", "-p", "hello-api")
	h.expectCode(ExitUsage, "triggers", "replay", "github", bad.ID, "--failed", "-p", "hello-api")

	h.loginWith(h.srv.ScopedToken("viewer", "projects.view"))
	r = h.expectCode(ExitAuth, "triggers", "events", "github", "-p", "hello-api")
	contains(t, r.err, "replay.manage")
	h.mustRun("triggers", "ls", "-p", "hello-api")
}

func TestTopics(t *testing.T) {
	h := trigHarness(t)
	r := h.mustRun("topics", "create", "orders", "--region", "eu-1", "--description", "Order events")
	contains(t, r.out, "runr triggers add --type topic --set topic=orders")
	if body := h.srv.CallsTo("POST /topics")[0].Body; body["name"] != "orders" || body["description"] != "Order events" || body["knative_region_id"] == "" {
		t.Fatalf("body = %v", body)
	}
	r = h.mustRun("topics", "ls")
	contains(t, r.out, "orders")
	contains(t, r.out, "eu-1")

	h.mustRun("topics", "publish", "orders", "--data", `{"order_id":"o-1"}`, "--type", "order.created", "--subject", "o-1")
	h.runStdin(`{"a":1}`, "topics", "publish", "orders")
	file := filepath.Join(t.TempDir(), "event.json")
	write(t, file, `[1,2]`)
	h.mustRun("topics", "publish", "orders", "--file", file)
	h.mustRun("topics", "publish", "orders", "--data", "plain text", "--content-type", "text/plain")
	events := h.srv.TopicEvents()
	if len(events) != 4 {
		t.Fatalf("events = %+v", events)
	}
	if string(events[0].Data) != `{"order_id":"o-1"}` || events[0].Type != "order.created" || events[0].Subject != "o-1" || events[0].ContentType != "" {
		t.Fatalf("first = %+v", events[0])
	}
	if string(events[1].Data) != `{"a":1}` || string(events[2].Data) != `[1,2]` {
		t.Fatalf("stdin / file = %s / %s", events[1].Data, events[2].Data)
	}
	if string(events[3].Data) != `"plain text"` || events[3].ContentType != "text/plain" {
		t.Fatalf("text = %+v", events[3])
	}
	var pub runr.Published
	decodeJSON(t, h.mustRun("topics", "publish", "orders", "--data", "null", "--id", "evt-1", "-o", "json").out, &pub)
	if pub.ID != "evt-1" || pub.StreamID == "" {
		t.Fatalf("published = %+v", pub)
	}

	r = h.expectCode(ExitUsage, "topics", "publish", "orders", "--data", "not json")
	contains(t, r.err, "--content-type")
	h.expectCode(ExitUsage, "topics", "publish", "orders")
	h.expectCode(ExitNotFound, "topics", "publish", "ghost", "--data", "{}")

	// A subscribed topic cannot go
	h.mustRun("triggers", "add", "--type", "topic", "--name", "orders-in", "--set", "topic=orders", "--no-deploy", "-p", "hello-api")
	h.expectCode(ExitValidation, "triggers", "add", "--type", "topic", "--set", "topic=ghost", "--no-deploy", "-p", "hello-api")
	r = h.expectCode(ExitValidation, "topics", "delete", "orders", "--yes")
	contains(t, r.err, "subscriptions")
	h.mustRun("triggers", "rm", "orders-in", "--yes", "--no-deploy", "-p", "hello-api")
	h.mustRun("topics", "delete", "orders", "--yes")
	if len(h.srv.Topics()) != 0 {
		t.Fatalf("topics = %+v", h.srv.Topics())
	}

	h.srv.SeedTopic("audit", "eu-1")
	h.loginWith(h.srv.ScopedToken("reader", "projects.view", "regions.view"))
	r = h.expectCode(ExitAuth, "topics", "publish", "audit", "--data", "{}")
	contains(t, r.err, "events.send")
	r = h.expectCode(ExitAuth, "topics", "create", "more", "--region", "eu-1")
	contains(t, r.err, "topics.manage")
	h.mustRun("topics", "ls")
}

const triggersDeployYAML = `name: hello-api
type: faas
runtime: python
triggers:
  - type: http
  - type: webhook
    name: github
    verify: github
  - type: schedule
    name: nightly
    schedule: "0 3 * * *"
    delivery:
      max_retries: 2
`

// runr deploy replaces the trigger list with `triggers:` before the deployment; a webhook
// keeps the signing secret that was set in RunR.
func TestDeployAppliesTriggerList(t *testing.T) {
	h := newHarness(t).login()
	dir := h.project(triggersDeployYAML, map[string]string{"main.py": pythonHandler})

	// The verifying webhook has no signing secret yet
	r := h.expectCode(ExitValidation, "deploy", "--dir", dir)
	contains(t, r.err, "triggers.1.secrets.signing_secret")
	contains(t, r.err, "runr triggers add --type webhook")
	if len(h.srv.CallsTo("POST /projects/{project}/deploy")) != 0 {
		t.Fatal("deployed although the trigger list was refused")
	}

	h.mustRun("triggers", "add", "--type", "webhook", "--name", "github", "--set", "verify=github",
		"--secret", "signing_secret=gh-s3cr3t-value", "--no-deploy", "-p", "hello-api")
	h.srv.ResetCalls()
	r = h.mustRun("deploy", "--dir", dir)
	contains(t, r.err, "Applying the triggers of runr.yaml: (http), github (webhook), nightly (schedule)")
	contains(t, r.err, "Webhook github: ")

	calls := h.srv.Calls()
	put, deploy := -1, -1
	for i, c := range calls {
		switch {
		case c.Method == "PUT" && c.Endpoint == "/projects/{project}/triggers":
			put = i
		case c.Method == "POST" && c.Endpoint == "/projects/{project}/deploy":
			deploy = i
		}
	}
	if put < 0 || deploy < put {
		t.Fatalf("PUT triggers at %d, deploy at %d", put, deploy)
	}
	got, _ := json.Marshal(calls[put].Body["triggers"])
	want := `[{"type":"http"},{"config":{"verify":"github"},"name":"github","type":"webhook"},` +
		`{"config":{"cron":"0 3 * * *","delivery":{"max_retries":2}},"name":"nightly","type":"schedule"}]`
	if string(got) != want {
		t.Fatalf("PUT body =\n%s\nwant\n%s", got, want)
	}
	list := h.srv.Triggers("hello-api")
	if len(list) != 3 || list[1].Name != "github" || h.srv.TriggerSecrets("hello-api", "github")["signing_secret"] != "gh-s3cr3t-value" {
		t.Fatalf("triggers = %+v", list)
	}
	if list[2].Delivery == nil || list[2].Delivery.MaxRetries != 2 {
		t.Fatalf("nightly = %+v", list[2])
	}

	// What the API ignores is a warning, printed once, and the deploy goes on
	write(t, filepath.Join(dir, "runr.yaml"), "trigger:\n  type: http\n"+strings.Replace(triggersDeployYAML,
		"    schedule: \"0 3 * * *\"\n", "    schedule: \"0 3 * * *\"\n    timzone: UTC\n", 1))
	r = h.mustRun("deploy", "--dir", dir)
	contains(t, r.err, "Warning: runr.yaml: trigger and triggers are both set: triggers (the whole list) is used")
	contains(t, r.err, "Warning: runr.yaml: triggers[2] (nightly): timzone is not a setting of schedule triggers; RunR ignores it")
	if n := strings.Count(r.err, "timzone is not a setting"); n != 1 {
		t.Fatalf("warning printed %d times:\n%s", n, r.err)
	}

	// Dropping an entry removes the trigger with the next deploy
	write(t, filepath.Join(dir, "runr.yaml"), strings.Replace(triggersDeployYAML,
		"  - type: schedule\n    name: nightly\n    schedule: \"0 3 * * *\"\n    delivery:\n      max_retries: 2\n", "", 1))
	h.mustRun("deploy", "--dir", dir)
	if list := h.srv.Triggers("hello-api"); len(list) != 2 {
		t.Fatalf("triggers = %+v", list)
	}
}
