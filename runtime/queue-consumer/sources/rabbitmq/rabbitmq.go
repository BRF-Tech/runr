// Package rabbitmq is the gateway source of kind "rabbitmq": it consumes a RabbitMQ (AMQP 0-9-1)
// queue with manual acknowledgements.
//
// Configuration (the trigger's "source" object):
//
//	{"url": "amqps://user:pass@rabbit:5671/vhost", "queue": "orders", "declare": false,
//	 "bindings": [{"exchange": "shop", "routing_key": "order.created"}], "prefetch": 0,
//	 "tls": {"enabled": true, "ca_pem": "...", "insecure_skip_verify": false}}
//
// url (amqp:// or amqps://; it carries the credentials and is never logged) and queue are
// required. declare true declares the queue (durable) when it does not exist and binds it to the
// exchanges of bindings, which need declare. prefetch is how many unacknowledged messages the
// broker hands this consumer at once, default 2×concurrency: it is the in-flight window. tls
// configures amqps:// connections.
//
// Each message is the event
//
//	id              the message-id property, else <queue>/<delivery tag>/<receipt unix nanos>
//	                (only a message-id is the same on every redelivery)
//	type            dev.runr.rabbitmq.message
//	source          /rabbitmq/<queue>
//	subject         the routing key
//	time            the timestamp property (unset: delivery time)
//	datacontenttype the content-type property, else application/octet-stream
//	data            the body, as received
//	extensions      rabbitexchange (when published to a named exchange), rabbitheaders (the
//	                headers table as JSON; left out above 8 KiB)
//
// Delivery guarantees:
//   - A message is acknowledged only when the gateway settled it. A message in delivery at
//     shutdown is rejected with requeue, so the broker delivers it again (at-least-once).
//   - When the channel or connection closes, the deliveries in flight are abandoned (the broker
//     has requeued them) and Run returns the error; the gateway reconnects with backoff.
//   - Messages are delivered in parallel within the prefetch window, so their order is not kept.
//   - The x-delivery-count header of quorum queues is passed on as the prior attempts; without
//     it a redelivered message counts one.
package rabbitmq

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"strings"
	"sync"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"

	"github.com/brf-tech/runr/runtime/queue-consumer/internal/conf"
	"github.com/brf-tech/runr/runtime/queue-consumer/source"
	"github.com/brf-tech/runr/runtime/queue-consumer/sources/internal/extjson"
	"github.com/brf-tech/runr/runtime/queue-consumer/sources/internal/tlsconf"
)

func init() { source.Register("rabbitmq", New) }

// Config is the "source" object of a rabbitmq trigger.
type Config struct {
	URL      string          `json:"url"`
	Queue    string          `json:"queue"`
	Declare  bool            `json:"declare"`
	Bindings []Binding       `json:"bindings"` // only with declare
	Prefetch int             `json:"prefetch"` // default 2×concurrency
	TLS      *tlsconf.Config `json:"tls"`
}

// Binding binds the declared queue to an exchange.
type Binding struct {
	Exchange   string `json:"exchange"`
	RoutingKey string `json:"routing_key"`
}

// Source consumes one queue.
type Source struct {
	cfg  Config
	host string // host:port/vhost, for logs
	amqp amqp.Config
}

