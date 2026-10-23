package gateway

import (
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"net/url"
	"os"
	"regexp"
	"slices"
	"strings"
	"time"

	"github.com/brf-tech/runr/runtime/queue-consumer/internal/conf"
	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

// Defaults of a trigger's delivery settings.
const (
	DefaultMaxRetries      = 5
	DefaultRetryBackoff    = 2 * time.Second
	DefaultRetryBackoffMax = 5 * time.Minute
	DefaultDeliveryTimeout = 30 * time.Second

	// DefaultLegacySink is the sink of legacy env mode without SINK_URL, as the queue consumer
	// had it.
	DefaultLegacySink = "http://localhost:8080"
	// DefaultClaimMinIdle is how long another consumer's pending entry is idle before legacy env
	// mode claims it (CLAIM_MIN_IDLE).
	DefaultClaimMinIdle = 30 * time.Second
)

// Config is gateway.json (GATEWAY_CONFIG, rendered by the RunR API into a Secret), or what
// FromLegacyEnv builds from the queue consumer's environment. Unknown JSON fields are ignored.
type Config struct {
	Version         int             `json:"version"`
	Sink            string          `json:"sink"`             // default sink of every trigger
	DeliveryTimeout Duration        `json:"delivery_timeout"` // default delivery timeout of every trigger (30s)
	Triggers        []TriggerConfig `json:"triggers"`

	// Legacy is set by FromLegacyEnv: GET /stats then also answers the queue consumer's
	// top-level keys.
	Legacy *LegacyEnv `json:"-"`
}

// TriggerConfig is one trigger: a source and where and how its events are delivered.
type TriggerConfig struct {
	Name            string            `json:"name"`              // unique, [a-z0-9-]
	Type            string            `json:"type"`              // RunR trigger type (queue, topic, webhook, schedule, ...): a label for metrics and logs
	Kind            string            `json:"kind"`              // source kind (package source registry)
	Sink            string            `json:"sink"`              // overrides Config.Sink
	Concurrency     int               `json:"concurrency"`       // delivery attempts at once, default 1
	MaxRetries      *int              `json:"max_retries"`       // retries after the first attempt, default 5; 0 = one attempt
	RetryBackoff    Duration          `json:"retry_backoff"`     // wait before the first retry, doubled per retry, default 2s
	RetryBackoffMax Duration          `json:"retry_backoff_max"` // cap of the wait, default 5m
	DeliveryTimeout Duration          `json:"delivery_timeout"`  // timeout of one attempt, default Config.DeliveryTimeout, then 30s
	DeadLetter      *DeadLetterConfig `json:"dead_letter"`       // where exhausted events go, default kind "log"
	Source          json.RawMessage   `json:"source"`            // kind-specific, passed verbatim to the source factory
}

// DeadLetterConfig is a trigger's "dead_letter" object. Kind picks the factory registered with
// RegisterDeadLetter, which receives the whole object (Raw).
type DeadLetterConfig struct {
	Kind string
	Raw  json.RawMessage
}

// UnmarshalJSON keeps the whole object for the factory.
func (d *DeadLetterConfig) UnmarshalJSON(b []byte) error {
	var head struct {
		Kind string `json:"kind"`
	}
	if err := json.Unmarshal(b, &head); err != nil {
		return fmt.Errorf("dead_letter: %w", err)
	}
	d.Kind = head.Kind
	d.Raw = append(json.RawMessage(nil), b...)
	return nil
}

// MarshalJSON writes the object the factory receives.
func (d DeadLetterConfig) MarshalJSON() ([]byte, error) {
	if len(d.Raw) == 0 {
		return json.Marshal(map[string]string{"kind": d.Kind})
	}
	return d.Raw, nil
}

// LegacyEnv is what FromLegacyEnv read, kept for the queue consumer's keys of GET /stats.
type LegacyEnv struct {
	Stream    string
	Group     string
	Consumer  string
	DLQStream string
}

var triggerNameRE = regexp.MustCompile(`^[a-z0-9-]+$`)

// Load reads and validates a gateway.json file.
func Load(path string) (*Config, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var c Config
	if err := json.Unmarshal(b, &c); err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}
	if err := c.Validate(); err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}
	return &c, nil
}

