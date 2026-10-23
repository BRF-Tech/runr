package gateway

import (
	"context"
	"encoding/json"
	"net/http"
	"time"

	"github.com/brf-tech/runr/runtime/queue-consumer/internal/httpx"
)

// Serve answers the gateway's HTTP endpoints on addr (":8081") until ctx ends.
func (g *Gateway) Serve(ctx context.Context, addr string) error {
	return httpx.Serve(ctx, addr, g.Handler())
}

// Handler serves
//
//	GET  /healthz                  "ok" (liveness and readiness probe)
//	GET  /stats                    the triggers' counters as JSON (+ the queue consumer's keys in legacy env mode)
//	GET  /metrics                  the same counters in the Prometheus text format
//	POST /v1/nudge?trigger=<name>  wake a trigger's source (all triggers without ?trigger)
func (g *Gateway) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		w.Write([]byte("ok"))
	})
	mux.HandleFunc("/stats", func(w http.ResponseWriter, r *http.Request) {
		rctx, cancel := context.WithTimeout(r.Context(), 3*time.Second)
		defer cancel()
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(g.stats(rctx))
	})
	mux.HandleFunc("/metrics", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/plain; version=0.0.4; charset=utf-8")
		g.writeMetrics(w)
	})
	mux.HandleFunc("/v1/nudge", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.Method != http.MethodPost {
			w.Header().Set("Allow", http.MethodPost)
			w.WriteHeader(http.StatusMethodNotAllowed)
			json.NewEncoder(w).Encode(map[string]string{"error": "use POST"})
			return
		}
		name := r.URL.Query().Get("trigger")
		if !g.Nudge(name) {
			w.WriteHeader(http.StatusNotFound)
			json.NewEncoder(w).Encode(map[string]string{"error": "unknown trigger"})
			return
		}
		w.WriteHeader(http.StatusAccepted)
		json.NewEncoder(w).Encode(map[string]string{"nudged": name})
	})
	return mux
}

// stats is the GET /stats answer: {"triggers": [...]} with each trigger's counters, the keys of
// its source's Stats and its dead letter's dlq_length. In legacy env mode it also carries the
// queue consumer's top-level keys (stream, group, consumer, dlq_stream, max_retries, delivered,
// failed, retried, dead_lettered, pending, dlq_length).
func (g *Gateway) stats(ctx context.Context) map[string]any {
	entries := make([]map[string]any, 0, len(g.triggers))
	for i, ts := range g.TriggerStats() {
		t := g.triggers[i]
		e := map[string]any{
			"name":            ts.Name,
			"type":            ts.Type,
			"kind":            ts.Kind,
			"delivered":       ts.Delivered,
			"failed":          ts.Failed,
			"retried":         ts.Retried,
			"dead_lettered":   ts.DeadLettered,
			"in_flight":       ts.InFlight,
			"source_restarts": ts.Restarts,
		}
		if s, ok := t.src.(interface {
			Stats(context.Context) map[string]any
		}); ok {
			for k, v := range s.Stats(ctx) {
				if _, taken := e[k]; !taken {
					e[k] = v
				}
			}
		}
		if dl, ok := t.dl.(DeadLetterLen); ok {
			if n, err := dl.Len(ctx); err == nil {
				e["dlq_length"] = n
			}
		}
		entries = append(entries, e)
	}

	out := map[string]any{"triggers": entries}
	if g.legacy != nil && len(g.triggers) == 1 {
		e := entries[0]
		out["stream"] = g.legacy.Stream
		out["group"] = g.legacy.Group
		out["consumer"] = g.legacy.Consumer
		out["dlq_stream"] = g.legacy.DLQStream
		out["max_retries"] = g.triggers[0].maxRetries
		for _, k := range []string{"delivered", "failed", "retried", "dead_lettered", "pending", "dlq_length"} {
			if v, ok := e[k]; ok {
				out[k] = v
			}
		}
	}
	return out
}
