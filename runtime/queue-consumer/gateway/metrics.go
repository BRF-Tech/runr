package gateway

import (
	"fmt"
	"io"
	"strconv"
	"strings"
	"sync/atomic"
	"time"
)

// triggerMetrics are the counters of one trigger.
type triggerMetrics struct {
	delivered    atomic.Int64 // events delivered with a 2xx
	failed       atomic.Int64 // failed attempts (shutdown interruptions excluded)
	retried      atomic.Int64 // attempts after the first, prior attempts counted
	deadLettered atomic.Int64 // events written to the dead letter
	inFlight     atomic.Int64 // attempts in progress (HTTP requests)
	restarts     atomic.Int64 // source restarts after an error
	attemptNanos atomic.Int64 // duration of all attempts
	attempts     atomic.Int64 // attempts made
}

func (m *triggerMetrics) observe(d time.Duration) {
	m.attemptNanos.Add(int64(d))
	m.attempts.Add(1)
}

// TriggerStats is a snapshot of a trigger's counters, as GET /stats reports them.
type TriggerStats struct {
	Name         string `json:"name"`
	Type         string `json:"type"`
	Kind         string `json:"kind"`
	Delivered    int64  `json:"delivered"`
	Failed       int64  `json:"failed"`
	Retried      int64  `json:"retried"`
	DeadLettered int64  `json:"dead_lettered"`
	InFlight     int64  `json:"in_flight"`
	Restarts     int64  `json:"source_restarts"`
}

// TriggerStats snapshots the counters of every trigger, in configuration order.
func (g *Gateway) TriggerStats() []TriggerStats {
	out := make([]TriggerStats, 0, len(g.triggers))
	for _, t := range g.triggers {
		out = append(out, TriggerStats{
			Name:         t.name,
			Type:         t.typ,
			Kind:         t.kind,
			Delivered:    t.m.delivered.Load(),
			Failed:       t.m.failed.Load(),
			Retried:      t.m.retried.Load(),
			DeadLettered: t.m.deadLettered.Load(),
			InFlight:     t.m.inFlight.Load(),
			Restarts:     t.m.restarts.Load(),
		})
	}
	return out
}

// writeMetrics writes the Prometheus text exposition format (version 0.0.4).
func (g *Gateway) writeMetrics(w io.Writer) {
	family := func(name, typ, help string, line func(t *trigger, labels string)) {
		fmt.Fprintf(w, "# HELP %s %s\n# TYPE %s %s\n", name, help, name, typ)
		for _, t := range g.triggers {
			line(t, `trigger="`+escapeLabel(t.name)+`",type="`+escapeLabel(t.typ)+`"`)
		}
	}
	family("runr_gateway_deliveries_total", "counter", "Events settled, by outcome.", func(t *trigger, l string) {
		fmt.Fprintf(w, "runr_gateway_deliveries_total{%s,outcome=\"delivered\"} %d\n", l, t.m.delivered.Load())
		fmt.Fprintf(w, "runr_gateway_deliveries_total{%s,outcome=\"dead_lettered\"} %d\n", l, t.m.deadLettered.Load())
	})
	family("runr_gateway_failed_attempts_total", "counter", "Failed delivery attempts.", func(t *trigger, l string) {
		fmt.Fprintf(w, "runr_gateway_failed_attempts_total{%s} %d\n", l, t.m.failed.Load())
	})
	family("runr_gateway_retries_total", "counter", "Delivery attempts after the first.", func(t *trigger, l string) {
		fmt.Fprintf(w, "runr_gateway_retries_total{%s} %d\n", l, t.m.retried.Load())
	})
	family("runr_gateway_in_flight", "gauge", "Delivery attempts in progress.", func(t *trigger, l string) {
		fmt.Fprintf(w, "runr_gateway_in_flight{%s} %d\n", l, t.m.inFlight.Load())
	})
	family("runr_gateway_delivery_seconds", "summary", "Duration of delivery attempts.", func(t *trigger, l string) {
		secs := time.Duration(t.m.attemptNanos.Load()).Seconds()
		fmt.Fprintf(w, "runr_gateway_delivery_seconds_sum{%s} %s\n", l, strconv.FormatFloat(secs, 'g', -1, 64))
		fmt.Fprintf(w, "runr_gateway_delivery_seconds_count{%s} %d\n", l, t.m.attempts.Load())
	})
	family("runr_gateway_source_restarts_total", "counter", "Restarts of the trigger's source after an error.", func(t *trigger, l string) {
		fmt.Fprintf(w, "runr_gateway_source_restarts_total{%s} %d\n", l, t.m.restarts.Load())
	})
}

var labelEscaper = strings.NewReplacer(`\`, `\\`, `"`, `\"`, "\n", `\n`)

func escapeLabel(v string) string { return labelEscaper.Replace(v) }