// Validate checks what the gateway cannot start without: version 1, at least one trigger,
// unique [a-z0-9-] names, a registered source kind, an http(s) sink (the trigger's or the
// top-level one) and a registered dead letter kind.
func (c *Config) Validate() error {
	if c.Version != 0 && c.Version != 1 {
		return fmt.Errorf("unsupported config version %d (this gateway reads version 1)", c.Version)
	}
	if len(c.Triggers) == 0 {
		return errors.New("no triggers")
	}
	kinds := source.Kinds()
	seen := make(map[string]bool, len(c.Triggers))
	for i, t := range c.Triggers {
		if !triggerNameRE.MatchString(t.Name) {
			return fmt.Errorf("trigger %d: name %q must match [a-z0-9-]+", i, t.Name)
		}
		if seen[t.Name] {
			return fmt.Errorf("trigger %s: duplicate name", t.Name)
		}
		seen[t.Name] = true
		if !slices.Contains(kinds, t.Kind) {
			return fmt.Errorf("trigger %s: unknown source kind %q (known: %s)", t.Name, t.Kind, strings.Join(kinds, ", "))
		}
		sink := t.Sink
		if sink == "" {
			sink = c.Sink
		}
		if sink == "" {
			return fmt.Errorf("trigger %s: no sink (set the top-level sink or the trigger's)", t.Name)
		}
		if err := checkSink(sink); err != nil {
			return fmt.Errorf("trigger %s: %w", t.Name, err)
		}
		if t.DeadLetter != nil && !deadLetterKnown(t.DeadLetter.kind()) {
			return fmt.Errorf("trigger %s: unknown dead_letter kind %q (known: %s)", t.Name, t.DeadLetter.Kind, strings.Join(deadLetterKinds(), ", "))
		}
	}
	return nil
}

func checkSink(sink string) error {
	u, err := url.Parse(sink)
	if err != nil {
		return fmt.Errorf("sink: %w", err)
	}
	if (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return fmt.Errorf("sink %q is not an http(s) URL", u.Redacted())
	}
	return nil
}

func (d *DeadLetterConfig) kind() string {
	if d == nil || d.Kind == "" {
		return "log"
	}
	return d.Kind
}