// New builds a rabbitmq source from its configuration.
func New(raw json.RawMessage) (source.Source, error) {
	var c Config
	if err := json.Unmarshal(raw, &c); err != nil {
		return nil, err
	}
	c.URL = strings.TrimSpace(c.URL)
	if c.URL == "" {
		return nil, errors.New("url is required")
	}
	uri, err := amqp.ParseURI(c.URL)
	if err != nil {
		return nil, errors.New("url is not an amqp:// or amqps:// URL") // the parser's error may quote it
	}
	if c.Queue == "" {
		return nil, errors.New("queue is required")
	}
	if len(c.Bindings) > 0 && !c.Declare {
		return nil, errors.New("bindings need declare: true")
	}
	for i, b := range c.Bindings {
		if b.Exchange == "" {
			return nil, fmt.Errorf("bindings[%d].exchange is required", i)
		}
	}
	if c.Prefetch < 0 {
		return nil, errors.New("prefetch must not be negative")
	}
	if c.TLS.On() && uri.Scheme != "amqps" {
		return nil, errors.New("tls needs an amqps:// url")
	}
	tc, err := c.TLS.Build("")
	if err != nil {
		return nil, err
	}
	return &Source{
		cfg:  c,
		host: fmt.Sprintf("%s:%d/%s", uri.Host, uri.Port, strings.TrimPrefix(uri.Vhost, "/")),
		amqp: amqp.Config{
			TLSClientConfig: tc,
			Heartbeat:       10 * time.Second,
			Dial:            amqp.DefaultDial(10 * time.Second),
		},
	}, nil
}

// Name is "rabbitmq <queue>@<host>:<port>/<vhost>" (no credentials).
func (s *Source) Name() string { return "rabbitmq " + s.cfg.Queue + "@" + s.host }

// Run connects, consumes the queue until ctx ends and returns nil then; a closed channel or
// connection is returned as an error, after the deliveries in flight returned.
func (s *Source) Run(ctx context.Context, emit func(context.Context, source.Event) error) error {
	trigger := source.TriggerName(ctx)
	cfg := s.amqp
	cfg.Properties = amqp.NewConnectionProperties()
	cfg.Properties.SetClientConnectionName("runr-gateway " + trigger)
	conn, err := amqp.DialConfig(s.cfg.URL, cfg)
	if err != nil {
		return fmt.Errorf("connect: %w", err)
	}
	defer conn.Close()

	if s.cfg.Declare {
		if err := s.declare(conn); err != nil {
			return err
		}
	}
	ch, err := conn.Channel()
	if err != nil {
		return fmt.Errorf("channel: %w", err)
	}
	defer ch.Close()
	closed := ch.NotifyClose(make(chan *amqp.Error, 1))

	prefetch := s.cfg.Prefetch
	if prefetch <= 0 {
		prefetch = 2 * source.Concurrency(ctx)
	}
	if err := ch.Qos(prefetch, 0, false); err != nil {
		return fmt.Errorf("qos: %w", err)
	}
	tag := fmt.Sprintf("runr-%s-%s", trigger, conf.Hostname())
	deliveries, err := ch.Consume(s.cfg.Queue, tag, false, false, false, false, nil)
	if err != nil {
		return fmt.Errorf("consume %s: %w", s.cfg.Queue, err)
	}
	reason := func() error {
		select {
		case e, ok := <-closed:
			if ok && e != nil {
				return e
			}
		default:
		}
		return errors.New("channel closed")
	}
	return s.consume(ctx, deliveries, func() { ch.Cancel(tag, false) }, reason, emit)
}

// declare makes sure the queue exists (declaring it durable when it does not) and binds it. An
// existing queue is left as it is, so its type and arguments do not have to match.
func (s *Source) declare(conn *amqp.Connection) error {
	ch, err := conn.Channel()
	if err != nil {
		return fmt.Errorf("channel: %w", err)
	}
	defer func() { ch.Close() }()
	if _, err := ch.QueueDeclarePassive(s.cfg.Queue, true, false, false, false, nil); err != nil {
		var aerr *amqp.Error
		if !errors.As(err, &aerr) || aerr.Code != amqp.NotFound {
			return fmt.Errorf("queue %s: %w", s.cfg.Queue, err)
		}
		// The failed passive declare closed the channel.
		if ch, err = conn.Channel(); err != nil {
			return fmt.Errorf("channel: %w", err)
		}
		if _, err := ch.QueueDeclare(s.cfg.Queue, true, false, false, false, nil); err != nil {
			return fmt.Errorf("declare queue %s: %w", s.cfg.Queue, err)
		}
		log.Printf("%s: declared the queue", s.Name())
	}
	for _, b := range s.cfg.Bindings {
		if err := ch.QueueBind(s.cfg.Queue, b.RoutingKey, b.Exchange, false, nil); err != nil {
			return fmt.Errorf("bind %s to exchange %s with key %q: %w", s.cfg.Queue, b.Exchange, b.RoutingKey, err)
		}
	}
	return nil
}

