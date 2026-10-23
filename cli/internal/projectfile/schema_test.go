package projectfile

import (
	"bytes"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

const apiDir = "../../../api"

// The embedded schema is the API's: `php artisan triggers:schema` writes
// api/resources/triggers/triggers.schema.json, this package embeds a copy of it.
func TestTriggerSchemaIsTheAPIs(t *testing.T) {
	api, err := os.ReadFile(filepath.Join(apiDir, "resources", "triggers", "triggers.schema.json"))
	if err != nil {
		t.Skipf("no API checkout next to the CLI: %v", err)
	}
	if !bytes.Equal(api, triggerSchemaJSON) {
		t.Fatal("cli/internal/projectfile/triggers.schema.json differs from api/resources/triggers/triggers.schema.json: " +
			"run `php artisan triggers:schema` in api and copy the file here")
	}
	doc, err := triggerSchema()
	if err != nil {
		t.Fatal(err)
	}
	for _, typ := range []string{"http", "schedule", "queue", "workflow", "webhook", "topic", "platform_event", "kafka", "nats", "rabbitmq", "mqtt", "cdc", "s3", "email"} {
		if doc.Types[typ] == nil {
			t.Errorf("the schema has no %s type", typ)
		}
	}
	if doc.Version != 1 || doc.Entry == nil {
		t.Fatalf("schema = %+v", doc)
	}
}

// The cases of api/tests/Fixtures/triggers/runr-yaml-cases.json carry the server's verdict
// (checked by the API's TriggerSchemaTest): every entry the server takes passes here, every
// entry it refuses is refused here too unless the case says the CLI leaves it to the server.
func TestTriggerSchemaAgreesWithTheServer(t *testing.T) {
	data, err := os.ReadFile(filepath.Join(apiDir, "tests", "Fixtures", "triggers", "runr-yaml-cases.json"))
	if err != nil {
		t.Skipf("no API checkout next to the CLI: %v", err)
	}
	var cases []struct {
		Case   string          `json:"case"`
		Entry  json.RawMessage `json:"entry"`
		Server bool            `json:"server"`
		CLI    string          `json:"cli"`
	}
	if err := json.Unmarshal(data, &cases); err != nil {
		t.Fatal(err)
	}
	if len(cases) < 20 {
		t.Fatalf("only %d cases", len(cases))
	}
	for _, c := range cases {
		// JSON is YAML: the entry goes through the same parser as a runr.yaml file
		src := "name: a\ntype: faas\nruntime: python\ntriggers:\n  - " + string(c.Entry) + "\n"
		_, err := Parse([]byte(src))
		var ve *ValidationError
		if err != nil && !errors.As(err, &ve) {
			t.Errorf("%s: not a validation error: %v", c.Case, err)
			continue
		}
		switch {
		case c.Server && err != nil:
			t.Errorf("%s: the server takes it, the CLI refuses it: %v", c.Case, err)
		case !c.Server && c.CLI != "lenient" && err == nil:
			t.Errorf("%s: the server refuses it, the CLI lets it through", c.Case)
		case !c.Server && c.CLI == "lenient" && err != nil:
			t.Errorf("%s: marked as left to the server, but the CLI refuses it: %v", c.Case, err)
		}
	}
}

func TestValidCron(t *testing.T) {
	for expr, ok := range map[string]bool{
		"* * * * *":                   true,
		"*/5 * * * *":                 true,
		"0 3 * * *":                   true,
		"0-30/10 8-18 1,15 * MON-FRI": true,
		"0 0 1 jan,jul *":             true,
		"0 0 * * 7":                   true,
		"@daily":                      true,
		"@HOURLY":                     true,
		"  0 3 * * *  ":               true,
		"@every 5m":                   false,
		"daily":                       false,
		"0 3 * *":                     false,
		"0 0 3 * * *":                 false,
		"60 * * * *":                  false,
		"* 24 * * *":                  false,
		"* * 0 * *":                   false,
		"* * * 13 *":                  false,
		"* * * * 8":                   false,
		"* * * * 5L":                  false,
		"* * * * MON#2":               false,
		"* * L * *":                   false,
		"CRON_TZ=UTC 0 3 * * *":       false,
		"0 3 * FOO *":                 false,
		"0,,3 * * * *":                false,
	} {
		if got := validCron(expr); got != ok {
			t.Errorf("validCron(%q) = %v, want %v", expr, got, ok)
		}
	}
}

func TestValidTimeZone(t *testing.T) {
	for zone, ok := range map[string]bool{
		"UTC": true, "Europe/Berlin": true, "Europe/Istanbul": true, "America/Argentina/Buenos_Aires": true,
		"Europe/Kiev": true, "US/Eastern": true,
		"": false, "Local": false, "Mars/Olympus": false, "europe/berlin": false, " UTC": false, "+03:00": false,
	} {
		if got := validTimeZone(zone); got != ok {
			t.Errorf("validTimeZone(%q) = %v, want %v", zone, got, ok)
		}
	}
}

// Laravel's types: integers and booleans in their string forms, arrays as lists or mappings.
func TestSchemaKeywordsFollowLaravel(t *testing.T) {
	min1, max32 := 1.0, 32.0
	s := &jsonSchema{Type: schemaTypes{"integer", "null"}, Minimum: &min1, Maximum: &max32}
	for v, ok := range map[any]bool{8: true, "8": true, 8.0: true, 0: false, "33": false, 1.5: false, "x": false, true: false} {
		if got := len(s.validate("n", v)) == 0; got != ok {
			t.Errorf("integer %v: ok %v, want %v", v, got, ok)
		}
	}
	if p := s.validate("n", nil); len(p) != 0 {
		t.Errorf("nullable null: %v", p)
	}

	b := &jsonSchema{Type: schemaTypes{"boolean"}}
	for v, ok := range map[any]bool{true: true, false: true, 1: true, 0: true, "1": true, "0": true, "true": false, 2: false} {
		if got := len(b.validate("b", v)) == 0; got != ok {
			t.Errorf("boolean %v: ok %v, want %v", v, got, ok)
		}
	}

	e := &jsonSchema{Type: schemaTypes{"integer"}, Enum: []string{"0", "1", "2"}}
	if len(e.validate("qos", 1)) != 0 || len(e.validate("qos", "2")) != 0 || len(e.validate("qos", 3)) == 0 {
		t.Error("enum compares as strings")
	}

	maxItems := 1
	items := &jsonSchema{Type: schemaTypes{"string"}, Pattern: `^[a-z]+\n?$`}
	a := &jsonSchema{Type: schemaTypes{"array"}, MaxItems: &maxItems, Items: items}
	if p := a.validate("tables", map[string]any{"x": "orders"}); len(p) != 0 {
		t.Errorf("a mapping is an array: %v", p)
	}
	if p := a.validate("tables", []any{"Orders"}); len(p) != 1 || !strings.Contains(p[0], "tables[0] must match ^[a-z]+$") {
		t.Errorf("items: %v", p)
	}
	if p := a.validate("tables", []any{"a", "b"}); len(p) != 1 || !strings.Contains(p[0], "at most one entry") {
		t.Errorf("maxItems: %v", p)
	}

	r := &jsonSchema{Type: schemaTypes{"object"}, Required: []string{"cron"}, Properties: map[string]*jsonSchema{"cron": {Type: schemaTypes{"string"}}}}
	for _, v := range []map[string]any{{}, {"cron": "  "}, {"cron": nil}} {
		if p := r.validate("", v); len(p) != 1 || p[0] != "cron is required" {
			t.Errorf("required %v: %v", v, p)
		}
	}

	// PCRE's "$" before a final newline, as the generator keeps it
	n := &jsonSchema{Type: schemaTypes{"string"}, Pattern: `^[a-z]+\n?$`, Not: &jsonSchema{Pattern: `^runr\.`}}
	if len(n.validate("s", "orders\n")) != 0 || len(n.validate("s", "runr.x")) == 0 {
		t.Error("pattern / not.pattern")
	}
}
