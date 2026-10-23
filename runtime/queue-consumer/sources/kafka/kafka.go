// Package kafka is the gateway source of kind "kafka": it reads a Kafka topic through a consumer
// group.
//
// Configuration (the trigger's "source" object):
//
//	{"brokers": ["kafka-0:9092", "kafka-1:9092"], "topic": "orders", "group_id": "runr.<project>.<trigger>",
//	 "start_from": "latest", "sasl": {"mechanism": "scram-sha-512", "username": "...", "password": "..."},
//	 "tls": {"enabled": true, "ca_pem": "...", "insecure_skip_verify": false},
//	 "min_bytes": 1, "max_wait": "1s"}
//
// brokers, topic and group_id are required. start_from is where a group without committed
// offsets begins: "latest" (default: new messages only) or "earliest" (the whole topic). sasl
// (optional) takes the mechanisms plain (default), scram-sha-256 and scram-sha-512 and needs
// username and password; tls is optional too. One fetch waits up to max_wait (default 1s) for
// min_bytes (default 1) and returns at most 10 MiB.
//
// Each message is the event
//
//	id              <topic>/<partition>/<offset>   (the same on every redelivery)
//	type            dev.runr.kafka.message
//	source          /kafka/<topic>
//	subject         the key, when it is valid UTF-8
//	time            the message timestamp
//	datacontenttype the "content-type" header, else application/octet-stream
//	data            the value, as received
//	extensions      kafkapartition, kafkaoffset, kafkakey (a non-empty UTF-8 key), kafkaheaders
//	                (the headers as a JSON object, repeated names joined by ", "; left out
//	                above 8 KiB)
//
// Delivery guarantees:
//   - The source runs one group member per trigger concurrency; Kafka assigns each member its
//     share of the partitions. A member delivers its messages one at a time, so the messages of
//     a partition reach the sink in order, and a message in retry holds up its partition.
//   - An offset is committed (synchronously) only after the gateway settled its message. A
//     message in delivery at shutdown is not committed: the partition's next owner reads it
//     again. A rebalance during a delivery can deliver a message twice (at-least-once).
//   - Kafka keeps no delivery count: every delivery starts with 0 prior attempts.
package kafka

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"strconv"
	"strings"
	"sync"
	"time"
	"unicode/utf8"

	kafkago "github.com/segmentio/kafka-go"
	"github.com/segmentio/kafka-go/sasl"
	"github.com/segmentio/kafka-go/sasl/plain"
	"github.com/segmentio/kafka-go/sasl/scram"

	"github.com/brf-tech/runr/runtime/queue-consumer/gateway"
	"github.com/brf-tech/runr/runtime/queue-consumer/source"
	"github.com/brf-tech/runr/runtime/queue-consumer/sources/internal/extjson"
	"github.com/brf-tech/runr/runtime/queue-consumer/sources/internal/tlsconf"
)

func init() { source.Register("kafka", New) }

// Config is the "source" object of a kafka trigger.
type Config struct {
	Brokers   []string         `json:"brokers"`
	Topic     string           `json:"topic"`
	GroupID   string           `json:"group_id"`
	StartFrom string           `json:"start_from"` // "latest" (default) or "earliest"
	SASL      *SASLConfig      `json:"sasl"`
	TLS       *tlsconf.Config  `json:"tls"`
	MinBytes  int              `json:"min_bytes"` // default 1
	MaxWait   gateway.Duration `json:"max_wait"`  // default 1s
}

// SASLConfig is the "sasl" object.
type SASLConfig struct {
	Mechanism string `json:"mechanism"` // plain, scram-sha-256, scram-sha-512
	Username  string `json:"username"`
	Password  string `json:"password"`
}

// reader is the part of *kafka.Reader the source uses.
type reader interface {
	FetchMessage(ctx context.Context) (kafkago.Message, error)
	CommitMessages(ctx context.Context, msgs ...kafkago.Message) error
	Close() error
}

// Source reads one topic through one consumer group.
type Source struct {
	cfg       Config
	readerCfg kafkago.ReaderConfig
	newReader func() reader
}

// maxFetchBytes is the most one fetch returns (and so the largest message the source reads).
const maxFetchBytes = 10 << 20

// New builds a kafka source from its configuration.
func New(raw json.RawMessage) (source.Source, error) {
	var c Config
	if err := json.Unmarshal(raw, &c); err != nil {
		return nil, err
	}
	brokers := c.Brokers[:0:0]
	for _, b := range c.Brokers {
		if b = strings.TrimSpace(b); b != "" {
			brokers = append(brokers, b)
		}
	}
	c.Brokers = brokers
	if len(c.Brokers) == 0 {
		return nil, errors.New("brokers is required")
	}
	if c.Topic == "" {
		return nil, errors.New("topic is required")
	}
	if c.GroupID == "" {
		return nil, errors.New("group_id is required")
	}
	start := kafkago.LastOffset
	switch c.StartFrom {
	case "", "latest":
		c.StartFrom = "latest"
	case "earliest":
		start = kafkago.FirstOffset
	default:
		return nil, fmt.Errorf("start_from %q: want latest or earliest", c.StartFrom)
	}
	if c.MinBytes <= 0 {
		c.MinBytes = 1
	}
	maxWait := c.MaxWait.D()
	if maxWait <= 0 {
		maxWait = time.Second
	}

	dialer := &kafkago.Dialer{Timeout: 10 * time.Second, DualStack: true}
	tc, err := c.TLS.Build("")
	if err != nil {
		return nil, err
	}
	dialer.TLS = tc
	if c.SASL != nil {
		mech, err := c.SASL.mechanism()
		if err != nil {
			return nil, err
		}
		dialer.SASLMechanism = mech
	}

	s := &Source{cfg: c}
	s.readerCfg = kafkago.ReaderConfig{
		Brokers:               c.Brokers,
		GroupID:               c.GroupID,
		Topic:                 c.Topic,
		Dialer:                dialer,
		MinBytes:              c.MinBytes,
		MaxBytes:              maxFetchBytes,
		MaxWait:               maxWait,
		StartOffset:           start,
		CommitInterval:        0, // synchronous commits
		WatchPartitionChanges: true,
		ErrorLogger:           kafkago.LoggerFunc(s.logError),
	}
	if err := s.readerCfg.Validate(); err != nil {
		return nil, err
	}
	s.newReader = func() reader { return kafkago.NewReader(s.readerCfg) }
	return s, nil
}

