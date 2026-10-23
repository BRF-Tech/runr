package projectfile

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

const triggersYAML = `
name: orders
type: faas
runtime: python
triggers:
  - type: http
  - type: schedule
    name: nightly
    schedule: "0 3 * * *"
  - type: queue
    name: orders-in
    queue: orders
    start_from: latest
    delivery:
      concurrency: 8
      max_retries: 10
  - type: webhook
    name: github
    verify: hmac
    hmac:
      header: X-Hub-Signature-256
      prefix: "sha256="
    retention_days: 14
  - type: platform_event
    name: deploys
    enabled: false
    events: [deployment.failed, alert.fired]
`

func TestParseTriggerList(t *testing.T) {
	f, err := Parse([]byte(triggersYAML))
	if err != nil {
		t.Fatal(err)
	}
	if len(f.Triggers) != 5 || f.Trigger != nil {
		t.Fatalf("triggers = %+v", f.Triggers)
	}
	specs := f.TriggerSpecs()
	got, err := json.Marshal(specs)
	if err != nil {
		t.Fatalf("the list does not encode as JSON: %v", err)
	}
	want := `[{"type":"http"},` +
		`{"type":"schedule","name":"nightly","config":{"cron":"0 3 * * *"}},` +
		`{"type":"queue","name":"orders-in","config":{"delivery":{"concurrency":8,"max_retries":10},"start_from":"latest","stream":"orders"}},` +
		`{"type":"webhook","name":"github","config":{"hmac":{"header":"X-Hub-Signature-256","prefix":"sha256="},"retention_days":14,"verify":"hmac"}},` +
		`{"type":"platform_event","name":"deploys","enabled":false,"config":{"events":["deployment.failed","alert.fired"]}}]`
	if string(got) != want {
		t.Fatalf("specs =\n%s\nwant\n%s", got, want)
	}
	// The trigger list says nothing about trigger_type: the API mirrors the primary trigger
	if c := f.Config(); c.TriggerType != nil || c.Schedule != nil || c.QueueStream != nil {
		t.Fatalf("config = %+v", c)
	}
	if f.TriggerType() != runr.TriggerHTTP || f.QueueStream() != "orders" {
		t.Fatalf("trigger type %s, queue %s", f.TriggerType(), f.QueueStream())
	}
}

func TestTriggerListInvocation(t *testing.T) {
	for src, want := range map[string]string{
		"triggers:\n  - type: schedule\n    cron: '@hourly'\n":                                       runr.TriggerSchedule,
		"triggers:\n  - type: schedule\n    cron: '@hourly'\n  - type: queue\n    stream: jobs\n":    runr.TriggerQueue,
		"triggers:\n  - type: topic\n    topic: orders\n":                                            runr.TriggerQueue,
		"triggers:\n  - type: workflow\n":                                                            runr.TriggerHTTP,
		"triggers:\n  - type: http\n    enabled: false\n  - type: webhook\n    verify: none\n":       runr.TriggerQueue,
		"triggers:\n  - type: schedule\n    cron: '* * * * *'\n  - type: http\n    enabled: false\n": runr.TriggerSchedule,
	} {
		f, err := Parse([]byte("name: a\ntype: faas\nruntime: python\n" + src))
		if err != nil {
			t.Fatalf("%s: %v", src, err)
		}
		if got := f.TriggerType(); got != want {
			t.Errorf("%s: invoked as %s, want %s", src, got, want)
		}
	}
}

func TestTriggerListRejects(t *testing.T) {
	base := "name: a\ntype: faas\nruntime: python\n"
	cases := map[string]struct{ src, problem string }{
		"no type":        {"triggers:\n  - name: x\n", "type is required"},
		"bad type":       {"triggers:\n  - type: Web Hook\n", "not a trigger type"},
		"bad name":       {"triggers:\n  - type: http\n    name: My_Hook\n", "name must be lower-case"},
		"duplicate name": {"triggers:\n  - type: http\n    name: a\n  - type: schedule\n    name: a\n    cron: '* * * * *'\n", "used twice"},
		"two http":       {"triggers:\n  - type: http\n  - type: http\n    name: second\n", "at most one http trigger"},
		"workflow":       {"triggers:\n  - type: workflow\n  - type: http\n", "only trigger"},
		"cron":           {"triggers:\n  - type: schedule\n    cron: daily\n", "five fields"},
		"cron range":     {"triggers:\n  - type: schedule\n    cron: '0 24 * * *'\n", "five fields"},
		"time zone":      {"triggers:\n  - type: schedule\n    cron: '0 9 * * *'\n    timezone: Mars/Olympus\n", "time zone of the IANA database"},
		"enabled":        {"triggers:\n  - type: http\n    enabled: 'yes'\n", "enabled must be true or false"},
		"kafka broker":   {"triggers:\n  - type: kafka\n    brokers: [kafka-1]\n    topic: orders\n", "brokers[0] must match"},
		"cdc required":   {"triggers:\n  - type: cdc\n", "publication is required"},
		"stream":         {"triggers:\n  - type: queue\n    stream: runr.internal\n", "Redis stream"},
		"topic":          {"triggers:\n  - type: topic\n", "topic is required"},
		"verify":         {"triggers:\n  - type: webhook\n", "verify is required"},
		"events":         {"triggers:\n  - type: platform_event\n", "events is required"},
		"delivery range": {"triggers:\n  - type: topic\n    topic: t\n    delivery:\n      concurrency: 64\n", "delivery.concurrency must be between 1 and 32"},
		"too many":       {"triggers:\n" + strings.Repeat("  - type: schedule\n    cron: '* * * * *'\n", 21), "at most 20"},
	}
	for name, c := range cases {
		_, err := Parse([]byte(base + c.src))
		var ve *ValidationError
		if !errors.As(err, &ve) {
			t.Errorf("%s: err = %v, want a ValidationError", name, err)
			continue
		}
		if !strings.Contains(err.Error(), c.problem) {
			t.Errorf("%s: %v does not mention %q", name, err, c.problem)
		}
	}
}

