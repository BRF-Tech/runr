// Package mqtt is the gateway source of kind "mqtt": it subscribes to an MQTT 3.1.1 topic
// filter with a persistent session and manual acknowledgements.
//
// Configuration (the trigger's "source" object):
//
//	{"broker": "ssl://mqtt.example:8883", "topic": "sensors/+/temp", "qos": 1,
//	 "client_id": "runr-<project>-<trigger>", "clean_session": false, "username": "...",
//	 "password": "...", "tls": {"enabled": true, "ca_pem": "..."}, "shared_group": ""}
//
// broker (tcp://, mqtt://, ssl://, tls://, mqtts://, ws:// or wss://), topic and client_id are
// required. qos is 0, 1 (default) or 2. shared_group subscribes to $share/<group>/<topic>, so the
// gateway replicas share the messages (each replica needs its own client_id then). tls configures
// ssl://, tls://, mqtts:// and wss:// brokers.
//
// Each message is the event
//
//	id              <client_id>/<receipt unix nanos>/<packet id>   (MQTT reuses packet ids, so
//	                no id survives a redelivery: a redelivered message gets a new one)
//	type            dev.runr.mqtt.message
//	source          /mqtt/<topic of the message>
//	subject         the topic of the message
//	datacontenttype application/octet-stream (MQTT 3.1.1 carries no content type)
//	data            the payload, as received
//	extensions      mqttqos, mqttretained ("true"/"false"), mqttmessageid
//
// Delivery guarantees:
//   - QoS 1 and 2: a message is acknowledged (PUBACK / PUBREC) only when the gateway settled it.
//     With clean_session false the broker keeps the session while the gateway is away and
//     delivers the messages it holds (and the unacknowledged ones) on the next connection:
//     at-least-once. The client reconnects by itself after a lost connection; acknowledgements
//     of messages received on the lost connection are dropped and the broker redelivers them.
//     With clean_session true the broker forgets the session on disconnect: messages published
//     meanwhile, and unacknowledged ones, are lost.
//   - QoS 0 is at most once: a message is gone once it reached the gateway.
//   - Up to 2×concurrency messages are delivered at once, so their order is not kept; the
//     broker's in-flight limit for the session bounds how many unacknowledged QoS 1/2 messages
//     it sends. QoS 0 messages that arrive while far more than the window are waiting are
//     dropped (logged).
package mqtt

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	paho "github.com/eclipse/paho.mqtt.golang"

	"github.com/brf-tech/runr/runtime/queue-consumer/internal/wait"
	"github.com/brf-tech/runr/runtime/queue-consumer/source"
	"github.com/brf-tech/runr/runtime/queue-consumer/sources/internal/tlsconf"
)

func init() { source.Register("mqtt", New) }

// Config is the "source" object of an mqtt trigger.
type Config struct {
	Broker       string          `json:"broker"`
	Topic        string          `json:"topic"`
	QoS          *int            `json:"qos"` // default 1
	ClientID     string          `json:"client_id"`
	CleanSession bool            `json:"clean_session"`
	Username     string          `json:"username"`
	Password     string          `json:"password"`
	TLS          *tlsconf.Config `json:"tls"`
	SharedGroup  string          `json:"shared_group"`
}

// Source subscribes to one topic filter.
type Source struct {
	cfg    Config
	qos    byte
	filter string // topic, or $share/<group>/<topic>
	host   string // broker without userinfo, for logs
	tls    *tls.Config

	// newClient builds the client from the options; tests replace it.
	newClient func(*paho.ClientOptions) paho.Client
}