func (c *SASLConfig) mechanism() (sasl.Mechanism, error) {
	if c.Username == "" || c.Password == "" {
		return nil, errors.New("sasl.username and sasl.password are required")
	}
	switch strings.ToLower(c.Mechanism) {
	case "plain", "":
		return plain.Mechanism{Username: c.Username, Password: c.Password}, nil
	case "scram-sha-256":
		return scram.Mechanism(scram.SHA256, c.Username, c.Password)
	case "scram-sha-512":
		return scram.Mechanism(scram.SHA512, c.Username, c.Password)
	default:
		return nil, fmt.Errorf("sasl.mechanism %q: want plain, scram-sha-256 or scram-sha-512", c.Mechanism)
	}
}

// Name is "kafka <topic>@<group_id>".
func (s *Source) Name() string { return "kafka " + s.cfg.Topic + "@" + s.cfg.GroupID }

func (s *Source) logError(format string, args ...any) {
	log.Printf("%s: %s", s.Name(), fmt.Sprintf(format, args...))
}

// Run starts one group member per trigger concurrency and returns when ctx ends (nil) or when
// a member fails (its error, after the others stopped).
func (s *Source) Run(ctx context.Context, emit func(context.Context, source.Event) error) error {
	n := source.Concurrency(ctx)
	mctx, cancel := context.WithCancel(ctx)
	defer cancel()

	errs := make(chan error, n)
	var wg sync.WaitGroup
	for range n {
		r := s.newReader()
		wg.Add(1)
		go func() {
			defer wg.Done()
			errs <- s.consume(mctx, r, emit)
		}()
	}
	var first error
	for range n {
		if err := <-errs; err != nil && first == nil {
			first = err
			cancel() // one member failed: stop the others, the gateway restarts the source
		}
	}
	wg.Wait()
	if ctx.Err() != nil {
		return nil
	}
	return first
}

// consume delivers the messages of one group member one at a time and commits each settled one.
func (s *Source) consume(ctx context.Context, r reader, emit func(context.Context, source.Event) error) error {
	defer r.Close()
	for {
		m, err := r.FetchMessage(ctx)
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			return fmt.Errorf("fetch: %w", err)
		}
		ev := s.event(m)
		if err := emit(ctx, ev); !source.Settled(err) {
			if ctx.Err() != nil {
				return nil // shutting down: not committed, the partition's next owner reads it again
			}
			return fmt.Errorf("delivery of %s not settled: %w", ev.ID, err)
		}
		// Settled: commit even when shutdown began meanwhile.
		cctx, cancelCommit := context.WithTimeout(context.WithoutCancel(ctx), 10*time.Second)
		err = r.CommitMessages(cctx, m)
		cancelCommit()
		if err != nil {
			// The next commit on the partition covers this offset; if none comes (rebalance,
			// shutdown) the message is delivered again.
			log.Printf("%s: commit of %s failed: %v", s.Name(), ev.ID, err)
		}
	}
}

// event maps a Kafka message to the event to deliver.
func (s *Source) event(m kafkago.Message) source.Event {
	ev := source.Event{
		ID:              fmt.Sprintf("%s/%d/%d", m.Topic, m.Partition, m.Offset),
		Type:            "dev.runr.kafka.message",
		Source:          "/kafka/" + m.Topic,
		DataContentType: "application/octet-stream",
		Time:            m.Time,
		Data:            m.Value,
		Extensions: map[string]string{
			"kafkapartition": strconv.Itoa(m.Partition),
			"kafkaoffset":    strconv.FormatInt(m.Offset, 10),
		},
	}
	if len(m.Key) > 0 && utf8.Valid(m.Key) {
		ev.Subject = string(m.Key)
		ev.Extensions["kafkakey"] = string(m.Key)
	}
	if len(m.Headers) > 0 {
		h := make(map[string][]string, len(m.Headers))
		for _, hd := range m.Headers {
			h[hd.Key] = append(h[hd.Key], string(hd.Value))
			if strings.EqualFold(hd.Key, "content-type") && len(hd.Value) > 0 {
				ev.DataContentType = string(hd.Value)
			}
		}
		if js := extjson.Encode(extjson.Joined(h)); js != "" {
			ev.Extensions["kafkaheaders"] = js
		}
	}
	return ev
}