// Shorthands only apply to their own type, and an explicit cron / stream wins.
func TestTriggerListShorthands(t *testing.T) {
	f, err := Parse([]byte("name: a\ntype: faas\nruntime: python\ntriggers:\n" +
		"  - type: schedule\n    cron: '0 * * * *'\n    schedule: ignored\n" +
		"  - type: webhook\n    verify: none\n    queue: kept\n"))
	if err != nil {
		t.Fatal(err)
	}
	specs := f.TriggerSpecs()
	if specs[0].Config["cron"] != "0 * * * *" || specs[0].Config["schedule"] != "ignored" {
		t.Fatalf("schedule config = %v", specs[0].Config)
	}
	if specs[1].Config["queue"] != "kept" || specs[1].Config["stream"] != nil {
		t.Fatalf("webhook config = %v", specs[1].Config)
	}
}

// What the API accepts and ignores is a warning here too, and is not sent.
func TestTriggerListWarnings(t *testing.T) {
	base := "name: a\ntype: faas\nruntime: python\n"
	cases := map[string]struct{ src, warning string }{
		"both blocks":    {"trigger:\n  type: schedule\n  schedule: '* * * * *'\ntriggers:\n  - type: http\n", "trigger and triggers are both set: triggers (the whole list) is used"},
		"secrets":        {"triggers:\n  - type: webhook\n    verify: github\n    secrets:\n      signing_secret: x\n", "triggers[0]: secrets are ignored"},
		"delivery http":  {"triggers:\n  - type: http\n    delivery:\n      concurrency: 2\n", "delivery is not a setting of http triggers; RunR ignores it (delivery applies to the triggers the event gateway runs)"},
		"delivery field": {"triggers:\n  - type: topic\n    topic: t\n    delivery:\n      parallel: 2\n", "delivery.parallel is not a setting of topic triggers"},
		"typo":           {"triggers:\n  - type: schedule\n    name: nightly\n    cron: '0 3 * * *'\n    timzone: UTC\n", "triggers[0] (nightly): timzone is not a setting of schedule triggers"},
		"shorthand left": {"triggers:\n  - type: schedule\n    cron: '0 * * * *'\n    schedule: ignored\n", "schedule is not a setting of schedule triggers"},
	}
	for name, c := range cases {
		f, err := Parse([]byte(base + c.src))
		if err != nil {
			t.Errorf("%s: %v", name, err)
			continue
		}
		if !strings.Contains(strings.Join(f.Warnings(), "\n"), c.warning) {
			t.Errorf("%s: warnings %q do not mention %q", name, f.Warnings(), c.warning)
		}
	}

	f, err := Parse([]byte(base + "trigger:\n  type: schedule\n  schedule: '* * * * *'\ntriggers:\n  - type: webhook\n    verify: github\n    secrets:\n      signing_secret: x\n"))
	if err != nil {
		t.Fatal(err)
	}
	if f.Trigger != nil || f.Config().TriggerType != nil || f.TriggerType() != runr.TriggerQueue {
		t.Fatalf("trigger is still used: %+v", f.Trigger)
	}
	if _, ok := f.TriggerSpecs()[0].Config["secrets"]; ok {
		t.Fatal("secrets are sent")
	}

	f, err = Parse([]byte(base + "triggers:\n  - type: schedule\n    cron: '0 9 * * *'\n    timezone: Europe/Berlin\n  - type: future_type\n    whatever: 1\n"))
	if err != nil {
		t.Fatal(err)
	}
	if len(f.Warnings()) != 0 {
		t.Fatalf("warnings = %q", f.Warnings())
	}
	if f.TriggerSpecs()[0].Config["timezone"] != "Europe/Berlin" {
		t.Fatalf("specs = %+v", f.TriggerSpecs())
	}
}

// enabled is the API's boolean: 1 / 0 and "1" / "0" too; null is the default.
func TestTriggerEnabledLikeTheAPI(t *testing.T) {
	f, err := Parse([]byte("name: a\ntype: faas\nruntime: python\ntriggers:\n  - type: http\n    enabled: 1\n" +
		"  - type: schedule\n    cron: '@daily'\n    enabled: '0'\n  - type: queue\n    stream: s\n    enabled: ~\n"))
	if err != nil {
		t.Fatal(err)
	}
	specs := f.TriggerSpecs()
	if specs[0].Enabled == nil || !*specs[0].Enabled || specs[1].Enabled == nil || *specs[1].Enabled || specs[2].Enabled != nil {
		t.Fatalf("specs = %+v", specs)
	}
}

// The single trigger: block takes the API's cron rule too (macros included).
func TestSingleTriggerCron(t *testing.T) {
	for src, ok := range map[string]bool{
		"trigger:\n  type: schedule\n  schedule: '@daily'\n":      true,
		"trigger:\n  type: schedule\n  schedule: '*/5 * * * *'\n": true,
		"trigger:\n  type: schedule\n  schedule: '@every 5m'\n":   false,
		"trigger:\n  type: schedule\n  schedule: '0 0 3 * * *'\n": false,
	} {
		_, err := Parse([]byte("name: a\ntype: faas\nruntime: python\n" + src))
		if (err == nil) != ok {
			t.Errorf("%s: err = %v, want ok %v", src, err, ok)
		}
	}
}
