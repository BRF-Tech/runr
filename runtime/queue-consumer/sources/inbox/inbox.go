// Package inbox is the gateway source of kind "inbox": the events the RunR API stored for a
// trigger — webhook calls and platform events — pulled from the API with a lease.
//
// Configuration (the trigger's "source" object):
//
//	{"url": "https://api.example/api/internal/triggers/<context>/<projectId>/<token>",
//	 "trigger": "<name>", "batch": 10, "lease_seconds": 600, "poll_interval": "15s"}
//
// The url carries a secret token and is never logged. trigger defaults to the gateway trigger's
// name.
//
// Protocol:
//
//	POST <url>/claim  {"trigger": "<name>", "limit": <n>, "lease_seconds": <s>}
//	  → 200 {"events": [{"id", "type", "source", "subject", "time", "datacontenttype",
//	                     "data_base64", "extensions": {...}, "attempts": <prior attempts>}]}
//	POST <url>/ack    {"trigger": "<name>", "results": [{"id": "...", "status": "delivered"|"failed", "error": "..."}]}
//
// Delivery guarantees:
//   - A claimed event is leased to this gateway for lease_seconds; it is acknowledged only when
//     the gateway settled it ("delivered", or "failed" after it was dead-lettered, with the last
//     error). An event not settled (shutdown) is not acknowledged: when its lease expires the
//     API offers it again, with its attempts.
//   - Acknowledgements are sent in batches and retried a few times; one that is lost makes the
//     API offer the event again after the lease (a repeated delivery, never a lost one).
//   - The source claims again at once while a claim returns a full batch; otherwise it waits
//     poll_interval or until the trigger is nudged (POST /v1/nudge), e.g. when the API stored a
//     new event.
package inbox

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"

	"github.com/brf-tech/runr/runtime/queue-consumer/gateway"
	"github.com/brf-tech/runr/runtime/queue-consumer/internal/wait"
	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

func init() { source.Register("inbox", New) }

// Config is the "source" object of an inbox trigger.
type Config struct {
	URL          string           `json:"url"`
	Trigger      string           `json:"trigger"`       // default: the gateway trigger's name
	Batch        int              `json:"batch"`         // events per claim, default 10
	LeaseSeconds int              `json:"lease_seconds"` // default 600
	PollInterval gateway.Duration `json:"poll_interval"` // default 15s
}

// Source pulls one trigger's events from the API.
type Source struct {
	cfg      Config
	host     string // for logs: the url without its secret path
	poll     time.Duration
	errWait  time.Duration // wait after a failed claim: min(poll_interval, 5s)
	client   *http.Client
	ackTries int
}

// New builds an inbox source from its configuration.
func New(raw json.RawMessage) (source.Source, error) {
	var c Config
	if err := json.Unmarshal(raw, &c); err != nil {
		return nil, err
	}
	u, err := url.Parse(c.URL)
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return nil, errors.New("url must be an http(s) URL")
	}
	c.URL = strings.TrimRight(c.URL, "/")
	if c.Batch <= 0 {
		c.Batch = 10
	}
	if c.LeaseSeconds <= 0 {
		c.LeaseSeconds = 600
	}
	poll := c.PollInterval.D()
	if poll <= 0 {
		poll = 15 * time.Second
	}
	return &Source{
		cfg:      c,
		host:     u.Host,
		poll:     poll,
		errWait:  min(poll, 5*time.Second),
		client:   &http.Client{Timeout: 30 * time.Second},
		ackTries: 3,
	}, nil
}

// Name is "inbox <trigger>@<api host>" (the token in the url stays out of logs).
func (s *Source) Name() string {
	if s.cfg.Trigger != "" {
		return "inbox " + s.cfg.Trigger + "@" + s.host
	}
	return "inbox @" + s.host
}

type claimRequest struct {
	Trigger      string `json:"trigger"`
	Limit        int    `json:"limit"`
	LeaseSeconds int    `json:"lease_seconds"`
}

type claimedEvent struct {
	ID              string            `json:"id"`
	Type            string            `json:"type"`
	Source          string            `json:"source"`
	Subject         string            `json:"subject"`
	Time            string            `json:"time"`
	DataContentType string            `json:"datacontenttype"`
	DataBase64      string            `json:"data_base64"`
	Extensions      map[string]string `json:"extensions"`
	Attempts        int               `json:"attempts"`
}

type claimResponse struct {
	Events []claimedEvent `json:"events"`
}

type ackResult struct {
	ID     string `json:"id"`
	Status string `json:"status"` // delivered | failed
	Error  string `json:"error,omitempty"`
}

type ackRequest struct {
	Trigger string      `json:"trigger"`
	Results []ackResult `json:"results"`
}

// errNotFound is the API's answer for an unknown token or trigger.
var errNotFound = errors.New("the API does not know this token or trigger (404)")

