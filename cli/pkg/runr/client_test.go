package runr_test

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func client(t *testing.T, srv *runrtest.Server) *runr.Client {
	t.Helper()
	c, err := runr.New(runr.Options{APIURL: srv.URL, Token: runrtest.DefaultToken, HTTPClient: srv.Client(), Retries: -1})
	if err != nil {
		t.Fatal(err)
	}
	return c
}

func TestNormalizeAPIURL(t *testing.T) {
	cases := map[string]string{
		"https://runr.example.com":       "https://runr.example.com/api",
		"https://runr.example.com/":      "https://runr.example.com/api",
		"https://runr.example.com/api":   "https://runr.example.com/api",
		"https://runr.example.com/api/":  "https://runr.example.com/api",
		"runr.example.com":               "https://runr.example.com/api",
		"http://10.0.0.5:8080/runr":      "http://10.0.0.5:8080/runr/api",
		"https://runr.example.com?x=1#y": "https://runr.example.com/api",
	}
	for in, want := range cases {
		u, err := runr.NormalizeAPIURL(in)
		if err != nil || u.String() != want {
			t.Errorf("NormalizeAPIURL(%q) = %v, %v; want %s", in, u, err, want)
		}
	}
	for _, bad := range []string{"", "ftp://x", "https://"} {
		if _, err := runr.NormalizeAPIURL(bad); err == nil {
			t.Errorf("NormalizeAPIURL(%q) accepted", bad)
		}
	}
}

func TestConsoleURL(t *testing.T) {
	c, _ := runr.New(runr.Options{APIURL: "https://runr.example.com/api"})
	if c.ConsoleURL() != "https://runr.example.com" || c.BaseURL() != "https://runr.example.com/api" {
		t.Fatalf("console %s base %s", c.ConsoleURL(), c.BaseURL())
	}
}

func TestErrorsAreTyped(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()

	_, err := c.GetProject(ctx, "01J00000000000000000000099")
	if !runr.IsNotFound(err) {
		t.Fatalf("missing project: %v", err)
	}

	_, err = c.WithToken("wrong").ListProjects(ctx)
	if !runr.IsUnauthorized(err) {
		t.Fatalf("bad token: %v", err)
	}

	_, err = c.CreateProject(ctx, runr.CreateProjectInput{Name: "x", ServiceType: "faas", RegionID: "nope"})
	var ae *runr.APIError
	if !errors.As(err, &ae) || ae.StatusCode != 422 || len(ae.Errors["knative_region_id"]) == 0 {
		t.Fatalf("validation error = %#v", err)
	}
	if !strings.Contains(err.Error(), "knative_region_id: ") {
		t.Fatalf("validation message lacks the field: %v", err)
	}
	if !runr.IsValidation(err) {
		t.Fatal("IsValidation is false for 422")
	}
}

func TestRetriesIdempotentRequestsOnly(t *testing.T) {
	var gets, posts int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodGet {
			if atomic.AddInt32(&gets, 1) < 3 {
				w.WriteHeader(http.StatusServiceUnavailable)
				return
			}
			w.Write([]byte(`{"data":[]}`))
			return
		}
		atomic.AddInt32(&posts, 1)
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	defer srv.Close()
	c, _ := runr.New(runr.Options{APIURL: srv.URL, Token: "t", Retries: 3})
	runr.SetSleep(c, func(time.Duration) {})
	if _, err := c.ListProjects(context.Background()); err != nil {
		t.Fatalf("GET after two 503: %v", err)
	}
	if gets != 3 {
		t.Fatalf("%d GET attempts, want 3", gets)
	}
	if _, err := c.Deploy(context.Background(), "p", "v", nil); err == nil {
		t.Fatal("POST 503 succeeded")
	}
	if posts != 1 {
		t.Fatalf("POST retried: %d attempts", posts)
	}
}

