package runr_test

import (
	"bytes"
	"context"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/runrsh/core/cli/pkg/runr"
	"github.com/runrsh/core/cli/pkg/runr/runrtest"
)

func radmRegionID(t *testing.T, srv *runrtest.Server, c *runr.Client) string {
	t.Helper()
	reg, err := c.FindRegion(context.Background(), "eu-1")
	if err != nil {
		t.Fatal(err)
	}
	return reg.ID
}

var radmFast = runr.WaitOptions{Timeout: 5 * time.Second, Interval: time.Millisecond}

func TestRegionTracingClient(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	rid := radmRegionID(t, srv, c)

	cur, err := c.RegionTracing(ctx, rid)
	if err != nil {
		t.Fatal(err)
	}
	if cur.Mode != runr.TracingManaged || cur.RegionID != rid || cur.RetentionHours == nil {
		t.Fatalf("tracing = %+v", cur)
	}

	endpoint := "https://otlp.example.com/v1/traces"
	headers := map[string]string{"Authorization": "Bearer otlp-secret"}
	set, _, err := c.SetRegionTracing(ctx, rid, runr.RegionTracingInput{Mode: runr.TracingExternal, ExportEndpoint: &endpoint,
		ExportHeaders: &headers})
	if err != nil {
		t.Fatal(err)
	}
	if set.Mode != runr.TracingExternal || set.ExportEndpoint != endpoint || len(set.ExportHeaderNames) != 1 ||
		set.ExportHeaderNames[0] != "Authorization" {
		t.Fatalf("set = %+v", set)
	}
	if got := srv.RegionTracingHeaders("eu-1"); got["Authorization"] != "Bearer otlp-secret" {
		t.Fatalf("stored headers = %v", got)
	}

	inst, msg, err := c.InstallRegionTracing(ctx, rid)
	if err != nil || inst.Status != runr.RegionServiceInstalling || msg == "" {
		t.Fatalf("install = %+v %q %v", inst, msg, err)
	}
	ready, err := c.WaitRegionTracing(ctx, rid, "eu-1", radmFast)
	if err != nil || ready.Status != runr.RegionServiceReady || !ready.AcceptsSpans {
		t.Fatalf("wait = %+v %v", ready, err)
	}

	if _, err := c.RemoveRegionTracing(ctx, rid); err != nil {
		t.Fatal(err)
	}
	calls := srv.CallsTo("DELETE /observability/regions/{knativeRegion}/tracing")
	if len(calls) != 1 {
		t.Fatalf("delete calls = %d", len(calls))
	}
}

func TestRegionTracingInstallFails(t *testing.T) {
	srv := runrtest.New(t)
	srv.RegionAdmin().FailInstall = true
	c := client(t, srv)
	ctx := context.Background()
	rid := radmRegionID(t, srv, c)
	if _, _, err := c.SetRegionTracing(ctx, rid, runr.RegionTracingInput{Mode: runr.TracingManaged, Install: true}); err != nil {
		t.Fatal(err)
	}
	_, err := c.WaitRegionTracing(ctx, rid, "eu-1", radmFast)
	var fe *runr.FailedError
	if !errors.As(err, &fe) || fe.Status != runr.RegionServiceError {
		t.Fatalf("wait err = %v", err)
	}
}

func TestRegionTracingNoCluster(t *testing.T) {
	srv := runrtest.New(t)
	srv.RegionAdmin().Disconnected = []string{"eu-1"}
	c := client(t, srv)
	rid := radmRegionID(t, srv, c)
	_, _, err := c.InstallRegionTracing(context.Background(), rid)
	var ae *runr.APIError
	if !errors.As(err, &ae) || ae.StatusCode != 409 {
		t.Fatalf("install without a cluster = %v", err)
	}
}

func TestRegionTracingHeadersNeverTraced(t *testing.T) {
	srv := runrtest.New(t)
	var trace bytes.Buffer
	c, _ := runr.New(runr.Options{APIURL: srv.URL, Token: runrtest.DefaultToken, HTTPClient: srv.Client(), Debug: &trace})
	rid := radmRegionID(t, srv, c)
	endpoint := "https://otlp.example.com"
	headers := map[string]string{"X-Api-Key": "very-secret-key"}
	if _, _, err := c.SetRegionTracing(context.Background(), rid, runr.RegionTracingInput{Mode: runr.TracingExternal,
		ExportEndpoint: &endpoint, ExportHeaders: &headers}); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(trace.String(), "very-secret-key") {
		t.Fatalf("the trace holds an export header value:\n%s", trace.String())
	}
}

