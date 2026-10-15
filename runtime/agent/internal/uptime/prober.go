// Package uptime runs RunR's synthetic HTTP checks from inside a region: the agent fetches the
// checks to run here from the RunR API, probes each at its interval and reports the results back
// (the incident state machine runs in RunR, docs/observability.md#uptime-checks).
package uptime

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"
)

const (
	syncInterval   = 60 * time.Second
	tickInterval   = time.Second
	reportInterval = 10 * time.Second
	reportBatch    = 50
	maxParallel    = 16
	maxBodyRead    = 1 << 20
	defaultTimeout = 10 * time.Second
	minInterval    = 10 * time.Second
)

// Check is one synthetic check as the RunR API hands it to the agent.
type Check struct {
	Key            string            `json:"key"`
	URL            string            `json:"url"`
	Method         string            `json:"method"`
	Headers        map[string]string `json:"headers"`
	IntervalSec    int               `json:"interval_seconds"`
	TimeoutSec     int               `json:"timeout_seconds"`
	ExpectedStatus string            `json:"expected_status"`
	BodyRegex      string            `json:"body_regex"`
}

// Result of one probe.
type Result struct {
	Key        string `json:"key"`
	OK         bool   `json:"ok"`
	StatusCode int    `json:"status_code"`
	ResponseMs int64  `json:"response_ms"`
	Error      string `json:"error,omitempty"`
	CheckedAt  string `json:"checked_at"`
}

// Prober keeps the region's checks in sync with RunR and runs them.
type Prober struct {
	apiBase  string
	regionID string
	token    string
	client   *http.Client
	now      func() time.Time

	mu      sync.Mutex
	checks  map[string]*scheduled
	pending []Result
}

type scheduled struct {
	check   Check
	re      *regexp.Regexp
	next    time.Time
	running bool
}

// New prober for the region; apiBase is RunR's base URL as seen from the region.
func New(apiBase, regionID, token string) *Prober {
	return &Prober{
		apiBase:  strings.TrimRight(apiBase, "/"),
		regionID: regionID,
		token:    token,
		client:   &http.Client{Timeout: 15 * time.Second},
		now:      time.Now,
		checks:   map[string]*scheduled{},
	}
}

// Run syncs, probes and reports until ctx is done.
func (p *Prober) Run(ctx context.Context) {
	p.sync(ctx)

	syncT := time.NewTicker(syncInterval)
	tick := time.NewTicker(tickInterval)
	report := time.NewTicker(reportInterval)
	defer syncT.Stop()
	defer tick.Stop()
	defer report.Stop()

	sem := make(chan struct{}, maxParallel)
	for {
		select {
		case <-ctx.Done():
			p.report(context.Background())
			return
		case <-syncT.C:
			p.sync(ctx)
		case <-report.C:
			p.report(ctx)
		case <-tick.C:
			for _, s := range p.due() {
				sem <- struct{}{}
				go func(s *scheduled) {
					defer func() { <-sem }()
					r := Probe(ctx, s.check, s.re)
					p.done(s, r)
				}(s)
			}
		}
	}
}

