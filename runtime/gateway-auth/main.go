// runr-gateway-auth — the API product service of a RunR region (docs/api-product.md).
//
// The region Traefik asks it before every call of a gateway route that needs an API key or a JWT
// (forwardAuth, GET /auth/<route id>). It checks the key's hash or the token, the consumer and
// the key's scopes, applies the plan's rate limits and quotas (counters in the region's Redis,
// or in the pod without one), counts every decision per hour, consumer, key and route, and
// reports the counts to RunR. What to enforce comes from RunR (keys as SHA-256 hashes only),
// fetched every SYNC_SECONDS with an ETag. The routes' calls then pass its metering proxy
// (proxy.go), which records what the function answered: status, latency, bytes.
//
// Environment (Secret + Deployment runr-gateway-auth, App\Services\Manifests\GatewayAuthManifests):
//
//	RUNR_API_URL    RunR's base URL as the region reaches it
//	RUNR_REGION_ID  the region's id
//	RUNR_TOKEN      the region's gateway-auth token (also the key of the proxy's tickets)
//	REDIS_URL       redis[s]://[:password@]host:port (optional)
//	LISTEN          listen address (default :8080)
//	PROXY_LISTEN    the metering proxy's listen address (default :8081)
//	UPSTREAM_URL    where the proxy hands calls on: the region Traefik's web entry point
//	                (no proxy without it)
//	SYNC_SECONDS    config sync interval (default 10)
//	FLUSH_SECONDS   usage report interval (default 30)
//	POD_NAME        part of the usage batch ids
package main

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"net/url"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/redis/go-redis/v9"
)

var version = "dev"

type settings struct {
	apiURL   string
	regionID string
	token    string
	redisURL string
	listen   string
	proxy    string
	upstream *url.URL
	sync     time.Duration
	flush    time.Duration
	pod      string
}

func loadSettings(getenv func(string) string) (settings, error) {
	s := settings{
		apiURL:   strings.TrimRight(getenv("RUNR_API_URL"), "/"),
		regionID: getenv("RUNR_REGION_ID"),
		token:    getenv("RUNR_TOKEN"),
		redisURL: getenv("REDIS_URL"),
		listen:   getenv("LISTEN"),
		proxy:    getenv("PROXY_LISTEN"),
		sync:     seconds(getenv("SYNC_SECONDS"), 10),
		flush:    seconds(getenv("FLUSH_SECONDS"), 30),
		pod:      getenv("POD_NAME"),
	}
	if s.apiURL == "" || s.regionID == "" || s.token == "" {
		return s, errors.New("RUNR_API_URL, RUNR_REGION_ID and RUNR_TOKEN are required")
	}
	if s.listen == "" {
		s.listen = ":8080"
	}
	if s.proxy == "" {
		s.proxy = ":8081"
	}
	if raw := strings.TrimSpace(getenv("UPSTREAM_URL")); raw != "" {
		u, err := url.Parse(raw)
		if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
			return s, errors.New("UPSTREAM_URL must be an http(s) URL")
		}
		s.upstream = u
	}
	if s.pod == "" {
		s.pod, _ = os.Hostname()
	}
	return s, nil
}

func seconds(v string, def int) time.Duration {
	n, err := strconv.Atoi(v)
	if err != nil || n <= 0 {
		n = def
	}
	return time.Duration(n) * time.Second
}

