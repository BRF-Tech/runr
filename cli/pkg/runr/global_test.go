package runr_test

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

// The API sends nulls for what a region has not done yet and may wrap the answer in data.
func TestProjectGlobalDecodesNullsAndDataWrapper(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":{"mode":"ha","hostname":"shop.g.example.com","primary_region_id":"r1","warnings":[],
			"regions":[{"region_id":"r1","region_name":"eu-1","role":"primary","position":0,"status":"ready","status_message":null,
			"version":3,"target_version":null,"healthy":true,"health_checked_at":"2026-10-21T10:00:00+00:00","address":"203.0.113.10","in_dns":true},
			{"region_id":"r2","region_name":"us-1","role":"secondary","position":1,"status":"building","status_message":null,
			"version":null,"target_version":3,"healthy":null,"health_checked_at":null,"address":null,"in_dns":false}]}}`))
	}))
	defer srv.Close()
	c, _ := runr.New(runr.Options{APIURL: srv.URL, Token: "t", Retries: -1})

	g, err := c.ProjectGlobal(context.Background(), "p1")
	if err != nil {
		t.Fatal(err)
	}
	if g.Mode != runr.GlobalModeHA || g.Hostname != "shop.g.example.com" || len(g.Regions) != 2 {
		t.Fatalf("global = %+v", g)
	}
	if p := g.Primary(); p == nil || p.RegionName != "eu-1" || *p.Version != 3 || !*p.Healthy || p.HealthCheckedAt == nil {
		t.Fatalf("primary = %+v", p)
	}
	us := g.Regions[1]
	if us.Version != nil || *us.TargetVersion != 3 || us.Healthy != nil || us.Address != "" || us.InDNS {
		t.Fatalf("secondary = %+v", us)
	}
}

func TestRegionGlobalDNSDecodesTheEmbeddedServer(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"region_id":"r1","region_name":"eu-1","status":"ready","status_message":null,"address":"198.51.100.53",
			"ns_name":"ns-eu-1.g.example.com","reachable":false,"sync_status":"drift","sync_message":"2 records differ",
			"synced_at":"2026-10-21T10:00:00+00:00","tls_status":"pending","tls_message":null,"tls_expires_at":null,
			"eligible":true,"reason":null}`))
	}))
	defer srv.Close()
	c, _ := runr.New(runr.Options{APIURL: srv.URL, Token: "t", Retries: -1})

	d, err := c.RegionGlobalDNS(context.Background(), "r1")
	if err != nil {
		t.Fatal(err)
	}
	if d.Status != runr.GlobalDNSReady || d.NSName != "ns-eu-1.g.example.com" || d.Reachable == nil || *d.Reachable ||
		d.SyncStatus != "drift" || d.SyncedAt == nil || d.TLSExpiresAt != nil || !d.Eligible || d.Reason != "" {
		t.Fatalf("region global DNS = %+v", d)
	}
}

func TestGlobalDNSEndpointsOfOlderAPIsAreUnsupported(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusNotFound)
		w.Write([]byte(`{"message":"Not found."}`))
	}))
	defer srv.Close()
	c, _ := runr.New(runr.Options{APIURL: srv.URL, Token: "t", Retries: -1})

	if _, err := c.GlobalDNS(context.Background()); !errors.Is(err, runr.ErrUnsupported) {
		t.Fatalf("GlobalDNS = %v, want ErrUnsupported", err)
	}
	if err := c.SyncGlobalDNS(context.Background()); !errors.Is(err, runr.ErrUnsupported) {
		t.Fatalf("SyncGlobalDNS = %v, want ErrUnsupported", err)
	}
	if _, err := c.RegionGlobalDNS(context.Background(), "r1"); !errors.Is(err, runr.ErrUnsupported) {
		t.Fatalf("RegionGlobalDNS = %v, want ErrUnsupported", err)
	}
	// a regional project's global endpoint is a plain 404 (the caller knows the project)
	if _, err := c.ProjectGlobal(context.Background(), "p1"); !runr.IsNotFound(err) {
		t.Fatalf("ProjectGlobal = %v, want a 404", err)
	}
}

