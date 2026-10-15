package main

import (
	"context"
	"log"
	"os"
	"os/signal"
	"sync"
	"syscall"
	"time"

	"github.com/runrsh/core/runtime/agent/internal/collector"
	"github.com/runrsh/core/runtime/agent/internal/session"
	"github.com/runrsh/core/runtime/agent/internal/uptime"
	"github.com/runrsh/core/runtime/agent/internal/watcher"
	"github.com/runrsh/core/runtime/agent/internal/ws"
)

func main() {
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

	log.Printf("runr-agent started: region=%s namespace=%s", regionID, namespace)

	<-sigCh
	log.Println("Shutting down...")
	cancel()
	wg.Wait()
}

func firstNonEmpty(values ...string) string {
	for _, v := range values {
		if v != "" {
			return v
		}
	}
	return ""
}
