package cmd

import (
	"path/filepath"
	"testing"

	"github.com/runrsh/core/cli/pkg/runr"
)

func TestBindingsCreateListGet(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("bindings", "create", "cache", "--type", "redis", "--set", "memory_mb=128", "--wait")
	contains(t, r.err, "Created binding cache")
	contains(t, r.out, "memory_mb=128")
	b, ok := h.srv.BindingByName("cache")
	if !ok || b.Status != runr.BindingReady || b.Type != runr.BindingKV {
		t.Fatalf("binding = %+v", b)
	}
	body := h.srv.CallsTo("POST /bindings")[0].Body
	settings, _ := body["settings"].(map[string]any)
	if settings["memory_mb"] != float64(128) || body["knative_region_id"] == nil {
		t.Fatalf("create body = %v", body)
	}

	r = h.mustRun("bindings", "ls")
	contains(t, r.out, "cache")
	contains(t, r.out, "kv")
	contains(t, r.out, "eu-1")
	var list []runr.Binding
	decodeJSON(t, h.mustRun("bindings", "ls", "-o", "json").out, &list)
	if len(list) != 1 || list[0].Name != "cache" {
		t.Fatalf("ls json = %+v", list)
	}
	if r := h.mustRun("bindings", "ls", "--type", "postgres"); r.out != "NAME   TYPE   REGION   STATUS   PROJECTS   SETTINGS\n" {
		t.Fatalf("filtered ls = %q", r.out)
	}
	if q := h.srv.CallsTo("GET /bindings"); q[len(q)-1].Query != "type=postgres" {
		t.Fatalf("type filter query = %q", q[len(q)-1].Query)
	}

	r = h.mustRun("bindings", "get", "cache", "--usage")
	contains(t, r.out, "Health:")
	contains(t, r.out, "REDIS_URL, KV_PREFIX")
	contains(t, r.out, "STORAGE GB-H")
	var got struct {
		Binding runr.Binding        `json:"binding"`
		Health  *runr.BindingHealth `json:"health"`
	}
	decodeJSON(t, h.mustRun("bindings", "get", "cache", "-o", "json").out, &got)
	if got.Binding.Name != "cache" || got.Health == nil || got.Health.State == "" {
		t.Fatalf("get json = %+v", got)
	}
	h.expectCode(ExitNotFound, "bindings", "get", "ghost")
}

func TestBindingsCreateUsage(t *testing.T) {
	h := newHarness(t).login()
	h.expectCode(ExitUsage, "bindings", "create", "x", "--type", "mysql")
	h.expectCode(ExitUsage, "bindings", "create", "x")
	h.expectCode(ExitUsage, "bindings", "create", "ext", "--type", "external")
	h.expectCode(ExitUsage, "bindings", "create", "kv", "--type", "kv", "--value", "A=1")
	h.expectCode(ExitUsage, "bindings", "create", "kv", "--type", "kv", "--plan", "dedicated")
	h.expectCode(ExitUsage, "bindings", "create", "kv", "--type", "kv", "--attach", "--env-prefix", "lower_")
	h.expectCode(ExitUsage, "bindings", "create", "kv", "--type", "kv", "--set", "novalue")
	h.expectCode(ExitUsage, "bindings", "create", "kv", "--type", "kv", "--config", "{not json")
	// The API's validation: name pattern and maxima.
	h.expectCode(ExitValidation, "bindings", "create", "Bad_Name", "--type", "kv")
	h.expectCode(ExitValidation, "bindings", "create", "huge", "--type", "kv", "--set", "memory_mb=999999")
	if calls := h.srv.CallsTo("POST /bindings"); len(calls) != 2 {
		t.Fatalf("%d create calls, want 2 (usage errors send nothing)", len(calls))
	}
}

func TestBindingsCreateWithConfigFileAndDedicatedPlan(t *testing.T) {
	h := newHarness(t).login()
	dir := t.TempDir()
	cfg := filepath.Join(dir, "pg.json")
	write(t, cfg, `{"storage_gb": 10, "instances": 2, "backups": true}`)
	h.mustRun("bindings", "create", "reports", "--type", "pg", "--plan", "dedicated", "--config", "@"+cfg,
		"--set", "storage_gb=20", "--set", "statement_timeout=15s")
	b, _ := h.srv.BindingByName("reports")
	if b.Plan != runr.BindingPlanDedicated || b.Settings["storage_gb"] != float64(20) || b.Settings["instances"] != float64(2) ||
		b.Settings["backups"] != true || b.Settings["statement_timeout"] != "15s" {
		t.Fatalf("dedicated binding = %+v", b)
	}
}

