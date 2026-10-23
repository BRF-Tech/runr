package nats

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	natsgo "github.com/nats-io/nats.go"
	"github.com/nats-io/nats.go/jetstream"
	"github.com/nats-io/nkeys"

	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

func TestConfig(t *testing.T) {
	for name, tc := range map[string]struct{ cfg, wantErr string }{
		"no url":           {`{"subject": "a"}`, "url"},
		"no subject":       {`{"url": "nats://n:4222"}`, "subject"},
		"deliver":          {`{"url": "nats://n:4222", "subject": "a", "deliver": "last"}`, "deliver"},
		"stream needs dur": {`{"url": "nats://n:4222", "subject": "a", "stream": "S"}`, "durable"},
		"queue with js":    {`{"url": "nats://n:4222", "subject": "a", "stream": "S", "durable": "d", "queue_group": "q"}`, "queue_group"},
		"two auths":        {`{"url": "nats://n:4222", "subject": "a", "auth": {"token": "t", "user": "u", "password": "p"}}`, "one of"},
		"user only":        {`{"url": "nats://n:4222", "subject": "a", "auth": {"user": "u"}}`, "auth.user and auth.password"},
		"bad seed":         {`{"url": "nats://n:4222", "subject": "a", "auth": {"nkey_seed": "SUxx"}}`, "nkey_seed"},
		"bad creds":        {`{"url": "nats://n:4222", "subject": "a", "auth": {"creds": "garbage"}}`, "creds"},
		"bad tls":          {`{"url": "nats://n:4222", "subject": "a", "tls": {"enabled": true, "ca_pem": "x"}}`, "ca_pem"},
		"ack_wait":         {`{"url": "nats://n:4222", "subject": "a", "ack_wait": "-1s"}`, "negative"},
	} {
		t.Run(name, func(t *testing.T) {
			_, err := New(json.RawMessage(tc.cfg))
			if err == nil || !strings.Contains(err.Error(), tc.wantErr) {
				t.Fatalf("New(%s) = %v, want an error naming %q", tc.cfg, err, tc.wantErr)
			}
		})
	}

	kp, _ := nkeys.CreateUser()
	seed, _ := kp.Seed()
	creds := "-----BEGIN NATS USER JWT-----\neyJ0eXAiOiJKV1QifQ.e30.c2ln\n------END NATS USER JWT------\n\n" +
		"-----BEGIN USER NKEY SEED-----\n" + string(seed) + "\n------END USER NKEY SEED------\n"
	for _, auth := range []map[string]string{
		{"user": "u", "password": "hunter2"}, {"token": "hunter2"}, {"nkey_seed": string(seed)}, {"creds": creds},
	} {
		raw, _ := json.Marshal(map[string]any{"url": "nats://u:hunter2@n:4222", "subject": "orders.>", "stream": "ORDERS", "durable": "runr-p-t", "auth": auth})
		s, err := New(raw)
		if err != nil {
			t.Fatalf("auth %v: %v", auth, err)
		}
		src := s.(*Source)
		if len(src.opts) != 1 || src.ackWait != 60*time.Second || src.cfg.Deliver != "new" {
			t.Errorf("auth %v: opts %d ackWait %s deliver %q", auth, len(src.opts), src.ackWait, src.cfg.Deliver)
		}
		if strings.Contains(src.Name(), "hunter2") || src.Name() != "nats orders.>@ORDERS/runr-p-t" {
			t.Errorf("Name %q", src.Name())
		}
	}
}

func TestEvent(t *testing.T) {
	s := &Source{}
	ev := s.event("orders.eu", natsgo.Header{"content-type": {"application/json"}, "Trace": {"a", "b"}}, []byte(`{"n":1}`))
	if ev.Type != "dev.runr.nats.message" || ev.Source != "/nats/orders.eu" || ev.Subject != "orders.eu" ||
		ev.DataContentType != "application/json" || string(ev.Data) != `{"n":1}` {
		t.Errorf("event %+v", ev)
	}
	var h map[string]string
	if err := json.Unmarshal([]byte(ev.Extensions["natsheaders"]), &h); err != nil || h["Trace"] != "a, b" {
		t.Errorf("natsheaders %q", ev.Extensions["natsheaders"])
	}
	ev = s.event("x", nil, []byte{0})
	if ev.DataContentType != "application/octet-stream" || ev.Extensions != nil {
		t.Errorf("event without headers %+v", ev)
	}
}

// fakeMsg is a JetStream message that records its acknowledgements.
type fakeMsg struct {
	subject    string
	data       []byte
	header     natsgo.Header
	meta       *jetstream.MsgMetadata
	acks       atomic.Int64
	inProgress atomic.Int64
	naks       atomic.Int64
}

