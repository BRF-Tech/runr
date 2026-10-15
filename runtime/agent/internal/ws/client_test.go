package ws

import (
	"context"
	"encoding/json"
	"math"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/gorilla/websocket"
)

// platform is a fake RunR gateway: it upgrades /ws/agent connections and hands them to the test.
type platform struct {
	srv     *httptest.Server
	conns   chan *websocket.Conn
	headers chan http.Header
	reject  atomic.Int32 // the next N handshakes answer 401
	dials   atomic.Int32

	mu       sync.Mutex
	accepted []time.Time
}

func newPlatform(t *testing.T) *platform {
	t.Helper()
	p := &platform{conns: make(chan *websocket.Conn, 16), headers: make(chan http.Header, 16)}
	upgrader := websocket.Upgrader{}
	p.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		p.dials.Add(1)
		if p.reject.Load() > 0 {
			p.reject.Add(-1)
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		conn, err := upgrader.Upgrade(w, r, nil)
		if err != nil {
			return
		}
		p.mu.Lock()
		p.accepted = append(p.accepted, time.Now())
		p.mu.Unlock()
		p.headers <- r.Header.Clone()
		p.conns <- conn
	}))
	t.Cleanup(p.srv.Close)
	return p
}

func (p *platform) url() string {
	return "ws" + strings.TrimPrefix(p.srv.URL, "http") + "/ws/agent/r1"
}

func (p *platform) nextConn(t *testing.T) *websocket.Conn {
	t.Helper()
	select {
	case c := <-p.conns:
		t.Cleanup(func() { c.Close() })
		return c
	case <-time.After(3 * time.Second):
		t.Fatal("agent did not connect")
		return nil
	}
}

func readJSON(t *testing.T, conn *websocket.Conn) map[string]interface{} {
	t.Helper()
	conn.SetReadDeadline(time.Now().Add(3 * time.Second))
	typ, data, err := conn.ReadMessage()
	if err != nil {
		t.Fatalf("server read: %v", err)
	}
	if typ != websocket.TextMessage {
		t.Fatalf("message type %d, want text", typ)
	}
	var m map[string]interface{}
	if err := json.Unmarshal(data, &m); err != nil {
		t.Fatalf("invalid JSON %q: %v", data, err)
	}
	return m
}

func testClient(url string) *Client {
	c := NewClient(url, "r1", "tok")
	c.initialBackoff = 10 * time.Millisecond
	c.maxBackoff = 40 * time.Millisecond
	c.stableAfter = time.Hour
	return c
}

// start runs the client until the test ends and checks that Run returns on cancel.
func start(t *testing.T, c *Client) context.CancelFunc {
	t.Helper()
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		c.Run(ctx)
		close(done)
	}()
	t.Cleanup(func() {
		cancel()
		select {
		case <-done:
		case <-time.After(3 * time.Second):
			t.Error("Run did not return after cancel")
		}
	})
	return cancel
}

func TestRunAuthenticatesAndDeliversMessages(t *testing.T) {
	p := newPlatform(t)
	c := testClient(p.url())
	start(t, c)

	conn := p.nextConn(t)
	h := <-p.headers
	if got := h.Get("Authorization"); got != "Bearer tok" {
		t.Errorf("Authorization = %q", got)
	}
	if got := h.Get("X-Region-ID"); got != "r1" {
		t.Errorf("X-Region-ID = %q", got)
	}

	c.Send(map[string]interface{}{"type": "metrics", "project_slug": "shop"})
	c.Send(map[string]interface{}{"type": "session_end", "pod": "p"})

	m := readJSON(t, conn)
	if m["type"] != "metrics" || m["project_slug"] != "shop" || m["region_id"] != "r1" {
		t.Fatalf("first message %v", m)
	}
	if m := readJSON(t, conn); m["type"] != "session_end" || m["region_id"] != "r1" {
		t.Fatalf("second message %v (order must be kept)", m)
	}
}

func TestMessagesQueuedWhileDisconnectedAreDeliveredOnConnect(t *testing.T) {
	p := newPlatform(t)
	c := testClient(p.url())
	c.Send(map[string]interface{}{"type": "event", "kind": "pod_oom"})

	start(t, c)
	if m := readJSON(t, p.nextConn(t)); m["kind"] != "pod_oom" {
		t.Fatalf("queued message %v", m)
	}
}