func TestRegionDataServicesClient(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	rid := radmRegionID(t, srv, c)

	all, err := c.RegionDataServices(ctx, rid, false)
	if err != nil {
		t.Fatal(err)
	}
	if !all.Connected || !all.CanManage || len(all.Services) != 3 || all.Services[0].Service != runr.DataServiceKV ||
		all.Services[2].Service != runr.DataServicePostgres {
		t.Fatalf("services = %+v", all)
	}
	if s := all.Service(runr.DataServiceKV); s.Status != runr.RegionServiceNotInstalled || s.Installed {
		t.Fatalf("kv = %+v", s)
	}

	res, err := c.InstallDataService(ctx, rid, runr.DataServiceKV, map[string]any{"maxmemory_mb": 512})
	if err != nil || res.Status != runr.RegionServiceInstalling || res.Mode != "managed" {
		t.Fatalf("install = %+v %v", res, err)
	}
	svc, err := c.WaitDataService(ctx, rid, "eu-1", runr.DataServiceKV, radmFast)
	if err != nil || svc.Status != runr.RegionServiceReady || !svc.Installed || svc.Config["maxmemory_mb"] == nil {
		t.Fatalf("wait = %+v %v", svc, err)
	}

	live, err := c.RegionDataServices(ctx, rid, true)
	if err != nil {
		t.Fatal(err)
	}
	if h := live.Service(runr.DataServiceKV).Health; h == nil || h.State != "ok" {
		t.Fatalf("health = %+v", h)
	}
	if calls := srv.CallsTo("GET /knative-regions/{knativeRegion}/data-services"); calls[len(calls)-1].Query != "live=1" {
		t.Fatalf("query = %q", calls[len(calls)-1].Query)
	}

	if _, err := c.UninstallDataService(ctx, rid, runr.DataServiceKV); err != nil {
		t.Fatal(err)
	}
	gone, err := c.WaitDataService(ctx, rid, "eu-1", runr.DataServiceKV, radmFast)
	if err != nil || gone.Status != runr.RegionServiceNotInstalled {
		t.Fatalf("after uninstall = %+v %v", gone, err)
	}
}

func TestRegionDataServiceRules(t *testing.T) {
	srv := runrtest.New(t)
	srv.SeedDataService("eu-1", runr.DataServicePostgres, "", 2)
	c := client(t, srv)
	ctx := context.Background()
	rid := radmRegionID(t, srv, c)

	if _, err := c.UninstallDataService(ctx, rid, runr.DataServicePostgres); !runr.IsValidation(err) {
		t.Fatalf("uninstall while bindings use it = %v", err)
	}
	if _, err := c.InstallDataService(ctx, rid, "redis", nil); !runr.IsNotFound(err) {
		t.Fatalf("unknown service = %v", err)
	}
	if _, err := c.InstallDataService(ctx, rid, runr.DataServiceKV, map[string]any{"mode": "cluster"}); !runr.IsValidation(err) {
		t.Fatalf("invalid mode = %v", err)
	}

	srv.RegionAdmin().FailInstall = true
	if _, err := c.InstallDataService(ctx, rid, runr.DataServiceObject, nil); err != nil {
		t.Fatal(err)
	}
	_, err := c.WaitDataService(ctx, rid, "eu-1", runr.DataServiceObject, radmFast)
	var fe *runr.FailedError
	if !errors.As(err, &fe) {
		t.Fatalf("failed install = %v", err)
	}
}

func TestRegionDataServiceCredentialsNeverTraced(t *testing.T) {
	srv := runrtest.New(t)
	var trace bytes.Buffer
	c, _ := runr.New(runr.Options{APIURL: srv.URL, Token: runrtest.DefaultToken, HTTPClient: srv.Client(), Debug: &trace})
	rid := radmRegionID(t, srv, c)
	_, err := c.InstallDataService(context.Background(), rid, runr.DataServiceObject, map[string]any{
		"mode": "external", "provider": "s3", "endpoint": "https://s3.example.com",
		"access_key_id": "AKIAEXAMPLE", "secret_access_key": "s3-super-secret"})
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(trace.String(), "s3-super-secret") || strings.Contains(trace.String(), "AKIAEXAMPLE") {
		t.Fatalf("the trace holds credentials:\n%s", trace.String())
	}
	_, secrets := srv.DataServiceOptions("eu-1", runr.DataServiceObject)
	if secrets["secret_access_key"] != "s3-super-secret" {
		t.Fatalf("stored secrets = %v", secrets)
	}
}

func TestRegionDataServicesDisconnected(t *testing.T) {
	srv := runrtest.New(t)
	srv.RegionAdmin().Disconnected = []string{"eu-1"}
	c := client(t, srv)
	all, err := c.RegionDataServices(context.Background(), radmRegionID(t, srv, c), false)
	if err != nil || all.Connected || len(all.Services) != 0 {
		t.Fatalf("disconnected = %+v %v", all, err)
	}
}