// New builds an mqtt source from its configuration.
func New(raw json.RawMessage) (source.Source, error) {
	var c Config
	if err := json.Unmarshal(raw, &c); err != nil {
		return nil, err
	}
	c.Broker = strings.TrimSpace(c.Broker)
	if c.Broker == "" {
		return nil, errors.New("broker is required")
	}
	u, err := url.Parse(c.Broker)
	if err != nil || u.Host == "" {
		return nil, errors.New("broker must be a URL like tcp://host:1883")
	}
	secure := false
	switch u.Scheme {
	case "tcp", "mqtt", "ws":
	case "ssl", "tls", "mqtts", "tcps", "mqtt+ssl", "wss":
		secure = true
	default:
		return nil, fmt.Errorf("broker scheme %q: want tcp, mqtt, ssl, tls, mqtts, ws or wss", u.Scheme)
	}
	if c.Topic == "" {
		return nil, errors.New("topic is required")
	}
	if c.ClientID == "" {
		return nil, errors.New("client_id is required")
	}
	qos := 1
	if c.QoS != nil {
		qos = *c.QoS
	}
	if qos < 0 || qos > 2 {
		return nil, fmt.Errorf("qos %d: want 0, 1 or 2", qos)
	}
	if c.TLS.On() && !secure {
		return nil, errors.New("tls needs an ssl://, tls://, mqtts:// or wss:// broker")
	}
	tc, err := c.TLS.Build("")
	if err != nil {
		return nil, err
	}

	s := &Source{
		cfg:       c,
		qos:       byte(qos),
		filter:    c.Topic,
		host:      u.Scheme + "://" + u.Host,
		tls:       tc,
		newClient: paho.NewClient,
	}
	if c.SharedGroup != "" {
		s.filter = "$share/" + c.SharedGroup + "/" + c.Topic
	}
	return s, nil
}

// options are the client options of one Run, without the handlers.
func (s *Source) options() *paho.ClientOptions {
	o := paho.NewClientOptions().
		AddBroker(s.cfg.Broker).
		SetClientID(s.cfg.ClientID).
		SetCleanSession(s.cfg.CleanSession).
		SetUsername(s.cfg.Username).
		SetPassword(s.cfg.Password).
		SetAutoAckDisabled(true).
		SetOrderMatters(false). // each message is handled in its own goroutine
		SetAutoReconnect(true).
		SetConnectRetry(false).
		SetMaxReconnectInterval(30 * time.Second).
		SetConnectTimeout(10 * time.Second).
		SetKeepAlive(30 * time.Second)
	if s.tls != nil {
		o.SetTLSConfig(s.tls)
	}
	return o
}

// Name is "mqtt <topic filter>@<broker>" (no credentials).
func (s *Source) Name() string { return "mqtt " + s.filter + "@" + s.host }

// Run connects, subscribes on every (re)connect and delivers messages until ctx ends. A failed
// first connect is returned as an error; later connection losses are recovered by the client.
func (s *Source) Run(ctx context.Context, emit func(context.Context, source.Event) error) error {
	h := newHandler(s, ctx, emit, 2*source.Concurrency(ctx))
	opts := s.options()
	opts.SetDefaultPublishHandler(h.handle) // session messages that arrive before the subscription
	opts.SetOnConnectHandler(func(c paho.Client) {
		if !h.enter() {
			return
		}
		go func() {
			defer h.leave()
			s.subscribe(ctx, c, h.handle)
		}()
	})
	opts.SetConnectionLostHandler(func(_ paho.Client, err error) {
		log.Printf("%s: connection lost, reconnecting: %v", s.Name(), err)
	})
	client := s.newClient(opts)

	tok := client.Connect()
	select {
	case <-tok.Done():
		if err := tok.Error(); err != nil {
			h.close()
			return fmt.Errorf("connect: %w", err)
		}
		<-ctx.Done()
	case <-ctx.Done():
	}
	// Stop taking messages, let the deliveries in flight end (unsettled ones stay
	// unacknowledged in the session) and leave without unsubscribing, so the broker keeps the
	// session and its messages.
	h.close()
	client.Disconnect(250)
	return nil
}

