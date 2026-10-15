package main

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
	"os"
	"os/signal"
	"regexp"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/redis/go-redis/v9"
)

const (
	queueKey = "sentry:queue"
	// Largest envelope accepted; a bigger one is refused (413), never forwarded cut off.
	maxEnvelopeBytes = 10 << 20
	// RETRY_INTERVAL when unset or invalid.
	defaultRetryDelay = 5 * time.Second
)

type queueItem struct {
	URL     string            `json:"url"`
	Headers map[string]string `json:"headers"`
	Body    string            `json:"body"` // base64
}

var (
	phpPathRe   = regexp.MustCompile(`/api/(\d+)/`)
	projectIDRe = regexp.MustCompile(`^\d+$`)

	errNoDSN = errors.New("no Sentry DSN configured")
	errEmpty = errors.New("queue empty")
)

// queue is the persistent FIFO of envelopes waiting to be forwarded.
type queue interface {
	Push(ctx context.Context, item []byte) error      // at the tail
	PushFront(ctx context.Context, item []byte) error // back at the head, after a failed forward
	Pop(ctx context.Context) ([]byte, error)          // errEmpty when there is none
	Len(ctx context.Context) (int64, error)
}

// redisQueue is the queue in a Redis list (survives restarts of the proxy).
type redisQueue struct {
	rdb *redis.Client
	key string
}

func (q redisQueue) Push(ctx context.Context, item []byte) error {
	return q.rdb.RPush(ctx, q.key, item).Err()
}

func (q redisQueue) PushFront(ctx context.Context, item []byte) error {
	return q.rdb.LPush(ctx, q.key, item).Err()
}

func (q redisQueue) Pop(ctx context.Context) ([]byte, error) {
	raw, err := q.rdb.LPop(ctx, q.key).Bytes()
	if errors.Is(err, redis.Nil) {
		return nil, errEmpty
	}
	return raw, err
}

func (q redisQueue) Len(ctx context.Context) (int64, error) {
	return q.rdb.LLen(ctx, q.key).Result()
}

// target is a configured Sentry ingest base: scheme://host[/path prefix] of a DSN.
type target struct {
	scheme string
	host   string
	prefix string
}

func (t target) ingestURL(project string) string {
	return fmt.Sprintf("%s://%s%s/api/%s/envelope/", t.scheme, t.host, t.prefix, project)
}

// parseDSN splits a Sentry DSN ({scheme}://{key}@{host}{/prefix}/{project}) into its ingest
// base and project id.
func parseDSN(dsn string) (target, string, error) {
	u, err := url.Parse(strings.TrimSpace(dsn))
	if err != nil {
		return target{}, "", fmt.Errorf("invalid dsn")
	}
	if (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return target{}, "", fmt.Errorf("invalid dsn: needs an http(s) host")
	}
	path := strings.TrimRight(u.Path, "/")
	i := strings.LastIndex(path, "/")
	project := path[i+1:]
	if !projectIDRe.MatchString(project) {
		return target{}, "", fmt.Errorf("invalid dsn: no project id")
	}
	return target{scheme: u.Scheme, host: u.Host, prefix: path[:max(i, 0)]}, project, nil
}

// targetsFromEnv: the configured DSNs, the API's (SENTRY_LARAVEL_DSN) first.
func targetsFromEnv(getenv func(string) string) []target {
	var out []target
	for _, key := range []string{"SENTRY_LARAVEL_DSN", "VITE_SENTRY_DSN"} {
		dsn := getenv(key)
		if dsn == "" {
			continue
		}
		t, _, err := parseDSN(dsn)
		if err != nil {
			log.Printf("sentry-proxy: %s ignored: %v", key, err)
			continue
		}
		dup := false
		for _, o := range out {
			dup = dup || o == t
		}
		if !dup {
			out = append(out, t)
		}
	}
	return out
}

// retryDelayFromEnv: RETRY_INTERVAL in milliseconds; unset, invalid or not positive → 5 s
// (0 would retry a failing Sentry in a busy loop).
func retryDelayFromEnv(getenv func(string) string) time.Duration {
	ms, err := strconv.Atoi(strings.TrimSpace(getenv("RETRY_INTERVAL")))
	if err != nil || ms <= 0 {
		return defaultRetryDelay
	}
	return time.Duration(ms) * time.Millisecond
}

