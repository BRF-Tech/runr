package mqtt

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	paho "github.com/eclipse/paho.mqtt.golang"

	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

func TestConfig(t *testing.T) {
	for name, tc := range map[string]struct{ cfg, wantErr string }{
		"no broker":     {`{"topic": "a", "client_id": "c"}`, "broker"},
		"bad scheme":    {`{"broker": "http://h:1883", "topic": "a", "client_id": "c"}`, "scheme"},
		"no host":       {`{"broker": "tcp://", "topic": "a", "client_id": "c"}`, "broker"},
		"no topic":      {`{"broker": "tcp://h:1883", "client_id": "c"}`, "topic"},
		"no client id":  {`{"broker": "tcp://h:1883", "topic": "a"}`, "client_id"},
		"qos":           {`{"broker": "tcp://h:1883", "topic": "a", "client_id": "c", "qos": 3}`, "qos"},
		"tls plain tcp": {`{"broker": "tcp://h:1883", "topic": "a", "client_id": "c", "tls": {"enabled": true}}`, "tls"},
		"tls ca":        {`{"broker": "ssl://h:8883", "topic": "a", "client_id": "c", "tls": {"enabled": true, "ca_pem": "x"}}`, "ca_pem"},
	} {
		t.Run(name, func(t *testing.T) {
			_, err := New(json.RawMessage(tc.cfg))
			if err == nil || !strings.Contains(err.Error(), tc.wantErr) {
				t.Fatalf("New(%s) = %v, want an error naming %q", tc.cfg, err, tc.wantErr)
			}
		})
	}

	s, err := New(json.RawMessage(`{"broker": "ssl://u:hunter2@mqtt.example:8883", "topic": "sensors/+/temp", "client_id": "runr-p-t",
		"username": "u", "password": "hunter2", "shared_group": "runr", "tls": {"enabled": true}}`))
	if err != nil {
		t.Fatal(err)
	}
	src := s.(*Source)
	if src.qos != 1 || src.filter != "$share/runr/sensors/+/temp" || src.Name() != "mqtt $share/runr/sensors/+/temp@ssl://mqtt.example:8883" {
		t.Errorf("qos %d filter %q name %q", src.qos, src.filter, src.Name())
	}
	o := src.options()
	if !o.AutoAckDisabled || o.Order || o.CleanSession || o.ClientID != "runr-p-t" || o.TLSConfig == nil || !o.AutoReconnect {
		t.Errorf("options %+v", o)
	}

	s, _ = New(json.RawMessage(`{"broker": "tcp://h:1883", "topic": "a", "client_id": "c", "qos": 0, "clean_session": true}`))
	if src := s.(*Source); src.qos != 0 || !src.options().CleanSession {
		t.Errorf("qos 0 / clean session not taken: %+v", src.cfg)
	}
}

// fakeMessage is an MQTT message that counts its acknowledgements.
type fakeMessage struct {
	topic   string
	qos     byte
	id      uint16
	payload []byte
	acks    atomic.Int64
}

func (m *fakeMessage) Duplicate() bool   { return false }
func (m *fakeMessage) Qos() byte         { return m.qos }
func (m *fakeMessage) Retained() bool    { return false }
func (m *fakeMessage) Topic() string     { return m.topic }
func (m *fakeMessage) MessageID() uint16 { return m.id }
func (m *fakeMessage) Payload() []byte   { return m.payload }
func (m *fakeMessage) Ack()              { m.acks.Add(1) }

func TestEvent(t *testing.T) {
	s := &Source{cfg: Config{ClientID: "runr-p-t"}}
	received := time.Unix(0, 1700000000000000001)
	ev := s.event(&fakeMessage{topic: "sensors/a/temp", qos: 1, id: 17, payload: []byte("21.5")}, received)
	if ev.ID != "runr-p-t/1700000000000000001/17" || ev.Type != "dev.runr.mqtt.message" || ev.Source != "/mqtt/sensors/a/temp" ||
		ev.Subject != "sensors/a/temp" || ev.DataContentType != "application/octet-stream" || string(ev.Data) != "21.5" {
		t.Errorf("event %+v", ev)
	}
	if ev.Extensions["mqttqos"] != "1" || ev.Extensions["mqttretained"] != "false" || ev.Extensions["mqttmessageid"] != "17" {
		t.Errorf("extensions %v", ev.Extensions)
	}
}

