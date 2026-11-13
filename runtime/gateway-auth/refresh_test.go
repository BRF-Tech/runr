package main

import (
	"context"
	"net/http"
	"net/http/httptest"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

// The on-demand sync (Syncer.Refresh): a check that meets an unknown key or route asks RunR once
// more — one request at a time per pod, at most one per MinRefresh — and decides again on what it
// loaded.

// fakeClock is a settable clock for the spacing of the on-demand syncs.
type fakeClock struct {
	mu  sync.Mutex
	now time.Time
}

func (c *fakeClock) Now() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.now
}

func (c *fakeClock) Add(d time.Duration) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.now = c.now.Add(d)
}

// syncedAgainst: a Syncer loaded from api (the first sync done), on-demand syncs every 2 s.
func syncedAgainst(t *testing.T, api http.Handler) (*Syncer, *fakeClock) {
	t.Helper()
	srv := httptest.NewServer(api)
	t.Cleanup(srv.Close)
	clock := &fakeClock{now: time.Date(2026, 11, 13, 10, 0, 0, 0, time.UTC)}
	s := &Syncer{URL: srv.URL, Token: "secret", Client: srv.Client(), MinRefresh: 2 * time.Second, Now: clock.Now}
	if _, err := s.Sync(context.Background()); err != nil {
		t.Fatal(err)
	}
	return s, clock
}

// withKey is the fixture with one more key of consumer c-org, as RunR answers once it was created.
func withKey(key string) *Config {
	cfg := fixture("")
	cfg.Version = "v2"
	cfg.Keys = append(cfg.Keys, Key{ID: "k-new", Hash: hashOf(key), ConsumerID: "c-org"})
	return cfg
}

func TestANewKeyWorksAtOnceThroughTheOnDemandSync(t *testing.T) {
	api := &fakeAPI{}
	api.config.Store(fixture(""))
	api.etag.Store(`"v1"`)
	s, _ := syncedAgainst(t, api)
	a, rec := newAuthorizer(t, fixture(""))
	a.Snapshots, a.Refresher = s, s

	// created in RunR after this pod's last scheduled sync
	api.config.Store(withKey("rk_brand_new"))
	api.etag.Store(`"v2"`)
	before := api.calls.Load()

	w := call(a, routeK, "GET", map[string]string{"X-Api-Key": "rk_brand_new"})
	if w.Code != http.StatusOK || w.Header().Get("X-Runr-Key") != "k-new" {
		t.Fatalf("the new key: %d %s", w.Code, w.Body.String())
	}
	if n := api.calls.Load() - before; n != 1 {
		t.Errorf("RunR asked %d times, want once", n)
	}
	if api.lastINM.Load() != `"v1"` {
		t.Errorf("the on-demand sync is conditional too: If-None-Match %v", api.lastINM.Load())
	}

	// counted once, as allowed: the first look that did not know the key is no decision
	rec.mu.Lock()
	if len(rec.pending) != 1 {
		t.Errorf("usage rows %+v", rec.pending)
	}
	for k, e := range rec.pending {
		if k.KeyID != "k-new" || e.Requests != 1 || e.Unauthorized != 0 {
			t.Errorf("usage %+v %+v", k, e)
		}
	}
	rec.mu.Unlock()

	// known now: no further request to RunR
	call(a, routeK, "GET", map[string]string{"X-Api-Key": "rk_brand_new"})
	if n := api.calls.Load() - before; n != 1 {
		t.Errorf("a known key asked RunR again (%d requests)", n)
	}
}

