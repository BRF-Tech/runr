package cmd

import (
	"strings"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// seedPythonPatches adds two patches of python 3.13 (the fake's default version): an older
// routine one and a newer security one, and lets hello-api run the older.
func seedPythonPatches(h *harness) (older, newer runr.RuntimePatch) {
	h.t.Helper()
	older = h.srv.SeedRuntimePatch(runr.RuntimePatch{Runtime: "python", Version: "3.13", Tag: "3.13.8-r1", Level: runr.PatchRoutine,
		Reason: "Python 3.13.8", Upstream: "Python 3.13.8", PublishedAt: time.Now().Add(-72 * time.Hour).UTC().Format(time.RFC3339)})
	newer = h.srv.SeedRuntimePatch(runr.RuntimePatch{Runtime: "python", Version: "3.13", Tag: "3.13.9-r1", Level: runr.PatchSecurity,
		Reason: "OpenSSL 3.5.4: TLS session fix", CVEs: []string{"CVE-2026-1111"}, URL: "https://example.com/advisories/1111",
		PublishedAt: time.Now().Add(-time.Hour).UTC().Format(time.RFC3339)})
	h.srv.SeedRunningPatch("hello-api", older.ID)
	return older, newer
}

func TestProjectGetShowsTheRuntimePatchState(t *testing.T) {
	h := newHarness(t).login()
	h.deployHello()

	// No patches: nothing to show
	r := h.mustRun("projects", "get", "hello-api")
	notContains(t, r.out, "Runtime patch")

	seedPythonPatches(h)
	r = h.mustRun("projects", "get", "hello-api")
	contains(t, r.out, "Runtime patch")
	contains(t, r.out, "patch available (security)")
	contains(t, r.out, "3.13.8-r1 → 3.13.9-r1")

	var p runr.Project
	decodeJSON(t, h.mustRun("projects", "get", "hello-api", "-o", "json").out, &p)
	if p.RuntimePatch == nil || p.RuntimePatch.State != runr.PatchAvailable || p.RuntimePatch.Level != runr.PatchSecurity ||
		p.RuntimePatch.Latest == nil || p.RuntimePatch.Latest.Tag != "3.13.9-r1" {
		t.Fatalf("runtime_patch = %+v", p.RuntimePatch)
	}
}

func TestProjectPatchesListsWhyEachPatchCame(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	seedPythonPatches(h)

	r := h.mustRun("projects", "patches", "hello-api")
	contains(t, r.out, "3.13.9-r1")
	contains(t, r.out, "security")
	contains(t, r.out, "CVE-2026-1111")
	contains(t, r.out, "OpenSSL 3.5.4")
	contains(t, r.out, "https://example.com/advisories/1111")

	var rp runr.ProjectRuntimePatches
	decodeJSON(t, h.mustRun("projects", "patches", "--dir", dir, "-o", "json").out, &rp)
	if rp.State == nil || len(rp.Patches) != 2 || rp.Patches[0].Tag != "3.13.9-r1" || rp.Keep != 5 || !rp.AutoMigrate {
		t.Fatalf("patches = %+v", rp)
	}
}

func TestProjectPatchesOfACaaSProject(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("web", runr.TypeCaaS, "", "eu-1", map[string]any{"image": "nginx:1.27", "ports": []any{80}})

	r := h.mustRun("projects", "patches", "web")
	contains(t, r.out, "No runtime patch concerns web")
}

func TestProjectPatchApplyRedeploysOntoTheNewest(t *testing.T) {
	h := newHarness(t).login()
	h.deployHello()
	_, newer := seedPythonPatches(h)
	before := len(h.srv.Deployments("hello-api"))

	r := h.mustRun("projects", "patch", "apply", "hello-api", "--wait")
	contains(t, r.out, "Migration")
	contains(t, r.out, "active")
	if n := len(h.srv.CallsTo("POST /projects/{project}/runtime-patches/apply")); n != 1 {
		t.Fatalf("apply calls = %d", n)
	}
	deps := h.srv.Deployments("hello-api")
	if len(deps) != before+1 || deps[0].Status != runr.DeploymentActive {
		t.Fatalf("deployments = %+v", deps)
	}
	if p, _ := h.srv.Project("hello-api"); p.RuntimePatch == nil || p.RuntimePatch.State != runr.PatchUpToDate || p.RuntimePatch.Current.ID != newer.ID {
		t.Fatalf("runtime_patch after apply = %+v", p.RuntimePatch)
	}

	// Already on the newest: the API refuses (409)
	r = h.expectCode(ExitValidation, "projects", "patch", "apply", "hello-api")
	contains(t, r.err, "already runs")
}

func TestProjectPatchApplyWithoutWaitReturnsTheMigration(t *testing.T) {
	h := newHarness(t).login()
	h.deployHello()
	seedPythonPatches(h)

	var res patchActionResult
	decodeJSON(t, h.mustRun("projects", "patch", "apply", "hello-api", "-o", "json").out, &res)
	if res.Migration == nil || res.Migration.Trigger != "apply" || res.Migration.Status != "started" || res.Migration.DeploymentID == "" || res.Deployment != nil {
		t.Fatalf("apply = %+v", res)
	}
}

func TestProjectPatchPinAndUnpin(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	older, _ := seedPythonPatches(h)

	// By tag, without a redeploy
	r := h.mustRun("projects", "patch", "pin", "hello-api", "3.13.8-r1", "--no-deploy")
	contains(t, r.out, "pinned to 3.13.8-r1")
	calls := h.srv.CallsTo("PUT /projects/{project}/runtime-patches/pin")
	if len(calls) != 1 || calls[0].Body["patch_id"] != older.ID || calls[0].Body["deploy"] != false {
		t.Fatalf("pin calls = %+v", calls)
	}
	if got := h.srv.PinnedPatch("hello-api"); got != older.ID {
		t.Fatalf("pinned = %q", got)
	}

	// A patch the project cannot be pinned to
	r = h.expectCode(ExitUsage, "projects", "patch", "pin", "hello-api", "3.12.1-r9")
	contains(t, r.err, "not one of the patches")
	contains(t, r.err, "3.13.9-r1")

	// The project of runr.yaml, by id
	h.mustRun("projects", "patch", "unpin", "--dir", dir)
	if got := h.srv.PinnedPatch("hello-api"); got != "" {
		t.Fatalf("pin after unpin = %q", got)
	}
	if n := len(h.srv.CallsTo("DELETE /projects/{project}/runtime-patches/pin")); n != 1 {
		t.Fatalf("unpin calls = %d", n)
	}
}

func TestProjectPatchPinRedeploysOntoThePin(t *testing.T) {
	h := newHarness(t).login()
	h.deployHello()
	_, newer := seedPythonPatches(h)

	var res patchActionResult
	decodeJSON(t, h.mustRun("projects", "patch", "pin", "hello-api", newer.ID, "-o", "json").out, &res)
	if res.Migration == nil || res.Migration.Trigger != "pin" || res.State == nil || res.State.Pin == nil || res.State.Pin.ID != newer.ID {
		t.Fatalf("pin = %+v", res)
	}
}

func TestRuntimesPatches(t *testing.T) {
	h := newHarness(t).login()
	h.deployHello()
	seedPythonPatches(h)
	h.srv.SeedRuntimePatch(runr.RuntimePatch{Runtime: "php", Version: "8.4", Tag: "8.4.14-r1", Level: runr.PatchZeroDay,
		Reason: "Remote code execution in the FPM worker", DeadlineAt: time.Now().Add(48 * time.Hour).UTC().Format(time.RFC3339)})

	r := h.mustRun("runtimes", "patches")
	contains(t, r.out, "8.4.14-r1")
	contains(t, r.out, "zero-day")
	contains(t, r.out, "3.13.9-r1")

	r = h.mustRun("runtimes", "patches", "--runtime", "php")
	contains(t, r.out, "8.4.14-r1")
	notContains(t, r.out, "3.13.9-r1")
	calls := h.srv.CallsTo("GET /runtime-patches")
	if last := calls[len(calls)-1]; !strings.Contains(last.Query, "runtime=php") {
		t.Fatalf("query = %q", last.Query)
	}

	var list runr.RuntimePatchList
	decodeJSON(t, h.mustRun("runtimes", "patches", "--runtime", "python", "--version", "3.13", "-o", "json").out, &list)
	if len(list.Patches) != 2 || !list.Feed.Enabled || list.Keep != 5 {
		t.Fatalf("list = %+v", list)
	}
}

func TestPatchStateLabel(t *testing.T) {
	cases := []struct {
		state runr.RuntimePatchState
		want  []string
	}{
		{runr.RuntimePatchState{State: runr.PatchUpToDate, Deployed: true, Current: &runr.PatchRef{Tag: "8.3.36-r1"}, Latest: &runr.PatchRef{Tag: "8.3.36-r1"}},
			[]string{"up to date", "8.3.36-r1"}},
		{runr.RuntimePatchState{State: runr.PatchOverdue, Level: runr.PatchZeroDay, Deployed: true, Pending: []string{"x"},
			Latest: &runr.PatchRef{Tag: "8.3.36-r1"}, DeadlineAt: "2026-10-20T10:00:00Z"},
			[]string{"overdue (zero-day)", "an older image → 8.3.36-r1", "deadline 2026-10-"}},
		{runr.RuntimePatchState{State: runr.PatchPinned, Deployed: true, Current: &runr.PatchRef{Digest: "sha256:abc"},
			Pin: &runr.PatchPin{Digest: "sha256:abc", Available: true, By: "runr"}},
			[]string{"pinned", "pinned to sha256:abc by RunR after a failed migration"}},
		{runr.RuntimePatchState{State: runr.PatchPinned, Pin: &runr.PatchPin{ID: "php-8.3-8.3.30-r1"}},
			[]string{"not deployed", "no longer offered"}},
	}
	for _, c := range cases {
		got := patchStateLabel(&c.state)
		for _, w := range c.want {
			if !strings.Contains(got, w) {
				t.Errorf("patchStateLabel(%+v) = %q, want %q in it", c.state, got, w)
			}
		}
	}
}
