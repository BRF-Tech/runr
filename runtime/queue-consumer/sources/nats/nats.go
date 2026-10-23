// Package nats is the gateway source of kind "nats": it reads a NATS subject, through a
// JetStream durable pull consumer when a stream is given, else as a core NATS subscription.
//
// Configuration (the trigger's "source" object):
//
//	{"url": "nats://nats-0:4222,nats://nats-1:4222", "subject": "orders.>", "stream": "ORDERS",
//	 "durable": "runr-<project>-<trigger>", "deliver": "new", "queue_group": "", "ack_wait": "60s",
//	 "auth": {"user": "...", "password": "..."}, "tls": {"enabled": true, "ca_pem": "..."}}
//
// url (comma-separated servers) and subject are required. auth is one of {"user", "password"},
// {"token"}, {"nkey_seed"} or {"creds"} (the content of a .creds file), or null. With a stream,
// durable is required; deliver is where a new durable starts: "new" (default) or "all". Without
// a stream, queue_group (optional) makes the gateway replicas share the subject's messages.
//
// Each message is the event
//
//	id              JetStream: <stream>/<stream sequence> (the same on every redelivery);
//	                core NATS: the Nats-Msg-Id header, else a generated id
//	type            dev.runr.nats.message
//	source          /nats/<subject of the message>
//	subject         the subject of the message
//	time            JetStream: the time the stream stored the message; core NATS: receipt
//	datacontenttype the Content-Type header, else application/octet-stream
//	data            the payload, as received
//	extensions      natsheaders (the headers as a JSON object, repeated names joined by ", ";
//	                left out above 8 KiB)
//
// Delivery guarantees, JetStream:
//   - The durable consumer is created when missing (explicit acks, filtered to subject) and
//     bound otherwise; a changed subject or ack_wait is applied to it. Messages are fetched in
//     batches of at most the in-flight window (2×concurrency) and delivered in parallel.
//   - A message is acknowledged only when the gateway settled it. While it is in delivery the
//     source sends "in progress" every ack_wait/3, so a delivery with long retries is not
//     redelivered meanwhile. A message in delivery at shutdown is left unacknowledged: JetStream
//     redelivers it after ack_wait (at-least-once).
//   - JetStream's delivery count is passed on as the prior attempts (NumDelivered-1).
//
// Core NATS (no stream) has no acknowledgements: a message is delivered at most once. It is
// handed to the gateway when it arrives and the next one is read after it settled; messages
// published while the gateway is down, or that overflow the client's buffer while a delivery
// is slow, are lost.
package nats

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"strings"
	"sync"
	"time"

	natsgo "github.com/nats-io/nats.go"
	"github.com/nats-io/nats.go/jetstream"
	"github.com/nats-io/nkeys"
	"github.com/nats-io/nuid"

	"github.com/brf-tech/runr/runtime/queue-consumer/gateway"
	"github.com/brf-tech/runr/runtime/queue-consumer/internal/wait"
	"github.com/brf-tech/runr/runtime/queue-consumer/source"
	"github.com/brf-tech/runr/runtime/queue-consumer/sources/internal/extjson"
	"github.com/brf-tech/runr/runtime/queue-consumer/sources/internal/tlsconf"
)

func init() { source.Register("nats", New) }

// Config is the "source" object of a nats trigger.
type Config struct {
	URL        string           `json:"url"`
	Subject    string           `json:"subject"`
	Stream     string           `json:"stream"`      // JetStream stream; empty: core NATS
	Durable    string           `json:"durable"`     // required with stream
	Deliver    string           `json:"deliver"`     // "new" (default) or "all", for a new durable
	QueueGroup string           `json:"queue_group"` // core NATS only
	AckWait    gateway.Duration `json:"ack_wait"`    // default 60s
	Auth       *AuthConfig      `json:"auth"`
	TLS        *tlsconf.Config  `json:"tls"`
}

// AuthConfig is the "auth" object: exactly one of user+password, token, nkey_seed and creds.
type AuthConfig struct {
	User     string `json:"user"`
	Password string `json:"password"`
	Token    string `json:"token"`
	NkeySeed string `json:"nkey_seed"`
	Creds    string `json:"creds"`
}

// Source reads one subject.
type Source struct {
	cfg       Config
	ackWait   time.Duration
	opts      []natsgo.Option
	fetchWait time.Duration // how long one JetStream fetch waits for messages
}

