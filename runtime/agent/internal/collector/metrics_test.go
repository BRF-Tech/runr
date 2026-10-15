package collector

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"sort"
	"strings"
	"sync"
	"testing"
)

// promSample is one vector sample: pod label + value string, as Prometheus encodes it.
type promSample struct {
	pod   string
	value string
}

// fakeProm answers /api/v1/query with the cpu or memory vector depending on the query, and
// records the queries it received.
type fakeProm struct {
	mu      sync.Mutex
	queries []string
	cpu     []promSample
	mem     []promSample
	status  int
	raw     string // when set, returned verbatim instead of a vector
}

func (f *fakeProm) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path != "/api/v1/query" {
		http.NotFound(w, r)
		return
	}
	q := r.URL.Query().Get("query")
	f.mu.Lock()
	f.queries = append(f.queries, q)
	f.mu.Unlock()

	if f.status != 0 {
		w.WriteHeader(f.status)
	}
	if f.raw != "" {
		fmt.Fprint(w, f.raw)
		return
	}
	samples := f.mem
	if strings.Contains(q, "container_cpu_usage_seconds_total") {
		samples = f.cpu
	}
	type result struct {
		Metric map[string]string `json:"metric"`
		Value  []interface{}     `json:"value"`
	}
	out := struct {
		Status string `json:"status"`
		Data   struct {
			ResultType string   `json:"resultType"`
			Result     []result `json:"result"`
		} `json:"data"`
	}{Status: "success"}
	out.Data.ResultType = "vector"
	out.Data.Result = []result{}
	for _, s := range samples {
		out.Data.Result = append(out.Data.Result, result{
			Metric: map[string]string{"pod": s.pod},
			Value:  []interface{}{1700000000.123, s.value},
		})
	}
	json.NewEncoder(w).Encode(out)
}

type projectMetrics struct {
	slug     string
	cpu      float64
	memory   float64
	podCount int
}

func flatten(t *testing.T, results []map[string]interface{}) []projectMetrics {
	t.Helper()
	var out []projectMetrics
	for _, r := range results {
		if r["type"] != "metrics" {
			t.Fatalf("type = %v, want metrics", r["type"])
		}
		data, ok := r["data"].(map[string]interface{})
		if !ok {
			t.Fatalf("data has type %T", r["data"])
		}
		out = append(out, projectMetrics{
			slug:     r["project_slug"].(string),
			cpu:      data["cpu"].(float64),
			memory:   data["memory"].(float64),
			podCount: data["pod_count"].(int),
		})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].slug < out[j].slug })
	return out
}

func TestCollectAggregatesPerProject(t *testing.T) {
	prom := &fakeProm{
		cpu: []promSample{
			{"shop-00001-deployment-6b8f9c7d4-xk9pq", "0.25"},
			{"shop-00002-deployment-7c9d8b6f5-2xk9p", "0.5"}, // pod hash starts with a digit
			{"api-gw-00003-deployment-59c7d8b6f4-bcdfg", "1.5"},
			{"runr-agent-6f7d9c-qwert", "9"}, // not a project pod
		},
		mem: []promSample{
			{"shop-00001-deployment-6b8f9c7d4-xk9pq", "100"},
			{"shop-00002-deployment-7c9d8b6f5-2xk9p", "28.5"},
			{"api-gw-00003-deployment-59c7d8b6f4-bcdfg", "64"},
			{"memonly-00001-deployment-abc-defgh", "12"},
		},
	}
	srv := httptest.NewServer(prom)
	defer srv.Close()

	got := flatten(t, New(srv.URL, "tenant-ns").Collect(context.Background()))
	want := []projectMetrics{
		{"api-gw", 1.5, 64, 1},
		{"memonly", 0, 12, 0},
		{"shop", 0.75, 128.5, 2},
	}
	if fmt.Sprint(got) != fmt.Sprint(want) {
		t.Fatalf("Collect() = %+v\nwant      %+v", got, want)
	}

	prom.mu.Lock()
	defer prom.mu.Unlock()
	if len(prom.queries) != 2 {
		t.Fatalf("%d queries, want 2 (cpu + memory)", len(prom.queries))
	}
	for _, q := range prom.queries {
		if !strings.Contains(q, `namespace="tenant-ns"`) {
			t.Errorf("query %q is not scoped to the namespace", q)
		}
		if !strings.HasPrefix(q, "sum by (pod)") {
			t.Errorf("query %q does not aggregate by pod", q)
		}
	}
}

func TestCollectSkipsNonFiniteAndMalformedSamples(t *testing.T) {
	prom := &fakeProm{
		cpu: []promSample{
			{"a-00001-deployment-abc-defgh", "NaN"},
			{"b-00001-deployment-abc-defgh", "+Inf"},
			{"c-00001-deployment-abc-defgh", "not-a-number"},
			{"d-00001-deployment-abc-defgh", "0.1"},
		},
	}
	srv := httptest.NewServer(prom)
	defer srv.Close()

	results := New(srv.URL, "runr").Collect(context.Background())
	got := flatten(t, results)
	if len(got) != 1 || got[0].slug != "d" || got[0].cpu != 0.1 {
		t.Fatalf("Collect() = %+v, want only project d", got)
	}
	// every result must be JSON-encodable (the ws client marshals it)
	if _, err := json.Marshal(results); err != nil {
		t.Fatalf("results are not JSON-encodable: %v", err)
	}
}

func TestCollectHandlesPrometheusFailures(t *testing.T) {
	cases := []struct {
		name string
		prom *fakeProm
	}{
		{"server error", &fakeProm{status: http.StatusInternalServerError, raw: `{"status":"error"}`}},
		{"invalid json", &fakeProm{raw: `<html>proxy error</html>`}},
		{"empty vector", &fakeProm{}},
		{"bad request with samples", &fakeProm{status: http.StatusBadRequest, cpu: []promSample{{"x-00001-deployment-a-b", "1"}}}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			srv := httptest.NewServer(tc.prom)
			defer srv.Close()
			if got := New(srv.URL, "runr").Collect(context.Background()); len(got) != 0 {
				t.Fatalf("Collect() = %v, want no metrics", got)
			}
		})
	}
}

func TestCollectUnreachableOrInvalidURL(t *testing.T) {
	srv := httptest.NewServer(http.NotFoundHandler())
	url := srv.URL
	srv.Close() // nothing listens there any more

	for _, u := range []string{url, "http://[::1", "://no-scheme"} {
		if got := New(u, "runr").Collect(context.Background()); len(got) != 0 {
			t.Errorf("Collect(%q) = %v, want no metrics", u, got)
		}
	}
}

func TestCollectHonoursContextCancel(t *testing.T) {
	block := make(chan struct{})
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		select {
		case <-block:
		case <-r.Context().Done():
		}
	}))
	defer srv.Close()
	defer close(block)

	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if got := New(srv.URL, "runr").Collect(ctx); len(got) != 0 {
		t.Fatalf("Collect() with a cancelled context = %v", got)
	}
}

func TestExtractSlug(t *testing.T) {
	if got := extractSlug("hello-00001-deployment-6b8f9c7d4-2xk9p"); got != "hello" {
		t.Fatalf("extractSlug = %q, want hello", got)
	}
	if got := extractSlug("hello"); got != "" {
		t.Fatalf("extractSlug(hello) = %q, want empty", got)
	}
}