// sync replaces the check list with what RunR assigns to this region.
func (p *Prober) sync(ctx context.Context) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, p.endpoint("uptime-checks"), nil)
	if err != nil {
		return
	}
	p.auth(req)

	resp, err := p.client.Do(req)
	if err != nil {
		log.Printf("uptime: fetching checks failed: %v", err)
		return
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		log.Printf("uptime: fetching checks: RunR answered %d", resp.StatusCode)
		return
	}

	var body struct {
		Checks []Check `json:"checks"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 8<<20)).Decode(&body); err != nil {
		log.Printf("uptime: invalid check list: %v", err)
		return
	}
	p.setChecks(body.Checks)
}

// setChecks keeps the schedule of checks that stay, adds new ones (due now) and drops removed ones.
func (p *Prober) setChecks(checks []Check) {
	p.mu.Lock()
	defer p.mu.Unlock()

	next := map[string]*scheduled{}
	for _, c := range checks {
		if c.Key == "" || c.URL == "" {
			continue
		}
		var re *regexp.Regexp
		if c.BodyRegex != "" {
			compiled, err := regexp.Compile(c.BodyRegex)
			if err != nil {
				log.Printf("uptime: check %s: body regex %q: %v", c.Key, c.BodyRegex, err)
			}
			re = compiled
		}
		if old, ok := p.checks[c.Key]; ok {
			old.check, old.re = c, re
			next[c.Key] = old
			continue
		}
		next[c.Key] = &scheduled{check: c, re: re, next: p.now()}
	}
	p.checks = next
}

// due marks and returns the checks whose time has come.
func (p *Prober) due() []*scheduled {
	p.mu.Lock()
	defer p.mu.Unlock()

	now := p.now()
	var out []*scheduled
	for _, s := range p.checks {
		if s.running || now.Before(s.next) {
			continue
		}
		s.running = true
		s.next = now.Add(interval(s.check))
		out = append(out, s)
	}
	return out
}

func (p *Prober) done(s *scheduled, r Result) {
	p.mu.Lock()
	s.running = false
	p.pending = append(p.pending, r)
	full := len(p.pending) >= reportBatch
	p.mu.Unlock()

	if full {
		p.report(context.Background())
	}
}

// report sends the collected results; they are kept for the next attempt when RunR is unreachable.
func (p *Prober) report(ctx context.Context) {
	p.mu.Lock()
	batch := p.pending
	p.pending = nil
	p.mu.Unlock()
	if len(batch) == 0 {
		return
	}

	body, _ := json.Marshal(map[string]any{"results": batch})
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, p.endpoint("uptime-results"), bytes.NewReader(body))
	if err == nil {
		req.Header.Set("Content-Type", "application/json")
		p.auth(req)
		var resp *http.Response
		resp, err = p.client.Do(req)
		if err == nil {
			resp.Body.Close()
			if resp.StatusCode < 300 {
				return
			}
			err = fmt.Errorf("RunR answered %d", resp.StatusCode)
		}
	}

	log.Printf("uptime: reporting %d result(s) failed: %v", len(batch), err)
	p.mu.Lock()
	// keep the newest results only: a long outage of RunR must not grow the queue without bound
	p.pending = append(batch, p.pending...)
	if len(p.pending) > 10*reportBatch {
		p.pending = p.pending[len(p.pending)-10*reportBatch:]
	}
	p.mu.Unlock()
}

func (p *Prober) endpoint(name string) string {
	return p.apiBase + "/api/internal/observability/agent/" + url.PathEscape(p.regionID) + "/" + name
}

func (p *Prober) auth(req *http.Request) {
	req.Header.Set("Authorization", "Bearer "+p.token)
	req.Header.Set("X-Region-ID", p.regionID)
}

func interval(c Check) time.Duration {
	d := time.Duration(c.IntervalSec) * time.Second
	if d < minInterval {
		return minInterval
	}
	return d
}

// Probe runs one check: the request, the expected status and the body regex.
func Probe(ctx context.Context, c Check, re *regexp.Regexp) Result {
	timeout := time.Duration(c.TimeoutSec) * time.Second
	if timeout <= 0 {
		timeout = defaultTimeout
	}
	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	start := time.Now()
	res := Result{Key: c.Key, CheckedAt: start.UTC().Format(time.RFC3339Nano)}

	method := strings.ToUpper(c.Method)
	if method == "" {
		method = http.MethodGet
	}
	req, err := http.NewRequestWithContext(ctx, method, c.URL, nil)
	if err != nil {
		res.Error = "invalid request: " + err.Error()
		return res
	}
	req.Header.Set("User-Agent", "runr-uptime/1 (region agent)")
	for k, v := range c.Headers {
		req.Header.Set(k, v)
	}

	client := &http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	resp, err := client.Do(req)
	res.ResponseMs = time.Since(start).Milliseconds()
	if err != nil {
		if ctx.Err() != nil {
			res.Error = fmt.Sprintf("timed out after %s", timeout)
		} else {
			res.Error = err.Error()
		}
		return res
	}
	defer resp.Body.Close()
	res.StatusCode = resp.StatusCode

	body, _ := io.ReadAll(io.LimitReader(resp.Body, maxBodyRead))
	res.ResponseMs = time.Since(start).Milliseconds()

	if !StatusMatches(c.ExpectedStatus, resp.StatusCode) {
		res.Error = fmt.Sprintf("status %d, expected %s", resp.StatusCode, expectedLabel(c.ExpectedStatus))
		return res
	}
	if c.BodyRegex != "" {
		if re == nil {
			res.Error = "invalid body regex"
			return res
		}
		if !re.Match(body) {
			res.Error = "body does not match " + c.BodyRegex
			return res
		}
	}

	res.OK = true
	return res
}

// StatusMatches checks a status against "200", "200,204", "200-399" or "2xx" (comma-separated
// lists of those); empty means 200-399.
func StatusMatches(expected string, code int) bool {
	expected = strings.TrimSpace(expected)
	if expected == "" {
		return code >= 200 && code < 400
	}
	for _, part := range strings.Split(expected, ",") {
		part = strings.ToLower(strings.TrimSpace(part))
		switch {
		case len(part) == 3 && strings.HasSuffix(part, "xx") && part[0] >= '1' && part[0] <= '5':
			if code/100 == int(part[0]-'0') {
				return true
			}
		case strings.Contains(part, "-"):
			lo, hi, _ := strings.Cut(part, "-")
			l, err1 := strconv.Atoi(strings.TrimSpace(lo))
			h, err2 := strconv.Atoi(strings.TrimSpace(hi))
			if err1 == nil && err2 == nil && code >= l && code <= h {
				return true
			}
		default:
			if n, err := strconv.Atoi(part); err == nil && n == code {
				return true
			}
		}
	}
	return false
}

func expectedLabel(expected string) string {
	if strings.TrimSpace(expected) == "" {
		return "200-399"
	}
	return expected
}

// DeriveAPIBase turns the agent's WebSocket URL (ws(s)://host[/prefix]/ws/agent/<id>) into
// RunR's HTTP base URL (http(s)://host[/prefix]).
func DeriveAPIBase(wsURL string) string {
	u, err := url.Parse(strings.TrimSpace(wsURL))
	if err != nil || u.Host == "" {
		return ""
	}
	switch u.Scheme {
	case "wss":
		u.Scheme = "https"
	case "ws":
		u.Scheme = "http"
	}
	if i := strings.Index(u.Path, "/ws/agent"); i >= 0 {
		u.Path = u.Path[:i]
	}
	u.RawQuery, u.Fragment = "", ""
	return strings.TrimRight(u.String(), "/")
}