func TestGPUQuotaClient(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	rid := radmRegionID(t, srv, c)

	q, err := c.GPUQuota(ctx, rid)
	if err != nil || q.Quota != nil || q.Remaining != nil || q.Used != 0 {
		t.Fatalf("unlimited quota = %+v %v", q, err)
	}
	four := 4
	srv.SeedGPUQuota("eu-1", &four, 3)
	q, err = c.GPUQuota(ctx, rid)
	if err != nil || q.Quota == nil || *q.Quota != 4 || q.Used != 3 || q.Remaining == nil || *q.Remaining != 1 {
		t.Fatalf("quota = %+v %v", q, err)
	}
	if calls := srv.CallsTo("GET /models/gpu-quota"); calls[0].Query != "knative_region_id="+rid {
		t.Fatalf("query = %q", calls[0].Query)
	}
}

func TestTenantGPUQuotaClient(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	rid := radmRegionID(t, srv, c)
	two := 2
	if _, err := c.SetTenantGPUQuota(ctx, "01JTENANT0000000000000000A", rid, &two); !runr.IsNotFound(err) {
		t.Fatalf("single-tenant edition = %v", err)
	}
	srv.RBACMultiTenant()
	res, err := c.SetTenantGPUQuota(ctx, "01JTENANT0000000000000000A", rid, &two)
	if err != nil || res.GPUQuota == nil || *res.GPUQuota != 2 {
		t.Fatalf("set = %+v %v", res, err)
	}
	if q := srv.GPUQuotaOf("eu-1"); q == nil || *q != 2 {
		t.Fatalf("fake quota = %v", q)
	}
	res, err = c.SetTenantGPUQuota(ctx, "01JTENANT0000000000000000A", rid, nil)
	if err != nil || res.GPUQuota != nil {
		t.Fatalf("unlimited = %+v %v", res, err)
	}
	call := srv.CallsTo("PUT /tenants/{tenant}/regions/{region}/gpu-quota")
	if v, present := call[len(call)-1].Body["gpu_quota"]; !present || v != nil {
		t.Fatalf("body = %v", call[len(call)-1].Body)
	}
}

func TestGitProvidersCRUD(t *testing.T) {
	srv := runrtest.New(t)
	var trace bytes.Buffer
	c, _ := runr.New(runr.Options{APIURL: srv.URL, Token: runrtest.DefaultToken, HTTPClient: srv.Client(), Debug: &trace})
	ctx := context.Background()
	str := func(s string) *string { return &s }

	typ := runr.GitProviderGitHub
	_, err := c.CreateGitProvider(ctx, runr.GitProviderInput{Type: &typ, Name: str("acme-github")})
	if !runr.IsValidation(err) {
		t.Fatalf("GitHub App without its settings = %v", err)
	}
	pem := "-----BEGIN RSA PRIVATE KEY-----\nMIIBOgIBAAJBAKj34GkxFhD90vcNLYLInFEX6Ppy1tPf9Cnzj4p4WGeKLs1Pt8Qu\n-----END RSA PRIVATE KEY-----\n"
	p, err := c.CreateGitProvider(ctx, runr.GitProviderInput{Type: &typ, Name: str("acme-github"), AppID: str("123456"),
		AppSlug: str("acme-runr"), ClientID: str("Iv1.abc"), ClientSecret: str("client-secret-value"), PrivateKey: &pem,
		WebhookSecret: str("webhook-secret-0123456789")})
	if err != nil {
		t.Fatal(err)
	}
	if !p.HasPrivateKey || !p.HasWebhookSecret || !p.HasClientSecret || !p.Configured || p.URLs["webhook"] == "" {
		t.Fatalf("created = %+v", p)
	}
	for _, secret := range []string{"client-secret-value", "MIIBOgIBAAJBAKj34", "webhook-secret-0123456789"} {
		if strings.Contains(trace.String(), secret) {
			t.Fatalf("the trace holds %q", secret)
		}
	}

	got, err := c.FindGitProvider(ctx, "github")
	if err != nil || got.ID != p.ID {
		t.Fatalf("find by type = %+v %v", got, err)
	}
	off := false
	upd, err := c.UpdateGitProvider(ctx, p.ID, runr.GitProviderInput{Name: str("acme"), IsEnabled: &off})
	if err != nil || upd.Name != "acme" || upd.IsEnabled || !upd.HasPrivateKey {
		t.Fatalf("update = %+v %v", upd, err)
	}
	if cs, pk, ws := srv.GitProviderSecrets(p.ID); cs != "client-secret-value" || pk != pem || ws != "webhook-secret-0123456789" {
		t.Fatalf("secrets kept = %q %q %q", cs, pk, ws)
	}
	if err := c.DeleteGitProvider(ctx, p.ID); err != nil {
		t.Fatal(err)
	}
	if _, err := c.FindGitProvider(ctx, p.ID); err == nil {
		t.Fatal("deleted provider still found")
	}
}
