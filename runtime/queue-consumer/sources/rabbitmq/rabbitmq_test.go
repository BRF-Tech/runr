package rabbitmq

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

	amqp "github.com/rabbitmq/amqp091-go"

	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

func TestConfig(t *testing.T) {
	for name, tc := range map[string]struct{ cfg, wantErr string }{
		"no url":            {`{"queue": "q"}`, "url"},
		"bad url":           {`{"url": "http://u:secret@h/", "queue": "q"}`, "amqp"},
		"no queue":          {`{"url": "amqp://h"}`, "queue"},
		"bindings no decl":  {`{"url": "amqp://h", "queue": "q", "bindings": [{"exchange": "x"}]}`, "declare"},
		"binding exchange":  {`{"url": "amqp://h", "queue": "q", "declare": true, "bindings": [{"routing_key": "k"}]}`, "bindings[0].exchange"},
		"negative prefetch": {`{"url": "amqp://h", "queue": "q", "prefetch": -1}`, "prefetch"},
		"tls without amqps": {`{"url": "amqp://h", "queue": "q", "tls": {"enabled": true}}`, "amqps"},
	} {
		t.Run(name, func(t *testing.T) {
			_, err := New(json.RawMessage(tc.cfg))
			if err == nil || !strings.Contains(err.Error(), tc.wantErr) {
				t.Fatalf("New(%s) = %v, want an error naming %q", tc.cfg, err, tc.wantErr)
			}
			if err != nil && strings.Contains(err.Error(), "secret") {
				t.Errorf("error %q leaks the URL's password", err)
			}
		})
	}
	s, err := New(json.RawMessage(`{"url": "amqps://user:hunter2@rabbit.example:5671/shop", "queue": "orders", "tls": {"enabled": true}}`))
	if err != nil {
		t.Fatal(err)
	}
	src := s.(*Source)
	if src.Name() != "rabbitmq orders@rabbit.example:5671/shop" || src.amqp.TLSClientConfig == nil {
		t.Errorf("Name %q tls %v", src.Name(), src.amqp.TLSClientConfig)
	}
}

func TestEventAndPriorAttempts(t *testing.T) {
	s := &Source{cfg: Config{Queue: "orders"}}
	at := time.Date(2026, 10, 21, 10, 0, 0, 0, time.UTC)
	d := amqp.Delivery{MessageId: "m-1", RoutingKey: "order.created", Exchange: "shop", ContentType: "application/json",
		Timestamp: at, Body: []byte(`{"n":1}`), DeliveryTag: 9, Headers: amqp.Table{"x-delivery-count": int64(3), "tenant": "acme"}}
	ev := s.event(d, time.Now())
	if ev.ID != "m-1" || ev.Type != "dev.runr.rabbitmq.message" || ev.Source != "/rabbitmq/orders" || ev.Subject != "order.created" ||
		ev.DataContentType != "application/json" || !ev.Time.Equal(at) || string(ev.Data) != `{"n":1}` || ev.Extensions["rabbitexchange"] != "shop" {
		t.Errorf("event %+v", ev)
	}
	var h map[string]any
	if err := json.Unmarshal([]byte(ev.Extensions["rabbitheaders"]), &h); err != nil || h["tenant"] != "acme" {
		t.Errorf("rabbitheaders %q", ev.Extensions["rabbitheaders"])
	}
	if n := priorAttempts(d); n != 3 {
		t.Errorf("prior attempts %d, want x-delivery-count", n)
	}

	received := time.Unix(0, 1700000000123456789)
	ev = s.event(amqp.Delivery{DeliveryTag: 4, Body: []byte{1}}, received)
	if ev.ID != "orders/4/1700000000123456789" || ev.DataContentType != "application/octet-stream" || ev.Extensions != nil || !ev.Time.IsZero() {
		t.Errorf("event without properties %+v", ev)
	}
	if priorAttempts(amqp.Delivery{Redelivered: true}) != 1 || priorAttempts(amqp.Delivery{}) != 0 {
		t.Error("redelivered flag must count one prior attempt, a new message none")
	}
}

// acker records the acknowledgements of the deliveries it is the Acknowledger of.
type acker struct {
	mu    sync.Mutex
	acks  []uint64
	nacks []uint64
}

func (a *acker) Ack(tag uint64, multiple bool) error {
	a.mu.Lock()
	defer a.mu.Unlock()
	a.acks = append(a.acks, tag)
	return nil
}

func (a *acker) Nack(tag uint64, multiple, requeue bool) error {
	a.mu.Lock()
	defer a.mu.Unlock()
	if requeue {
		a.nacks = append(a.nacks, tag)
	}
	return nil
}

func (a *acker) Reject(tag uint64, requeue bool) error { return a.Nack(tag, false, requeue) }

