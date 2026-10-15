package runr_test

import (
	"context"
	"errors"
	"testing"
	"time"

	"github.com/runrsh/core/cli/pkg/runr"
	"github.com/runrsh/core/cli/pkg/runr/runrtest"
)

func bindWait() runr.WaitOptions {
	return runr.WaitOptions{Timeout: 5 * time.Second, Interval: time.Millisecond}
}

func TestBindingLifecycle(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	c := client(t, srv)
	region, err := c.FindRegion(ctx, "")
	if err != nil {
		t.Fatal(err)
	}

	b, err := c.CreateBinding(ctx, runr.CreateBindingInput{Name: "cache", Type: runr.BindingKV, RegionID: region.ID,
		Settings: map[string]any{"memory_mb": 128}})
	if err != nil {
		t.Fatal(err)
	}
	if b.Status != runr.BindingProvisioning || b.Settings["memory_mb"] != float64(128) || b.RegionName() != "eu-1" {
		t.Fatalf("created = %+v", b)
	}
	if len(b.EnvKeys) != 2 || b.EnvKeys[0] != "REDIS_URL" {
		t.Fatalf("env keys = %v", b.EnvKeys)
	}
	var statuses []string
	opts := bindWait()
	opts.Progress = func(s string) { statuses = append(statuses, s) }
	b, err = c.WaitBinding(ctx, b, opts)
	if err != nil || b.Status != runr.BindingReady || len(statuses) == 0 || statuses[0] != runr.BindingProvisioning {
		t.Fatalf("wait = %+v %v (progress %v)", b, err, statuses)
	}

	found, err := c.FindBinding(ctx, "cache")
	if err != nil || found.ID != b.ID {
		t.Fatalf("find by name = %+v %v", found, err)
	}
	if found, err = c.FindBinding(ctx, b.ID); err != nil || found.Name != "cache" {
		t.Fatalf("find by id = %+v %v", found, err)
	}
	var nf *runr.NotFoundError
	if _, err := c.FindBinding(ctx, "ghost"); !errors.As(err, &nf) {
		t.Fatalf("ghost: %v", err)
	}

	b, err = c.UpdateBinding(ctx, b.ID, runr.UpdateBindingInput{Settings: map[string]any{"memory_mb": 256}})
	if err != nil || b.Settings["memory_mb"] != float64(256) {
		t.Fatalf("update = %+v %v", b, err)
	}
	if _, err := c.UpdateBinding(ctx, b.ID, runr.UpdateBindingInput{Values: map[string]string{"A": "1"}}); !runr.IsValidation(err) {
		t.Fatalf("values on a kv binding: %v", err)
	}

	before := srv.BindingCredentialsOf("cache")["REDIS_URL"]
	b, err = c.RotateBinding(ctx, b.ID)
	if err != nil || b.Status != runr.BindingRotating {
		t.Fatalf("rotate = %+v %v", b, err)
	}
	if b, err = c.WaitBinding(ctx, b, bindWait()); err != nil || b.RotatedAt == "" {
		t.Fatalf("rotation wait = %+v %v", b, err)
	}
	if after := srv.BindingCredentialsOf("cache")["REDIS_URL"]; after == before {
		t.Fatal("rotation kept the old credentials")
	}

	deleted, msg, err := c.DeleteBinding(ctx, b)
	if err != nil || deleted.Status != runr.BindingDeleting || deleted.PurgeAt == "" || !deleted.Deleted() || msg == "" {
		t.Fatalf("delete = %+v %q %v", deleted, msg, err)
	}
	if calls := srv.CallsTo("DELETE /bindings/{binding}"); len(calls) != 1 || calls[0].Body["confirm"] != "cache" {
		t.Fatalf("delete calls = %+v", calls)
	}
	if _, _, err := c.DeleteBinding(ctx, b); !runr.IsValidation(err) {
		t.Fatalf("second delete: %v", err)
	}
	restored, err := c.RestoreBinding(ctx, b.ID)
	if err != nil || restored.Status != runr.BindingReady || restored.PurgeAt != "" {
		t.Fatalf("restore = %+v %v", restored, err)
	}

	got, h, err := c.BindingStatus(ctx, b.ID)
	if err != nil || got.Name != "cache" || h == nil || h.State == "" {
		t.Fatalf("status = %+v %+v %v", got, h, err)
	}
	usage, err := c.BindingUsage(ctx, b.ID, 7)
	if err != nil || len(usage) != 1 || usage[0].RecordDate == "" {
		t.Fatalf("usage = %+v %v", usage, err)
	}
	if calls := srv.CallsTo("GET /bindings/{binding}/usage"); len(calls) != 1 || calls[0].Query != "days=7" {
		t.Fatalf("usage calls = %+v", calls)
	}
}