func TestWaitRegionGlobalDNS(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	regions, err := c.ListRegions(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	eu := regions[0]

	if _, err := c.EnableRegionGlobalDNS(context.Background(), eu.ID); err != nil {
		t.Fatal(err)
	}
	var seen []string
	d, err := c.WaitRegionGlobalDNS(context.Background(), eu.ID, eu.Name, runr.WaitOptions{Interval: time.Millisecond,
		Progress: func(s string) { seen = append(seen, s) }})
	if err != nil || d.Status != runr.GlobalDNSReady || strings.Join(seen, ",") != runr.GlobalDNSInstalling {
		t.Fatalf("wait = %+v, %v (seen %v)", d, err, seen)
	}

	srv.RegionAdmin().FailInstall = true
	if _, err := c.DisableRegionGlobalDNS(context.Background(), eu.ID); err != nil {
		t.Fatal(err)
	}
	_, err = c.WaitRegionGlobalDNS(context.Background(), eu.ID, eu.Name, runr.WaitOptions{Interval: time.Millisecond})
	var fe *runr.FailedError
	if !errors.As(err, &fe) || fe.Ref != "eu-1" {
		t.Fatalf("failed removal = %v", err)
	}
}

// The failover, data and GeoIP fields of newer APIs; nulls where a region has nothing yet.
func TestProjectGlobalDecodesFailoverDataAndTriggers(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"mode":"ha","failover":"automatic","failover_after_seconds":180,"moving_to":null,"hostname":"shop.g.example.com",
			"primary_region_id":"r1","warnings":[],
			"regions":[{"region_id":"r1","region_name":"eu-1","role":"primary","position":0,"status":"ready","version":3,"target_version":null,
			"healthy":true,"in_dns":true,"triggers_active":true,"data_status":"ready","data_message":null,"unhealthy_since":null,"fenced_at":null},
			{"region_id":"r2","region_name":"us-1","role":"secondary","position":1,"status":"fenced","version":3,"target_version":null,
			"healthy":false,"in_dns":false,"triggers_active":false,"data_status":"syncing","data_message":"The replica cluster is being rebuilt",
			"unhealthy_since":"2026-10-22T10:00:00+00:00","fenced_at":"2026-10-22T10:03:00+00:00"}],
			"bindings":[{"binding_id":"b1","name":"orders","type":"postgres","plan":"dedicated","home_region_id":"r1","home_region_name":"eu-1",
			"access":"replicas","regions":[{"region_id":"r2","region_name":"us-1","kind":"replica","status":"recloning","status_message":null,
			"lag_seconds":null,"checked_at":null}]}]}`))
	}))
	defer srv.Close()
	c, _ := runr.New(runr.Options{APIURL: srv.URL, Token: "t", Retries: -1})

	g, err := c.ProjectGlobal(context.Background(), "p1")
	if err != nil {
		t.Fatal(err)
	}
	if g.Failover != runr.GlobalFailoverAutomatic || g.FailoverAfterSeconds != 180 || g.MovingTo != "" {
		t.Fatalf("global = %+v", g)
	}
	if p := g.Primary(); !p.TriggersActive || p.DataStatus != runr.GlobalDataReady || p.FencedAt != nil {
		t.Fatalf("primary = %+v", p)
	}
	us := g.Regions[1]
	if us.Status != runr.GlobalRegionFenced || us.FencedAt == nil || us.UnhealthySince == nil || us.DataStatus != runr.GlobalDataSyncing {
		t.Fatalf("fenced region = %+v", us)
	}
	if len(g.Bindings) != 1 || g.Bindings[0].Access != runr.GlobalAccessReplicas || g.Bindings[0].Regions[0].Kind != "replica" || g.Bindings[0].Regions[0].LagSeconds != nil {
		t.Fatalf("bindings = %+v", g.Bindings)
	}
}

func TestFailoverAndGeoIPAgainstTheFake(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	regions, err := c.ListRegions(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	eu := regions[0]
	if _, err := c.EnableRegionGlobalDNS(context.Background(), eu.ID); err != nil {
		t.Fatal(err)
	}
	if _, err := c.WaitRegionGlobalDNS(context.Background(), eu.ID, eu.Name, runr.WaitOptions{Interval: time.Millisecond}); err != nil {
		t.Fatal(err)
	}

	d, err := c.SetRegionGeoIP(context.Background(), eu.ID, runr.GeoIPSourceURL, "https://mirror.internal/geo.mmdb.gz")
	if err != nil || d.GeoIP == nil || d.GeoIP.Source != runr.GeoIPSourceURL || d.GeoIP.EffectiveURL != "https://mirror.internal/geo.mmdb.gz" {
		t.Fatalf("set = %+v, %v", d, err)
	}
	d, err = c.ReloadRegionGeoIP(context.Background(), eu.ID)
	if err != nil || d.GeoIP.Status != "unknown" {
		t.Fatalf("reload = %+v, %v", d, err)
	}
	if _, err := c.SetRegionGeoIP(context.Background(), eu.ID, "maxmind", ""); !runr.IsValidation(err) {
		t.Fatalf("an unknown source = %v, want a validation error", err)
	}
}