type proxy struct {
	queue      queue
	targets    []target
	client     *http.Client
	retryDelay time.Duration // between attempts while Sentry fails or is unreachable
	idleDelay  time.Duration // between polls of an empty queue
}

func main() {
	redisURL := getenv("REDIS_URL", "redis://redis:6379")
	port := getenv("PORT", "8080")

	opt, err := redis.ParseURL(redisURL)
	if err != nil {
		log.Fatalf("invalid REDIS_URL: %v", err)
	}

	p := &proxy{
		queue:      redisQueue{rdb: redis.NewClient(opt), key: queueKey},
		targets:    targetsFromEnv(os.Getenv),
		client:     &http.Client{Timeout: 5 * time.Second},
		retryDelay: retryDelayFromEnv(os.Getenv),
		idleDelay:  time.Second,
	}
	if len(p.targets) > 0 {
		log.Printf("sentry-proxy: forwarding to %s", p.targets[0].host)
	} else {
		log.Println("sentry-proxy: no DSN configured — envelopes are refused (503)")
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	go p.drain(ctx)

	srv := &http.Server{Addr: ":" + port, Handler: p.routes(), ReadHeaderTimeout: 10 * time.Second}
	go func() {
		<-ctx.Done()
		shutdown, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = srv.Shutdown(shutdown)
	}()

	log.Printf("sentry-proxy :%s", port)
	if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Fatal(err)
	}
}

func (p *proxy) routes() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", p.handleHealth)
	mux.HandleFunc("POST /{path...}", p.handleEnvelope)
	return withCORS(mux)
}

// ── Handlers ──────────────────────────────────────────────────────────────────

func (p *proxy) handleHealth(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	length, err := p.queue.Len(r.Context())
	if err != nil {
		w.WriteHeader(http.StatusServiceUnavailable)
		_ = json.NewEncoder(w).Encode(map[string]any{"ok": false, "error": "queue unavailable"})
		return
	}
	_ = json.NewEncoder(w).Encode(map[string]any{"ok": true, "queueLength": length})
}

func (p *proxy) handleEnvelope(w http.ResponseWriter, r *http.Request) {
	body, err := io.ReadAll(http.MaxBytesReader(w, r.Body, maxEnvelopeBytes))
	if err != nil {
		var tooLarge *http.MaxBytesError
		if errors.As(err, &tooLarge) {
			http.Error(w, "envelope too large", http.StatusRequestEntityTooLarge)
			return
		}
		http.Error(w, "read error", http.StatusBadRequest)
		return
	}

	ct := r.Header.Get("Content-Type")
	if ct == "" {
		ct = "application/x-sentry-envelope"
	}

	ingestURL, err := p.resolveIngestURL(r.URL.Path, body)
	if errors.Is(err, errNoDSN) {
		http.Error(w, err.Error(), http.StatusServiceUnavailable)
		return
	}
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	item := queueItem{
		URL:     ingestURL,
		Headers: map[string]string{"content-type": ct},
		Body:    base64.StdEncoding.EncodeToString(body),
	}
	if auth := r.Header.Get("X-Sentry-Auth"); auth != "" {
		item.Headers["x-sentry-auth"] = auth
	}

	raw, _ := json.Marshal(item)
	if err := p.queue.Push(r.Context(), raw); err != nil {
		http.Error(w, "queue error", http.StatusInternalServerError)
		return
	}
	w.WriteHeader(http.StatusAccepted)
}

// resolveIngestURL determines the Sentry ingest endpoint. Envelopes only ever go to a configured
// Sentry host, never to one the request names.
//
//   - PHP SDK: path matches /api/{id}/ → the first configured DSN's host
//   - JS SDK:  first envelope line contains {"dsn":"..."} → its project, on the configured DSN
//     with the same host (the first one otherwise)
func (p *proxy) resolveIngestURL(path string, body []byte) (string, error) {
	if len(p.targets) == 0 {
		return "", errNoDSN
	}
	if m := phpPathRe.FindStringSubmatch(path); m != nil {
		return p.targets[0].ingestURL(m[1]), nil
	}

	firstLine, _, _ := bytes.Cut(body, []byte("\n"))
	var hdr struct {
		DSN string `json:"dsn"`
	}
	if err := json.Unmarshal(firstLine, &hdr); err != nil || hdr.DSN == "" {
		return "", fmt.Errorf("missing dsn in envelope header")
	}
	dsn, project, err := parseDSN(hdr.DSN)
	if err != nil {
		return "", err
	}

	t := p.targets[0]
	for _, c := range p.targets {
		if strings.EqualFold(c.host, dsn.host) {
			t = c
			break
		}
	}
	return t.ingestURL(project), nil
}