// subscribe subscribes on a new connection, retrying with backoff while the connection is open.
func (s *Source) subscribe(ctx context.Context, c paho.Client, handle paho.MessageHandler) {
	backoff := time.Second
	for {
		tok := c.Subscribe(s.filter, s.qos, handle)
		timeout := time.NewTimer(15 * time.Second)
		var err error
		select {
		case <-tok.Done():
			err = tok.Error()
		case <-timeout.C:
			err = errors.New("no answer in 15s")
		case <-ctx.Done():
			timeout.Stop()
			return
		}
		timeout.Stop()
		if err == nil {
			log.Printf("%s: subscribed (qos %d)", s.Name(), s.qos)
			return
		}
		if !c.IsConnectionOpen() {
			return // the next connection subscribes again
		}
		log.Printf("%s: subscribe failed, retry in %s: %v", s.Name(), backoff, err)
		if !wait.Sleep(ctx, backoff) {
			return
		}
		backoff = min(backoff*2, 30*time.Second)
	}
}

// handler runs the deliveries of one Run.
type handler struct {
	s       *Source
	ctx     context.Context
	emit    func(context.Context, source.Event) error
	slots   chan struct{} // the in-flight window
	waiting atomic.Int64  // handlers waiting for a slot
	maxWait int64         // QoS 0 messages beyond this many waiting are dropped

	mu     sync.Mutex
	closed bool
	wg     sync.WaitGroup // deliveries and subscriptions in progress
}

func newHandler(s *Source, ctx context.Context, emit func(context.Context, source.Event) error, window int) *handler {
	return &handler{s: s, ctx: ctx, emit: emit, slots: make(chan struct{}, max(window, 1)), maxWait: int64(64 * max(window, 1))}
}

// handle is the paho message handler. It runs in its own goroutine per message; it waits for a
// slot of the window, delivers the message and acknowledges it when it is settled.
func (h *handler) handle(_ paho.Client, msg paho.Message) {
	if !h.enter() {
		return // shutting down: not acknowledged, the session redelivers it
	}
	defer h.leave()

	received := time.Now()
	if msg.Qos() == 0 && h.waiting.Load() >= h.maxWait {
		log.Printf("%s: QoS 0 message on %s dropped, %d messages wait for delivery", h.s.Name(), msg.Topic(), h.waiting.Load())
		return
	}
	h.waiting.Add(1)
	select {
	case h.slots <- struct{}{}:
		h.waiting.Add(-1)
	case <-h.ctx.Done():
		h.waiting.Add(-1)
		return
	}
	defer func() { <-h.slots }()

	if source.Settled(h.emit(h.ctx, h.s.event(msg, received))) {
		msg.Ack() // PUBACK / PUBREC; nothing for QoS 0
	}
}

// enter registers a goroutine of this Run (a delivery or a subscription); it reports false once
// close began.
func (h *handler) enter() bool {
	h.mu.Lock()
	defer h.mu.Unlock()
	if h.closed {
		return false
	}
	h.wg.Add(1)
	return true
}

func (h *handler) leave() { h.wg.Done() }

// close stops new deliveries and waits for those in flight.
func (h *handler) close() {
	h.mu.Lock()
	h.closed = true
	h.mu.Unlock()
	h.wg.Wait()
}

// event maps a message to the event to deliver.
func (s *Source) event(msg paho.Message, received time.Time) source.Event {
	return source.Event{
		ID:              fmt.Sprintf("%s/%d/%d", s.cfg.ClientID, received.UnixNano(), msg.MessageID()),
		Type:            "dev.runr.mqtt.message",
		Source:          "/mqtt/" + msg.Topic(),
		Subject:         msg.Topic(),
		DataContentType: "application/octet-stream",
		Data:            msg.Payload(),
		Extensions: map[string]string{
			"mqttqos":       strconv.Itoa(int(msg.Qos())),
			"mqttretained":  strconv.FormatBool(msg.Retained()),
			"mqttmessageid": strconv.Itoa(int(msg.MessageID())),
		},
	}
}
