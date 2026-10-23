package main

import (
	"context"
	"log"
	"os"
	"os/signal"
	"sync"
	"syscall"
	"time"

	"github.com/brf-tech/runr/runtime/agent/internal/authz"
	"github.com/brf-tech/runr/runtime/agent/internal/collector"
	"github.com/brf-tech/runr/runtime/agent/internal/globalhealth"
	"github.com/brf-tech/runr/runtime/agent/internal/session"
	"github.com/brf-tech/runr/runtime/agent/internal/uptime"
	"github.com/brf-tech/runr/runtime/agent/internal/watcher"
	"github.com/brf-tech/runr/runtime/agent/internal/ws"
	"k8s.io/client-go/dynamic"
	"k8s.io/client-go/rest"
)

func main() {
	// The ForwardAuth of the region Traefik's private entry point (runr-internal-auth Deployment)
	if os.Getenv("RUNR_AGENT_MODE") == "authz" {
		runAuthz()
		return
	}

	wsURL := os.Getenv("RUNR_WS_URL")
	regionID := os.Getenv("RUNR_REGION_ID")
	token := os.Getenv("RUNR_AGENT_TOKEN")
	prometheusURL := os.Getenv("PROMETHEUS_URL")
	namespace := os.Getenv("PROJECT_NAMESPACE")

	if wsURL == "" || regionID == "" || token == "" {
		log.Fatal("RUNR_WS_URL, RUNR_REGION_ID, RUNR_AGENT_TOKEN required")
	}
	if prometheusURL == "" {
		prometheusURL = "http://prometheus-operated.observability:9090"
	}
	if namespace == "" {
		namespace = "runr"
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM)

	client := ws.NewClient(wsURL, regionID, token)
	mc := collector.New(prometheusURL, namespace)
	st := session.NewTracker(namespace)
	ew := watcher.New(namespace)

	var wg sync.WaitGroup

	// WebSocket connection
	wg.Add(1)
	go func() {
		defer wg.Done()
		client.Run(ctx)
	}()

	// Metrics collector (every 5s)
	wg.Add(1)
	go func() {
		defer wg.Done()
		ticker := time.NewTicker(5 * time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				metrics := mc.Collect(ctx)
				for _, m := range metrics {
					client.Send(m)
				}
			}
		}
	}()

	// Session tracker
	wg.Add(1)
	go func() {
		defer wg.Done()
		st.Run(ctx, func(event map[string]interface{}) {
			client.Send(event)
		})
	}()

	// Event watcher
	wg.Add(1)
	go func() {
		defer wg.Done()
		ew.Run(ctx, func(event map[string]interface{}) {
			client.Send(event)
		})
	}()

	// Uptime checks run from this region (RUNR_API_URL, else derived from RUNR_WS_URL)
	if apiBase := firstNonEmpty(os.Getenv("RUNR_API_URL"), uptime.DeriveAPIBase(wsURL)); apiBase != "" && os.Getenv("RUNR_UPTIME_DISABLED") != "true" {
		wg.Add(1)
		go func() {
			defer wg.Done()
			uptime.New(apiBase, regionID, token).Run(ctx)
		}()
	}

	// Health of the region's copies of global projects, checked by the global zone's PowerDNS
	// servers through the region's Traefik (RUNR_GLOBAL_HEALTH_ADDR; "off" or "" disables)
	if addr := globalHealthAddr(); addr != "" {
		if hs := newGlobalHealth(namespace); hs != nil {
			wg.Add(1)
			go func() {
				defer wg.Done()
				if err := hs.Run(ctx, addr); err != nil {
					log.Printf("global health: server on %s stopped: %v", addr, err)
				}
			}()
		}
	}

	log.Printf("runr-agent started: region=%s namespace=%s", regionID, namespace)

	<-sigCh
	log.Println("Shutting down...")
	cancel()
	wg.Wait()
}

// runAuthz serves the internal network's ForwardAuth (internal/authz): the policy comes from the
// Knative Services' annotations and the ConfigMap / Secret runr-internal-network of
// RUNR_INTERNAL_NAMESPACE (the Serving namespace).
func runAuthz() {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM)
	go func() {
		<-sigCh
		cancel()
	}()

	namespace := firstNonEmpty(os.Getenv("RUNR_INTERNAL_NAMESPACE"), "knative-serving")
	addr := firstNonEmpty(os.Getenv("RUNR_AUTHZ_ADDR"), ":8090")
	interval := 10 * time.Second
	if d, err := time.ParseDuration(os.Getenv("RUNR_AUTHZ_RELOAD")); err == nil && d > 0 {
		interval = d
	}
	if err := authz.Serve(ctx, namespace, addr, interval); err != nil {
		log.Fatalf("authz: %v", err)
	}
}

// globalHealthAddr is the listen address of the global-project health checks:
// RUNR_GLOBAL_HEALTH_ADDR, ":8086" when unset, "" (disabled) for "off" or an empty value.
func globalHealthAddr() string {
	addr, set := os.LookupEnv("RUNR_GLOBAL_HEALTH_ADDR")
	if !set {
		return ":8086"
	}
	if addr == "off" {
		return ""
	}
	return addr
}

// newGlobalHealth builds the health server on the in-cluster config; nil (logged) outside a
// cluster or without a client: the agent runs on without it.
func newGlobalHealth(namespace string) *globalhealth.Server {
	config, err := rest.InClusterConfig()
	if err != nil {
		log.Printf("global health: no in-cluster config, health checks of global projects are off: %v", err)
		return nil
	}
	client, err := dynamic.NewForConfig(config)
	if err != nil {
		log.Printf("global health: cannot create a client, health checks of global projects are off: %v", err)
		return nil
	}
	return globalhealth.New(client, namespace)
}

func firstNonEmpty(values ...string) string {
	for _, v := range values {
		if v != "" {
			return v
		}
	}
	return ""
}