// New builds a nats source from its configuration.
func New(raw json.RawMessage) (source.Source, error) {
	var c Config
	if err := json.Unmarshal(raw, &c); err != nil {
		return nil, err
	}
	c.URL = strings.TrimSpace(c.URL)
	if c.URL == "" {
		return nil, errors.New("url is required")
	}
	if c.Subject == "" {
		return nil, errors.New("subject is required")
	}
	switch c.Deliver {
	case "":
		c.Deliver = "new"
	case "new", "all":
	default:
		return nil, fmt.Errorf("deliver %q: want new or all", c.Deliver)
	}
	if c.Stream != "" && c.Durable == "" {
		return nil, errors.New("durable is required with stream")
	}
	if c.Stream != "" && c.QueueGroup != "" {
		return nil, errors.New("queue_group is for core NATS (no stream); JetStream replicas share the durable")
	}
	s := &Source{cfg: c, ackWait: c.AckWait.D(), fetchWait: 5 * time.Second}
	if s.ackWait <= 0 {
		s.ackWait = 60 * time.Second
	}
	auth, err := c.Auth.option()
	if err != nil {
		return nil, err
	}
	if auth != nil {
		s.opts = append(s.opts, auth)
	}
	tc, err := c.TLS.Build("")
	if err != nil {
		return nil, err
	}
	if tc != nil {
		s.opts = append(s.opts, natsgo.Secure(tc))
	}
	return s, nil
}

// option is the connect option of the auth object (nil without auth). Seeds and credentials are
// parsed here, so a broken one fails at start.
func (a *AuthConfig) option() (natsgo.Option, error) {
	if a == nil {
		return nil, nil
	}
	forms := 0
	for _, set := range []bool{a.User != "" || a.Password != "", a.Token != "", a.NkeySeed != "", a.Creds != ""} {
		if set {
			forms++
		}
	}
	if forms > 1 {
		return nil, errors.New("auth: give one of user+password, token, nkey_seed or creds")
	}
	switch {
	case a.User != "" || a.Password != "":
		if a.User == "" || a.Password == "" {
			return nil, errors.New("auth.user and auth.password are required together")
		}
		return natsgo.UserInfo(a.User, a.Password), nil
	case a.Token != "":
		return natsgo.Token(a.Token), nil
	case a.NkeySeed != "":
		kp, err := nkeys.FromSeed([]byte(strings.TrimSpace(a.NkeySeed)))
		if err != nil {
			return nil, errors.New("auth.nkey_seed is not an nkey seed")
		}
		pub, err := kp.PublicKey()
		if err != nil {
			return nil, errors.New("auth.nkey_seed is not an nkey seed")
		}
		return natsgo.Nkey(pub, kp.Sign), nil
	case a.Creds != "":
		jwt, err := nkeys.ParseDecoratedJWT([]byte(a.Creds))
		if err != nil || jwt == "" {
			return nil, errors.New("auth.creds holds no user JWT")
		}
		kp, err := nkeys.ParseDecoratedUserNKey([]byte(a.Creds))
		if err != nil {
			return nil, errors.New("auth.creds holds no user nkey seed")
		}
		seed, err := kp.Seed()
		if err != nil {
			return nil, errors.New("auth.creds holds no user nkey seed")
		}
		return natsgo.UserJWTAndSeed(jwt, string(seed)), nil
	default:
		return nil, nil
	}
}

// Name is "nats <subject>" plus "@<stream>/<durable>" with JetStream (the url, which may carry
// credentials, stays out).
func (s *Source) Name() string {
	if s.cfg.Stream != "" {
		return "nats " + s.cfg.Subject + "@" + s.cfg.Stream + "/" + s.cfg.Durable
	}
	return "nats " + s.cfg.Subject
}