func TestAnUnknownKeyIsRefusedAfterTheOnDemandSyncAndInvalidKeysAreSpaced(t *testing.T) {
	api := &fakeAPI{}
	api.config.Store(fixture(""))
	api.etag.Store(`"v1"`)
	s, clock := syncedAgainst(t, api)
	a, _ := newAuthorizer(t, fixture(""))
	a.Snapshots, a.Refresher = s, s
	before := api.calls.Load()

	// a flood of invalid keys: one request to RunR (304), every check 401
	for i := 0; i < 50; i++ {
		w := call(a, routeK, "GET", map[string]string{"X-Api-Key": "rk_invalid"})
		if w.Code != http.StatusUnauthorized || errorCode(t, w) != "invalid_key" {
			t.Fatalf("check %d: %d %s", i, w.Code, w.Body.String())
		}
	}
	if n := api.calls.Load() - before; n != 1 {
		t.Errorf("50 invalid keys asked RunR %d times within MinRefresh, want once", n)
	}

	clock.Add(1999 * time.Millisecond)
	call(a, routeK, "GET", map[string]string{"X-Api-Key": "rk_invalid"})
	if n := api.calls.Load() - before; n != 1 {
		t.Errorf("asked again before MinRefresh passed (%d requests)", n)
	}
	clock.Add(time.Millisecond)
	call(a, routeK, "GET", map[string]string{"X-Api-Key": "rk_invalid"})
	if n := api.calls.Load() - before; n != 2 {
		t.Errorf("after MinRefresh: %d requests, want 2", n)
	}
}

func TestOnDemandSyncsRunOneAtATime(t *testing.T) {
	api := &fakeAPI{}
	api.config.Store(fixture(""))
	api.etag.Store(`"v1"`)
	gate := make(chan struct{})
	var gated atomic.Bool
	entered := make(chan struct{}, 1)
	s, _ := syncedAgainst(t, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if gated.Load() {
			select {
			case entered <- struct{}{}:
			default:
			}
			<-gate
		}
		api.ServeHTTP(w, r)
	}))
	gated.Store(true)
	api.config.Store(withKey("rk_brand_new"))
	api.etag.Store(`"v2"`)
	before := api.calls.Load()

	const n = 20
	var wg sync.WaitGroup
	var waited, spaced atomic.Int32
	for i := 0; i < n; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			ran, err := s.Refresh(context.Background())
			switch {
			case err != nil:
				t.Errorf("on-demand sync: %v", err)
			case ran:
				waited.Add(1) // ran it, or waited for the one running and got its outcome
			default:
				spaced.Add(1) // came after it ended: MinRefresh holds it back
			}
		}()
	}
	<-entered // one request reached RunR; the others wait for it
	time.Sleep(50 * time.Millisecond)
	close(gate)
	wg.Wait()

	if got := api.calls.Load() - before; got != 1 {
		t.Errorf("%d concurrent on-demand syncs asked RunR %d times, want once", n, got)
	}
	if waited.Load() < 2 || waited.Load()+spaced.Load() != n {
		t.Errorf("waited %d, spaced %d of %d", waited.Load(), spaced.Load(), n)
	}
	if s.Snapshot().KeyByHash(hashOf("rk_brand_new")) == nil {
		t.Error("the new key was not loaded")
	}
}

func TestAWaitingCheckGivesUpWithItsRequest(t *testing.T) {
	api := &fakeAPI{}
	api.config.Store(fixture(""))
	api.etag.Store(`"v1"`)
	gate := make(chan struct{})
	var gated atomic.Bool
	entered := make(chan struct{}, 1)
	s, _ := syncedAgainst(t, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if gated.Load() {
			entered <- struct{}{}
			<-gate
		}
		api.ServeHTTP(w, r)
	}))
	gated.Store(true)
	defer close(gate)

	go func() { _, _ = s.Refresh(context.Background()) }()
	<-entered

	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Millisecond)
	defer cancel()
	ran, err := s.Refresh(ctx)
	if ran || err == nil {
		t.Errorf("a check whose request ended: ran=%v err=%v", ran, err)
	}
}