func TestDebugTraceRedacts(t *testing.T) {
	srv := runrtest.New(t)
	var trace bytes.Buffer
	c, _ := runr.New(runr.Options{APIURL: srv.URL, HTTPClient: srv.Client(), Debug: &trace})
	if _, _, err := c.Login(context.Background(), "ada", "secret-password", ""); err != nil {
		t.Fatal(err)
	}
	out := trace.String()
	if strings.Contains(out, "secret-password") || strings.Contains(out, "session_") {
		t.Fatalf("trace leaks a secret:\n%s", out)
	}
	if !strings.Contains(out, `"password":"[redacted]"`) || !strings.Contains(out, `"token":"[redacted]"`) {
		t.Fatalf("trace does not show redaction:\n%s", out)
	}

	trace.Reset()
	c2, _ := runr.New(runr.Options{APIURL: srv.URL, Token: runrtest.DefaultToken, HTTPClient: srv.Client(), Debug: &trace})
	if _, err := c2.ListProjects(context.Background()); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(trace.String(), runrtest.DefaultToken) || !strings.Contains(trace.String(), "Bearer [redacted]") {
		t.Fatalf("token not redacted:\n%s", trace.String())
	}
}

func TestRedactJSONTruncates(t *testing.T) {
	big := `{"logs":"` + strings.Repeat("x", 10000) + `"}`
	out := runr.RedactJSON([]byte(big))
	if len(out) > 5000 || !strings.Contains(out, "bytes)") {
		t.Fatalf("not truncated: %d bytes", len(out))
	}
	if runr.RedactJSON([]byte("plain text")) != "plain text" {
		t.Fatal("non-JSON body changed")
	}
}

func TestDecodeTolerantShapes(t *testing.T) {
	var p runr.Project
	raw := `{"id":"1","name":"a","slug":"a","service_type":"faas","config":{"env_vars":[],"ports":["8080",9090],"memory_mb":128},` +
		`"latest_version":{"id":"v","number":2,"label":"v2","config_snapshot":[],"changed_files":["main.py"],"build_status":"ready","created_by":{"id":5,"name":"Ada"}}}`
	if err := json.Unmarshal([]byte(raw), &p); err != nil {
		t.Fatal(err)
	}
	if len(p.Config.EnvVars) != 0 || len(p.Config.Ports) != 2 || p.Config.Ports[0] != 8080 || *p.Config.MemoryMB != 128 {
		t.Fatalf("config = %+v", p.Config)
	}
	if p.LatestVersion.CreatedBy.ID != "5" || p.LatestVersion.ConfigSnapshot != nil {
		t.Fatalf("version = %+v", p.LatestVersion)
	}
	// A decoded config is written back as the API sent it.
	out, _ := json.Marshal(p.Config)
	if !strings.Contains(string(out), `"env_vars":[]`) {
		t.Fatalf("config re-encoded as %s", out)
	}

	var env runr.EnvMap
	if err := json.Unmarshal([]byte(`{"A":"x","N":3,"B":true,"Z":null}`), &env); err != nil {
		t.Fatal(err)
	}
	if env["N"] != "3" || env["B"] != "true" || env["Z"] != "" || env["A"] != "x" {
		t.Fatalf("env = %v", env)
	}

	var d runr.Deployment
	if err := json.Unmarshal([]byte(`{"id":"d","status":"active","deploy_config":[],"version":{"id":"v","label":"v3"}}`), &d); err != nil {
		t.Fatal(err)
	}
	if d.VersionLabel != "v3" || d.Version.ID != "v" || d.DeployConfig != nil {
		t.Fatalf("deployment = %+v", d)
	}
	enc, _ := json.Marshal(d)
	if !strings.Contains(string(enc), `"version":{"id":"v","label":"v3"}`) {
		t.Fatalf("deployment encoded as %s", enc)
	}

	var u runr.User
	_ = json.Unmarshal([]byte(`{"id":12,"username":"ada"}`), &u)
	if u.ID != "12" {
		t.Fatalf("user id = %q", u.ID)
	}
	_ = json.Unmarshal([]byte(`{"id":"01HXYZ","username":"ada"}`), &u)
	if u.ID != "01HXYZ" {
		t.Fatalf("user id = %q", u.ID)
	}
}