func TestBindingProvisioningFails(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	c := client(t, srv)
	srv.Bindings().FailProvision = true
	region, _ := c.FindRegion(ctx, "")
	b, err := c.CreateBinding(ctx, runr.CreateBindingInput{Name: "broken", Type: runr.BindingPostgres, RegionID: region.ID})
	if err != nil {
		t.Fatal(err)
	}
	_, err = c.WaitBinding(ctx, b, bindWait())
	var fe *runr.FailedError
	if !errors.As(err, &fe) || fe.Status != runr.BindingError || fe.Message == "" {
		t.Fatalf("wait of a failing binding: %v", err)
	}
}

func TestBindingValidation(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	c := client(t, srv)
	region, _ := c.FindRegion(ctx, "")
	cases := []runr.CreateBindingInput{
		{Name: "Bad_Name", Type: runr.BindingKV, RegionID: region.ID},
		{Name: "no-region", Type: runr.BindingObject},
		{Name: "ext", Type: runr.BindingExternal},
		{Name: "ext-bad", Type: runr.BindingExternal, Values: map[string]string{"lower": "x"}},
		{Name: "big", Type: runr.BindingKV, RegionID: region.ID, Settings: map[string]any{"memory_mb": 999999}},
		{Name: "dedicated-kv", Type: runr.BindingKV, Plan: runr.BindingPlanDedicated, RegionID: region.ID},
	}
	for _, in := range cases {
		if _, err := c.CreateBinding(ctx, in); !runr.IsValidation(err) {
			t.Errorf("%s: want 422, got %v", in.Name, err)
		}
	}
	srv.SeedBinding("taken", runr.BindingKV, "eu-1", nil, nil)
	if _, err := c.CreateBinding(ctx, runr.CreateBindingInput{Name: "taken", Type: runr.BindingKV, RegionID: region.ID}); !runr.IsValidation(err) {
		t.Fatalf("duplicate name: %v", err)
	}
	srv.Bindings().DeniedRegions = []string{"eu-1"}
	if _, err := c.CreateBinding(ctx, runr.CreateBindingInput{Name: "elsewhere", Type: runr.BindingKV, RegionID: region.ID}); !runr.IsValidation(err) {
		t.Fatalf("residency: %v", err)
	}
}

func TestExternalBinding(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	c := client(t, srv)
	b, err := c.CreateBinding(ctx, runr.CreateBindingInput{Name: "payments", Type: runr.BindingExternal,
		Values: map[string]string{"PAYMENTS_URL": "https://pay.example.com", "PAYMENTS_TOKEN": "tok"}})
	if err != nil {
		t.Fatal(err)
	}
	if b.RegionID != "" || b.Plan != "external" || len(b.EnvKeys) != 2 || b.EnvKeys[0] != "PAYMENTS_TOKEN" {
		t.Fatalf("external = %+v", b)
	}
	if _, ok := srv.CallsTo("POST /bindings")[0].Body["knative_region_id"]; ok {
		t.Fatal("an external binding sent a region")
	}
	env, err := c.BindingCredentials(ctx, b.ID)
	if err != nil || env["PAYMENTS_TOKEN"] != "tok" {
		t.Fatalf("credentials = %v %v", env, err)
	}
	b, err = c.UpdateBinding(ctx, b.ID, runr.UpdateBindingInput{Values: map[string]string{"PAYMENTS_URL": "https://v2.example.com"}})
	if err != nil || len(b.EnvKeys) != 1 {
		t.Fatalf("update values = %+v %v", b, err)
	}
}