// Run claims and delivers events until ctx ends. It keeps at most 2×concurrency events in
// delivery and returns after they and their acknowledgements are done.
func (s *Source) Run(ctx context.Context, emit func(context.Context, source.Event) error) error {
	trigger := s.cfg.Trigger
	if trigger == "" {
		trigger = source.TriggerName(ctx)
	}
	nudges := source.Nudges(ctx)
	win := wait.NewWindow(2 * source.Concurrency(ctx))

	results := make(chan ackResult, 64)
	acked := make(chan struct{})
	go func() {
		defer close(acked)
		s.acknowledge(context.WithoutCancel(ctx), trigger, results)
	}()
	var wg sync.WaitGroup
	defer func() {
		wg.Wait()
		close(results)
		<-acked
	}()

	for ctx.Err() == nil {
		if win.Free() == 0 {
			win.WaitFree(ctx, time.Second)
			continue
		}
		limit := min(s.cfg.Batch, win.Free())
		events, err := s.claim(ctx, trigger, limit)
		if err != nil {
			if ctx.Err() != nil {
				break
			}
			pause := s.errWait
			if errors.Is(err, errNotFound) {
				pause = s.poll
			}
			log.Printf("%s: claim failed, retry in %s: %v", s.Name(), pause, err)
			s.sleep(ctx, pause, nudges)
			continue
		}
		for _, ce := range events {
			ev, prior, err := toEvent(ce)
			if err != nil {
				// Malformed: deliver nothing, report it failed so the API does not offer it forever.
				results <- ackResult{ID: ce.ID, Status: "failed", Error: err.Error()}
				continue
			}
			win.Take(1)
			wg.Add(1)
			go func() {
				defer wg.Done()
				defer win.Done()
				err := emit(source.WithPriorAttempts(ctx, prior), ev)
				switch {
				case err == nil:
					results <- ackResult{ID: ev.ID, Status: "delivered"}
				case source.Settled(err):
					results <- ackResult{ID: ev.ID, Status: "failed", Error: truncate(err.Error(), 500)}
				}
				// not settled (shutdown): no ack, the lease expires and the API offers it again
			}()
		}
		if len(events) >= limit {
			continue // a full batch: there may be more
		}
		s.sleep(ctx, s.poll, nudges)
	}
	return nil
}

// sleep waits d, until a nudge arrives or until ctx ends.
func (s *Source) sleep(ctx context.Context, d time.Duration, nudges <-chan struct{}) {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
	case <-t.C:
	case <-nudges:
	}
}

func (s *Source) claim(ctx context.Context, trigger string, limit int) ([]claimedEvent, error) {
	var resp claimResponse
	if err := s.post(ctx, "/claim", claimRequest{Trigger: trigger, Limit: limit, LeaseSeconds: s.cfg.LeaseSeconds}, &resp); err != nil {
		return nil, err
	}
	if len(resp.Events) > limit {
		return resp.Events[:limit], nil // never more than the window has room for
	}
	return resp.Events, nil
}

// acknowledge sends the results as they come, batching those that arrive together, until
// results is closed. It runs on a context that outlives the shutdown, so settled events are
// acknowledged even while the gateway stops.
func (s *Source) acknowledge(ctx context.Context, trigger string, results <-chan ackResult) {
	for r := range results {
		batch := []ackResult{r}
	collect:
		for len(batch) < 100 {
			select {
			case more, ok := <-results:
				if !ok {
					break collect
				}
				batch = append(batch, more)
			default:
				break collect
			}
		}
		var err error
		for try := 1; try <= s.ackTries; try++ {
			actx, cancel := context.WithTimeout(ctx, 10*time.Second)
			err = s.post(actx, "/ack", ackRequest{Trigger: trigger, Results: batch}, nil)
			cancel()
			if err == nil || errors.Is(err, errNotFound) {
				break
			}
			time.Sleep(time.Duration(try) * 500 * time.Millisecond)
		}
		if err != nil {
			log.Printf("%s: acknowledging %d events failed (the API offers them again after the lease): %v", s.Name(), len(batch), err)
		}
	}
}

// post sends body as JSON to url+path and decodes a 2xx answer into out (when not nil).
func (s *Source) post(ctx context.Context, path string, body, out any) error {
	b, err := json.Marshal(body)
	if err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, s.cfg.URL+path, bytes.NewReader(b))
	if err != nil {
		return errors.New("bad url")
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	resp, err := s.client.Do(req)
	if err != nil {
		var uerr *url.Error
		if errors.As(err, &uerr) {
			err = uerr.Err // the url holds the token: keep it out of the error
		}
		return fmt.Errorf("POST %s%s: %w", s.host, path, err)
	}
	defer resp.Body.Close()
	if resp.StatusCode == http.StatusNotFound {
		io.Copy(io.Discard, io.LimitReader(resp.Body, 64<<10))
		return errNotFound
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		io.Copy(io.Discard, io.LimitReader(resp.Body, 64<<10))
		return fmt.Errorf("POST %s%s: status %d", s.host, path, resp.StatusCode)
	}
	if out == nil {
		io.Copy(io.Discard, io.LimitReader(resp.Body, 64<<10))
		return nil
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 64<<20)).Decode(out); err != nil {
		return fmt.Errorf("POST %s%s: answer: %w", s.host, path, err)
	}
	return nil
}

// toEvent maps a claimed event; a missing or invalid time leaves it zero (the gateway then uses
// the delivery time).
func toEvent(ce claimedEvent) (source.Event, int, error) {
	if ce.ID == "" {
		return source.Event{}, 0, errors.New("event without id")
	}
	data, err := base64.StdEncoding.DecodeString(ce.DataBase64)
	if err != nil {
		return source.Event{}, 0, fmt.Errorf("data_base64: %w", err)
	}
	ev := source.Event{
		ID:              ce.ID,
		Type:            ce.Type,
		Source:          ce.Source,
		Subject:         ce.Subject,
		DataContentType: ce.DataContentType,
		Data:            data,
		Extensions:      ce.Extensions,
	}
	if t, err := time.Parse(time.RFC3339Nano, ce.Time); err == nil {
		ev.Time = t
	}
	return ev, max(ce.Attempts, 0), nil
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}
