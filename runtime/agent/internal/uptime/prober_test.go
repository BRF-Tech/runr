package uptime

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"regexp"
	"strings"
	"sync"
	"testing"
	"time"
)

func TestStatusMatches(t *testing.T) {
	cases := []struct {
		expected string
		code     int
		want     bool
	}{
		{"", 200, true}, {"", 302, true}, {"", 404, false}, {"", 500, false},
		{"200", 200, true}, {"200", 201, false},
		{"200,204", 204, true}, {"200, 204", 202, false},
		{"200-299", 250, true}, {"200-299", 300, false},
		{"2xx", 204, true}, {"2xx,301", 301, true}, {"4XX", 404, true}, {"5xx", 404, false},
		{"junk", 200, false},
	}
	for _, c := range cases {
		if got := StatusMatches(c.expected, c.code); got != c.want {
			t.Errorf("StatusMatches(%q, %d) = %t, want %t", c.expected, c.code, got, c.want)
		}
	}
}

func TestDeriveAPIBase(t *testing.T) {
	cases := map[string]string{
		"ws://runr-gateway.runr.svc.cluster.local/ws/agent/01JREGION": "http://runr-gateway.runr.svc.cluster.local",
		"wss://console.example.com/ws/agent/01JREGION":                "https://console.example.com",
		"wss://example.com/runr/ws/agent/01J":                         "https://example.com/runr",
		"":                                                            "",
	}
	for in, want := range cases {
		if got := DeriveAPIBase(in); got != want {
			t.Errorf("DeriveAPIBase(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestProbe(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/health":
			if r.Header.Get("X-Check") != "1" {
				w.WriteHeader(http.StatusBadRequest)
				return
			}
			_, _ = w.Write([]byte(`{"status":"ok","db":"up"}`))
		case "/down":
			w.WriteHeader(http.StatusServiceUnavailable)
		case "/slow":
			time.Sleep(1500 * time.Millisecond)
		case "/redirect":
			http.Redirect(w, r, "/health", http.StatusFound)
		}
	}))
	defer srv.Close()

	ok := Probe(context.Background(), Check{Key: "a", URL: srv.URL + "/health", Headers: map[string]string{"X-Check": "1"}, BodyRegex: `"db":"up"`}, regexp.MustCompile(`"db":"up"`))
	if !ok.OK || ok.StatusCode != 200 || ok.Error != "" || ok.Key != "a" || ok.CheckedAt == "" {
		t.Errorf("healthy check: %+v", ok)
	}

	down := Probe(context.Background(), Check{Key: "b", URL: srv.URL + "/down"}, nil)
	if down.OK || down.StatusCode != 503 || !strings.Contains(down.Error, "expected 200-399") {
		t.Errorf("503: %+v", down)
	}

	mismatch := Probe(context.Background(), Check{Key: "c", URL: srv.URL + "/health", Headers: map[string]string{"X-Check": "1"}, BodyRegex: "maintenance"}, regexp.MustCompile("maintenance"))
	if mismatch.OK || !strings.Contains(mismatch.Error, "does not match") {
		t.Errorf("body mismatch: %+v", mismatch)
	}

	slow := Probe(context.Background(), Check{Key: "d", URL: srv.URL + "/slow", TimeoutSec: 1}, nil)
	if slow.OK || !strings.Contains(slow.Error, "timed out") {
		t.Errorf("timeout: %+v", slow)
	}

	redirect := Probe(context.Background(), Check{Key: "e", URL: srv.URL + "/redirect", ExpectedStatus: "302"}, nil)
	if !redirect.OK || redirect.StatusCode != 302 {
		t.Errorf("redirects are not followed: %+v", redirect)
	}
}

// fakeRunR serves the check list and records reported results and the agent's credentials.
type fakeRunR struct {
	mu      sync.Mutex
	checks  []Check
	results []Result
	auth    []string
}

func (f *fakeRunR) handler(t *testing.T) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		f.auth = append(f.auth, r.Header.Get("Authorization")+"|"+r.Header.Get("X-Region-ID"))
		switch {
		case r.Method == http.MethodGet && r.URL.Path == "/api/internal/observability/agent/r1/uptime-checks":
			_ = json.NewEncoder(w).Encode(map[string]any{"checks": f.checks})
		case r.Method == http.MethodPost && r.URL.Path == "/api/internal/observability/agent/r1/uptime-results":
			var body struct {
				Results []Result `json:"results"`
			}
			if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
				t.Errorf("results body: %v", err)
			}
			f.results = append(f.results, body.Results...)
			w.WriteHeader(http.StatusAccepted)
		default:
			w.WriteHeader(http.StatusNotFound)
		}
	})
}

func TestSyncScheduleAndReport(t *testing.T) {
	target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {}))
	defer target.Close()

	f := &fakeRunR{checks: []Check{
		{Key: "_:01JCHECK1", URL: target.URL + "/", IntervalSec: 30},
		{Key: "acme:01JCHECK2", URL: target.URL + "/x", IntervalSec: 60, BodyRegex: "("}, // invalid regex → failing result
	}}
	api := httptest.NewServer(f.handler(t))
	defer api.Close()

	now := time.Unix(1_700_000_000, 0)
	p := New(api.URL+"/", "r1", "agent-token")
	p.now = func() time.Time { return now }

	p.sync(context.Background())
	due := p.due()
	if len(due) != 2 {
		t.Fatalf("new checks are due at once: %d", len(due))
	}
	if again := p.due(); len(again) != 0 {
		t.Fatal("running checks are not started twice")
	}

	for _, s := range due {
		p.done(s, Probe(context.Background(), s.check, s.re))
	}

	// 30 s later only the 30 s check is due again
	now = now.Add(30 * time.Second)
	if d := p.due(); len(d) != 1 || d[0].check.Key != "_:01JCHECK1" {
		t.Fatalf("due after 30s: %+v", d)
	}

	p.report(context.Background())

	f.mu.Lock()
	defer f.mu.Unlock()
	if len(f.results) != 2 {
		t.Fatalf("reported results: %+v", f.results)
	}
	byKey := map[string]Result{}
	for _, r := range f.results {
		byKey[r.Key] = r
	}
	if !byKey["_:01JCHECK1"].OK || byKey["acme:01JCHECK2"].OK || byKey["acme:01JCHECK2"].Error != "invalid body regex" {
		t.Errorf("results %+v", byKey)
	}
	for _, a := range f.auth {
		if a != "Bearer agent-token|r1" {
			t.Errorf("agent credentials %q", a)
		}
	}
}

func TestRemovedChecksStopAndResultsSurviveOutages(t *testing.T) {
	p := New("http://127.0.0.1:1", "r1", "t")
	p.setChecks([]Check{{Key: "k1", URL: "http://x"}, {Key: "k2", URL: "http://y"}})
	p.setChecks([]Check{{Key: "k2", URL: "http://y"}})
	if _, ok := p.checks["k1"]; ok || len(p.checks) != 1 {
		t.Fatalf("checks after removal: %v", p.checks)
	}

	p.pending = []Result{{Key: "k2"}}
	p.report(context.Background()) // RunR unreachable
	if len(p.pending) != 1 {
		t.Errorf("undelivered results are kept: %d", len(p.pending))
	}
}
