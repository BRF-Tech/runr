package main

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync"
	"sync/atomic"
	"testing"
)

type usageSink struct {
	mu      sync.Mutex
	reports []usageReport
	fail    atomic.Bool
}

func (u *usageSink) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if r.Header.Get("Authorization") != "Bearer secret" {
		w.WriteHeader(http.StatusUnauthorized)
		return
	}
	var report usageReport
	_ = json.NewDecoder(r.Body).Decode(&report)
	u.mu.Lock()
	u.reports = append(u.reports, report)
	u.mu.Unlock()
	if u.fail.Load() {
		w.WriteHeader(http.StatusBadGateway)
		return
	}
	w.WriteHeader(http.StatusAccepted)
}

func TestFlushReportsTheCountsOnce(t *testing.T) {
	sink := &usageSink{}
	srv := httptest.NewServer(sink)
	defer srv.Close()
	rec := NewRecorder(srv.URL, "secret", "pod-a", srv.Client())

	k := UsageKey{Scope: "_", GatewayID: gwA, RouteID: routeK, ConsumerID: "c", KeyID: "k", Hour: "2026-10-21T10:00:00Z"}
	rec.Add(k, CounterRequests)
	rec.Add(k, CounterRequests)
	rec.Add(k, CounterRateLimited)
	rec.Add(UsageKey{Scope: "_", GatewayID: gwA, RouteID: routeK, Hour: k.Hour}, CounterUnauthorized)

	if err := rec.Flush(context.Background()); err != nil {
		t.Fatal(err)
	}
	if err := rec.Flush(context.Background()); err != nil {
		t.Fatal(err)
	}
	if len(sink.reports) != 1 || len(sink.reports[0].Entries) != 2 {
		t.Fatalf("reports %+v", sink.reports)
	}
	for _, e := range sink.reports[0].Entries {
		if e.ConsumerID != nil && (e.Requests != 2 || e.RateLimited != 1 || *e.KeyID != "k") {
			t.Errorf("consumer row %+v", e)
		}
		if e.ConsumerID == nil && (e.Unauthorized != 1 || e.KeyID != nil || *e.RouteID != routeK) {
			t.Errorf("anonymous row %+v", e)
		}
	}
	if rec.Pending() != 0 {
		t.Errorf("%d rows left", rec.Pending())
	}
}

func TestFailedReportIsSentAgainWithTheSameBatch(t *testing.T) {
	sink := &usageSink{}
	srv := httptest.NewServer(sink)
	defer srv.Close()
	rec := NewRecorder(srv.URL, "secret", "pod-a", srv.Client())
	k := UsageKey{Scope: "_", GatewayID: gwA, Hour: "2026-10-21T10:00:00Z"}

	rec.Add(k, CounterRequests)
	sink.fail.Store(true)
	if err := rec.Flush(context.Background()); err == nil {
		t.Fatal("a 502 is an error")
	}
	rec.Add(k, CounterRequests) // counted while the first report waits

	sink.fail.Store(false)
	if err := rec.Flush(context.Background()); err != nil {
		t.Fatal(err)
	}

	if len(sink.reports) != 3 {
		t.Fatalf("%d reports, want failed + retried + new", len(sink.reports))
	}
	if sink.reports[0].Batch != sink.reports[1].Batch {
		t.Error("the retry must keep the batch id (RunR applies a batch once)")
	}
	if sink.reports[2].Batch == sink.reports[1].Batch || sink.reports[1].Entries[0].Requests != 1 || sink.reports[2].Entries[0].Requests != 1 {
		t.Errorf("the call counted meanwhile goes in a new batch: %+v", sink.reports)
	}
}
