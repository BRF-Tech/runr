package kafka

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	kafkago "github.com/segmentio/kafka-go"

	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

func TestConfig(t *testing.T) {
	for name, tc := range map[string]struct{ cfg, wantErr string }{
		"no brokers":     {`{"topic": "t", "group_id": "g"}`, "brokers"},
		"blank brokers":  {`{"brokers": [" "], "topic": "t", "group_id": "g"}`, "brokers"},
		"no topic":       {`{"brokers": ["k:9092"], "group_id": "g"}`, "topic"},
		"no group":       {`{"brokers": ["k:9092"], "topic": "t"}`, "group_id"},
		"start_from":     {`{"brokers": ["k:9092"], "topic": "t", "group_id": "g", "start_from": "middle"}`, "start_from"},
		"sasl mechanism": {`{"brokers": ["k:9092"], "topic": "t", "group_id": "g", "sasl": {"mechanism": "gssapi", "username": "u", "password": "p"}}`, "sasl.mechanism"},
		"sasl password":  {`{"brokers": ["k:9092"], "topic": "t", "group_id": "g", "sasl": {"mechanism": "plain", "username": "u"}}`, "sasl.username and sasl.password"},
		"tls ca":         {`{"brokers": ["k:9092"], "topic": "t", "group_id": "g", "tls": {"enabled": true, "ca_pem": "nope"}}`, "ca_pem"},
		"max_wait":       {`{"brokers": ["k:9092"], "topic": "t", "group_id": "g", "max_wait": "soon"}`, "duration"},
	} {
		t.Run(name, func(t *testing.T) {
			_, err := New(json.RawMessage(tc.cfg))
			if err == nil || !strings.Contains(err.Error(), tc.wantErr) {
				t.Fatalf("New(%s) = %v, want an error naming %q", tc.cfg, err, tc.wantErr)
			}
		})
	}

	s, err := New(json.RawMessage(`{"brokers": ["k:9092"], "topic": "orders", "group_id": "runr.p.t",
		"sasl": {"mechanism": "scram-sha-512", "username": "u", "password": "hunter2"}, "tls": {"enabled": true}}`))
	if err != nil {
		t.Fatal(err)
	}
	src := s.(*Source)
	rc := src.readerCfg
	if rc.StartOffset != kafkago.LastOffset || rc.MinBytes != 1 || rc.MaxWait != time.Second || rc.CommitInterval != 0 ||
		rc.Dialer.TLS == nil || rc.Dialer.SASLMechanism == nil || rc.Dialer.SASLMechanism.Name() != "SCRAM-SHA-512" {
		t.Errorf("reader config %+v dialer %+v", rc, rc.Dialer)
	}
	if strings.Contains(src.Name(), "hunter2") || src.Name() != "kafka orders@runr.p.t" {
		t.Errorf("Name %q", src.Name())
	}

	s, err = New(json.RawMessage(`{"brokers": ["k:9092"], "topic": "t", "group_id": "g", "start_from": "earliest", "min_bytes": 1024, "max_wait": "250ms"}`))
	if err != nil {
		t.Fatal(err)
	}
	rc = s.(*Source).readerCfg
	if rc.StartOffset != kafkago.FirstOffset || rc.MinBytes != 1024 || rc.MaxWait != 250*time.Millisecond || rc.Dialer.TLS != nil {
		t.Errorf("reader config %+v", rc)
	}
}

func TestEvent(t *testing.T) {
	s := &Source{}
	at := time.Date(2026, 10, 21, 10, 0, 0, 0, time.UTC)
	ev := s.event(kafkago.Message{
		Topic: "orders", Partition: 3, Offset: 42, Key: []byte("order-7"), Value: []byte(`{"total":5}`), Time: at,
		Headers: []kafkago.Header{{Key: "Content-Type", Value: []byte("application/json")}, {Key: "trace", Value: []byte("a")}, {Key: "trace", Value: []byte("b")}},
	})
	if ev.ID != "orders/3/42" || ev.Type != "dev.runr.kafka.message" || ev.Source != "/kafka/orders" || ev.Subject != "order-7" ||
		!ev.Time.Equal(at) || ev.DataContentType != "application/json" || string(ev.Data) != `{"total":5}` {
		t.Errorf("event %+v", ev)
	}
	if ev.Extensions["kafkapartition"] != "3" || ev.Extensions["kafkaoffset"] != "42" || ev.Extensions["kafkakey"] != "order-7" {
		t.Errorf("extensions %v", ev.Extensions)
	}
	var headers map[string]string
	if err := json.Unmarshal([]byte(ev.Extensions["kafkaheaders"]), &headers); err != nil || headers["trace"] != "a, b" || headers["Content-Type"] != "application/json" {
		t.Errorf("kafkaheaders %q (%v)", ev.Extensions["kafkaheaders"], err)
	}

	// A binary key is neither the subject nor an extension; no content-type header means
	// application/octet-stream; huge headers are left out.
	ev = s.event(kafkago.Message{Topic: "t", Key: []byte{0xff, 0xfe}, Value: []byte{1, 2},
		Headers: []kafkago.Header{{Key: "big", Value: []byte(strings.Repeat("x", 9000))}}})
	if ev.Subject != "" || ev.Extensions["kafkakey"] != "" || ev.DataContentType != "application/octet-stream" || ev.Extensions["kafkaheaders"] != "" {
		t.Errorf("binary key event %+v", ev)
	}
}