// FromLegacyEnv builds the configuration of legacy env mode (no GATEWAY_CONFIG): the queue
// consumer's environment becomes one trigger "queue" of kind redis-stream that reads STREAM
// through GROUP and dead-letters to the Redis stream DLQ_STREAM, so existing <slug>-qc
// Deployments keep working unchanged.
//
//	REDIS_ADDRESS     localhost:6379 (host:port, redis://, rediss:// = TLS; userinfo ignored)
//	REDIS_PASSWORD
//	STREAM            default-stream
//	GROUP             default-group
//	CONSUMER          the host (pod) name
//	SINK_URL          http://localhost:8080 (the queue consumer's default; logged when used)
//	DLQ_STREAM        STREAM + "-dlq"; must differ from STREAM
//	DLQ_MAXLEN        10000 (≤ 0: no trimming)
//	MAX_RETRIES       5 (negative: 0)
//	RETRY_BACKOFF     2s (≤ 0: 1s)
//	RETRY_BACKOFF_MAX 5m (raised to RETRY_BACKOFF when smaller)
//	DELIVERY_TIMEOUT  30s
//	BATCH_SIZE        10 (≤ 0: 10)
//	CONCURRENCY       1 (≤ 0: 1)
//	CLAIM_MIN_IDLE    30s: how long another consumer's pending entry stays idle before it is
//	                  claimed (≤ 0: 30s)
//
// Durations are Go durations or plain seconds; an invalid number or duration logs a warning and
// uses the default.
//
// What the queue consumer did and this mode keeps: an entry is acknowledged only once delivered
// or dead-lettered, and the dead-letter XADD and the XACK run in one MULTI/EXEC (the dead letter
// is on the same Redis). What differs: retries run in the gateway's memory with the same backoff
// (the delivery count in Redis still counts them across a restart), and another consumer's
// pending entry is claimed once idle for CLAIM_MIN_IDLE — the entries in delivery are kept fresh,
// so a long retry is not claimed away — where the queue consumer claimed any entry once idle for
// its next backoff.
func FromLegacyEnv(getenv func(string) string) (*Config, error) {
	redisAddress := conf.String(getenv, "REDIS_ADDRESS", "localhost:6379")
	redisPassword := getenv("REDIS_PASSWORD")
	stream := conf.String(getenv, "STREAM", "default-stream")
	group := conf.String(getenv, "GROUP", "default-group")
	consumer := conf.String(getenv, "CONSUMER", conf.Hostname())
	sink := strings.TrimSpace(getenv("SINK_URL"))
	if sink == "" {
		sink = DefaultLegacySink
		log.Printf("SINK_URL is not set: delivering to %s (the queue consumer's default)", sink)
	}
	claimMinIdle := conf.Duration(getenv, "CLAIM_MIN_IDLE", DefaultClaimMinIdle)
	if claimMinIdle <= 0 {
		claimMinIdle = DefaultClaimMinIdle
	}
	dlqStream := conf.String(getenv, "DLQ_STREAM", stream+"-dlq")
	dlqMaxLen := conf.Int(getenv, "DLQ_MAXLEN", 10000)
	maxRetries := int(conf.Int(getenv, "MAX_RETRIES", DefaultMaxRetries))
	backoff := conf.Duration(getenv, "RETRY_BACKOFF", DefaultRetryBackoff)
	backoffMax := conf.Duration(getenv, "RETRY_BACKOFF_MAX", DefaultRetryBackoffMax)
	timeout := conf.Duration(getenv, "DELIVERY_TIMEOUT", DefaultDeliveryTimeout)
	batch := conf.Int(getenv, "BATCH_SIZE", 10)
	concurrency := int(conf.Int(getenv, "CONCURRENCY", 1))

	if dlqStream == stream {
		return nil, errors.New("DLQ_STREAM must differ from STREAM")
	}
	if dlqMaxLen <= 0 {
		dlqMaxLen = -1 // no trimming
	}
	maxRetries = max(maxRetries, 0)
	if backoff <= 0 {
		backoff = time.Second
	}
	if backoffMax < backoff {
		backoffMax = backoff
	}
	if batch <= 0 {
		batch = 10
	}
	concurrency = max(concurrency, 1)

	src, err := json.Marshal(map[string]any{
		"address":  redisAddress,
		"password": redisPassword,
		"stream":   stream,
		"group":    group,
		"consumer": consumer,
		"start":    "0",
		"format":   "queue",
		"batch":    batch,
		// how the redis-stream source reads a Duration: Go duration text
		"claim_min_idle": claimMinIdle.String(),
	})
	if err != nil {
		return nil, err
	}
	dlq, err := json.Marshal(map[string]any{
		"kind":     "redis-stream",
		"address":  redisAddress,
		"password": redisPassword,
		"stream":   dlqStream,
		"max_len":  dlqMaxLen,
		"meta":     map[string]string{"runr.dlq.stream": stream, "runr.dlq.group": group},
	})
	if err != nil {
		return nil, err
	}

	c := &Config{
		Version:         1,
		Sink:            sink,
		DeliveryTimeout: Duration(timeout),
		Triggers: []TriggerConfig{{
			Name:            "queue",
			Type:            "queue",
			Kind:            "redis-stream",
			Concurrency:     concurrency,
			MaxRetries:      &maxRetries,
			RetryBackoff:    Duration(backoff),
			RetryBackoffMax: Duration(backoffMax),
			DeliveryTimeout: Duration(timeout),
			DeadLetter:      &DeadLetterConfig{Kind: "redis-stream", Raw: dlq},
			Source:          src,
		}},
		Legacy: &LegacyEnv{Stream: stream, Group: group, Consumer: consumer, DLQStream: dlqStream},
	}
	if err := c.Validate(); err != nil {
		return nil, err
	}
	return c, nil
}