// ── Drain worker ──────────────────────────────────────────────────────────────

// permanentError: the item can never be forwarded (dropped instead of retried).
type permanentError struct{ err error }

func (e *permanentError) Error() string { return e.err.Error() }

func (p *proxy) drain(ctx context.Context) {
	for ctx.Err() == nil {
		if !p.drainOnce(ctx) {
			sleep(ctx, p.idleDelay)
		}
	}
}

// drainOnce forwards the envelope at the head of the queue. False when there was none to
// forward (empty queue, Redis error): the caller waits before polling again. A failed forward
// puts the envelope back at the head and waits until Sentry answers again, so the order stays.
func (p *proxy) drainOnce(ctx context.Context) bool {
	raw, err := p.queue.Pop(ctx)
	if errors.Is(err, errEmpty) {
		return false
	}
	if err != nil {
		if ctx.Err() == nil {
			log.Printf("[drain] queue error: %v", err)
		}
		return false
	}

	var item queueItem
	if err := json.Unmarshal(raw, &item); err != nil {
		log.Printf("[drain] invalid item — dropping: %v", err)
		return true
	}
	body, err := base64.StdEncoding.DecodeString(item.Body)
	if err != nil {
		log.Printf("[drain] base64 decode error — dropping")
		return true
	}

	err = p.forward(ctx, item, body)
	if err == nil {
		return true
	}
	var permanent *permanentError
	if errors.As(err, &permanent) {
		log.Printf("[drain] %v — dropping", err)
		return true
	}

	log.Printf("[drain] forward failed: %v — pausing", err)
	// Also on shutdown (ctx cancelled): the envelope stays queued for the next start
	if err := p.queue.PushFront(context.WithoutCancel(ctx), raw); err != nil {
		log.Printf("[drain] cannot requeue, envelope lost: %v", err)
	}
	if sleep(ctx, p.retryDelay) {
		p.waitUntilReachable(ctx, item.URL)
	}
	return true
}

func (p *proxy) forward(ctx context.Context, item queueItem, body []byte) error {
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, item.URL, bytes.NewReader(body))
	if err != nil {
		return &permanentError{fmt.Errorf("invalid ingest url: %w", err)}
	}
	for k, v := range item.Headers {
		req.Header.Set(k, v)
	}

	resp, err := p.client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, resp.Body)

	if resp.StatusCode == http.StatusTooManyRequests {
		return nil // rate limited — drop silently
	}
	if resp.StatusCode >= 400 && resp.StatusCode < 500 {
		log.Printf("[drain] client error %d — dropping", resp.StatusCode)
		return nil
	}
	if !isSuccess(resp.StatusCode) {
		return fmt.Errorf("HTTP %d", resp.StatusCode)
	}
	return nil
}

// waitUntilReachable returns once the ingest host answers (any HTTP response) or ctx ends.
func (p *proxy) waitUntilReachable(ctx context.Context, rawURL string) {
	u, err := url.Parse(rawURL)
	if err != nil || u.Scheme == "" || u.Host == "" {
		return
	}
	probe := fmt.Sprintf("%s://%s", u.Scheme, u.Host)
	for {
		req, err := http.NewRequestWithContext(ctx, http.MethodHead, probe, nil)
		if err != nil {
			return
		}
		resp, err := p.client.Do(req)
		if err == nil {
			resp.Body.Close()
			log.Println("[drain] sentry reachable, resuming")
			return
		}
		log.Printf("[drain] sentry unreachable, retry in %v", p.retryDelay)
		if !sleep(ctx, p.retryDelay) {
			return
		}
	}
}

// ── Helpers ───────────────────────────────────────────────────────────────────

func withCORS(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Headers", "*")
		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		next.ServeHTTP(w, r)
	})
}

// sleep waits d; false when ctx ended first.
func sleep(ctx context.Context, d time.Duration) bool {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-t.C:
		return true
	}
}

func getenv(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func isSuccess(code int) bool {
	return code >= 200 && code < 300
}