// Run connects and reads until ctx ends. The client reconnects by itself after a lost
// connection; a failed first connect or a closed connection is returned as an error.
func (s *Source) Run(ctx context.Context, emit func(context.Context, source.Event) error) error {
	name := s.Name()
	opts := append([]natsgo.Option{
		natsgo.Name("runr-gateway " + source.TriggerName(ctx)),
		natsgo.Timeout(10 * time.Second),
		natsgo.MaxReconnects(-1),
		natsgo.ReconnectWait(2 * time.Second),
		natsgo.DisconnectErrHandler(func(_ *natsgo.Conn, err error) {
			if err != nil {
				log.Printf("%s: disconnected: %v", name, err)
			}
		}),
		natsgo.ReconnectHandler(func(*natsgo.Conn) { log.Printf("%s: reconnected", name) }),
		natsgo.ErrorHandler(func(_ *natsgo.Conn, _ *natsgo.Subscription, err error) {
			log.Printf("%s: %v", name, err)
		}),
	}, s.opts...)
	nc, err := natsgo.Connect(s.cfg.URL, opts...)
	if err != nil {
		return fmt.Errorf("connect: %w", err)
	}
	defer func() {
		nc.FlushTimeout(2 * time.Second) // send the last acknowledgements
		nc.Close()
	}()
	if s.cfg.Stream == "" {
		return s.runCore(ctx, nc, emit)
	}
	js, err := jetstream.New(nc)
	if err != nil {
		return err
	}
	cons, err := s.consumer(ctx, js)
	if err != nil {
		return err
	}
	return s.runJetStream(ctx, cons, emit)
}

// consumer binds the durable consumer, creating it when missing and applying a changed
// subject or ack_wait to it.
func (s *Source) consumer(ctx context.Context, js jetstream.JetStream) (jetstream.Consumer, error) {
	cctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	for range 2 {
		c, err := js.Consumer(cctx, s.cfg.Stream, s.cfg.Durable)
		if err == nil {
			cur := c.CachedInfo().Config
			if cur.FilterSubject == s.cfg.Subject && len(cur.FilterSubjects) == 0 && cur.AckWait == s.ackWait {
				return c, nil
			}
			cur.FilterSubject, cur.FilterSubjects, cur.AckWait = s.cfg.Subject, nil, s.ackWait
			c, err = js.UpdateConsumer(cctx, s.cfg.Stream, cur)
			if err != nil {
				return nil, fmt.Errorf("update consumer %s: %w", s.cfg.Durable, err)
			}
			return c, nil
		}
		if !errors.Is(err, jetstream.ErrConsumerNotFound) {
			return nil, fmt.Errorf("consumer %s of stream %s: %w", s.cfg.Durable, s.cfg.Stream, err)
		}
		deliver := jetstream.DeliverNewPolicy
		if s.cfg.Deliver == "all" {
			deliver = jetstream.DeliverAllPolicy
		}
		c, err = js.CreateConsumer(cctx, s.cfg.Stream, jetstream.ConsumerConfig{
			Durable:       s.cfg.Durable,
			FilterSubject: s.cfg.Subject,
			AckPolicy:     jetstream.AckExplicitPolicy,
			AckWait:       s.ackWait,
			DeliverPolicy: deliver,
		})
		if errors.Is(err, jetstream.ErrConsumerExists) {
			continue // another replica created it meanwhile: bind it
		}
		if err != nil {
			return nil, fmt.Errorf("create consumer %s: %w", s.cfg.Durable, err)
		}
		return c, nil
	}
	return nil, fmt.Errorf("consumer %s: created and missing at once", s.cfg.Durable)
}

// fetcher is the part of jetstream.Consumer the delivery loop uses.
type fetcher interface {
	Fetch(batch int, opts ...jetstream.FetchOpt) (jetstream.MessageBatch, error)
}

// runJetStream fetches as many messages as the in-flight window has room for and delivers them
// in parallel, until ctx ends.
func (s *Source) runJetStream(ctx context.Context, cons fetcher, emit func(context.Context, source.Event) error) error {
	win := wait.NewWindow(2 * source.Concurrency(ctx))
	var wg sync.WaitGroup
	defer wg.Wait()

	for ctx.Err() == nil {
		if win.Free() == 0 {
			win.WaitFree(ctx, time.Second)
			continue
		}
		fctx, cancel := context.WithTimeout(ctx, s.fetchWait)
		batch, err := cons.Fetch(win.Free(), jetstream.FetchContext(fctx))
		if err == nil {
			for msg := range batch.Messages() {
				win.Take(1)
				wg.Add(1)
				go func() {
					defer wg.Done()
					defer win.Done()
					s.deliver(ctx, emit, msg)
				}()
			}
			err = batch.Error()
		}
		cancel()
		if err == nil || ctx.Err() != nil || errors.Is(err, context.DeadlineExceeded) || errors.Is(err, natsgo.ErrTimeout) {
			continue
		}
		if errors.Is(err, jetstream.ErrConsumerDeleted) || errors.Is(err, jetstream.ErrConsumerNotFound) || errors.Is(err, natsgo.ErrConnectionClosed) {
			return fmt.Errorf("fetch: %w", err)
		}
		log.Printf("%s: fetch failed, retry in 1s: %v", s.Name(), err)
		wait.Sleep(ctx, time.Second)
	}
	return nil
}