func TestHandlerAcksSettledWithinWindow(t *testing.T) {
	s := &Source{cfg: Config{ClientID: "c"}}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	var active, peak atomic.Int64
	release := make(chan struct{})
	emit := func(ctx context.Context, ev source.Event) error {
		n := active.Add(1)
		defer active.Add(-1)
		for p := peak.Load(); n > p && !peak.CompareAndSwap(p, n); p = peak.Load() {
		}
		<-release
		if ev.Extensions["mqttmessageid"] == "3" {
			return fmt.Errorf("%w after 6 attempts", source.ErrDeadLettered)
		}
		return nil
	}
	h := newHandler(s, ctx, emit, 2)
	msgs := make([]*fakeMessage, 6)
	var wg sync.WaitGroup
	for i := range msgs {
		msgs[i] = &fakeMessage{topic: "t", qos: 1, id: uint16(i + 1)}
		wg.Add(1)
		go func() { defer wg.Done(); h.handle(nil, msgs[i]) }() // paho calls each handler in its own goroutine
	}
	time.Sleep(50 * time.Millisecond)
	if p := peak.Load(); p != 2 {
		t.Errorf("%d deliveries at once, want the window (2)", p)
	}
	close(release)
	wg.Wait()
	for _, m := range msgs {
		if m.acks.Load() != 1 {
			t.Errorf("message %d acked %d times", m.id, m.acks.Load())
		}
	}
}

func TestHandlerShutdown(t *testing.T) {
	s := &Source{cfg: Config{ClientID: "c"}}
	ctx, cancel := context.WithCancel(context.Background())
	started := make(chan struct{})
	emit := func(ctx context.Context, _ source.Event) error {
		close(started)
		<-ctx.Done()
		return ctx.Err()
	}
	h := newHandler(s, ctx, emit, 1)
	inFlight := &fakeMessage{topic: "t", qos: 1, id: 1}
	go h.handle(nil, inFlight)
	<-started
	cancel()
	h.close()
	late := &fakeMessage{topic: "t", qos: 1, id: 2}
	h.handle(nil, late)
	if inFlight.acks.Load() != 0 || late.acks.Load() != 0 {
		t.Fatalf("acks in flight=%d late=%d, want none: the session redelivers them", inFlight.acks.Load(), late.acks.Load())
	}
}

// fakeToken is a completed token.
type fakeToken struct{ err error }

func (t fakeToken) Wait() bool                     { return true }
func (t fakeToken) WaitTimeout(time.Duration) bool { return true }
func (t fakeToken) Done() <-chan struct{}          { ch := make(chan struct{}); close(ch); return ch }
func (t fakeToken) Error() error                   { return t.err }

// fakeClient connects at once, records subscriptions and the handler, and calls OnConnect like
// paho does.
type fakeClient struct {
	paho.Client // methods the source does not use panic
	opts        *paho.ClientOptions
	connectErr  error

	mu           sync.Mutex
	subscribed   []string
	handler      paho.MessageHandler
	unsubscribed bool
	disconnected bool
}

func (c *fakeClient) Connect() paho.Token {
	if c.connectErr == nil {
		go c.opts.OnConnect(c)
	}
	return fakeToken{err: c.connectErr}
}

func (c *fakeClient) IsConnectionOpen() bool { return true }

func (c *fakeClient) Subscribe(topic string, qos byte, cb paho.MessageHandler) paho.Token {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.subscribed = append(c.subscribed, fmt.Sprintf("%s@%d", topic, qos))
	c.handler = cb
	return fakeToken{}
}

func (c *fakeClient) Unsubscribe(...string) paho.Token {
	c.mu.Lock()
	c.unsubscribed = true
	c.mu.Unlock()
	return fakeToken{}
}

func (c *fakeClient) Disconnect(uint) {
	c.mu.Lock()
	c.disconnected = true
	c.mu.Unlock()
}

func (c *fakeClient) subscribedHandler() paho.MessageHandler {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.handler
}