func TestPickVersion(t *testing.T) {
	vs := []runr.Version{{ID: "c", Number: 3, Label: "v3"}, {ID: "b", Number: 2, Label: "v2"}, {ID: "a", Number: 1, Label: "v1"}}
	for ref, want := range map[string]string{"": "c", "latest": "c", "v2": "b", "2": "b", "V1": "a", "a": "a"} {
		v, err := runr.PickVersion(vs, ref)
		if err != nil || v.ID != want {
			t.Errorf("PickVersion(%q) = %v, %v; want %s", ref, v, err, want)
		}
	}
	var nf *runr.NotFoundError
	if _, err := runr.PickVersion(vs, "v9"); !errors.As(err, &nf) {
		t.Fatalf("v9: %v", err)
	}
	if _, err := runr.PickVersion(nil, "latest"); !errors.As(err, &nf) {
		t.Fatalf("latest of none: %v", err)
	}
}

func TestPickRegion(t *testing.T) {
	regions := []runr.Region{{ID: "1", Name: "eu-1", IsActive: true}, {ID: "2", Name: "us-1", IsActive: false}}
	if r, err := runr.PickRegion(regions, ""); err != nil || r.Name != "eu-1" {
		t.Fatalf("only active: %v %v", r, err)
	}
	if r, err := runr.PickRegion(regions, "US-1"); err != nil || r.ID != "2" {
		t.Fatalf("by name: %v %v", r, err)
	}
	if r, err := runr.PickRegion(regions, "2"); err != nil || r.Name != "us-1" {
		t.Fatalf("by id: %v %v", r, err)
	}
	regions[1].IsActive = true
	if _, err := runr.PickRegion(regions, ""); err == nil || !strings.Contains(err.Error(), "several regions") {
		t.Fatalf("ambiguous: %v", err)
	}
	if _, err := runr.PickRegion(nil, ""); err == nil {
		t.Fatal("no regions accepted")
	}
}

func TestPickCurrentDeployment(t *testing.T) {
	list := []runr.Deployment{{ID: "3", Status: "failed"}, {ID: "2", Status: "active"}, {ID: "1", Status: "shut_down"}}
	if d := runr.PickCurrentDeployment(list); d.ID != "2" {
		t.Fatalf("current = %s", d.ID)
	}
	list = []runr.Deployment{{ID: "3", Status: "failed"}, {ID: "2", Status: "pending"}}
	if d := runr.PickCurrentDeployment(list); d.ID != "2" {
		t.Fatalf("live = %s", d.ID)
	}
	if d := runr.PickCurrentDeployment([]runr.Deployment{{ID: "9", Status: "failed"}}); d.ID != "9" {
		t.Fatal("newest fallback")
	}
	if runr.PickCurrentDeployment(nil) != nil {
		t.Fatal("empty list")
	}
}

func TestDeployLifecycle(t *testing.T) {
	srv := runrtest.New(t)
	srv.DeployPolls = 2
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	v, err := c.SaveFiles(ctx, p.ID, []runr.File{{Name: "main.py", Content: "def handler(e, c):\n    return 1\n"}}, "first")
	if err != nil {
		t.Fatal(err)
	}
	if v.Label != "v1" || v.BuildStatus != runr.BuildReady {
		t.Fatalf("version = %+v", v)
	}
	again, err := c.SaveFiles(ctx, p.ID, []runr.File{{Name: "main.py", Content: "def handler(e, c):\n    return 1\n"}}, "")
	if err != nil || again.ID != v.ID {
		t.Fatalf("unchanged files made a new version: %+v %v", again, err)
	}

	d, err := c.Deploy(ctx, p.ID, v.ID, &runr.DeployConfig{MinScale: intp(1)})
	if err != nil || d.Status != runr.DeploymentPending {
		t.Fatalf("deploy = %+v, %v", d, err)
	}
	var seen []string
	d, err = c.WaitDeployment(ctx, p.ID, d.ID, runr.WaitOptions{Interval: time.Millisecond, Progress: func(s string) { seen = append(seen, s) }})
	if err != nil || d.Status != runr.DeploymentActive || d.ReadyAt == nil {
		t.Fatalf("wait = %+v, %v", d, err)
	}
	if len(seen) != 2 || seen[0] != "pending" || seen[1] != "active" {
		t.Fatalf("progress = %v", seen)
	}

	logs, err := c.DeploymentLogs(ctx, p.ID, d.ID, 0)
	if err != nil || len(logs.Pods) != 1 {
		t.Fatalf("logs = %+v, %v", logs, err)
	}
	if err := c.DeleteDeployment(ctx, p.ID, d.ID); err != nil {
		t.Fatal(err)
	}
	if list, _ := c.ListDeployments(ctx, p.ID); len(list) != 0 {
		t.Fatalf("deployments after delete = %+v", list)
	}
}