// deliver hands one JetStream message to emit and acknowledges it when it is settled.
func (s *Source) deliver(ctx context.Context, emit func(context.Context, source.Event) error, msg jetstream.Msg) {
	meta, err := msg.Metadata()
	if err != nil {
		log.Printf("%s: message without JetStream metadata on %s, left to redelivery: %v", s.Name(), msg.Subject(), err)
		return
	}
	ev := s.event(msg.Subject(), msg.Headers(), msg.Data())
	ev.ID = fmt.Sprintf("%s/%d", meta.Stream, meta.Sequence.Stream)
	ev.Time = meta.Timestamp

	stop := s.keepInProgress(msg)
	err = emit(source.WithPriorAttempts(ctx, max(int(meta.NumDelivered)-1, 0)), ev)
	stop()
	if !source.Settled(err) {
		return // shutting down: unacknowledged, JetStream redelivers it after ack_wait
	}
	if err := msg.Ack(); err != nil {
		log.Printf("%s: ack of %s failed (redelivered after ack_wait): %v", s.Name(), ev.ID, err)
	}
}

// keepInProgress tells JetStream every ack_wait/3 that msg is still in delivery; the returned
// function stops it.
func (s *Source) keepInProgress(msg jetstream.Msg) (stop func()) {
	done := make(chan struct{})
	finished := make(chan struct{})
	go func() {
		defer close(finished)
		tick := time.NewTicker(max(s.ackWait/3, 100*time.Millisecond))
		defer tick.Stop()
		for {
			select {
			case <-done:
				return
			case <-tick.C:
				msg.InProgress()
			}
		}
	}()
	return func() {
		close(done)
		<-finished
	}
}

// subscription is the part of *nats.Subscription the core loop uses.
type subscription interface {
	NextMsgWithContext(ctx context.Context) (*natsgo.Msg, error)
}

// runCore subscribes (with queue_group when set) and delivers one message at a time.
func (s *Source) runCore(ctx context.Context, nc *natsgo.Conn, emit func(context.Context, source.Event) error) error {
	var sub *natsgo.Subscription
	var err error
	if s.cfg.QueueGroup != "" {
		sub, err = nc.QueueSubscribeSync(s.cfg.Subject, s.cfg.QueueGroup)
	} else {
		sub, err = nc.SubscribeSync(s.cfg.Subject)
	}
	if err != nil {
		return fmt.Errorf("subscribe: %w", err)
	}
	defer sub.Unsubscribe()
	return s.consumeCore(ctx, sub, emit)
}

func (s *Source) consumeCore(ctx context.Context, sub subscription, emit func(context.Context, source.Event) error) error {
	for {
		msg, err := sub.NextMsgWithContext(ctx)
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			if errors.Is(err, natsgo.ErrSlowConsumer) {
				log.Printf("%s: messages dropped while a delivery was slow: %v", s.Name(), err)
				continue
			}
			return fmt.Errorf("receive: %w", err)
		}
		ev := s.event(msg.Subject, msg.Header, msg.Data)
		ev.ID = headerValue(msg.Header, natsgo.MsgIdHdr)
		if ev.ID == "" {
			ev.ID = nuid.Next()
		}
		emit(ctx, ev) // at most once: nothing to acknowledge, the gateway logs failures and dead letters
	}
}

// event maps a message's subject, headers and payload; the caller sets id and time.
func (s *Source) event(subject string, h natsgo.Header, data []byte) source.Event {
	ev := source.Event{
		Type:            "dev.runr.nats.message",
		Source:          "/nats/" + subject,
		Subject:         subject,
		DataContentType: "application/octet-stream",
		Data:            data,
	}
	if ct := headerValue(h, "Content-Type"); ct != "" {
		ev.DataContentType = ct
	}
	if js := extjson.Encode(extjson.Joined(h)); js != "" {
		ev.Extensions = map[string]string{"natsheaders": js}
	}
	return ev
}

// headerValue is the first value of header name, matched without regard to case (NATS headers
// are case-sensitive, senders differ).
func headerValue(h natsgo.Header, name string) string {
	if v := h.Get(name); v != "" {
		return v
	}
	for k, vs := range h {
		if strings.EqualFold(k, name) && len(vs) > 0 {
			return vs[0]
		}
	}
	return ""
}
