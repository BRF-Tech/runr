package cmd

import (
	"path/filepath"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

const globalYAML = `name: shop
type: faas
runtime: python
global:
  regions: [eu-1, us-1]
`

// globalHarness has the regions eu-1 and us-1 (both with global DNS) and ap-1 (without).
func globalHarness(t *testing.T) *harness {
	t.Helper()
	h := newHarness(t).login()
	h.srv.AddRegion("us-1", true)
	h.srv.AddRegion("ap-1", true)
	h.srv.SetGlobalDNS("eu-1", runr.GlobalDNSReady)
	h.srv.SetGlobalDNS("us-1", runr.GlobalDNSReady)
	return h
}

func TestDeployCreatesGlobalProject(t *testing.T) {
	h := globalHarness(t)
	dir := h.project(globalYAML, map[string]string{"main.py": pythonHandler})

	r := h.mustRun("deploy", "--dir", dir)
	contains(t, r.err, "Creating global project shop (faas, python, mode ha) in eu-1 (primary), us-1")
	contains(t, r.out, "https://shop.g.runr.test")

	body := h.srv.CallsTo("POST /projects")[0].Body
	ids := body["region_ids"].([]any)
	if body["placement"] != runr.PlacementGlobal || len(ids) != 2 || body["knative_region_id"] != ids[0] {
		t.Fatalf("create body = %v", body)
	}
	if _, ok := body["global_mode"]; ok {
		t.Fatalf("global_mode sent without a mode: %v", body)
	}
	if got := strings.Join(h.srv.GlobalRegions("shop"), ","); got != "eu-1,us-1" {
		t.Fatalf("regions = %s", got)
	}

	// the project's detail names its regions; the list counts the others
	r = h.mustRun("projects", "get", "shop")
	contains(t, r.out, "ha, eu-1 (primary), us-1; 2 of 2 in DNS")
	r = h.mustRun("projects", "ls")
	contains(t, r.out, "eu-1 +1")
}

func TestDeployGlobalFlagsChooseRegionsPrimaryAndMode(t *testing.T) {
	h := globalHarness(t)
	dir := h.project(helloYAML, map[string]string{"main.py": pythonHandler})

	r := h.mustRun("deploy", "--dir", dir, "--global-regions", "eu-1,us-1", "--region", "us-1", "--global-mode", "Proximity")
	contains(t, r.err, "mode proximity) in eu-1, us-1 (primary)")
	body := h.srv.CallsTo("POST /projects")[0].Body
	ids := body["region_ids"].([]any)
	if body["global_mode"] != runr.GlobalModeProximity || body["knative_region_id"] != ids[1] {
		t.Fatalf("create body = %v", body)
	}
}

func TestDeployGlobalRefusals(t *testing.T) {
	h := globalHarness(t)
	dir := h.project(helloYAML, map[string]string{"main.py": pythonHandler})

	// checked before anything is sent
	r := h.expectCode(ExitUsage, "deploy", "--dir", dir, "--global-regions", "eu-1")
	contains(t, r.err, "at least 2 regions")
	r = h.expectCode(ExitUsage, "deploy", "--dir", dir, "--global-mode", "ha")
	contains(t, r.err, "--global-mode needs a global project")
	r = h.expectCode(ExitUsage, "deploy", "--dir", dir, "--global-regions", "eu-1,us-1", "--global-mode", "fastest")
	contains(t, r.err, "unknown global mode")
	r = h.expectCode(ExitUsage, "deploy", "--dir", dir, "--global-regions", "eu-1,us-1,eu-1")
	contains(t, r.err, "listed twice")
	r = h.expectCode(ExitUsage, "deploy", "--dir", dir, "--global-regions", "eu-1,us-1", "--region", "ap-1")
	contains(t, r.err, "primary region ap-1 is not one of the global regions")
	if n := len(h.srv.CallsTo("POST /projects")); n != 0 {
		t.Fatalf("%d projects created", n)
	}

	// the API checks that every region has global DNS
	r = h.expectCode(ExitValidation, "deploy", "--dir", dir, "--global-regions", "eu-1,ap-1")
	contains(t, r.err, "Region ap-1 has no global DNS")

	// a schedule-triggered project can be global: the schedule runs in the primary only
	sched := h.project(strings.Replace(globalYAML, "name: shop", "name: nightly", 1)+"trigger:\n  type: schedule\n  schedule: \"*/5 * * * *\"\n",
		map[string]string{"main.py": pythonHandler})
	h.mustRun("deploy", "--dir", sched)
	if got := strings.Join(h.srv.GlobalRegions("nightly"), ","); got != "eu-1,us-1" {
		t.Fatalf("regions of the scheduled global project = %s", got)
	}
}

func TestDeployChangesGlobalRegionsAndRefusesRegionalProjects(t *testing.T) {
	h := globalHarness(t)
	dir := h.project(globalYAML, map[string]string{"main.py": pythonHandler})
	h.mustRun("deploy", "--dir", dir)
	h.srv.SetGlobalDNS("ap-1", runr.GlobalDNSReady)

	// a third region and another mode: one PUT of the global endpoint, then the deploy
	write(t, filepath.Join(dir, "runr.yaml"), "name: shop\ntype: faas\nruntime: python\nglobal:\n  regions: [eu-1, us-1, ap-1]\n  mode: proximity\n")
	r := h.mustRun("deploy", "--dir", dir)
	contains(t, r.err, "Updating global project shop: mode proximity; regions eu-1, us-1, ap-1")
	put := h.srv.CallsTo("PUT /projects/{project}/global")
	if len(put) != 1 || len(put[0].Body["region_ids"].([]any)) != 3 || put[0].Body["mode"] != runr.GlobalModeProximity {
		t.Fatalf("PUT calls = %+v", put)
	}
	if n := len(h.srv.CallsTo("PUT /projects/{project}")); n != 0 {
		t.Fatalf("the primary region was changed through PUT /projects/{project} (%d calls)", n)
	}

	// nothing changed: no PUT
	h.srv.ResetCalls()
	h.mustRun("deploy", "--dir", dir)
	if n := len(h.srv.CallsTo("PUT /projects/{project}/global")); n != 0 {
		t.Fatalf("%d PUTs without a change", n)
	}

	// a regional project does not become global
	hello := h.project(helloYAML, map[string]string{"main.py": pythonHandler})
	h.mustRun("deploy", "--dir", hello, "--region", "eu-1")
	r = h.expectCode(ExitError, "deploy", "--dir", hello, "--global-regions", "eu-1,us-1")
	contains(t, r.err, "project hello-api is a regional project; it cannot become global")
}

func TestProjectsGlobalShowSetSync(t *testing.T) {
	h := globalHarness(t)
	h.mustRun("deploy", "--dir", h.project(globalYAML, map[string]string{"main.py": pythonHandler}))
	h.srv.SetGlobalWarnings("shop", "Binding orders-db lives in eu-1 only.")

	r := h.mustRun("projects", "global", "shop")
	contains(t, r.out, "ha (the primary region answers")
	contains(t, r.out, "shop.g.runr.test")
	contains(t, r.out, "2 of 2 regions")
	contains(t, r.out, "us-1")
	contains(t, r.out, "secondary")
	contains(t, r.out, "Warning: Binding orders-db lives in eu-1 only.")
	var g runr.ProjectGlobal
	decodeJSON(t, h.mustRun("projects", "global", "show", "shop", "-o", "json").out, &g)
	if g.Mode != runr.GlobalModeHA || len(g.Regions) != 2 || g.Primary() == nil || g.Primary().RegionName != "eu-1" ||
		g.Regions[1].Version == nil || !g.Regions[1].InDNS {
		t.Fatalf("global = %+v", g)
	}

	// a copy that rolls out the next version keeps serving; a failed one leaves DNS
	h.srv.SetGlobalRegionStatus("shop", "us-1", runr.GlobalRegionFailed, "build failed in us-1")
	r = h.mustRun("projects", "global", "shop")
	contains(t, r.out, "build failed in us-1")
	contains(t, r.out, "1 of 2 regions")

	// the new primary must be ready
	r = h.expectCode(ExitValidation, "projects", "global", "set", "shop", "--primary", "us-1")
	contains(t, r.err, "must serve the project first")
	h.mustRun("projects", "global", "sync", "shop")
	if n := len(h.srv.CallsTo("POST /projects/{project}/global/sync")); n != 1 {
		t.Fatalf("%d sync calls", n)
	}
	r = h.mustRun("projects", "global", "set", "shop", "--primary", "us-1", "--mode", "proximity")
	contains(t, r.err, "Global project shop updated.")
	contains(t, r.out, "proximity (the healthy region closest")

	// removing a region asks first
	h.srv.SetGlobalDNS("ap-1", runr.GlobalDNSReady)
	h.mustRun("projects", "global", "set", "shop", "--regions", "eu-1,us-1,ap-1")
	r = h.expectCode(ExitUsage, "projects", "global", "set", "shop", "--regions", "us-1,ap-1")
	contains(t, r.err, "Remove eu-1 from global project shop")
	h.mustRun("projects", "global", "set", "shop", "--regions", "us-1,ap-1", "--yes")
	if got := strings.Join(h.srv.GlobalRegions("shop"), ","); got != "us-1,ap-1" {
		t.Fatalf("regions = %s", got)
	}

	h.expectCode(ExitUsage, "projects", "global", "set", "shop")
	h.expectCode(ExitUsage, "projects", "global", "set", "shop", "--mode", "nearest")
	h.expectCode(ExitUsage, "projects", "global", "set", "shop", "--regions", "us-1")
}

func TestProjectsGlobalOfRegionalProject(t *testing.T) {
	h := globalHarness(t)
	h.srv.SeedProject("api", runr.TypeFaaS, "python", "eu-1", map[string]any{})
	r := h.expectCode(ExitError, "projects", "global", "api")
	contains(t, r.err, "project api is a regional project (region eu-1)")
	h.expectCode(ExitError, "projects", "global", "sync", "api")
	if n := len(h.srv.CallsTo("GET /projects/{project}/global")); n != 0 {
		t.Fatalf("a regional project's global endpoint was called %d times", n)
	}
}

func TestRegionsGlobalDNS(t *testing.T) {
	h := newHarness(t).login()
	h.srv.AddRegion("us-1", true)

	r := h.mustRun("regions", "global-dns", "eu-1")
	contains(t, r.out, "disabled")
	contains(t, r.out, "runr regions global-dns enable eu-1")

	r = h.mustRun("regions", "global-dns", "enable", "eu-1", "--wait")
	contains(t, r.err, "Global DNS of eu-1: ready.")
	contains(t, r.out, "ns-eu-1.g.runr.test")
	contains(t, r.out, "yes (answers the zone on :53)")
	h.mustRun("regions", "global-dns", "enable", "us-1", "--wait")

	var d runr.RegionGlobalDNS
	decodeJSON(t, h.mustRun("regions", "global-dns", "status", "eu-1", "-o", "json").out, &d)
	if d.Status != runr.GlobalDNSReady || !d.Eligible || d.Address == "" || d.Reachable == nil || !*d.Reachable {
		t.Fatalf("global DNS = %+v", d)
	}

	// refused while a global project runs in the region
	h.mustRun("deploy", "--dir", h.project(globalYAML, map[string]string{"main.py": pythonHandler}))
	h.expectCode(ExitUsage, "regions", "global-dns", "disable", "eu-1")
	r = h.expectCode(ExitValidation, "regions", "global-dns", "disable", "eu-1", "--yes")
	contains(t, r.err, "serves global projects (shop)")

	// without the project the server goes (a fresh value: JSON null leaves a string field as it was)
	h.mustRun("projects", "delete", "shop", "--yes")
	d = runr.RegionGlobalDNS{}
	decodeJSON(t, h.mustRun("regions", "global-dns", "disable", "eu-1", "--yes", "--wait", "-o", "json").out, &d)
	if d.Status != runr.GlobalDNSDisabled || d.Address != "" {
		t.Fatalf("after disable = %+v", d)
	}
	h.expectCode(ExitValidation, "regions", "global-dns", "disable", "eu-1", "--yes")
}

func TestRegionsGlobalDNSRefusalsAndFailures(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SetRegionKnative("eu-1", runr.KnativeReady, runr.KnativeNotInstalled)
	r := h.expectCode(ExitValidation, "regions", "global-dns", "enable", "eu-1")
	contains(t, r.err, "Install Knative Serving")
	r = h.mustRun("regions", "global-dns", "eu-1")
	contains(t, r.out, "no: Install Knative Serving")

	h.srv.SetRegionKnative("eu-1", runr.KnativeReady, runr.KnativeReady)
	h.srv.RegionAdmin().FailInstall = true
	r = h.expectCode(ExitFailed, "regions", "global-dns", "enable", "eu-1", "--wait")
	contains(t, r.err, "global DNS of region eu-1 failed")
}

func TestRegionsGlobalDNSPermissions(t *testing.T) {
	h := newHarness(t).login()
	h.loginWith(h.srv.ScopedToken("viewer", "regions.view"))
	h.mustRun("regions", "global-dns", "eu-1")
	h.mustRun("global-dns")
	r := h.expectCode(ExitAuth, "regions", "global-dns", "enable", "eu-1")
	contains(t, r.err, "regions.manage")
	h.expectCode(ExitAuth, "global-dns", "sync")
}

func TestGlobalDNSStatusAndSync(t *testing.T) {
	h := newHarness(t).login()
	h.srv.AddRegion("us-1", true)

	r := h.mustRun("global-dns")
	contains(t, r.out, "g.runr.test")
	contains(t, r.out, "unknown (not checked)")
	contains(t, r.out, "No region has global DNS yet")

	h.srv.SetGlobalDNS("eu-1", runr.GlobalDNSReady)
	h.srv.SetGlobalDNS("us-1", runr.GlobalDNSReady)
	h.srv.SetGlobalDelegation("partial", "us-1 is missing from the parent zone")
	r = h.mustRun("global-dns")
	contains(t, r.out, "g.runr.test NS ns-eu-1.g.runr.test")
	contains(t, r.out, "ns-us-1.g.runr.test A 198.51.100.11")
	contains(t, r.out, "partial (the parent zone names only some of the servers): us-1 is missing")
	contains(t, r.out, "DNS-01 updates:")

	var g runr.GlobalDNS
	decodeJSON(t, h.mustRun("global-dns", "-o", "json").out, &g)
	if g.Zone != "g.runr.test" || len(g.Servers) != 2 || len(g.Delegation.Records) != 4 || g.UpdateRegionID == "" {
		t.Fatalf("global DNS = %+v", g)
	}

	r = h.mustRun("global-dns", "sync")
	contains(t, r.err, "Zone sync queued.")
	if n := len(h.srv.CallsTo("POST /global-dns/sync")); n != 1 {
		t.Fatalf("%d sync calls", n)
	}
}

func TestProjectsGlobalFailoverSettingAndFailover(t *testing.T) {
	h := globalHarness(t)
	h.mustRun("deploy", "--dir", h.project(globalYAML, map[string]string{"main.py": pythonHandler}))
	h.srv.SetGlobalBindings("shop", runr.GlobalBindingData{BindingID: "b1", Name: "orders", Type: "postgres", Plan: "dedicated",
		HomeRegionName: "eu-1", Access: runr.GlobalAccessReplicas, Regions: []runr.GlobalBindingRegion{
			{RegionName: "eu-1", Kind: "home", Status: "ready"},
			{RegionName: "us-1", Kind: "replica", Status: "ready", LagSeconds: intPtr(2)},
		}})

	r := h.mustRun("projects", "global", "shop")
	contains(t, r.out, "automatic (RunR moves the primary when its health check fails for 180s)")
	contains(t, r.out, "TRIGGERS")
	contains(t, r.out, "here")
	contains(t, r.out, "postgres (dedicated), home eu-1, replicas: us-1 replica ready (lag 2s)")

	r = h.mustRun("projects", "global", "set", "shop", "--failover", "Manual")
	contains(t, r.out, "manual (the primary stays until an operator moves it)")
	if put := h.srv.CallsTo("PUT /projects/{project}/global"); len(put) != 1 || put[0].Body["failover"] != runr.GlobalFailoverManual {
		t.Fatalf("PUT calls = %+v", put)
	}
	h.expectCode(ExitUsage, "projects", "global", "set", "shop", "--failover", "sometimes")

	// a failover asks first, the primary cannot be its own target
	r = h.expectCode(ExitUsage, "projects", "global", "failover", "shop", "--region", "us-1")
	contains(t, r.err, "writes after the last WAL its replicas received are lost")
	r = h.expectCode(ExitValidation, "projects", "global", "failover", "shop", "--region", "eu-1", "--yes")
	contains(t, r.err, "primary already")

	var g runr.ProjectGlobal
	decodeJSON(t, h.mustRun("projects", "global", "failover", "shop", "--yes", "-o", "json").out, &g)
	if p := g.Primary(); p == nil || p.RegionName != "us-1" || !p.TriggersActive {
		t.Fatalf("primary after the failover = %+v", p)
	}
	for _, r := range g.Regions {
		if r.RegionName == "eu-1" && (r.Status != runr.GlobalRegionFenced || r.FencedAt == nil || r.InDNS) {
			t.Fatalf("old primary = %+v", r)
		}
	}
	if calls := h.srv.CallsTo("POST /projects/{project}/global/failover"); len(calls) != 2 {
		t.Fatalf("%d failover calls", len(calls))
	} else if _, ok := calls[1].Body["region_id"]; ok {
		t.Fatalf("a failover without --region sent a region: %v", calls[1].Body)
	}
	r = h.mustRun("projects", "global", "shop")
	contains(t, r.out, "fenced")
	contains(t, r.out, "Failed over to us-1")
}

func TestRegionsGlobalDNSGeoIP(t *testing.T) {
	h := newHarness(t).login()
	h.mustRun("regions", "global-dns", "enable", "eu-1", "--wait")

	r := h.mustRun("regions", "global-dns", "geoip", "eu-1")
	contains(t, r.out, "default")
	contains(t, r.out, "loaded")
	contains(t, r.out, "dbip-city-lite")
	r = h.mustRun("regions", "global-dns", "eu-1")
	contains(t, r.out, "loaded (default)")
	r = h.mustRun("global-dns")
	contains(t, r.out, "GEOIP")
	contains(t, r.out, "loaded (default)")

	h.expectCode(ExitUsage, "regions", "global-dns", "geoip", "eu-1", "--source", "url")
	h.expectCode(ExitUsage, "regions", "global-dns", "geoip", "eu-1", "--source", "file", "--url", "https://mirror.internal/geo.mmdb.gz")
	h.expectCode(ExitUsage, "regions", "global-dns", "geoip", "eu-1", "--source", "maxmind")

	// --url alone is source url: the server downloads the operator's file
	r = h.mustRun("regions", "global-dns", "geoip", "eu-1", "--url", "https://mirror.internal/geo-{yyyy}-{mm}.mmdb.gz")
	contains(t, r.err, "GeoIP source of eu-1: url")
	contains(t, r.out, "https://mirror.internal/geo-{yyyy}-{mm}.mmdb.gz")
	put := h.srv.CallsTo("PUT /knative-regions/{knativeRegion}/global-dns/geoip")
	if len(put) != 1 || put[0].Body["source"] != runr.GeoIPSourceURL || put[0].Body["url"] != "https://mirror.internal/geo-{yyyy}-{mm}.mmdb.gz" {
		t.Fatalf("PUT calls = %+v", put)
	}

	// the operator's own file on the volume: copied, then reloaded
	r = h.mustRun("regions", "global-dns", "geoip", "eu-1", "--source", "file")
	contains(t, r.out, "kubectl -n runr-global-dns cp geoip.mmdb")
	if _, ok := h.srv.CallsTo("PUT /knative-regions/{knativeRegion}/global-dns/geoip")[1].Body["url"]; ok {
		t.Fatal("source file sent a url")
	}
	r = h.mustRun("regions", "global-dns", "geoip", "eu-1", "--reload")
	contains(t, r.err, "reads its GeoIP source again")
	if n := len(h.srv.CallsTo("POST /knative-regions/{knativeRegion}/global-dns/geoip/reload")); n != 1 {
		t.Fatalf("%d reload calls", n)
	}

	var g runr.GlobalDNSGeoIP
	decodeJSON(t, h.mustRun("regions", "global-dns", "geoip", "eu-1", "-o", "json").out, &g)
	if g.Source != runr.GeoIPSourceFile || g.Status != "unknown" {
		t.Fatalf("geoip = %+v", g)
	}

	// reading needs regions.view, a change regions.manage
	h.loginWith(h.srv.ScopedToken("viewer", "regions.view"))
	h.mustRun("regions", "global-dns", "geoip", "eu-1")
	r = h.expectCode(ExitAuth, "regions", "global-dns", "geoip", "eu-1", "--source", "none")
	contains(t, r.err, "regions.manage")
}

func intPtr(n int) *int { return &n }