func TestRunSubscribesAndLeavesTheSession(t *testing.T) {
	s, err := New(json.RawMessage(`{"broker": "tcp://h:1883", "topic": "sensors/#", "client_id": "c", "qos": 2}`))
	if err != nil {
		t.Fatal(err)
	}
	src := s.(*Source)
	fc := &fakeClient{}
	src.newClient = func(o *paho.ClientOptions) paho.Client { fc.opts = o; return fc }

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	var delivered atomic.Int64
	done := make(chan error, 1)
	go func() {
		done <- src.Run(ctx, func(context.Context, source.Event) error { delivered.Add(1); return nil })
	}()
	deadline := time.Now().Add(5 * time.Second)
	for fc.subscribedHandler() == nil && time.Now().Before(deadline) {
		time.Sleep(5 * time.Millisecond)
	}
	handle := fc.subscribedHandler()
	if handle == nil {
		t.Fatal("not subscribed after connect")
	}
	m := &fakeMessage{topic: "sensors/a", qos: 2, id: 9}
	handle(fc, m)
	// A message of the stored session that arrives before the subscription.
	early := &fakeMessage{topic: "sensors/b", qos: 1, id: 10}
	fc.opts.DefaultPublishHandler(fc, early)
	cancel()
	if err := <-done; err != nil {
		t.Fatalf("Run = %v", err)
	}
	fc.mu.Lock()
	defer fc.mu.Unlock()
	if fmt.Sprint(fc.subscribed) != "[sensors/#@2]" || fc.unsubscribed || !fc.disconnected {
		t.Errorf("subscribed %v unsubscribed %v disconnected %v, want one subscription kept in the session", fc.subscribed, fc.unsubscribed, fc.disconnected)
	}
	if m.acks.Load() != 1 || early.acks.Load() != 1 || delivered.Load() != 2 {
		t.Errorf("acks %d/%d delivered %d", m.acks.Load(), early.acks.Load(), delivered.Load())
	}
}

func TestRunReturnsAFailedConnect(t *testing.T) {
	s, _ := New(json.RawMessage(`{"broker": "tcp://h:1883", "topic": "a", "client_id": "c"}`))
	src := s.(*Source)
	refused := errors.New("connection refused")
	src.newClient = func(o *paho.ClientOptions) paho.Client { return &fakeClient{opts: o, connectErr: refused} }
	if err := src.Run(context.Background(), func(context.Context, source.Event) error { return nil }); !errors.Is(err, refused) {
		t.Fatalf("Run = %v, want the connect error", err)
	}
}

// ── Integration: an MQTT broker with QC_TEST_MQTT=tcp://host:1883 go test ./sources/mqtt ──

func TestMQTTBroker(t *testing.T) {
	broker := os.Getenv("QC_TEST_MQTT")
	if broker == "" {
		t.Skip("QC_TEST_MQTT not set")
	}
	topic := fmt.Sprintf("qc-test/%d", time.Now().UnixNano())
	clientID := fmt.Sprintf("qc-test-%d", time.Now().UnixNano())
	raw, _ := json.Marshal(map[string]any{"broker": broker, "topic": topic + "/#", "client_id": clientID, "qos": 1})
	s, err := New(raw)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	var mu sync.Mutex
	var got []source.Event
	runCtx, stop := context.WithCancel(ctx)
	done := make(chan error, 1)
	go func() {
		done <- s.Run(runCtx, func(_ context.Context, ev source.Event) error {
			mu.Lock()
			got = append(got, ev)
			mu.Unlock()
			return nil
		})
	}()

	pub := paho.NewClient(paho.NewClientOptions().AddBroker(broker).SetClientID(clientID + "-pub"))
	if tok := pub.Connect(); !tok.WaitTimeout(10*time.Second) || tok.Error() != nil {
		t.Fatalf("publisher connect: %v", tok.Error())
	}
	defer pub.Disconnect(100)
	time.Sleep(500 * time.Millisecond) // the source subscribed
	for i := range 3 {
		if tok := pub.Publish(topic+"/x", 1, false, fmt.Sprint(i)); !tok.WaitTimeout(10*time.Second) || tok.Error() != nil {
			t.Fatalf("publish: %v", tok.Error())
		}
	}
	for {
		mu.Lock()
		n := len(got)
		mu.Unlock()
		if n >= 3 || ctx.Err() != nil {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	stop()
	if err := <-done; err != nil {
		t.Fatalf("Run = %v", err)
	}
	mu.Lock()
	defer mu.Unlock()
	if len(got) != 3 || got[0].Subject != topic+"/x" || got[0].Extensions["mqttqos"] != "1" {
		t.Fatalf("events %+v", got)
	}
}