// consume delivers every message of deliveries in its own goroutine (the broker's prefetch
// bounds how many are in flight). On ctx end it cancels the consumer and waits for the
// deliveries in flight; when deliveries closes (the channel is gone) it abandons them and
// returns reason().
func (s *Source) consume(ctx context.Context, deliveries <-chan amqp.Delivery, cancelConsumer func(), reason func() error,
	emit func(context.Context, source.Event) error) error {
	dctx, abandon := context.WithCancel(ctx)
	defer abandon()
	var wg sync.WaitGroup
	for {
		select {
		case <-ctx.Done():
			cancelConsumer()
			wg.Wait() // their emits end with ctx's error: rejected with requeue
			return nil
		case d, ok := <-deliveries:
			if !ok {
				// Nothing can be acknowledged on a closed channel and the broker requeued the
				// unacknowledged messages: stop delivering them.
				abandon()
				wg.Wait()
				if ctx.Err() != nil {
					return nil
				}
				return reason()
			}
			received := time.Now()
			wg.Add(1)
			go func() {
				defer wg.Done()
				s.deliver(dctx, emit, d, received)
			}()
		}
	}
}

// deliver hands one message to emit, acknowledges it when it is settled and rejects it with
// requeue otherwise.
func (s *Source) deliver(ctx context.Context, emit func(context.Context, source.Event) error, d amqp.Delivery, received time.Time) {
	ev := s.event(d, received)
	if source.Settled(emit(source.WithPriorAttempts(ctx, priorAttempts(d)), ev)) {
		if err := d.Ack(false); err != nil {
			log.Printf("%s: ack of %s failed (delivered again): %v", s.Name(), ev.ID, err)
		}
		return
	}
	d.Nack(false, true) // on a closed channel this fails, and the broker requeued it already
}

// event maps a delivery to the event to deliver.
func (s *Source) event(d amqp.Delivery, received time.Time) source.Event {
	ev := source.Event{
		ID:              d.MessageId,
		Type:            "dev.runr.rabbitmq.message",
		Source:          "/rabbitmq/" + s.cfg.Queue,
		Subject:         d.RoutingKey,
		DataContentType: d.ContentType,
		Time:            d.Timestamp,
		Data:            d.Body,
	}
	if ev.ID == "" {
		ev.ID = fmt.Sprintf("%s/%d/%d", s.cfg.Queue, d.DeliveryTag, received.UnixNano())
	}
	if ev.DataContentType == "" {
		ev.DataContentType = "application/octet-stream"
	}
	ext := map[string]string{}
	if d.Exchange != "" {
		ext["rabbitexchange"] = d.Exchange
	}
	if len(d.Headers) > 0 {
		if js := extjson.Encode(d.Headers); js != "" {
			ext["rabbitheaders"] = js
		}
	}
	if len(ext) > 0 {
		ev.Extensions = ext
	}
	return ev
}

// priorAttempts is the x-delivery-count header (quorum queues), else 1 for a redelivered
// message and 0 for a new one.
func priorAttempts(d amqp.Delivery) int {
	if n := toInt(d.Headers["x-delivery-count"]); n > 0 {
		return n
	}
	if d.Redelivered {
		return 1
	}
	return 0
}

func toInt(v any) int {
	switch n := v.(type) {
	case int:
		return n
	case int8:
		return int(n)
	case int16:
		return int(n)
	case int32:
		return int(n)
	case int64:
		return int(n)
	case uint8:
		return int(n)
	case uint16:
		return int(n)
	case uint32:
		return int(n)
	case uint64:
		return int(min(n, 1<<31))
	}
	return 0
}