func (m *fakeMsg) Metadata() (*jetstream.MsgMetadata, error) { return m.meta, nil }
func (m *fakeMsg) Data() []byte                              { return m.data }
func (m *fakeMsg) Headers() natsgo.Header                    { return m.header }
func (m *fakeMsg) Subject() string                           { return m.subject }
func (m *fakeMsg) Reply() string                             { return "" }
func (m *fakeMsg) Ack() error                                { m.acks.Add(1); return nil }
func (m *fakeMsg) DoubleAck(context.Context) error           { m.acks.Add(1); return nil }
func (m *fakeMsg) Nak() error                                { m.naks.Add(1); return nil }
func (m *fakeMsg) NakWithDelay(time.Duration) error          { m.naks.Add(1); return nil }
func (m *fakeMsg) InProgress() error                         { m.inProgress.Add(1); return nil }
func (m *fakeMsg) Term() error                               { m.naks.Add(1); return nil }
func (m *fakeMsg) TermWithReason(string) error               { m.naks.Add(1); return nil }

func jsMsg(seq uint64, delivered uint64) *fakeMsg {
	return &fakeMsg{
		subject: "orders.eu", data: []byte(fmt.Sprint(seq)),
		meta: &jetstream.MsgMetadata{Stream: "ORDERS", Sequence: jetstream.SequencePair{Stream: seq}, NumDelivered: delivered,
			Timestamp: time.Date(2026, 10, 21, 10, 0, 0, 0, time.UTC)},
	}
}

func TestDeliverAcksOnlySettled(t *testing.T) {
	s := &Source{cfg: Config{Subject: "orders.>", Stream: "ORDERS", Durable: "d"}, ackWait: 300 * time.Millisecond}

	ok, dead, interrupted := jsMsg(7, 1), jsMsg(8, 3), jsMsg(9, 1)
	var gotPrior []int
	var gotEvent source.Event
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	emit := func(ctx context.Context, ev source.Event) error {
		gotPrior = append(gotPrior, source.PriorAttempts(ctx))
		switch ev.ID {
		case "ORDERS/7":
			gotEvent = ev
			time.Sleep(250 * time.Millisecond) // longer than ack_wait/3: in-progress is sent
			return nil
		case "ORDERS/8":
			return fmt.Errorf("%w after 6 attempts", source.ErrDeadLettered)
		}
		cancel()
		return ctx.Err()
	}
	s.deliver(ctx, emit, ok)
	s.deliver(ctx, emit, dead)
	s.deliver(ctx, emit, interrupted)

	if ok.acks.Load() != 1 || dead.acks.Load() != 1 || interrupted.acks.Load() != 0 || interrupted.naks.Load() != 0 {
		t.Fatalf("acks ok=%d dead=%d interrupted=%d (naks %d), want the settled ones acked and the interrupted one left alone",
			ok.acks.Load(), dead.acks.Load(), interrupted.acks.Load(), interrupted.naks.Load())
	}
	if ok.inProgress.Load() == 0 {
		t.Error("no in-progress signal during a delivery longer than ack_wait/3")
	}
	if fmt.Sprint(gotPrior) != "[0 2 0]" {
		t.Errorf("prior attempts %v, want NumDelivered-1", gotPrior)
	}
	if gotEvent.Source != "/nats/orders.eu" || !gotEvent.Time.Equal(time.Date(2026, 10, 21, 10, 0, 0, 0, time.UTC)) {
		t.Errorf("event %+v", gotEvent)
	}
}

type fakeBatch struct {
	msgs chan jetstream.Msg
	err  error
}

func (b *fakeBatch) Messages() <-chan jetstream.Msg { return b.msgs }
func (b *fakeBatch) Error() error                   { return b.err }

// fakeConsumer hands out its queued messages, at most batch per fetch, and records the sizes
// asked for.
type fakeConsumer struct {
	mu     sync.Mutex
	queue  []*fakeMsg
	asked  []int
	closed bool
}

func (c *fakeConsumer) Fetch(batch int, opts ...jetstream.FetchOpt) (jetstream.MessageBatch, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.asked = append(c.asked, batch)
	n := min(batch, len(c.queue))
	ch := make(chan jetstream.Msg, n)
	for _, m := range c.queue[:n] {
		ch <- m
	}
	c.queue = c.queue[n:]
	close(ch)
	if n == 0 {
		time.Sleep(10 * time.Millisecond) // an empty fetch waits
	}
	return &fakeBatch{msgs: ch}, nil
}