func main() {
	logger := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: slog.LevelInfo}))
	cfg, err := loadSettings(os.Getenv)
	if err != nil {
		logger.Error("configuration", "error", err.Error())
		os.Exit(2)
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()

	client := &http.Client{Timeout: 30 * time.Second}
	base := cfg.apiURL + "/api/internal/gateway-auth/" + cfg.regionID

	syncer := &Syncer{URL: base + "/config", Token: cfg.token, Client: client}
	recorder := NewRecorder(base+"/usage", cfg.token, cfg.pod, client)

	var limiter Limiter = NewMemoryLimiter()
	if cfg.redisURL != "" {
		opts, err := redis.ParseURL(cfg.redisURL)
		if err != nil {
			logger.Error("REDIS_URL is not valid; counting in the pod", "error", err.Error())
		} else {
			rl := NewRedisLimiter(redis.NewClient(opts))
			var once sync.Once
			rl.OnError = func(err error) {
				// once per pod life is enough to see it; the fallback keeps counting
				once.Do(func() { logger.Warn("Redis unavailable, counting in the pod", "error", err.Error()) })
			}
			limiter = rl
		}
	}

	tickets := NewTickets(cfg.token)
	auth := &Authorizer{
		Snapshots: syncer,
		Limiter:   limiter,
		JWKS:      NewJWKSCache(&http.Client{Timeout: 10 * time.Second}),
		Usage:     recorder,
		Logger:    logger,
	}
	if cfg.upstream != nil {
		// tickets only where a proxy takes them (a route asks with ?meter=1)
		auth.Tickets = tickets
	}

	mux := http.NewServeMux()
	mux.Handle("/auth/", auth)
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, _ *http.Request) { w.WriteHeader(http.StatusOK) })
	mux.HandleFunc("/readyz", func(w http.ResponseWriter, _ *http.Request) {
		if !syncer.Ready() {
			http.Error(w, "no configuration yet", http.StatusServiceUnavailable)
			return
		}
		w.WriteHeader(http.StatusOK)
	})

	server := &http.Server{
		Addr:              cfg.listen,
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       10 * time.Second,
		WriteTimeout:      15 * time.Second,
		IdleTimeout:       120 * time.Second,
		MaxHeaderBytes:    64 << 10,
	}

	// The metering proxy: calls run as long as the function takes (the region Traefik's timeouts
	// apply on both sides), so no read / write timeout of its own
	var proxyServer *http.Server
	if cfg.upstream != nil {
		proxyServer = &http.Server{
			Addr:              cfg.proxy,
			Handler:           NewMeter(cfg.upstream, tickets, recorder, logger),
			ReadHeaderTimeout: 10 * time.Second,
			IdleTimeout:       120 * time.Second,
			MaxHeaderBytes:    1 << 20,
		}
	}

	// the usage of calls still running at shutdown is reported after they end
	reportCtx, stopReports := context.WithCancel(context.Background())
	var wg sync.WaitGroup
	wg.Add(2)
	go func() {
		defer wg.Done()
		syncer.Run(ctx, cfg.sync,
			func(err error) { logger.Warn("config sync failed", "error", err.Error()) },
			func(s *Snapshot) { logger.Info("configuration loaded", "version", s.Version) })
	}()
	go func() {
		defer wg.Done()
		recorder.Run(reportCtx, cfg.flush, func(err error) { logger.Warn("usage report failed", "error", err.Error()) })
	}()

	serverDone := make(chan struct{})
	go func() {
		defer close(serverDone)
		<-ctx.Done()
		shutdown, cancel := context.WithTimeout(context.Background(), 25*time.Second)
		defer cancel()
		var down sync.WaitGroup
		for _, s := range []*http.Server{server, proxyServer} {
			if s != nil {
				down.Add(1)
				go func() { defer down.Done(); _ = s.Shutdown(shutdown) }()
			}
		}
		down.Wait()
	}()

	if proxyServer != nil {
		go func() {
			if err := proxyServer.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
				logger.Error("proxy server", "error", err.Error())
				os.Exit(1)
			}
		}()
	}

	logger.Info("gateway-auth listening", "version", version, "addr", cfg.listen, "proxy", proxyAddr(proxyServer), "region", cfg.regionID, "redis", cfg.redisURL != "")
	if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		logger.Error("server", "error", err.Error())
		os.Exit(1)
	}
	<-serverDone
	stopReports()
	wg.Wait()
}

func proxyAddr(s *http.Server) string {
	if s == nil {
		return "off"
	}
	return s.Addr
}