func (a *acker) state() (string, string) {
	a.mu.Lock()
	defer a.mu.Unlock()
	return fmt.Sprint(a.acks), fmt.Sprint(a.nacks)
}

func TestAckOnlySettledNackOnShutdown(t *testing.T) {
	a := &acker{}
	deliveries := make(chan amqp.Delivery, 3)
	for tag := uint64(1); tag <= 3; tag++ {
		deliveries <- amqp.Delivery{Acknowledger: a, DeliveryTag: tag, MessageId: fmt.Sprint(tag)}
	}
	s := &Source{cfg: Config{Queue: "q"}}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	var settled atomic.Int64
	var cancelled atomic.Bool
	emit := func(ctx context.Context, ev source.Event) error {
		switch ev.ID {
		case "1":
			settled.Add(1)
			return nil
		case "2":
			settled.Add(1)
			return fmt.Errorf("%w after 6 attempts", source.ErrDeadLettered)
		}
		for settled.Load() < 2 {
			time.Sleep(time.Millisecond)
		}
		cancel() // shutdown while the third is in delivery
		<-ctx.Done()
		return ctx.Err()
	}
	err := s.consume(ctx, deliveries, func() { cancelled.Store(true) }, func() error { return nil }, emit)
	if err != nil {
		t.Fatalf("consume = %v", err)
	}
	acks, nacks := a.state()
	if acks != "[1 2]" && acks != "[2 1]" {
		t.Errorf("acks %s, want the delivered and the dead-lettered message", acks)
	}
	if nacks != "[3]" || !cancelled.Load() {
		t.Errorf("nacks %s consumer cancelled %v, want the interrupted message requeued and the consumer cancelled", nacks, cancelled.Load())
	}
}

func TestClosedChannelAbandonsDeliveries(t *testing.T) {
	a := &acker{}
	deliveries := make(chan amqp.Delivery, 1)
	deliveries <- amqp.Delivery{Acknowledger: a, DeliveryTag: 1}
	s := &Source{cfg: Config{Queue: "q"}}

	started := make(chan struct{})
	emit := func(ctx context.Context, _ source.Event) error {
		close(started)
		<-ctx.Done() // a delivery with long retries, abandoned when the channel closes
		return ctx.Err()
	}
	go func() {
		<-started
		close(deliveries) // the connection dropped
	}()
	closeErr := &amqp.Error{Code: 320, Reason: "CONNECTION_FORCED"}
	err := s.consume(context.Background(), deliveries, func() {}, func() error { return closeErr }, emit)
	if !errors.Is(err, closeErr) {
		t.Fatalf("consume = %v, want the close reason", err)
	}
	if acks, _ := a.state(); acks != "[]" {
		t.Errorf("acks %s on a closed channel", acks)
	}
}

// ── Integration: a RabbitMQ broker with QC_TEST_AMQP=amqp://guest:guest@host:5672/ go test ./sources/rabbitmq ──

func TestRabbitMQBroker(t *testing.T) {
	url := os.Getenv("QC_TEST_AMQP")
	if url == "" {
		t.Skip("QC_TEST_AMQP not set")
	}
	queue := fmt.Sprintf("qc-test-%d", time.Now().UnixNano())
	raw, _ := json.Marshal(map[string]any{"url": url, "queue": queue, "declare": true,
		"bindings": []map[string]string{{"exchange": "amq.direct", "routing_key": queue}}})
	s, err := New(raw)
	if err != nil {
		t.Fatal(err)
	}
	conn, err := amqp.Dial(url)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	ch, err := conn.Channel()
	if err != nil {
		t.Fatal(err)
	}
	defer ch.QueueDelete(queue, false, false, false)

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
	// Publish once the source declared and bound the queue.
	for ctx.Err() == nil {
		if _, err := ch.QueueDeclarePassive(queue, true, false, false, false, nil); err == nil {
			break
		}
		ch, _ = conn.Channel()
		time.Sleep(50 * time.Millisecond)
	}
	time.Sleep(200 * time.Millisecond) // the binding follows the declaration
	for i := range 3 {
		if err := ch.PublishWithContext(ctx, "amq.direct", queue, false, false, amqp.Publishing{
			MessageId: fmt.Sprint("m-", i), ContentType: "text/plain", Body: []byte(fmt.Sprint(i)),
		}); err != nil {
			t.Fatal(err)
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
	if len(got) != 3 || got[0].Subject != queue || got[0].DataContentType != "text/plain" || got[0].Extensions["rabbitexchange"] != "amq.direct" {
		t.Fatalf("events %+v", got)
	}
	q, err := ch.QueueDeclarePassive(queue, true, false, false, false, nil)
	if err != nil || q.Messages != 0 {
		t.Fatalf("queue %+v (%v), want every message acknowledged", q, err)
	}
}