func TestJetStreamWindow(t *testing.T) {
	var msgs []*fakeMsg
	cons := &fakeConsumer{}
	for i := range 10 {
		m := jsMsg(uint64(i+1), 1)
		msgs = append(msgs, m)
		cons.queue = append(cons.queue, m)
	}
	s := &Source{cfg: Config{Subject: "a", Stream: "S", Durable: "d"}, ackWait: time.Minute, fetchWait: 50 * time.Millisecond}
	ctx, cancel := context.WithCancel(source.WithConcurrency(context.Background(), 2))
	defer cancel()

	var active, peak, delivered atomic.Int64
	release := make(chan struct{})
	emit := func(context.Context, source.Event) error {
		n := active.Add(1)
		defer active.Add(-1)
		for p := peak.Load(); n > p && !peak.CompareAndSwap(p, n); p = peak.Load() {
		}
		<-release
		delivered.Add(1)
		return nil
	}
	done := make(chan error, 1)
	go func() { done <- s.runJetStream(ctx, cons, emit) }()
	time.Sleep(100 * time.Millisecond)
	if p := peak.Load(); p != 4 {
		t.Errorf("%d deliveries in flight, want the window 2×concurrency = 4", p)
	}
	close(release)
	deadline := time.Now().Add(5 * time.Second)
	for delivered.Load() < 10 && time.Now().Before(deadline) {
		time.Sleep(5 * time.Millisecond)
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatalf("runJetStream = %v", err)
	}
	for _, m := range msgs {
		if m.acks.Load() != 1 {
			t.Fatalf("message %s acked %d times", m.data, m.acks.Load())
		}
	}
	cons.mu.Lock()
	defer cons.mu.Unlock()
	if cons.asked[0] != 4 {
		t.Errorf("first fetch asked for %d, want the window (4)", cons.asked[0])
	}
}

type fakeSub struct{ msgs chan *natsgo.Msg }

func (f *fakeSub) NextMsgWithContext(ctx context.Context) (*natsgo.Msg, error) {
	select {
	case m := <-f.msgs:
		return m, nil
	case <-ctx.Done():
		return nil, ctx.Err()
	}
}

func TestCoreIDs(t *testing.T) {
	sub := &fakeSub{msgs: make(chan *natsgo.Msg, 2)}
	sub.msgs <- &natsgo.Msg{Subject: "a.b", Data: []byte("1"), Header: natsgo.Header{natsgo.MsgIdHdr: {"order-7"}}}
	sub.msgs <- &natsgo.Msg{Subject: "a.c", Data: []byte("2")}
	s := &Source{cfg: Config{Subject: "a.>"}}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	var ids []string
	err := s.consumeCore(ctx, sub, func(_ context.Context, ev source.Event) error {
		ids = append(ids, ev.ID)
		if len(ids) == 2 {
			cancel()
		}
		return nil
	})
	if err != nil {
		t.Fatalf("consumeCore = %v", err)
	}
	if len(ids) != 2 || ids[0] != "order-7" || ids[1] == "" || ids[1] == ids[0] {
		t.Fatalf("ids %v, want the Nats-Msg-Id header, then a generated id", ids)
	}
}

// ── Integration: a NATS server with JetStream, QC_TEST_NATS=nats://host:4222 go test ./sources/nats ──

func TestNATSServer(t *testing.T) {
	url := os.Getenv("QC_TEST_NATS")
	if url == "" {
		t.Skip("QC_TEST_NATS not set")
	}
	nc, err := natsgo.Connect(url)
	if err != nil {
		t.Fatal(err)
	}
	defer nc.Close()
	js, _ := jetstream.New(nc)
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	name := fmt.Sprintf("QC_TEST_%d", time.Now().UnixNano())
	subject := strings.ToLower(name) + ".>"
	if _, err := js.CreateStream(ctx, jetstream.StreamConfig{Name: name, Subjects: []string{subject}}); err != nil {
		t.Fatal(err)
	}
	defer js.DeleteStream(context.Background(), name)
	for i := range 3 {
		m := natsgo.NewMsg(strings.ToLower(name) + ".orders")
		m.Data = []byte(fmt.Sprint(i))
		m.Header.Set("Content-Type", "text/plain")
		if _, err := js.PublishMsg(ctx, m); err != nil {
			t.Fatal(err)
		}
	}

	raw, _ := json.Marshal(map[string]any{"url": url, "subject": subject, "stream": name, "durable": "runr-test", "deliver": "all"})
	s, err := New(raw)
	if err != nil {
		t.Fatal(err)
	}
	var mu sync.Mutex
	got := map[string]source.Event{}
	runCtx, stop := context.WithCancel(ctx)
	done := make(chan error, 1)
	go func() {
		done <- s.Run(runCtx, func(_ context.Context, ev source.Event) error {
			mu.Lock()
			got[ev.ID] = ev
			mu.Unlock()
			return nil
		})
	}()
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
	if ev, ok := got[name+"/1"]; len(got) != 3 || !ok || string(ev.Data) != "0" || ev.DataContentType != "text/plain" {
		t.Fatalf("events %+v", got)
	}
	cons, err := js.Consumer(ctx, name, "runr-test")
	if err != nil {
		t.Fatal(err)
	}
	info, err := cons.Info(ctx)
	if err != nil || info.NumAckPending != 0 || info.NumPending != 0 {
		t.Fatalf("consumer info %+v (%v), want every message acknowledged", info, err)
	}
}