func TestBindingsProvisioningFailureExits6(t *testing.T) {
	h := newHarness(t).login()
	h.srv.Bindings().FailProvision = true
	r := h.expectCode(ExitFailed, "bindings", "create", "broken", "--type", "postgres", "--wait")
	contains(t, r.err, "binding broken failed")
}

func TestBindingsResidencyRefusal(t *testing.T) {
	h := newHarness(t).login()
	h.srv.Bindings().DeniedRegions = []string{"eu-1"}
	r := h.expectCode(ExitValidation, "bindings", "create", "cache", "--type", "kv")
	contains(t, r.err, "data residency")
}

func TestBindingsExternalCredentialsAndUpdate(t *testing.T) {
	h := newHarness(t).login()
	dir := t.TempDir()
	envFile := filepath.Join(dir, "payments.env")
	write(t, envFile, "PAYMENTS_TOKEN=tok-123\n# comment\n")
	h.mustRun("bindings", "create", "payments", "--type", "external", "--value", "PAYMENTS_URL=https://pay.example.com",
		"--from-file", envFile)
	b, _ := h.srv.BindingByName("payments")
	if b.RegionID != "" || len(b.EnvKeys) != 2 {
		t.Fatalf("external binding = %+v", b)
	}
	if body := h.srv.CallsTo("POST /bindings")[0].Body; body["knative_region_id"] != nil {
		t.Fatalf("external binding sent a region: %v", body)
	}

	r := h.mustRun("bindings", "credentials", "payments")
	contains(t, r.out, "PAYMENTS_TOKEN")
	contains(t, r.out, "tok-123")
	contains(t, r.err, "secret")
	var creds struct {
		Env map[string]string `json:"env"`
	}
	decodeJSON(t, h.mustRun("bindings", "credentials", "payments", "--env-prefix", "PAY_", "-o", "json").out, &creds)
	if creds.Env["PAY_PAYMENTS_URL"] != "https://pay.example.com" {
		t.Fatalf("prefixed credentials = %v", creds.Env)
	}

	h.mustRun("bindings", "update", "payments", "--value", "PAYMENTS_URL=https://v2.example.com")
	if b, _ := h.srv.BindingByName("payments"); len(b.EnvKeys) != 1 {
		t.Fatalf("values not replaced: %+v", b.EnvKeys)
	}
	h.srv.SeedBinding("cache", runr.BindingKV, "eu-1", nil, nil)
	h.expectCode(ExitUsage, "bindings", "update", "cache", "--value", "A=1")
	h.expectCode(ExitUsage, "bindings", "update", "cache")
	h.mustRun("bindings", "update", "cache", "--set", "memory_mb=256")
	if b, _ := h.srv.BindingByName("cache"); b.Settings["memory_mb"] != float64(256) {
		t.Fatalf("settings = %v", b.Settings)
	}
}

func TestBindingsCredentialsNeedPermission(t *testing.T) {
	h := newHarness(t)
	h.srv.SeedBinding("cache", runr.BindingKV, "eu-1", nil, nil)
	h.loginWith(h.srv.ScopedToken("viewer", "bindings.view"))
	h.mustRun("bindings", "ls")
	r := h.expectCode(ExitAuth, "bindings", "credentials", "cache")
	contains(t, r.err, "bindings.view_credentials")
	notContains(t, r.out, "REDIS_URL")
}

func TestBindingsAttachDetach(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	h.srv.SeedBinding("orders-db", runr.BindingPostgres, "eu-1", nil, nil)
	h.srv.SeedBinding("cache", runr.BindingKV, "eu-1", nil, nil)

	r := h.mustRun("bindings", "attach", "orders-db", "-p", "svc", "--env-prefix", "ORDERS_")
	contains(t, r.err, "Attached orders-db to svc")
	contains(t, r.out, "ORDERS_DATABASE_URL")
	att := h.srv.ProjectAttachments("svc")
	if len(att) != 1 || att[0].EnvPrefix != "ORDERS_" {
		t.Fatalf("attachments = %+v", att)
	}

	r = h.mustRun("bindings", "attachments", "-p", "svc")
	contains(t, r.out, "orders-db")
	contains(t, r.out, "ORDERS_PGHOST")
	contains(t, r.out, "Can be attached: cache")

	// Attaching again: the same prefix is a no-op, another one changes it.
	r = h.mustRun("bindings", "attach", "orders-db", "-p", "svc", "--env-prefix", "ORDERS_")
	contains(t, r.err, "already attached")
	h.mustRun("bindings", "attach", "orders-db", "-p", "svc", "--env-prefix", "SHOP_")
	if att := h.srv.ProjectAttachments("svc"); att[0].EnvPrefix != "SHOP_" {
		t.Fatalf("prefix not changed: %+v", att)
	}
	if n := len(h.srv.CallsTo("POST /projects/{project}/bindings")); n != 1 {
		t.Fatalf("%d attach calls, want 1", n)
	}
	h.expectCode(ExitUsage, "bindings", "attach", "cache", "-p", "svc", "--env-prefix", "nope")

	r = h.mustRun("bindings", "detach", "orders-db", "-p", "svc")
	contains(t, r.err, "Detached orders-db from svc")
	if att := h.srv.ProjectAttachments("svc"); len(att) != 0 {
		t.Fatalf("still attached: %+v", att)
	}
	h.expectCode(ExitNotFound, "bindings", "detach", "orders-db", "-p", "svc")
	h.expectCode(ExitNotFound, "bindings", "attach", "ghost", "-p", "svc")
}

