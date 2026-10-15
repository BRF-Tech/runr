package cmd

import (
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestModelSnapshotsRmAndLogs(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedModel(modelHubMiniLM)
	h.mustRun("models", "snapshot", "all-minilm-l6-v2", "--region", "eu-1")

	// SNAPSHOT is the region's name or the copy's id.
	r := h.mustRun("models", "snapshots", "logs", "all-minilm-l6-v2", "eu-1")
	contains(t, r.out, "snapshot ready")
	var logs map[string]any
	decodeJSON(t, h.mustRun("models", "snapshots", "logs", "all-minilm-l6-v2", "eu-1", "-o", "json").out, &logs)
	if logs["region"] != "eu-1" || logs["logs"] == "" {
		t.Fatalf("logs json = %v", logs)
	}

	var list []runr.ModelSnapshot
	decodeJSON(t, h.mustRun("models", "snapshots", "all-minilm-l6-v2", "-o", "json").out, &list)
	if len(list) != 1 {
		t.Fatalf("snapshots = %+v", list)
	}

	h.expectCode(ExitUsage, "models", "snapshots", "rm", "all-minilm-l6-v2", list[0].ID)
	h.mustRun("models", "snapshots", "rm", "all-minilm-l6-v2", list[0].ID, "--yes")
	if n := len(h.srv.CallsTo("DELETE /models/{model}/snapshots/{snapshot}")); n != 1 {
		t.Fatalf("delete calls = %d", n)
	}
	h.expectCode(ExitNotFound, "models", "snapshots", "rm", "all-minilm-l6-v2", "eu-1", "--yes")
	h.expectCode(ExitNotFound, "models", "snapshots", "logs", "all-minilm-l6-v2", "eu-1")
}

func TestModelSnapshotRmRefusedWhileServed(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedModel(modelHubMiniLM)
	h.mustRun("models", "snapshot", "all-minilm-l6-v2", "--region", "eu-1")
	h.mustRun("models", "deploy", "all-minilm-l6-v2", "--mode", "offline", "--region", "eu-1")
	r := h.expectCode(ExitValidation, "models", "snapshots", "rm", "all-minilm-l6-v2", "eu-1", "--yes")
	contains(t, r.err, "offline copy")
}

func TestModelsGPUQuota(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("models", "gpu-quota")
	contains(t, r.out, "unlimited")

	six := 6
	h.srv.SeedGPUQuota("eu-1", &six, 2)
	var q runr.GPUQuota
	decodeJSON(t, h.mustRun("models", "gpu-quota", "--region", "eu-1", "-o", "json").out, &q)
	if q.Quota == nil || *q.Quota != 6 || q.Used != 2 || q.Remaining == nil || *q.Remaining != 4 {
		t.Fatalf("quota = %+v", q)
	}
	h.expectCode(ExitNotFound, "models", "gpu-quota", "--region", "mars-1")
}

func TestModelsGPUQuotaSet(t *testing.T) {
	h := newHarness(t).login()
	h.expectCode(ExitUsage, "models", "gpu-quota", "set", "--region", "eu-1", "--gpus", "2")
	h.expectCode(ExitUsage, "models", "gpu-quota", "set", "--tenant", "01JTENANT0000000000000000A", "--region", "eu-1")
	h.expectCode(ExitUsage, "models", "gpu-quota", "set", "--tenant", "01JTENANT0000000000000000A", "--gpus", "2", "--unlimited")
	h.expectCode(ExitUsage, "models", "gpu-quota", "set", "--tenant", "01JTENANT0000000000000000A", "--gpus", "-1")

	// Single-tenant installations have no tenants: 404.
	r := h.expectCode(ExitNotFound, "models", "gpu-quota", "set", "--tenant", "01JTENANT0000000000000000A", "--gpus", "2")
	contains(t, r.err, "single-tenant")

	h.srv.RBACMultiTenant()
	h.mustRun("models", "gpu-quota", "set", "--tenant", "01JTENANT0000000000000000A", "--region", "eu-1", "--gpus", "2")
	if q := h.srv.GPUQuotaOf("eu-1"); q == nil || *q != 2 {
		t.Fatalf("quota = %v", q)
	}
	r = h.mustRun("models", "gpu-quota", "set", "--tenant", "01JTENANT0000000000000000A", "--unlimited", "-o", "json")
	contains(t, r.out, `"gpu_quota": null`)
	if q := h.srv.GPUQuotaOf("eu-1"); q != nil {
		t.Fatalf("unlimited quota = %v", *q)
	}

	// A scoped token without platform administration is refused.
	tok := h.srv.ScopedToken("models", "models.view")
	h.loginWith(tok)
	h.expectCode(ExitAuth, "models", "gpu-quota", "set", "--tenant", "01JTENANT0000000000000000A", "--gpus", "1")
}