// fakeReader hands out its messages, then blocks until ctx ends; it records commits.
type fakeReader struct {
	mu        sync.Mutex
	msgs      []kafkago.Message
	committed []int64
	closed    bool
	fetchErr  error
}

func (r *fakeReader) FetchMessage(ctx context.Context) (kafkago.Message, error) {
	r.mu.Lock()
	if r.fetchErr != nil {
		err := r.fetchErr
		r.mu.Unlock()
		return kafkago.Message{}, err
	}
	if len(r.msgs) > 0 {
		m := r.msgs[0]
		r.msgs = r.msgs[1:]
		r.mu.Unlock()
		return m, nil
	}
	r.mu.Unlock()
	<-ctx.Done()
	return kafkago.Message{}, ctx.Err()
}

func (r *fakeReader) CommitMessages(_ context.Context, msgs ...kafkago.Message) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	for _, m := range msgs {
		r.committed = append(r.committed, m.Offset)
	}
	return nil
}

func (r *fakeReader) Close() error {
	r.mu.Lock()
	r.closed = true
	r.mu.Unlock()
	return nil
}

func (r *fakeReader) state() ([]int64, bool) {
	r.mu.Lock()
	defer r.mu.Unlock()
	return append([]int64(nil), r.committed...), r.closed
}

func messages(offsets ...int64) []kafkago.Message {
	out := make([]kafkago.Message, len(offsets))
	for i, o := range offsets {
		out[i] = kafkago.Message{Topic: "t", Partition: 0, Offset: o, Value: []byte(strconv.FormatInt(o, 10))}
	}
	return out
}

func testSource(readers ...*fakeReader) *Source {
	var next atomic.Int64
	return &Source{cfg: Config{Topic: "t", GroupID: "g"}, newReader: func() reader {
		return readers[int(next.Add(1)-1)%len(readers)]
	}}
}

func TestCommitsOnlySettledInOrder(t *testing.T) {
	r := &fakeReader{msgs: messages(0, 1, 2)}
	s := testSource(r)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	var order []string
	emit := func(ctx context.Context, ev source.Event) error {
		order = append(order, ev.ID)
		switch ev.ID {
		case "t/0/1":
			return fmt.Errorf("%w after 6 attempts: status 500", source.ErrDeadLettered)
		case "t/0/2":
			cancel() // shutdown during the third delivery
			return ctx.Err()
		}
		return nil
	}
	if err := s.Run(ctx, emit); err != nil {
		t.Fatalf("Run = %v", err)
	}
	committed, closed := r.state()
	if fmt.Sprint(committed) != "[0 1]" || !closed {
		t.Fatalf("committed %v closed %v, want the delivered and the dead-lettered offset, not the interrupted one", committed, closed)
	}
	if strings.Join(order, ",") != "t/0/0,t/0/1,t/0/2" {
		t.Errorf("delivery order %v", order)
	}
}

func TestOneMemberPerConcurrency(t *testing.T) {
	readers := []*fakeReader{{msgs: messages(0)}, {msgs: messages(10)}, {msgs: messages(20)}}
	s := testSource(readers...)
	ctx, cancel := context.WithCancel(source.WithConcurrency(context.Background(), 3))
	defer cancel()

	var delivered atomic.Int64
	done := make(chan error, 1)
	go func() {
		done <- s.Run(ctx, func(context.Context, source.Event) error { delivered.Add(1); return nil })
	}()
	deadline := time.Now().Add(5 * time.Second)
	for delivered.Load() < 3 && time.Now().Before(deadline) {
		time.Sleep(5 * time.Millisecond)
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatalf("Run = %v", err)
	}
	for i, r := range readers {
		committed, closed := r.state()
		if len(committed) != 1 || !closed {
			t.Errorf("reader %d committed %v closed %v", i, committed, closed)
		}
	}
}