func TestReconnectsAfterServerDropsConnection(t *testing.T) {
	p := newPlatform(t)
	c := testClient(p.url())
	start(t, c)

	first := p.nextConn(t)
	c.Send(map[string]interface{}{"n": 1})
	if m := readJSON(t, first); m["n"] != float64(1) {
		t.Fatalf("message on first connection %v", m)
	}
	first.Close() // gateway restart

	second := p.nextConn(t)
	c.Send(map[string]interface{}{"n": 2})
	if m := readJSON(t, second); m["n"] != float64(2) {
		t.Fatalf("message on second connection %v", m)
	}
}

func TestRetriesRejectedHandshakeWithBackoff(t *testing.T) {
	p := newPlatform(t)
	p.reject.Store(2)
	c := testClient(p.url())
	began := time.Now()
	start(t, c)

	p.nextConn(t)
	if got := p.dials.Load(); got != 3 {
		t.Fatalf("%d dials, want 3 (2 rejected + 1 accepted)", got)
	}
	// waits of 10ms and 20ms before the second and third dial
	if elapsed := time.Since(began); elapsed < 30*time.Millisecond {
		t.Fatalf("connected after %v, backoff not applied", elapsed)
	}
}

func TestShortLivedConnectionsBackOffInsteadOfLooping(t *testing.T) {
	p := newPlatform(t)
	c := testClient(p.url())
	start(t, c)

	// the gateway accepts and drops every connection at once
	for i := 0; i < 4; i++ {
		p.nextConn(t).Close()
	}

	p.mu.Lock()
	defer p.mu.Unlock()
	// backoff after each drop: 10ms, 20ms, 40ms (capped)
	wantMin := []time.Duration{10 * time.Millisecond, 20 * time.Millisecond, 40 * time.Millisecond}
	for i, want := range wantMin {
		if gap := p.accepted[i+1].Sub(p.accepted[i]); gap < want {
			t.Errorf("reconnect %d after %v, want at least %v", i+1, gap, want)
		}
	}
}

func TestRunStopsWhileConnected(t *testing.T) {
	p := newPlatform(t)
	c := testClient(p.url())
	cancel := start(t, c)
	conn := p.nextConn(t)

	cancel()
	conn.SetReadDeadline(time.Now().Add(3 * time.Second))
	if _, _, err := conn.ReadMessage(); err == nil {
		t.Fatal("connection still open after cancel")
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.conn != nil {
		t.Fatal("client keeps a reference to the closed connection")
	}
}

func TestRunStopsWhileBackingOff(t *testing.T) {
	srv := httptest.NewServer(http.NotFoundHandler())
	url := "ws" + strings.TrimPrefix(srv.URL, "http")
	srv.Close()

	c := testClient(url)
	c.initialBackoff = time.Hour // Run must not wait for it
	start(t, c)
	time.Sleep(20 * time.Millisecond)
}

func TestIncomingMessagesKeepTheConnectionUsable(t *testing.T) {
	p := newPlatform(t)
	c := testClient(p.url())
	start(t, c)
	conn := p.nextConn(t)

	for _, msg := range []string{
		`{"type":"config_update","interval":5}`,
		`{"type":"force_snapshot"}`,
		`{"type":"unknown"}`,
		`not json`,
		`{"type":42}`,
	} {
		if err := conn.WriteMessage(websocket.TextMessage, []byte(msg)); err != nil {
			t.Fatal(err)
		}
	}
	c.Send(map[string]interface{}{"type": "metrics"})
	if m := readJSON(t, conn); m["type"] != "metrics" {
		t.Fatalf("message after incoming traffic %v", m)
	}
	if p.dials.Load() != 1 {
		t.Fatalf("%d dials, incoming messages must not drop the connection", p.dials.Load())
	}
}

func TestSendDropsWhenBufferFull(t *testing.T) {
	c := NewClient("ws://unused", "r1", "tok")
	for i := 0; i < cap(c.sendCh)+10; i++ {
		c.Send(map[string]interface{}{"i": i}) // must never block
	}
	if len(c.sendCh) != cap(c.sendCh) {
		t.Fatalf("%d queued, want %d", len(c.sendCh), cap(c.sendCh))
	}
	var first map[string]interface{}
	json.Unmarshal(<-c.sendCh, &first)
	if first["i"] != float64(0) || first["region_id"] != "r1" {
		t.Fatalf("oldest queued message %v", first)
	}
}

func TestSendSkipsUnencodableMessages(t *testing.T) {
	c := NewClient("ws://unused", "r1", "tok")
	c.Send(map[string]interface{}{"cpu": math.NaN()})
	c.Send(map[string]interface{}{"fn": func() {}})
	if len(c.sendCh) != 0 {
		t.Fatalf("%d unencodable messages queued", len(c.sendCh))
	}
}
