package runr

import (
	"encoding/json"
	"strings"
	"testing"
)

// PHP sends empty objects as [] and the topic detail lists its subscriptions.
func TestTriggerDecoding(t *testing.T) {
	var tr Trigger
	if err := json.Unmarshal([]byte(`{"id":"01J","name":"http","type":"http","enabled":true,"position":0,"primary":true,`+
		`"runs_on":"service","config":[],"secrets":[],"delivery":null,"retention_days":null}`), &tr); err != nil {
		t.Fatal(err)
	}
	if tr.Config == nil || len(tr.Config) != 0 || !tr.Primary || tr.Delivery != nil || tr.RetentionDays != nil {
		t.Fatalf("trigger = %+v", tr)
	}
	if err := json.Unmarshal([]byte(`{"name":"gh","type":"webhook","config":{"verify":"github"},"url":"https://x/api/hooks/o/k",`+
		`"has_signing_secret":true,"delivery":{"concurrency":4,"max_retries":5,"retry_backoff_seconds":2,"retry_backoff_max_seconds":300}}`), &tr); err != nil {
		t.Fatal(err)
	}
	if tr.Config["verify"] != "github" || tr.URL == "" || tr.HasSigningSecret == nil || !*tr.HasSigningSecret || tr.Delivery.MaxRetries != 5 {
		t.Fatalf("webhook = %+v", tr)
	}

	var e TriggerEvent
	if err := json.Unmarshal([]byte(`{"id":"e1","status":"failed","headers":{"x-a":"1","x-b":["2","3"]},"body_text":null,"size":3}`), &e); err != nil {
		t.Fatal(err)
	}
	if e.Headers["x-a"] != "1" || e.Headers["x-b"] != "2, 3" || e.BodyText != nil || e.Size != 3 {
		t.Fatalf("event = %+v", e)
	}
	if err := json.Unmarshal([]byte(`{"id":"e2","headers":[]}`), &e); err != nil || e.Headers != nil {
		t.Fatalf("event = %+v, %v", e, err)
	}

	var tp Topic
	if err := json.Unmarshal([]byte(`{"id":"t","name":"orders","created_by":"7","subscriptions":[{"trigger":"a"},{"trigger":"b"}]}`), &tp); err != nil {
		t.Fatal(err)
	}
	if tp.Subscriptions != 2 || tp.CreatedBy != "7" {
		t.Fatalf("topic = %+v", tp)
	}
	// created_by is a string in every answer (create and later reads alike); null is unknown
	var unknown Topic
	if err := json.Unmarshal([]byte(`{"id":"t","name":"orders","created_by":null,"subscriptions":0}`), &unknown); err != nil || unknown.CreatedBy != "" {
		t.Fatalf("topic = %+v, %v", unknown, err)
	}
	if err := json.Unmarshal([]byte(`{"id":"t","name":"orders","subscriptions":3}`), &tp); err != nil || tp.Subscriptions != 3 {
		t.Fatalf("topic = %+v, %v", tp, err)
	}
}

// A trigger's secrets stay out of the debug trace.
func TestTriggerSecretsRedacted(t *testing.T) {
	out := RedactJSON([]byte(`{"type":"webhook","config":{"verify":"github"},"secrets":{"signing_secret":"s3cr3t"}}`))
	if strings.Contains(out, "s3cr3t") || !strings.Contains(out, `"secrets":"[redacted]"`) || !strings.Contains(out, `"verify":"github"`) {
		t.Fatalf("trace = %s", out)
	}
}