func TestBindingAttachments(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	c := client(t, srv)
	proj := srv.SeedProject("orders-api", runr.TypeFaaS, "python", "eu-1", nil)
	db := srv.SeedBinding("orders-db", runr.BindingPostgres, "eu-1", nil, nil)
	srv.SeedBinding("other-db", runr.BindingPostgres, "eu-1", nil, nil)

	pb, err := c.ProjectBindings(ctx, proj.ID)
	if err != nil || len(pb.Attachments) != 0 || len(pb.Available) != 2 || pb.Secret != "orders-api-bindings" {
		t.Fatalf("project bindings = %+v %v", pb, err)
	}
	a, err := c.AttachBinding(ctx, proj.ID, runr.AttachBindingInput{BindingID: db.ID, EnvPrefix: "ORDERS_"})
	if err != nil || a.EnvPrefix != "ORDERS_" || a.Binding == nil || a.Binding.Name != "orders-db" {
		t.Fatalf("attach = %+v %v", a, err)
	}
	if a.EnvNames[0] != "ORDERS_DATABASE_URL" {
		t.Fatalf("env names = %v", a.EnvNames)
	}
	// A second Postgres binding with the same prefix would set the same variables.
	other, _ := c.FindBinding(ctx, "other-db")
	if _, err := c.AttachBinding(ctx, proj.ID, runr.AttachBindingInput{BindingID: other.ID, EnvPrefix: "ORDERS_"}); !runr.IsValidation(err) {
		t.Fatalf("env conflict: %v", err)
	}
	if _, err := c.AttachBinding(ctx, proj.ID, runr.AttachBindingInput{BindingID: db.ID}); !runr.IsValidation(err) {
		t.Fatalf("attached twice: %v", err)
	}

	found, err := c.FindAttachment(ctx, proj.ID, "orders-db")
	if err != nil || found.ID != a.ID {
		t.Fatalf("find attachment = %+v %v", found, err)
	}
	upd, err := c.UpdateAttachment(ctx, proj.ID, a.ID, "")
	if err != nil || upd.EnvPrefix != "" || upd.EnvNames[0] != "DATABASE_URL" {
		t.Fatalf("clear prefix = %+v %v", upd, err)
	}
	if calls := srv.CallsTo("PATCH /projects/{project}/bindings/{attachment}"); len(calls) != 1 || calls[0].Body["env_prefix"] != "" {
		t.Fatalf("patch calls = %+v", calls)
	}
	listed, _ := c.FindBinding(ctx, "orders-db")
	if len(listed.Attachments) != 1 || listed.Attachments[0].ProjectName() != "orders-api" {
		t.Fatalf("binding attachments = %+v", listed.Attachments)
	}

	if err := c.DetachBinding(ctx, proj.ID, a.ID); err != nil {
		t.Fatal(err)
	}
	var nf *runr.NotFoundError
	if _, err := c.FindAttachment(ctx, proj.ID, "orders-db"); !errors.As(err, &nf) {
		t.Fatalf("after detach: %v", err)
	}
	if err := c.DetachBinding(ctx, proj.ID, a.ID); !runr.IsNotFound(err) {
		t.Fatalf("detach twice: %v", err)
	}
}

