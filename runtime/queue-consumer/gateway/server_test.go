package gateway

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/redis/go-redis/v9"
)

func get(t *testing.T, srv *httptest.Server, path string) (*http.Response, string) {
	t.Helper()
	resp, err := http.Get(srv.URL + path)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(resp.Body)
	return resp, string(b)
}

func TestHealthz(t *testing.T) {
	srv := httptest.NewServer(testGateway(t, "http://fn", nil, nil).Handler())
	defer srv.Close()
	resp, body := get(t, srv, "/healthz")
	if resp.StatusCode != http.StatusOK || body != "ok" {
		t.Fatalf("/healthz = %d %q", resp.StatusCode, body)
	}
}

func TestStatsInLegacyEnvMode(t *testing.T) {
	addr, _ := testRedis(t)
	cfg, err := FromLegacyEnv(env(map[string]string{
		"REDIS_ADDRESS": addr, "STREAM": "qc-test-stats", "GROUP": "g", "CONSUMER": "pod-a",
		"SINK_URL": "http://fn", "MAX_RETRIES": "4",
	}))
	if err != nil {
		t.Fatal(err)
	}
	g, err := New(cfg) // the test stand-in of kind redis-stream reports pending=3
	if err != nil {
		t.Fatal(err)
	}
	defer g.close()

	rdb := redis.NewClient(&redis.Options{Addr: addr})
	defer rdb.Close()
	ctx := context.Background()
	rdb.XAdd(ctx, &redis.XAddArgs{Stream: "qc-test-stats-dlq", Values: map[string]any{"n": "0"}})
	defer rdb.Del(ctx, "qc-test-stats-dlq")
	tr := g.triggers[0]
	tr.m.delivered.Add(7)
	tr.m.failed.Add(2)

	srv := httptest.NewServer(g.Handler())
	defer srv.Close()
	resp, body := get(t, srv, "/stats")
	if ct := resp.Header.Get("Content-Type"); ct != "application/json" {
		t.Errorf("/stats Content-Type %q", ct)
	}
	var st map[string]any
	if err := json.Unmarshal([]byte(body), &st); err != nil {
		t.Fatal(err)
	}
	want := map[string]any{
		"stream": "qc-test-stats", "group": "g", "consumer": "pod-a", "dlq_stream": "qc-test-stats-dlq",
		"max_retries": float64(4), "delivered": float64(7), "failed": float64(2),
		"retried": float64(0), "dead_lettered": float64(0), "pending": float64(3), "dlq_length": float64(1),
	}
	for k, v := range want {
		if st[k] != v {
			t.Errorf("/stats %s = %v, want %v", k, st[k], v)
		}
	}
	triggers, _ := st["triggers"].([]any)
	if len(triggers) != 1 {
		t.Fatalf("/stats triggers = %v", st["triggers"])
	}
	e := triggers[0].(map[string]any)
	for k, v := range map[string]any{"name": "queue", "type": "queue", "kind": "redis-stream", "delivered": float64(7),
		"failed": float64(2), "in_flight": float64(0), "pending": float64(3), "dlq_length": float64(1)} {
		if e[k] != v {
			t.Errorf("/stats triggers[0].%s = %v, want %v", k, e[k], v)
		}
	}
}

func TestStatsWithoutLegacyKeys(t *testing.T) {
	g := testGateway(t, "http://fn", nil, nil)
	srv := httptest.NewServer(g.Handler())
	defer srv.Close()
	_, body := get(t, srv, "/stats")
	var st map[string]any
	json.Unmarshal([]byte(body), &st)
	if _, ok := st["stream"]; ok {
		t.Errorf("legacy keys outside legacy env mode: %s", body)
	}
	if triggers, _ := st["triggers"].([]any); len(triggers) != 1 {
		t.Errorf("/stats = %s", body)
	}
}

func TestMetricsText(t *testing.T) {
	g := testGateway(t, "http://fn", nil, nil)
	tr := g.triggers[0]
	tr.m.delivered.Add(5)
	tr.m.deadLettered.Add(1)
	tr.m.failed.Add(3)
	tr.m.retried.Add(2)
	tr.m.restarts.Add(1)
	tr.m.observe(1500 * time.Millisecond)

	srv := httptest.NewServer(g.Handler())
	defer srv.Close()
	resp, body := get(t, srv, "/metrics")
	if ct := resp.Header.Get("Content-Type"); !strings.HasPrefix(ct, "text/plain; version=0.0.4") {
		t.Errorf("/metrics Content-Type %q", ct)
	}
	for _, line := range []string{
		"# TYPE runr_gateway_deliveries_total counter",
		`runr_gateway_deliveries_total{trigger="orders",type="queue",outcome="delivered"} 5`,
		`runr_gateway_deliveries_total{trigger="orders",type="queue",outcome="dead_lettered"} 1`,
		`runr_gateway_failed_attempts_total{trigger="orders",type="queue"} 3`,
		`runr_gateway_retries_total{trigger="orders",type="queue"} 2`,
		"# TYPE runr_gateway_in_flight gauge",
		`runr_gateway_in_flight{trigger="orders",type="queue"} 0`,
		"# TYPE runr_gateway_delivery_seconds summary",
		`runr_gateway_delivery_seconds_sum{trigger="orders",type="queue"} 1.5`,
		`runr_gateway_delivery_seconds_count{trigger="orders",type="queue"} 1`,
		`runr_gateway_source_restarts_total{trigger="orders",type="queue"} 1`,
	} {
		if !strings.Contains(body, line+"\n") {
			t.Errorf("/metrics lacks %q:\n%s", line, body)
		}
	}
	if escapeLabel("a\"b\\c\nd") != `a\"b\\c\nd` {
		t.Errorf("escapeLabel = %q", escapeLabel("a\"b\\c\nd"))
	}
}

func TestNudgeEndpoint(t *testing.T) {
	g := testGateway(t, "http://fn", nil, nil)
	srv := httptest.NewServer(g.Handler())
	defer srv.Close()

	post := func(query string) int {
		resp, err := http.Post(srv.URL+"/v1/nudge"+query, "application/json", strings.NewReader("{}"))
		if err != nil {
			t.Fatal(err)
		}
		resp.Body.Close()
		return resp.StatusCode
	}
	if code := post("?trigger=orders"); code != http.StatusAccepted {
		t.Errorf("nudge orders = %d", code)
	}
	select {
	case <-g.triggers[0].nudge:
	default:
		t.Error("the trigger's nudge channel did not receive")
	}
	if code := post("?trigger=missing"); code != http.StatusNotFound {
		t.Errorf("nudge of an unknown trigger = %d, want 404", code)
	}
	if code := post(""); code != http.StatusAccepted {
		t.Errorf("nudge of all triggers = %d", code)
	}
	post("")
	if len(g.triggers[0].nudge) != 1 {
		t.Error("nudges did not collapse into one")
	}
	if resp, _ := get(t, srv, "/v1/nudge"); resp.StatusCode != http.StatusMethodNotAllowed {
		t.Errorf("GET /v1/nudge = %d, want 405", resp.StatusCode)
	}
}