func TestWaitDeploymentFailureAndTimeout(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	v := srv.SeedFiles("svc", map[string]string{"main.py": "def handler(e, c):\n    return 1\n"})

	srv.FailDeploy = true
	d, _ := c.Deploy(ctx, p.ID, v.ID, nil)
	_, err := c.WaitDeployment(ctx, p.ID, d.ID, runr.WaitOptions{Interval: time.Millisecond})
	var fe *runr.DeploymentFailedError
	if !errors.As(err, &fe) || !strings.Contains(err.Error(), "RevisionFailed") {
		t.Fatalf("failed deploy: %v", err)
	}

	srv.FailDeploy = false
	srv.DeployPolls = 1000
	d, _ = c.Deploy(ctx, p.ID, v.ID, nil)
	_, err = c.WaitDeployment(ctx, p.ID, d.ID, runr.WaitOptions{Interval: time.Millisecond, Timeout: 30 * time.Millisecond})
	var te *runr.TimeoutError
	if !errors.As(err, &te) {
		t.Fatalf("timeout: %v", err)
	}
}

func TestWaitVersionBuild(t *testing.T) {
	srv := runrtest.New(t)
	srv.BuildPolls = 2
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("gofn", runr.TypeFaaS, "go", "eu-1", map[string]any{"handler": "main.Handler"})
	v, err := c.SaveFiles(ctx, p.ID, []runr.File{{Name: "main.go", Content: "package main\nfunc Handler(e interface{}, c interface{}) (interface{}, error) { return nil, nil }\n"}}, "")
	if err != nil {
		t.Fatal(err)
	}
	if v.BuildStatus != runr.BuildPending {
		t.Fatalf("go version status = %s, want pending", v.BuildStatus)
	}
	v, err = c.WaitVersion(ctx, p.ID, v, runr.WaitOptions{Interval: time.Millisecond})
	if err != nil || v.BuildStatus != runr.BuildReady {
		t.Fatalf("build = %+v, %v", v, err)
	}

	srv.FailBuild = true
	v2, _ := c.SaveFiles(ctx, p.ID, []runr.File{{Name: "main.go", Content: "package main\nfunc Handler(e interface{}, c interface{}) (interface{}, error) { return 1, nil }\n"}}, "")
	_, err = c.WaitVersion(ctx, p.ID, v2, runr.WaitOptions{Interval: time.Millisecond})
	var be *runr.BuildError
	if !errors.As(err, &be) {
		t.Fatalf("failed build: %v", err)
	}
}

func TestApplyEnv(t *testing.T) {
	srv := runrtest.New(t)
	srv.SecretEnv = false // first an API without secret variables
	c := client(t, srv)
	ctx := context.Background()
	srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", map[string]any{"env_vars": map[string]any{"A": "1"}})
	p, _ := c.FindProject(ctx, "svc")

	if _, err := c.ApplyEnv(ctx, p, runr.EnvChange{Set: map[string]string{"K_SERVICE": "x"}}, ""); err == nil {
		t.Fatal("reserved name accepted")
	}
	res, err := c.ApplyEnv(ctx, p, runr.EnvChange{Set: map[string]string{"B": "2"}, Unset: []string{"A"}}, "m")
	if err != nil || !res.Changed {
		t.Fatalf("apply = %+v, %v", res, err)
	}
	if got := res.Project.Config.EnvVars; len(got) != 1 || got["B"] != "2" {
		t.Fatalf("env = %v", got)
	}
	res, _ = c.ApplyEnv(ctx, res.Project, runr.EnvChange{Set: map[string]string{"B": "2"}}, "m")
	if res.Changed {
		t.Fatal("same value counted as a change")
	}
	res, _ = c.ApplyEnv(ctx, res.Project, runr.EnvChange{Set: map[string]string{"S": "x"}, Secret: true}, "m")
	if !res.SecretStoredPlain || res.Project.Config.EnvVars["S"] != "x" {
		t.Fatalf("plain fallback = %+v", res)
	}

	srv.SecretEnv = true
	p, _ = c.FindProject(ctx, "svc")
	if !runr.SupportsSecretEnv(p) {
		t.Fatal("secret support not detected")
	}
	res, _ = c.ApplyEnv(ctx, p, runr.EnvChange{Set: map[string]string{"S": "y"}, Secret: true}, "m")
	if res.SecretStoredPlain || srv.Secrets("svc")["S"] != "y" {
		t.Fatalf("secret path = %+v secrets=%v", res, srv.Secrets("svc"))
	}
	if _, plain := res.Project.Config.EnvVars["S"]; plain {
		t.Fatal("secret kept as plain variable")
	}
	list := runr.EnvList(res.Project)
	for _, v := range list {
		if v.Name == "S" && (!v.Secret || v.Value != "") {
			t.Fatalf("env list shows a secret: %+v", v)
		}
	}
}