func TestBindingsCreateAndAttach(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	r := h.mustRun("bindings", "create", "files", "--type", "s3", "--set", "quota_gb=10", "--attach", "--env-prefix", "FILES_", "--dir", dir)
	contains(t, r.err, "attached it to hello-api")
	contains(t, r.out, "FILES_S3_BUCKET")
	att := h.srv.ProjectAttachments("hello-api")
	if len(att) != 1 || att[0].Binding == nil || att[0].Binding.Name != "files" {
		t.Fatalf("attachments = %+v", att)
	}
	if len(h.srv.CallsTo("POST /bindings")) != 0 {
		t.Fatal("create + attach used POST /bindings")
	}
	h.expectCode(ExitUsage, "bindings", "create", "more", "--type", "kv", "--env-prefix", "MORE_")
	h.expectCode(ExitUsage, "bindings", "create", "more", "--type", "kv", "--attach", "--region", "eu-1", "--dir", dir)
}

func TestBindingsRmRestoreRotate(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	h.srv.SeedBinding("cache", runr.BindingKV, "eu-1", nil, nil)
	h.mustRun("bindings", "attach", "cache", "-p", "svc")

	h.expectCode(ExitUsage, "bindings", "rm", "cache")
	r := h.mustRun("bindings", "rm", "cache", "--yes")
	contains(t, r.err, "scheduled for deletion")
	b, _ := h.srv.BindingByName("cache")
	if b.Status != runr.BindingDeleting || len(h.srv.ProjectAttachments("svc")) != 0 {
		t.Fatalf("after rm: %+v", b)
	}
	if r := h.mustRun("bindings", "ls"); r.out != "NAME   TYPE   REGION   STATUS   PROJECTS   SETTINGS\n" {
		t.Fatalf("ls shows a deleted binding: %q", r.out)
	}
	r = h.mustRun("bindings", "ls", "--deleted")
	contains(t, r.out, "cache")
	contains(t, r.out, "PURGE AT")
	h.expectCode(ExitValidation, "bindings", "rotate", "cache", "--yes")

	r = h.mustRun("bindings", "restore", "cache")
	contains(t, r.err, "Restored cache")
	h.expectCode(ExitValidation, "bindings", "restore", "cache")

	h.expectCode(ExitUsage, "bindings", "rotate", "cache")
	before := h.srv.BindingCredentialsOf("cache")["REDIS_URL"]
	r = h.mustRun("bindings", "rotate", "cache", "--yes", "--wait")
	contains(t, r.err, "New credentials of cache")
	if h.srv.BindingCredentialsOf("cache")["REDIS_URL"] == before {
		t.Fatal("rotate kept the credentials")
	}
}

func TestBindingsTypesAndQuota(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("bindings", "types")
	contains(t, r.out, "REDIS_URL, KV_PREFIX")
	contains(t, r.out, "shared, dedicated")
	contains(t, r.out, "kept 7 days")
	var info runr.BindingTypesInfo
	decodeJSON(t, h.mustRun("bindings", "types", "-o", "json").out, &info)
	if len(info.Types) != 4 || info.RetentionDays != 7 {
		t.Fatalf("types json = %+v", info)
	}
	h.srv.SeedBinding("cache", runr.BindingKV, "eu-1", map[string]any{"memory_mb": 128}, nil)
	r = h.mustRun("bindings", "quota")
	contains(t, r.out, "max_bindings")
	contains(t, r.out, "kv_mb")
}
