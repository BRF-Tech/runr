package main

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
)

// fakeAPI answers the config sync like GatewayAuthInternalController: token check, ETag, 304.
type fakeAPI struct {
	config  atomic.Pointer[Config]
	etag    atomic.Value
	status  atomic.Int32
	calls   atomic.Int32
	lastINM atomic.Value
}

func (f *fakeAPI) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	f.calls.Add(1)
	f.lastINM.Store(r.Header.Get("If-None-Match"))
	if r.Header.Get("Authorization") != "Bearer secret" {
		w.WriteHeader(http.StatusUnauthorized)
		return
	}
	if s := f.status.Load(); s != 0 {
		w.WriteHeader(int(s))
		return
	}
	etag, _ := f.etag.Load().(string)
	if etag != "" && r.Header.Get("If-None-Match") == etag {
		w.WriteHeader(http.StatusNotModified)
		return
	}
	w.Header().Set("ETag", etag)
	_ = json.NewEncoder(w).Encode(f.config.Load())
}

func TestSyncLoadsThenUsesTheETag(t *testing.T) {
	api := &fakeAPI{}
	api.config.Store(fixture(""))
	api.etag.Store(`"v1"`)
	srv := httptest.NewServer(api)
	defer srv.Close()

	s := &Syncer{URL: srv.URL, Token: "secret", Client: srv.Client()}
	if s.Ready() {
		t.Fatal("ready before the first sync")
	}
	changed, err := s.Sync(context.Background())
	if err != nil || !changed || !s.Ready() {
		t.Fatalf("first sync: changed=%v err=%v", changed, err)
	}
	if s.Snapshot().KeyByHash(hashOf("rk_org")) == nil {
		t.Error("keys not indexed")
	}

	changed, err = s.Sync(context.Background())
	if err != nil || changed {
		t.Errorf("unchanged sync: changed=%v err=%v", changed, err)
	}
	if api.lastINM.Load() != `"v1"` {
		t.Errorf("If-None-Match %v", api.lastINM.Load())
	}

	// a revoked key: RunR drops it, the ETag moves
	cfg := fixture("")
	cfg.Keys = cfg.Keys[1:]
	api.config.Store(cfg)
	api.etag.Store(`"v2"`)
	if changed, _ := s.Sync(context.Background()); !changed {
		t.Fatal("changed config not loaded")
	}
	if s.Snapshot().KeyByHash(hashOf("rk_gateway")) != nil {
		t.Error("the revoked key is still known")
	}
}

func TestFailedSyncKeepsTheLastSnapshot(t *testing.T) {
	api := &fakeAPI{}
	api.config.Store(fixture(""))
	api.etag.Store(`"v1"`)
	srv := httptest.NewServer(api)
	defer srv.Close()

	s := &Syncer{URL: srv.URL, Token: "secret", Client: srv.Client()}
	if _, err := s.Sync(context.Background()); err != nil {
		t.Fatal(err)
	}
	api.status.Store(http.StatusInternalServerError)
	if _, err := s.Sync(context.Background()); err == nil {
		t.Error("a 500 is an error")
	}
	if s.Snapshot() == nil || s.Snapshot().Version != "v1" {
		t.Error("the last snapshot must stay")
	}

	bad := &Syncer{URL: srv.URL, Token: "wrong", Client: srv.Client()}
	if _, err := bad.Sync(context.Background()); err == nil || bad.Ready() {
		t.Error("a wrong token must not load anything")
	}
}

func TestPartialConfigKeepsTheUnreadOrganization(t *testing.T) {
	prev := NewSnapshot(fixture(""), nil)

	// RunR could not read organization "t1": its consumer and key stay, "_" is replaced
	next := &Config{
		Version: "v2-partial",
		Routes:  []RouteConfig{{ID: routeK, Scope: "_", GatewayID: gwA, Mode: "key"}},
		Consumers: []Consumer{
			{ID: "c-org", Scope: "_"},
		},
		Keys:    []Key{{ID: "k-org", Hash: hashOf("rk_org"), ConsumerID: "c-org"}},
		Partial: []string{"t1"},
	}
	s := NewSnapshot(next, prev)

	if s.KeyByHash(hashOf("rk_tenant")) == nil || s.Consumer("c-tenant") == nil {
		t.Error("the unread organization lost its keys")
	}
	if s.KeyByHash(hashOf("rk_gateway")) != nil {
		t.Error("a key RunR dropped for a read organization is still there")
	}
	if s.Route(routeJ) != nil {
		t.Error("a route RunR dropped is still there")
	}
}

func TestConsumerBySubjectPrefersTheGatewaysOwn(t *testing.T) {
	gw := gwA
	other := gwB
	s := NewSnapshot(&Config{
		Routes: []RouteConfig{{ID: routeJ, Scope: "_", GatewayID: gwA, Mode: "jwt"}},
		Consumers: []Consumer{
			{ID: "org", Scope: "_", Subject: str("acme")},
			{ID: "own", Scope: "_", GatewayID: &gw, Subject: str("acme")},
			{ID: "foreign", Scope: "_", GatewayID: &other, Subject: str("solo")},
			{ID: "tenant", Scope: "t1", Subject: str("acme")},
		},
	}, nil)
	route := s.Route(routeJ)
	if c := s.ConsumerBySubject(route, "acme"); c == nil || c.ID != "own" {
		t.Errorf("acme: %+v", c)
	}
	if c := s.ConsumerBySubject(route, "solo"); c != nil {
		t.Errorf("a consumer of another gateway matched: %+v", c)
	}
}