func TestDomainsClient(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	d, err := c.AddDomain(ctx, p.ID, "Api.Example.com.")
	if err != nil || d.Domain != "api.example.com" || d.Verification.Type != "TXT" {
		t.Fatalf("add = %+v, %v", d, err)
	}
	_, err = c.VerifyDomain(ctx, p.ID, d.ID)
	var ve *runr.DomainVerifyError
	if !errors.As(err, &ve) || ve.Domain.ID != d.ID {
		t.Fatalf("verify pending: %v", err)
	}
	srv.VerifyDomains = true
	if d2, err := c.VerifyDomain(ctx, p.ID, d.ID); err != nil || d2.Status != "active" {
		t.Fatalf("verify = %+v, %v", d2, err)
	}
	if d3, err := c.FindDomain(ctx, p.ID, "API.example.com"); err != nil || d3.ID != d.ID {
		t.Fatalf("find = %+v, %v", d3, err)
	}
	if _, err := c.SetDomainTLS(ctx, p.ID, d.ID, runr.TLSCustom, "bad", "bad"); !runr.IsValidation(err) {
		t.Fatalf("bad PEM: %v", err)
	}
	list, meta, err := c.ListDomains(ctx, p.ID)
	if err != nil || len(list) != 1 || !meta.Eligible {
		t.Fatalf("list = %+v %+v %v", list, meta, err)
	}
	if err := c.DeleteDomain(ctx, p.ID, d.ID); err != nil {
		t.Fatal(err)
	}
}

func TestTokensAndSettings(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	exp := time.Now().Add(time.Hour)
	plain, tok, err := c.CreateToken(ctx, runr.CreateTokenInput{Name: "ci", Abilities: []string{"projects:read"}, ExpiresAt: &exp})
	if err != nil || plain == "" || tok.ID == "" {
		t.Fatalf("create token = %q %+v %v", plain, tok, err)
	}
	if _, err := c.WithToken(plain).Me(ctx); err != nil {
		t.Fatalf("new token rejected: %v", err)
	}
	list, abilities, err := c.ListTokens(ctx)
	if err != nil || len(list) != 1 || len(abilities) == 0 {
		t.Fatalf("list = %+v %v %v", list, abilities, err)
	}
	if err := c.DeleteToken(ctx, tok.ID.String()); err != nil {
		t.Fatal(err)
	}
	if _, err := c.WithToken(plain).Me(ctx); !runr.IsUnauthorized(err) {
		t.Fatalf("revoked token accepted: %v", err)
	}
	imgs, err := c.ExecutorImages(ctx)
	if err != nil || len(imgs) == 0 {
		t.Fatalf("images = %v %v", imgs, err)
	}
	v, err := c.ServerVersion(ctx)
	if err != nil || v != "v0.5.0" {
		t.Fatalf("version = %q %v", v, err)
	}
}

func TestLoginTwoFactor(t *testing.T) {
	srv := runrtest.New(t)
	srv.TwoFactorCode = "654321"
	c, _ := runr.New(runr.Options{APIURL: srv.URL, HTTPClient: srv.Client()})
	ctx := context.Background()
	if _, _, err := c.Login(ctx, "ada", "secret-password", ""); !errors.Is(err, runr.ErrTwoFactorRequired) {
		t.Fatalf("no code: %v", err)
	}
	tok, user, err := c.Login(ctx, "ada@example.com", "secret-password", "654321")
	if err != nil || tok == "" || user.Username != "ada" {
		t.Fatalf("login = %q %+v %v", tok, user, err)
	}
	if err := c.WithToken(tok).Logout(ctx); err != nil {
		t.Fatal(err)
	}
	if _, err := c.WithToken(tok).Me(ctx); !runr.IsUnauthorized(err) {
		t.Fatalf("logged-out session accepted: %v", err)
	}
}

