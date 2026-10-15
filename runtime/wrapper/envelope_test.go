package main

import (
	"encoding/json"
	"net/http/httptest"
	"regexp"
	"strings"
	"testing"
	"time"
)

var uuidPattern = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`)

func TestBuildEnvelopeHTTP(t *testing.T) {
	r := httptest.NewRequest("POST", "http://fn.example.test/hello?name=RunR&name=Other&b=2", strings.NewReader(`{"a":1}`))
	r.Header.Set("Content-Type", "application/json")
	r.Header.Add("X-Multi", "one")
	r.Header.Add("X-Multi", "two")

	env := buildEnvelope(r, []byte(`{"a":1}`), triggerHTTP)

	if env.Runr != 1 || env.Trigger != "http" || env.Method != "POST" || env.Path != "/hello" {
		t.Errorf("envelope = %+v", env)
	}
	if env.Query["name"] != "RunR" || env.Query["b"] != "2" || env.RawQuery != "name=RunR&name=Other&b=2" {
		t.Errorf("query = %v / %q", env.Query, env.RawQuery)
	}
	if env.Headers["x-multi"] != "one, two" || env.Headers["content-type"] != "application/json" || env.Headers["host"] != "fn.example.test" {
		t.Errorf("headers = %v", env.Headers)
	}
	if env.Body != `{"a":1}` || env.IsBase64Encoded {
		t.Errorf("body = %q base64=%v", env.Body, env.IsBase64Encoded)
	}
	if env.Event != nil {
		t.Error("a plain request has no event")
	}
	if !uuidPattern.MatchString(env.ID) {
		t.Errorf("generated id %q is not a UUID v4", env.ID)
	}

	// Marshalled names follow the contract
	var m map[string]any
	if err := json.Unmarshal(encodeEnvelope(env), &m); err != nil {
		t.Fatal(err)
	}
	for _, k := range []string{"runr", "trigger", "id", "method", "path", "query", "rawQuery", "headers", "body", "isBase64Encoded"} {
		if _, ok := m[k]; !ok {
			t.Errorf("envelope JSON lacks %q", k)
		}
	}
	if _, ok := m["event"]; ok {
		t.Error("event is omitted without a CloudEvent")
	}
}

func TestBuildEnvelopeEmptyQueryIsAnObject(t *testing.T) {
	env := buildEnvelope(httptest.NewRequest("GET", "/", nil), nil, triggerHTTP)
	line := string(encodeEnvelope(env))
	if !strings.Contains(line, `"query":{}`) || !strings.HasSuffix(line, "}\n") || strings.Count(line, "\n") != 1 {
		t.Errorf("envelope line = %q", line)
	}
}

func TestBuildEnvelopeBinaryBody(t *testing.T) {
	body := []byte{0xff, 0xfe, 0x00, 'a'}
	env := buildEnvelope(httptest.NewRequest("POST", "/bin", nil), body, triggerHTTP)

	if !env.IsBase64Encoded || env.Body != "//4AYQ==" {
		t.Errorf("body = %q base64=%v", env.Body, env.IsBase64Encoded)
	}
}

func TestRequestIDPrecedence(t *testing.T) {
	r := httptest.NewRequest("POST", "/", nil)
	r.Header.Set("Ce-Specversion", "1.0")
	r.Header.Set("Ce-Id", "ce-1")
	r.Header.Set("X-Request-Id", "req-1")
	if id := buildEnvelope(r, nil, triggerQueue).ID; id != "req-1" {
		t.Errorf("X-Request-Id wins, got %q", id)
	}

	r.Header.Del("X-Request-Id")
	if id := buildEnvelope(r, nil, triggerQueue).ID; id != "ce-1" {
		t.Errorf("ce-id comes next, got %q", id)
	}
}

func TestCloudEventBinaryModeJSON(t *testing.T) {
	r := httptest.NewRequest("POST", "/", nil)
	r.Header.Set("Content-Type", "application/json")
	r.Header.Set("Ce-Specversion", "1.0")
	r.Header.Set("Ce-Id", "1712345-0")
	r.Header.Set("Ce-Type", "dev.runr.queue.message")
	r.Header.Set("Ce-Source", "/redisstream/orders/fn")
	r.Header.Set("Ce-Time", "2026-10-10T10:00:00Z")

	env := buildEnvelope(r, []byte(` {"order": 42, "big": 12345678901234567890} `), triggerQueue)

	ev := env.Event
	if ev == nil || ev.SpecVersion != "1.0" || ev.ID != "1712345-0" || ev.Type != "dev.runr.queue.message" ||
		ev.Source != "/redisstream/orders/fn" || ev.Time != "2026-10-10T10:00:00Z" || ev.DataContentType != "application/json" {
		t.Fatalf("event = %+v", ev)
	}

	// data is embedded as (compacted) JSON, numbers untouched
	line := string(encodeEnvelope(env))
	if !strings.Contains(line, `"data":{"order":42,"big":12345678901234567890}`) {
		t.Errorf("envelope = %s", line)
	}
	if env.Trigger != "queue" || env.ID != "1712345-0" {
		t.Errorf("trigger/id = %s/%s", env.Trigger, env.ID)
	}
}

func TestCloudEventBinaryModeNonJSONData(t *testing.T) {
	r := httptest.NewRequest("POST", "/", nil)
	r.Header.Set("Ce-Specversion", "1.0")
	r.Header.Set("Ce-Type", "t")

	if ev := buildEnvelope(r, []byte("hello queue"), triggerQueue).Event; ev == nil || ev.Data != "hello queue" {
		t.Errorf("data = %#v", ev)
	}
	if ev := buildEnvelope(r, nil, triggerQueue).Event; ev == nil || ev.Data != nil {
		t.Errorf("an empty body has null data, got %#v", ev)
	}
}

func TestCloudEventStructuredMode(t *testing.T) {
	r := httptest.NewRequest("POST", "/", nil)
	r.Header.Set("Content-Type", "application/cloudevents+json; charset=utf-8")
	body := []byte(`{"specversion":"1.0","id":"s-1","type":"x.y","source":"/src","data":{"k":"v"}}`)

	env := buildEnvelope(r, body, triggerQueue)
	if env.Event == nil || env.Event.ID != "s-1" || env.Event.Type != "x.y" || env.ID != "s-1" {
		t.Fatalf("event = %+v id=%s", env.Event, env.ID)
	}
	if !strings.Contains(string(encodeEnvelope(env)), `"data":{"k":"v"}`) {
		t.Error("structured data is embedded")
	}
}

func TestInvocationEnv(t *testing.T) {
	r := httptest.NewRequest("PUT", "/items/7?x=1", nil)
	r.Header.Set("X-Foo-Bar", "baz")
	r.Header.Set("Content-Type", "text/plain")
	r.Header.Set("Ce-Specversion", "1.0")
	r.Header.Set("Ce-Id", "e-1")
	r.Header.Set("Ce-Type", "t.1")
	r.Header.Set("Ce-Source", "/s")

	vars := strings.Join(invocationEnv(buildEnvelope(r, nil, triggerQueue)), "\n") + "\n"

	for _, want := range []string{
		"RUNR_TRIGGER=queue\n", "RUNR_REQUEST_ID=e-1\n", "RUNR_METHOD=PUT\n", "RUNR_PATH=/items/7\n",
		"RUNR_QUERY=x=1\n", "RUNR_CONTENT_TYPE=text/plain\n", "RUNR_HEADER_X_FOO_BAR=baz\n",
		"RUNR_HEADER_CE_TYPE=t.1\n", "RUNR_EVENT_ID=e-1\n", "RUNR_EVENT_TYPE=t.1\n", "RUNR_EVENT_SOURCE=/s\n",
	} {
		if !strings.Contains(vars, want) {
			t.Errorf("env lacks %q:\n%s", want, vars)
		}
	}
}

func TestScheduleEnvelope(t *testing.T) {
	now := time.Date(2026, 10, 10, 12, 0, 0, 0, time.UTC)
	env := scheduleEnvelope(now)

	if env.Trigger != "schedule" || env.Method != "POST" || env.Path != "/" || env.Body != `{"trigger":"schedule"}` {
		t.Errorf("envelope = %+v", env)
	}
	ev := env.Event
	if ev == nil || ev.Type != scheduleEventType || ev.Source != scheduleEventSource || ev.ID != env.ID || ev.Time != "2026-10-10T12:00:00Z" {
		t.Fatalf("event = %+v", ev)
	}
	if env.Headers["ce-type"] != scheduleEventType || env.Headers["content-type"] != "application/json" {
		t.Errorf("headers = %v", env.Headers)
	}
	if !strings.Contains(string(encodeEnvelope(env)), `"data":{"trigger":"schedule"}`) {
		t.Error("schedule data is embedded as JSON")
	}
}

func TestEnvName(t *testing.T) {
	for in, want := range map[string]string{"x-foo": "X_FOO", "content-type": "CONTENT_TYPE", "a.b~c9": "A_B_C9"} {
		if got := envName(in); got != want {
			t.Errorf("envName(%q) = %q", in, got)
		}
	}
}
