package main

import (
	"bufio"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"sync"
	"testing"
	"time"
)

// fakeTraefik stands in for the region Traefik's web entry point: it records what reached it.
type fakeTraefik struct {
	mu   sync.Mutex
	seen []*http.Request
	body []string
}

func (f *fakeTraefik) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	body, _ := io.ReadAll(r.Body)
	f.mu.Lock()
	f.seen = append(f.seen, r.Clone(r.Context()))
	f.body = append(f.body, string(body))
	f.mu.Unlock()

	switch r.URL.Path {
	case "/fail":
		w.WriteHeader(http.StatusServiceUnavailable)
		_, _ = w.Write([]byte("down"))
	case "/missing":
		w.WriteHeader(http.StatusNotFound)
	case "/ws":
		conn, rw, err := http.NewResponseController(w).Hijack()
		if err != nil {
			return
		}
		defer conn.Close()
		_, _ = rw.WriteString("HTTP/1.1 101 Switching Protocols\r\nUpgrade: echo\r\nConnection: Upgrade\r\n\r\n")
		_ = rw.Flush()
		line, _ := rw.ReadString('\n')
		_, _ = rw.WriteString("echo " + line)
		_ = rw.Flush()
	default:
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"ok":true}`))
	}
}

func (f *fakeTraefik) last() (*http.Request, string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if len(f.seen) == 0 {
		return nil, ""
	}
	return f.seen[len(f.seen)-1], f.body[len(f.body)-1]
}

func newMeter(t *testing.T, upstream string) (*Meter, *Recorder, *Tickets) {
	t.Helper()
	u, err := url.Parse(upstream)
	if err != nil {
		t.Fatal(err)
	}
	tickets := NewTickets("region-token")
	rec := NewRecorder("http://unused", "t", "pod", http.DefaultClient)
	return NewMeter(u, tickets, rec, nil), rec, tickets
}

var meterUsage = UsageKey{Scope: "_", GatewayID: gwA, RouteID: routeK, ConsumerID: "c-org", KeyID: "k-org", Hour: time.Now().UTC().Truncate(time.Hour).Format(time.RFC3339)}

func pendingRow(rec *Recorder, k UsageKey) UsageEntry {
	rec.mu.Lock()
	defer rec.mu.Unlock()
	if e := rec.pending[k]; e != nil {
		return *e
	}
	return UsageEntry{}
}

func TestMeterRefusesCallsWithoutATicket(t *testing.T) {
	traefik := &fakeTraefik{}
	upstream := httptest.NewServer(traefik)
	defer upstream.Close()
	m, rec, _ := newMeter(t, upstream.URL)

	for _, ticket := range []string{"", "v1.forged.sig", NewTickets("other-region").Sign(TicketFor(meterUsage, time.Now()))} {
		req := httptest.NewRequest(http.MethodGet, "/orders/1", nil)
		if ticket != "" {
			req.Header.Set(TicketHeader, ticket)
		}
		w := httptest.NewRecorder()
		m.ServeHTTP(w, req)
		if w.Code != http.StatusForbidden || errorCode(t, w) != "not_through_gateway" {
			t.Errorf("ticket %q: %d %s", ticket, w.Code, w.Body.String())
		}
	}
	if r, _ := traefik.last(); r != nil {
		t.Errorf("a call without a ticket reached the function: %s", r.URL)
	}
	if rec.Pending() != 0 {
		t.Errorf("refused calls were counted: %d rows", rec.Pending())
	}
}

func TestMeterHandsTheCallOnUnchangedAndRecordsTheAnswer(t *testing.T) {
	traefik := &fakeTraefik{}
	upstream := httptest.NewServer(traefik)
	defer upstream.Close()
	m, rec, tickets := newMeter(t, upstream.URL)
	proxy := httptest.NewServer(m)
	defer proxy.Close()

	req, _ := http.NewRequest(http.MethodPost, proxy.URL+"/orders/1?expand=items", strings.NewReader(`{"sku":"A-1"}`))
	req.Host = "orders-acme.eu-1.runr.example"
	req.Header.Set(TicketHeader, tickets.Sign(TicketFor(meterUsage, time.Now())))
	req.Header.Set("X-Runr-Consumer", "c-org")
	req.Header.Set("X-Forwarded-For", "203.0.113.7")
	req.Header.Set("X-Forwarded-Proto", "https")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	body, _ := io.ReadAll(resp.Body)
	resp.Body.Close()
	if resp.StatusCode != 200 || string(body) != `{"ok":true}` {
		t.Fatalf("answer %d %s", resp.StatusCode, body)
	}

	seen, seenBody := traefik.last()
	if seen.Host != "orders-acme.eu-1.runr.example" {
		t.Errorf("Host %q: the gateway's Host rewrite must reach Traefik", seen.Host)
	}
	if seen.URL.Path != "/orders/1" || seen.URL.RawQuery != "expand=items" || seenBody != `{"sku":"A-1"}` {
		t.Errorf("request %s?%s %q", seen.URL.Path, seen.URL.RawQuery, seenBody)
	}
	if seen.Header.Get(TicketHeader) != "" {
		t.Error("the ticket reached the function")
	}
	if seen.Header.Get("X-Runr-Consumer") != "c-org" || seen.Header.Get("X-Forwarded-For") != "203.0.113.7" || seen.Header.Get("X-Forwarded-Proto") != "https" {
		t.Errorf("headers %v", seen.Header)
	}

	row := pendingRow(rec, meterUsage)
	if row.Status2xx != 1 || row.UpstreamErrors != 0 || row.BytesIn != int64(len(`{"sku":"A-1"}`)) || row.BytesOut != int64(len(`{"ok":true}`)) {
		t.Errorf("row %+v", row)
	}
	var measured int64
	for _, n := range row.LatencyBuckets {
		measured += n
	}
	if measured != 1 || len(row.LatencyBuckets) != len(LatencyBoundsMS)+1 {
		t.Errorf("latency buckets %v", row.LatencyBuckets)
	}
	// the decision itself is counted by the check, not here
	if row.Requests != 0 {
		t.Errorf("the proxy counted a request: %+v", row)
	}
}

func TestMeterCountsStatusClassesAndUpstreamErrors(t *testing.T) {
	traefik := &fakeTraefik{}
	upstream := httptest.NewServer(traefik)
	m, rec, tickets := newMeter(t, upstream.URL)

	do := func(path string) *httptest.ResponseRecorder {
		req := httptest.NewRequest(http.MethodGet, path, nil)
		req.Header.Set(TicketHeader, tickets.Sign(TicketFor(meterUsage, time.Now())))
		w := httptest.NewRecorder()
		m.ServeHTTP(w, req)
		return w
	}
	do("/ok")
	do("/missing")
	if w := do("/fail"); w.Code != http.StatusServiceUnavailable || w.Body.String() != "down" {
		t.Errorf("the function's 503 is passed on: %d %q", w.Code, w.Body.String())
	}

	upstream.Close()
	w := do("/ok")
	if w.Code != http.StatusBadGateway || errorCode(t, w) != "upstream_unavailable" {
		t.Errorf("no upstream: %d %s", w.Code, w.Body.String())
	}

	row := pendingRow(rec, meterUsage)
	if row.Status2xx != 1 || row.Status4xx != 1 || row.Status5xx != 1 || row.UpstreamErrors != 1 {
		t.Errorf("row %+v", row)
	}
	var measured int64
	for _, n := range row.LatencyBuckets {
		measured += n
	}
	if measured != 3 {
		t.Errorf("an answer without a function answer has no latency: %d measured", measured)
	}
}

func TestMeterPassesUpgradedConnections(t *testing.T) {
	traefik := &fakeTraefik{}
	upstream := httptest.NewServer(traefik)
	defer upstream.Close()
	m, rec, tickets := newMeter(t, upstream.URL)
	proxy := httptest.NewServer(m)
	defer proxy.Close()

	conn, err := net.Dial("tcp", strings.TrimPrefix(proxy.URL, "http://"))
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	_ = conn.SetDeadline(time.Now().Add(5 * time.Second))
	_, _ = conn.Write([]byte("GET /ws HTTP/1.1\r\nHost: orders.example\r\nConnection: Upgrade\r\nUpgrade: echo\r\n" +
		TicketHeader + ": " + tickets.Sign(TicketFor(meterUsage, time.Now())) + "\r\n\r\n"))
	reader := bufio.NewReader(conn)
	resp, err := http.ReadResponse(reader, nil)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != http.StatusSwitchingProtocols {
		t.Fatalf("status %d", resp.StatusCode)
	}
	_, _ = conn.Write([]byte("ping\n"))
	line, _ := reader.ReadString('\n')
	if line != "echo ping\n" {
		t.Errorf("tunnel answered %q", line)
	}
	conn.Close()

	// the tunnel's bytes count once it has closed: "ping\n" in, "echo ping\n" out
	deadline := time.Now().Add(3 * time.Second)
	for row := pendingRow(rec, meterUsage); (row.Status2xx != 1 || row.BytesOut != 10) && time.Now().Before(deadline); row = pendingRow(rec, meterUsage) {
		time.Sleep(10 * time.Millisecond)
	}
	row := pendingRow(rec, meterUsage)
	if row.Status2xx != 1 {
		t.Errorf("the upgrade is an answer of the function: %+v", row)
	}
	if row.BytesIn != int64(len("ping\n")) || row.BytesOut != int64(len("echo ping\n")) {
		t.Errorf("tunnel bytes in %d / out %d, want 5 / 10", row.BytesIn, row.BytesOut)
	}
	var measured int64
	for _, n := range row.LatencyBuckets {
		measured += n
	}
	if measured != 1 {
		t.Errorf("the handshake is the upgrade's one latency: %v", row.LatencyBuckets)
	}
}

func TestTunnelCountsBothWaysOnce(t *testing.T) {
	var got [][2]int64
	tunnel := &countingTunnel{ReadWriteCloser: &loopback{}, done: func(in, out int64) { got = append(got, [2]int64{in, out}) }}
	_, _ = tunnel.Write([]byte("hello"))
	buf := make([]byte, 3)
	_, _ = tunnel.Read(buf)
	_ = tunnel.Close()
	_ = tunnel.Close()
	if len(got) != 1 || got[0] != [2]int64{5, 3} {
		t.Errorf("done called with %v, want once with in 5, out 3", got)
	}
}

// loopback reads back what was written.
type loopback struct{ buf []byte }

func (l *loopback) Write(b []byte) (int, error) { l.buf = append(l.buf, b...); return len(b), nil }
func (l *loopback) Read(b []byte) (int, error) {
	n := copy(b, l.buf)
	l.buf = l.buf[n:]
	return n, nil
}
func (l *loopback) Close() error { return nil }

func TestLatencyBuckets(t *testing.T) {
	cases := map[time.Duration]int{
		500 * time.Microsecond: 0,
		2 * time.Millisecond:   0,
		3 * time.Millisecond:   1,
		100 * time.Millisecond: 7,
		101 * time.Millisecond: 8,
		30 * time.Second:       len(LatencyBoundsMS) - 1,
		2 * time.Minute:        len(LatencyBoundsMS),
	}
	for d, want := range cases {
		if got := latencyBucket(d); got != want {
			t.Errorf("%s: bucket %d, want %d", d, got, want)
		}
	}
}

func TestTheCheckIssuesATicketOnlyForAMeteredRoute(t *testing.T) {
	a, _ := newAuthorizer(t, fixture(""))
	a.Tickets = NewTickets("region-token")

	plain := call(a, routeK, "GET", map[string]string{"X-Api-Key": "rk_org"})
	if plain.Header().Get(TicketHeader) != "" {
		t.Error("a route that is not metered got a ticket")
	}

	req := httptest.NewRequest(http.MethodGet, "/auth/"+routeK+"?meter=1", nil)
	req.Header.Set("X-Forwarded-Method", "GET")
	req.Header.Set("X-Api-Key", "rk_org")
	w := httptest.NewRecorder()
	a.ServeHTTP(w, req)
	ticket, err := a.Tickets.Verify(w.Header().Get(TicketHeader), a.Now())
	if w.Code != 200 || err != nil {
		t.Fatalf("metered: %d %v", w.Code, err)
	}
	if ticket.Consumer != "c-org" || ticket.Key != "k-org" || ticket.Route != routeK || ticket.Gateway != gwA || ticket.Scope != "_" {
		t.Errorf("ticket %+v", ticket)
	}

	// a refused call gets none
	req = httptest.NewRequest(http.MethodGet, "/auth/"+routeK+"?meter=1", nil)
	w = httptest.NewRecorder()
	a.ServeHTTP(w, req)
	if w.Code != 401 || w.Header().Get(TicketHeader) != "" {
		t.Errorf("refused: %d ticket %q", w.Code, w.Header().Get(TicketHeader))
	}
}

func TestSettingsOfTheProxy(t *testing.T) {
	env := map[string]string{"RUNR_API_URL": "https://runr.example", "RUNR_REGION_ID": "r", "RUNR_TOKEN": "t"}
	get := func(k string) string { return env[k] }

	s, err := loadSettings(get)
	if err != nil || s.upstream != nil || s.proxy != ":8081" {
		t.Errorf("without UPSTREAM_URL: %+v %v", s, err)
	}
	env["UPSTREAM_URL"] = "http://traefik.knative-serving.svc.cluster.local"
	if s, err = loadSettings(get); err != nil || s.upstream == nil || s.upstream.Host != "traefik.knative-serving.svc.cluster.local" {
		t.Errorf("with UPSTREAM_URL: %+v %v", s, err)
	}
	env["UPSTREAM_URL"] = "traefik:80"
	if _, err = loadSettings(get); err == nil {
		t.Error("an UPSTREAM_URL without a scheme was taken")
	}
}

func TestACallerThatGoesAwayIsNoUpstreamError(t *testing.T) {
	release := make(chan struct{})
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		select {
		case <-r.Context().Done():
		case <-release:
		}
	}))
	defer upstream.Close()
	defer close(release)
	m, rec, tickets := newMeter(t, upstream.URL)
	proxy := httptest.NewServer(m)
	defer proxy.Close()

	req, _ := http.NewRequest(http.MethodGet, proxy.URL+"/slow", nil)
	req.Header.Set(TicketHeader, tickets.Sign(TicketFor(meterUsage, time.Now())))
	client := &http.Client{Timeout: 200 * time.Millisecond}
	if resp, err := client.Do(req); err == nil {
		resp.Body.Close()
		t.Fatal("the slow call answered")
	}

	time.Sleep(300 * time.Millisecond)
	if row := pendingRow(rec, meterUsage); row.UpstreamErrors != 0 || row.Status2xx+row.Status5xx != 0 {
		t.Errorf("a caller that went away was counted: %+v", row)
	}
}