func TestFetchErrorStopsTheOtherMembers(t *testing.T) {
	broken := &fakeReader{fetchErr: io.ErrUnexpectedEOF}
	idle := &fakeReader{}
	s := testSource(broken, idle)
	ctx := source.WithConcurrency(context.Background(), 2)

	err := s.Run(ctx, func(context.Context, source.Event) error { return nil })
	if !errors.Is(err, io.ErrUnexpectedEOF) {
		t.Fatalf("Run = %v, want the fetch error", err)
	}
	if _, closed := idle.state(); !closed {
		t.Error("the other member was not stopped and closed")
	}
}

// ── Integration: a real broker with QC_TEST_KAFKA=host:port go test ./sources/kafka ──────────

func TestKafkaBroker(t *testing.T) {
	addr := os.Getenv("QC_TEST_KAFKA")
	if addr == "" {
		t.Skip("QC_TEST_KAFKA not set")
	}
	topic := fmt.Sprintf("qc-test-%d", time.Now().UnixNano())
	createTopic(t, addr, topic)

	w := &kafkago.Writer{Addr: kafkago.TCP(addr), Topic: topic, Balancer: &kafkago.Hash{}}
	defer w.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()
	if err := w.WriteMessages(ctx,
		kafkago.Message{Key: []byte("k1"), Value: []byte(`{"n":1}`), Headers: []kafkago.Header{{Key: "content-type", Value: []byte("application/json")}}},
		kafkago.Message{Key: []byte("k1"), Value: []byte(`{"n":2}`)},
	); err != nil {
		t.Fatal(err)
	}

	raw, _ := json.Marshal(map[string]any{"brokers": []string{addr}, "topic": topic, "group_id": topic + "-g", "start_from": "earliest", "max_wait": "200ms"})
	s, err := New(raw)
	if err != nil {
		t.Fatal(err)
	}
	var mu sync.Mutex
	var got []source.Event
	runCtx, stop := context.WithCancel(ctx)
	done := make(chan error, 1)
	emit := func(_ context.Context, ev source.Event) error {
		mu.Lock()
		got = append(got, ev)
		mu.Unlock()
		return nil
	}
	// The gateway runs a source again after an error (gateway.runSource): a new broker answers
	// group requests with "coordinator not available" until its offsets topic exists.
	var lastErr atomic.Value
	go func() {
		for {
			err := s.Run(runCtx, emit)
			if runCtx.Err() != nil {
				done <- err
				return
			}
			lastErr.Store(fmt.Sprint(err))
			time.Sleep(time.Second)
		}
	}()
	for {
		mu.Lock()
		n := len(got)
		mu.Unlock()
		if n >= 2 || ctx.Err() != nil {
			break
		}
		time.Sleep(50 * time.Millisecond)
	}
	stop()
	if err := <-done; err != nil {
		t.Fatalf("Run = %v", err)
	}
	mu.Lock()
	defer mu.Unlock()
	if len(got) != 2 || string(got[0].Data) != `{"n":1}` || got[0].DataContentType != "application/json" || got[0].Subject != "k1" ||
		!strings.HasPrefix(got[0].ID, topic+"/") {
		t.Fatalf("events %+v (last error of a run: %v)", got, lastErr.Load())
	}

	// The group committed both offsets: a new run delivers nothing.
	s2, _ := New(raw)
	ctx2, cancel2 := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel2()
	var again atomic.Int64
	s2.Run(ctx2, func(context.Context, source.Event) error { again.Add(1); return nil })
	if again.Load() != 0 {
		t.Fatalf("%d messages delivered again after their commit", again.Load())
	}
}

func createTopic(t *testing.T, addr, topic string) {
	t.Helper()
	conn, err := kafkago.Dial("tcp", addr)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	ctrl, err := conn.Controller()
	if err != nil {
		t.Fatal(err)
	}
	ctrlAddr := net.JoinHostPort(ctrl.Host, strconv.Itoa(ctrl.Port))
	cc, err := kafkago.Dial("tcp", ctrlAddr)
	if err != nil {
		t.Fatal(err)
	}
	defer cc.Close()
	if err := cc.CreateTopics(kafkago.TopicConfig{Topic: topic, NumPartitions: 1, ReplicationFactor: 1}); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		if c, err := kafkago.Dial("tcp", ctrlAddr); err == nil {
			c.DeleteTopics(topic)
			c.Close()
		}
	})
}