func TestCreateAndAttachBinding(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	c := client(t, srv)
	srv.AddRegion("us-1", true)
	proj := srv.SeedProject("web", runr.TypeFaaS, "python", "us-1", nil)
	a, err := c.AttachBinding(ctx, proj.ID, runr.AttachBindingInput{EnvPrefix: "CACHE_",
		Binding: &runr.CreateBindingInput{Name: "web-cache", Type: runr.BindingKV, RegionID: "ignored", Settings: map[string]any{"memory_mb": 32}}})
	if err != nil {
		t.Fatal(err)
	}
	if a.Binding == nil || a.Binding.RegionName() != "us-1" || a.Binding.Settings["memory_mb"] != float64(32) {
		t.Fatalf("create + attach = %+v", a)
	}
	body := srv.CallsTo("POST /projects/{project}/bindings")[0].Body
	if nested, _ := body["binding"].(map[string]any); nested == nil || nested["knative_region_id"] != nil {
		t.Fatalf("create + attach body = %v", body)
	}
	if att := srv.ProjectAttachments("web"); len(att) != 1 || att[0].EnvPrefix != "CACHE_" {
		t.Fatalf("fake attachments = %+v", att)
	}
}

func TestBindingPermissions(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	b := srv.SeedBinding("cache", runr.BindingKV, "eu-1", nil, nil)
	proj := srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	viewer := client(t, srv).WithToken(srv.ScopedToken("viewer", "bindings.view", "projects.view"))
	if _, err := viewer.ListBindings(ctx, runr.BindingFilter{}); err != nil {
		t.Fatalf("list with bindings.view: %v", err)
	}
	_, err := viewer.BindingCredentials(ctx, b.ID)
	if got := runr.MissingPermissions(err); len(got) != 1 || got[0] != "bindings.view_credentials" {
		t.Fatalf("credentials without bindings.view_credentials: %v (%v)", got, err)
	}
	// Attaching needs env.edit on the project (route) and bindings.manage (controller).
	editor := client(t, srv).WithToken(srv.ScopedToken("editor", "env.edit"))
	_, err = editor.AttachBinding(ctx, proj.ID, runr.AttachBindingInput{BindingID: b.ID})
	if got := runr.MissingPermissions(err); len(got) != 1 || got[0] != "bindings.manage" {
		t.Fatalf("attach without bindings.manage: %v (%v)", got, err)
	}
	reader := client(t, srv).WithToken(srv.ScopedToken("reader", "projects.view"))
	pb, err := reader.ProjectBindings(ctx, proj.ID)
	if err != nil || len(pb.Available) != 0 {
		t.Fatalf("project bindings without bindings.view = %+v %v", pb, err)
	}
}

func TestBindingHelpers(t *testing.T) {
	for in, want := range map[string]bool{"": true, "ORDERS_": true, "A1_B_": true, "orders_": false, "ORDERS": false,
		"1A_": false, "A-B_": false, "ABCDEFGHIJKLMNOPQRSTUVWXYZABCDEF_": false} {
		if got := runr.ValidEnvPrefix(in); got != want {
			t.Errorf("ValidEnvPrefix(%q) = %v", in, got)
		}
	}
	list := []runr.Binding{{ID: "01jabc", Name: "cache"}, {ID: "01jdef", Name: "orders-db"}}
	if b := runr.PickBinding(list, "orders-db"); b == nil || b.ID != "01jdef" {
		t.Fatalf("by name: %+v", b)
	}
	if b := runr.PickBinding(list, "01JABC"); b == nil || b.Name != "cache" {
		t.Fatalf("by id, other case: %+v", b)
	}
	if runr.PickBinding(list, "x") != nil {
		t.Fatal("matched an unknown binding")
	}
	v := runr.BindingValues{"storage_gb": float64(2), "statement_timeout": "15s", "backups": true, "skip": nil}
	if got := v.String(); got != "backups=true, statement_timeout=15s, storage_gb=2" {
		t.Fatalf("values string = %q", got)
	}
}