func TestAFailedOnDemandSyncKeepsTheSnapshotAndRefuses(t *testing.T) {
	api := &fakeAPI{}
	api.config.Store(fixture(""))
	api.etag.Store(`"v1"`)
	s, _ := syncedAgainst(t, api)
	var heard atomic.Int32
	s.OnRefresh = func(changed bool, err error) {
		if err != nil && !changed {
			heard.Add(1)
		}
	}
	a, _ := newAuthorizer(t, fixture(""))
	a.Snapshots, a.Refresher = s, s
	api.status.Store(http.StatusBadGateway)

	w := call(a, routeK, "GET", map[string]string{"X-Api-Key": "rk_brand_new"})
	if w.Code != http.StatusUnauthorized {
		t.Errorf("RunR down: %d", w.Code)
	}
	if call(a, routeK, "GET", map[string]string{"X-Api-Key": "rk_org"}).Code != http.StatusOK || s.Snapshot().Version != "v1" {
		t.Error("the last snapshot must stay")
	}
	if heard.Load() != 1 {
		t.Errorf("OnRefresh heard %d failures, want 1", heard.Load())
	}
}

func TestOnDemandSyncsOffOrBeforeTheFirstSnapshot(t *testing.T) {
	api := &fakeAPI{}
	api.config.Store(fixture(""))
	api.etag.Store(`"v1"`)
	s, _ := syncedAgainst(t, api)
	s.MinRefresh = 0
	before := api.calls.Load()
	if ran, err := s.Refresh(context.Background()); ran || err != nil || api.calls.Load() != before {
		t.Errorf("MinRefresh 0: ran=%v err=%v", ran, err)
	}

	cold := &Syncer{URL: "http://127.0.0.1:1", Token: "secret", Client: http.DefaultClient, MinRefresh: time.Second}
	if ran, _ := cold.Refresh(context.Background()); ran {
		t.Error("an on-demand sync before the first snapshot (the scheduled one retries every 2 s)")
	}
}

func TestAnUnknownRouteIsAskedForOnce(t *testing.T) {
	api := &fakeAPI{}
	api.config.Store(fixture(""))
	api.etag.Store(`"v1"`)
	s, clock := syncedAgainst(t, api)
	a, _ := newAuthorizer(t, fixture(""))
	a.Snapshots, a.Refresher = s, s
	before := api.calls.Load()

	const routeNew = "01ROUTENEWAAAAAAAAAAAAAAAA"
	if w := call(a, routeNew, "GET", map[string]string{"X-Api-Key": "rk_org"}); w.Code != http.StatusServiceUnavailable || errorCode(t, w) != "route_unknown" {
		t.Fatalf("a route RunR does not have either: %d %s", w.Code, w.Body.String())
	}
	if n := api.calls.Load() - before; n != 1 {
		t.Errorf("an unknown route asked RunR %d times, want once", n)
	}

	cfg := fixture("")
	cfg.Version = "v2"
	cfg.Routes = append(cfg.Routes, RouteConfig{ID: routeNew, Scope: "_", GatewayID: gwA, Mode: "key"})
	api.config.Store(cfg)
	api.etag.Store(`"v2"`)
	clock.Add(2 * time.Second)
	if w := call(a, routeNew, "GET", map[string]string{"X-Api-Key": "rk_org"}); w.Code != http.StatusOK {
		t.Errorf("a route created since the last sync: %d %s", w.Code, w.Body.String())
	}
}

func TestOnDemandSyncSetting(t *testing.T) {
	env := map[string]string{"RUNR_API_URL": "https://runr.example", "RUNR_REGION_ID": "r", "RUNR_TOKEN": "t"}
	get := func(k string) string { return env[k] }
	for value, want := range map[string]time.Duration{"": 2 * time.Second, "5": 5 * time.Second, "0": 0, "-1": 2 * time.Second, "x": 2 * time.Second} {
		env["ON_DEMAND_SYNC_SECONDS"] = value
		s, err := loadSettings(get)
		if err != nil || s.onDemand != want {
			t.Errorf("ON_DEMAND_SYNC_SECONDS=%q: %v (%v), want %v", value, s.onDemand, err, want)
		}
	}
}