func TestFindProjectByNameSlugAndID(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("My_Service", runr.TypeFaaS, "python", "eu-1", nil)
	for _, ref := range []string{"My_Service", "my-service", p.ID, "my_service"} {
		got, err := c.FindProject(ctx, ref)
		if err != nil || got.ID != p.ID {
			t.Errorf("FindProject(%q) = %v, %v", ref, got, err)
		}
	}
	var nf *runr.NotFoundError
	if _, err := c.FindProject(ctx, "ghost"); !errors.As(err, &nf) {
		t.Fatalf("ghost: %v", err)
	}
}

func TestPermissionsAndForbidden(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	c := client(t, srv)
	p, err := c.Permissions(ctx)
	if err != nil || p.TokenScoped || !p.Has("projects.deploy", "") {
		t.Fatalf("full token = %+v %v", p, err)
	}

	scoped := c.WithToken(srv.ScopedToken("ci", "projects.deploy"))
	p, err = scoped.Permissions(ctx)
	if err != nil || !p.TokenScoped || !p.Has("projects.view", "") || p.Has("code.edit", "") {
		t.Fatalf("scoped token = %+v %v", p, err)
	}
	proj := srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	_, err = scoped.SaveFiles(ctx, proj.ID, []runr.File{{Name: "main.py", Content: "x"}}, "")
	if got := runr.MissingPermissions(err); len(got) != 1 || got[0] != "code.edit" {
		t.Fatalf("missing permissions = %v (%v)", got, err)
	}
	if !runr.IsUnauthorized(err) {
		t.Fatal("403 is not IsUnauthorized")
	}

	srv.RBAC = false
	if _, err := c.Permissions(ctx); !errors.Is(err, runr.ErrUnsupported) {
		t.Fatalf("API without RBAC: %v", err)
	}
	if runr.MissingPermissions(errors.New("x")) != nil {
		t.Fatal("MissingPermissions of a plain error")
	}
}

func TestApplyEnvRefusesHiddenVariables(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", map[string]any{"env_vars": map[string]any{"A": "1"}})
	c := client(t, srv).WithToken(srv.ScopedToken("ci", "projects.update"))
	p, err := c.FindProject(ctx, "svc")
	if err != nil || !p.Config.EnvVarsHidden || len(p.Config.EnvVars) != 0 {
		t.Fatalf("hidden env = %+v %v", p.Config, err)
	}
	_, err = c.ApplyEnv(ctx, p, runr.EnvChange{Set: map[string]string{"B": "2"}}, "")
	if got := runr.MissingPermissions(err); len(got) != 1 || got[0] != "env.view" {
		t.Fatalf("ApplyEnv on hidden variables: %v", err)
	}
	// Even a hand-made update with variables needs env.edit.
	env := map[string]string{"B": "2"}
	_, err = c.UpdateProject(ctx, p.ID, runr.UpdateProjectInput{Config: &runr.ConfigInput{EnvVars: &env}})
	if got := runr.MissingPermissions(err); len(got) != 1 || got[0] != "env.edit" {
		t.Fatalf("update with variables: %v", err)
	}
	if v, _ := srv.Project("svc"); v.Config.EnvVars["A"] != "1" {
		t.Fatalf("variables changed: %v", v.Config.EnvVars)
	}
}

func TestPermissionsDecodesEmptyProjects(t *testing.T) {
	var p runr.Permissions
	if err := json.Unmarshal([]byte(`{"permissions":["a"],"projects":[],"token_scoped":true,"role":null}`), &p); err != nil {
		t.Fatal(err)
	}
	if p.Projects != nil || !p.TokenScoped || p.Role != "" {
		t.Fatalf("permissions = %+v", p)
	}
	_ = json.Unmarshal([]byte(`{"permissions":[],"projects":{"p1":["logs.view"]}}`), &p)
	if !p.Has("logs.view", "p1") || p.Has("logs.view", "p2") {
		t.Fatalf("project permissions = %+v", p)
	}
}

func intp(v int) *int { return &v }
