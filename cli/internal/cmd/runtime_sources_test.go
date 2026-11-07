package cmd

import (
	"path/filepath"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

const acmeRepo = "https://git.example.com/acme/runtimes.git"

// rtDeprecatedBash is a runtime of a source that is deprecated, sunsets far ahead and names its
// replacement; its version 4 is deprecated on its own.
const rtDeprecatedBash = `apiVersion: runr.sh/v1
kind: Runtime
id: bash
name: Bash
extensions: [sh]
deprecated: "2026-01-01"
sunset: "2099-01-01"
replacement: zsh
notice: Bash 5 is the last release we carry.
versions:
  - version: "4"
    image: bash:4
    deprecated: "2026-02-01"
  - version: "5"
    image: bash:5
    default: true
run:
  mode: exec
  command: ["bash", "/app/code/{file}"]
`

// rtBroken has no run section: its directory is a problem of the read.
const rtBroken = `apiVersion: runr.sh/v1
kind: Runtime
id: broken
name: Broken
extensions: [b]
versions:
  - version: "1"
    image: broken:1
`

func acmeRemote(private bool) runrtest.RemoteRuntimeSource {
	return runrtest.RemoteRuntimeSource{Revision: "4f2c0ffee", Private: private,
		Runtimes: map[string]string{"bash": rtDeprecatedBash, "broken": rtBroken}}
}

func TestRuntimeSourcesAddLsGetSyncRm(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SetRemoteRuntimeSource(acmeRepo, acmeRemote(true))
	t.Setenv("ACME_TOKEN", "glpat-0123456789abcd")

	// The token never goes on the command line: --git-token-env reads it
	r := h.mustRun("runtimes", "sources", "add", "--name", "acme", "--kind", "git", "--url", acmeRepo,
		"--ref", "main", "--git-token-env", "ACME_TOKEN", "--interval", "30")
	contains(t, r.err, "Connected runtime source acme (git, platform).")
	contains(t, r.err, "acme was read with 1 problem")
	contains(t, r.out, "partial")
	contains(t, r.out, "git token ••••••abcd as x-access-token")
	contains(t, r.out, "runtimes/broken/runtime.yaml")
	contains(t, r.out, "/run/mode")
	notContains(t, r.out, "glpat-0123456789abcd")
	calls := h.srv.CallsTo("POST /runtime-sources")
	if len(calls) != 1 || calls[0].Body["git_token"] != "glpat-0123456789abcd" || calls[0].Body["ref"] != "main" ||
		calls[0].Body["interval_minutes"] != float64(30) || calls[0].Body["kind"] != "git" {
		t.Fatalf("add calls = %+v", calls)
	}
	if _, sent := calls[0].Body["registry_password"]; sent {
		t.Fatalf("add sent credentials it was not given: %v", calls[0].Body)
	}

	// ls: RunR's catalog first, then the source
	r = h.mustRun("runtimes", "sources")
	contains(t, r.out, "RunR runtimes *")
	contains(t, r.out, "acme")
	contains(t, r.out, acmeRepo+"@main")
	contains(t, r.out, "The built-in runtimes come from RunR's catalog")
	var list runr.RuntimeSourceList
	decodeJSON(t, h.mustRun("runtimes", "sources", "ls", "-o", "json").out, &list)
	if len(list.Sources) != 2 || !list.Sources[0].IsDefault || list.Sources[1].Name != "acme" || list.Sources[1].RuntimesCount != 1 ||
		list.Sources[1].Status != runr.RuntimeSourcePartial || !list.Abilities.Manage {
		t.Fatalf("ls = %+v", list)
	}

	// get: the runtimes with their lifecycle, the problems
	r = h.mustRun("runtimes", "sources", "get", "acme")
	contains(t, r.out, "deprecated since 2026-01-01, sunset 2099-01-01: use zsh (Bash 5 is the last release we carry.)")
	contains(t, r.out, "4 (deprecated, ends 2099-01-01)")
	contains(t, r.out, "1 problem:")
	var d runr.RuntimeSourceDetail
	decodeJSON(t, h.mustRun("runtimes", "sources", "get", "acme", "-o", "json").out, &d)
	if d.Source.Name != "acme" || len(d.Runtimes) != 1 || d.Runtimes[0].ID != "bash" || d.Runtimes[0].SourcePath != "bash" ||
		d.Runtimes[0].Lifecycle == nil || d.Runtimes[0].Lifecycle.State != runr.LifecycleDeprecated {
		t.Fatalf("get = %+v", d)
	}
	h.expectCode(ExitNotFound, "runtimes", "sources", "get", "nope")

	// the runtimes list shows the state and where a runtime comes from
	r = h.mustRun("runtimes", "ls")
	contains(t, r.out, "STATE")
	contains(t, r.out, "deprecated")
	contains(t, r.out, "acme")
	contains(t, r.out, "RunR runtimes")
	r = h.mustRun("runtimes", "get", "bash")
	contains(t, r.out, "Runtime source:")
	contains(t, r.out, "acme (bash)")
	contains(t, r.out, "Lifecycle:")

	// sync: fixed upstream, read again
	h.srv.SetRemoteRuntimeSource(acmeRepo, runrtest.RemoteRuntimeSource{Private: true, Revision: "5a1",
		Runtimes: map[string]string{"bash": rtDeprecatedBash}})
	r = h.mustRun("runtimes", "sources", "sync", "acme")
	contains(t, r.err, "Read runtime source acme: ok.")
	contains(t, r.out, "5a1")

	// a read that fails: the source with its status, exit code 6
	h.srv.SetRemoteRuntimeSource(acmeRepo, runrtest.RemoteRuntimeSource{Fail: runr.RuntimeSourceUnreachable, Error: "git.example.com: connection refused"})
	r = h.expectCode(ExitFailed, "runtimes", "sources", "sync", "acme")
	contains(t, r.out, "unreachable: git.example.com: connection refused")
	contains(t, r.err, "the read of runtime source acme failed")
	var failed runr.RuntimeSource
	decodeJSON(t, h.expectCode(ExitFailed, "runtimes", "sources", "sync", "acme", "-o", "json").out, &failed)
	if failed.Status != runr.RuntimeSourceUnreachable || failed.Revision != "5a1" {
		t.Fatalf("failed sync = %+v", failed)
	}

	// a read already running: 409
	h.srv.SetRuntimeSourceBusy("acme", true)
	h.expectCode(ExitValidation, "runtimes", "sources", "sync", "acme")
	h.srv.SetRuntimeSourceBusy("acme", false)

	// rm asks; a runtime a project uses stays, orphaned
	h.srv.SeedProject("script", runr.TypeXaaS, "bash", "eu-1", map[string]any{"filename": "main.sh"})
	h.expectCode(ExitUsage, "runtimes", "sources", "rm", "acme")
	h.mustRun("runtimes", "sources", "rm", "acme", "--yes")
	if _, ok := h.srv.RuntimeSource("acme"); ok {
		t.Fatal("acme is still connected")
	}
	r = h.mustRun("runtimes", "ls")
	contains(t, r.out, "orphaned")
	var bash runr.RuntimeDetail
	decodeJSON(t, h.mustRun("runtimes", "get", "bash", "-o", "json").out, &bash)
	if bash.Lifecycle == nil || bash.Lifecycle.State != runr.LifecycleOrphaned || bash.Managed || bash.Lifecycle.SunsetAt == "" {
		t.Fatalf("orphaned bash = %+v", bash.CatalogRuntime)
	}

	// RunR's catalog cannot be removed
	h.expectCode(ExitValidation, "runtimes", "sources", "rm", "RunR runtimes", "--yes")
}

func TestRuntimeSourcesCredentialsNeverOnTheCommandLine(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SetRemoteRuntimeSource("git@git.example.com:acme/runtimes.git", runrtest.RemoteRuntimeSource{Private: true,
		Runtimes: map[string]string{"bash": rtBashManifest}})
	h.srv.SetRemoteRuntimeSource("registry.example.com/acme/runtimes:catalog", runrtest.RemoteRuntimeSource{Private: true, Signed: true,
		Runtimes: map[string]string{"zsh": strings.Replace(rtBashManifest, "id: bash", "id: zsh", 1)}})
	dir := t.TempDir()
	key, kh, pub := filepath.Join(dir, "deploy_key"), filepath.Join(dir, "known_hosts"), filepath.Join(dir, "cosign.pub")
	write(t, key, "-----BEGIN OPENSSH PRIVATE KEY-----\r\nb3BlbnNzaA\r\n-----END OPENSSH PRIVATE KEY-----\r\n")
	write(t, kh, "git.example.com ssh-ed25519 AAAAC3\n")
	write(t, pub, "-----BEGIN PUBLIC KEY-----\nMFkw\n-----END PUBLIC KEY-----\n")

	// A deploy key and known_hosts from files (CRLF turned into LF)
	r := h.mustRun("runtimes", "sources", "add", "--name", "acme-ssh", "--kind", "git", "--url", "git@git.example.com:acme/runtimes.git",
		"--ssh-key-file", key, "--known-hosts-file", kh)
	contains(t, r.out, "deploy key (known_hosts set)")
	creds := h.srv.RuntimeSourceCredentials("acme-ssh")
	if creds["ssh_private_key"] != "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaA\n-----END OPENSSH PRIVATE KEY-----" ||
		creds["ssh_known_hosts"] != "git.example.com ssh-ed25519 AAAAC3" {
		t.Fatalf("ssh credentials = %q", creds)
	}

	// A registry password from stdin, the public key from a file
	r = h.runStdin("s3cret-registry-password\n", "runtimes", "sources", "add", "--name", "acme-catalog", "--kind", "oci-catalog",
		"--url", "registry.example.com/acme/runtimes:catalog", "--public-key-file", pub,
		"--registry-username", "ci", "--registry-password-stdin")
	if r.code != 0 {
		t.Fatalf("add oci: %d %s %s", r.code, r.out, r.err)
	}
	contains(t, r.out, "registry user ci with a password")
	contains(t, r.out, "set, the last read verified")
	if c := h.srv.RuntimeSourceCredentials("acme-catalog"); c["registry_password"] != "s3cret-registry-password" || c["registry_username"] != "ci" {
		t.Fatalf("registry credentials = %q", c)
	}
	if body := h.srv.CallsTo("POST /runtime-sources")[1].Body; body["public_key"] != "-----BEGIN PUBLIC KEY-----\nMFkw\n-----END PUBLIC KEY-----" {
		t.Fatalf("public key sent = %q", body["public_key"])
	}

	// Refusals before any request
	h.expectCode(ExitUsage, "runtimes", "sources", "add", "--name", "x", "--kind", "svn", "--url", "u")
	h.expectCode(ExitUsage, "runtimes", "sources", "add", "--name", "x", "--kind", "git")
	r = h.expectCode(ExitUsage, "runtimes", "sources", "add", "--name", "x", "--kind", "git", "--url", "u", "--git-token-env", "RUNR_NOT_SET_ANYWHERE")
	contains(t, r.err, "RUNR_NOT_SET_ANYWHERE is empty or not set")
	h.expectCode(ExitUsage, "runtimes", "sources", "add", "--name", "x", "--kind", "git", "--url", "u", "--git-token-stdin", "--registry-password-stdin")
	h.expectCode(ExitUsage, "runtimes", "sources", "add", "--name", "x", "--kind", "git", "--url", "u", "--git-token-stdin", "--ssh-key-file", key)
	if n := len(h.srv.CallsTo("POST /runtime-sources")); n != 2 {
		t.Fatalf("%d creates, want 2", n)
	}
	// --git-token is no flag: a value on the command line is refused
	h.expectCode(ExitUsage, "runtimes", "sources", "add", "--name", "x", "--kind", "git", "--url", "u", "--git-token", "abc")

	// update: only what is given; clearing sends null
	h.mustRun("runtimes", "sources", "update", "acme-catalog", "--clear-registry-credentials", "--registry-host", "")
	put := h.srv.CallsTo("PUT /runtime-sources/{source}")
	if len(put) != 1 || len(put[0].Body) != 3 || put[0].Body["registry_username"] != nil || put[0].Body["registry_password"] != nil {
		t.Fatalf("update body = %v", put[0].Body)
	}
	for _, k := range []string{"registry_username", "registry_password", "registry_host"} {
		if v, sent := put[0].Body[k]; !sent || v != nil {
			t.Fatalf("%s: sent %v (%v), want null", k, v, sent)
		}
	}
	if c := h.srv.RuntimeSourceCredentials("acme-catalog"); len(c) != 0 {
		t.Fatalf("credentials after clearing = %q", c)
	}
	r = h.mustRun("runtimes", "sources", "get", "acme-catalog")
	contains(t, r.out, "not found: registry.example.com/acme/runtimes:catalog does not exist or is not public")
	h.expectCode(ExitUsage, "runtimes", "sources", "update", "acme-catalog")
	h.expectCode(ExitUsage, "runtimes", "sources", "update", "acme-catalog", "--enable", "--disable")

	// RunR's catalog takes the schedule only
	h.mustRun("runtimes", "sources", "update", "RunR runtimes", "--interval", "30", "--disable")
	if s, _ := h.srv.RuntimeSource("RunR runtimes"); s.IntervalMinutes != 30 || s.IsEnabled {
		t.Fatalf("RunR runtimes = %+v", s)
	}
	r = h.expectCode(ExitValidation, "runtimes", "sources", "update", "RunR runtimes", "--url", "example.com/x:1")
	contains(t, r.err, "is_enabled and interval_minutes only")
}

func TestRuntimeSourcesTest(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SetRemoteRuntimeSource(acmeRepo, acmeRemote(false))

	// A new source: problems exit with code 5, nothing is saved
	r := h.expectCode(ExitValidation, "runtimes", "sources", "test", "--kind", "git", "--url", acmeRepo)
	contains(t, r.out, "partial")
	contains(t, r.out, "bash")
	contains(t, r.out, "broken")
	contains(t, r.out, "runtimes/broken/runtime.yaml")
	contains(t, r.err, "has 1 problem")
	var res runr.RuntimeSourceTest
	decodeJSON(t, h.expectCode(ExitValidation, "runtimes", "sources", "test", "--kind", "git", "--url", acmeRepo, "-o", "json").out, &res)
	if !res.OK || len(res.Runtimes) != 2 || res.Revision != "4f2c0ffee" || len(res.Problems) != 1 || res.Problems[0].Path != "/run/mode" {
		t.Fatalf("test = %+v", res)
	}
	if _, ok := h.srv.RuntimeSource("test"); ok {
		t.Fatal("test saved a source")
	}
	h.expectCode(ExitUsage, "runtimes", "sources", "test", "--kind", "git")

	// A connected source with a change: its id goes along, the read uses its credentials
	h.srv.SetRemoteRuntimeSource(acmeRepo, runrtest.RemoteRuntimeSource{Runtimes: map[string]string{"bash": rtBashManifest}})
	h.mustRun("runtimes", "sources", "add", "--name", "acme", "--kind", "git", "--url", acmeRepo)
	h.srv.SetRemoteRuntimeSource("https://git.example.com/acme/runtimes-next.git", runrtest.RemoteRuntimeSource{Runtimes: map[string]string{"bash": rtBashManifest}})
	r = h.mustRun("runtimes", "sources", "test", "acme", "--url", "https://git.example.com/acme/runtimes-next.git")
	contains(t, r.out, "ok")
	calls := h.srv.CallsTo("POST /runtime-sources/test")
	last := calls[len(calls)-1].Body
	if last["source"] == nil || last["url"] != "https://git.example.com/acme/runtimes-next.git" || last["kind"] != nil {
		t.Fatalf("test body = %v", last)
	}
	if s, _ := h.srv.RuntimeSource("acme"); s.URL != acmeRepo {
		t.Fatalf("test changed the source: %+v", s)
	}

	// A source that cannot be read: exit code 6
	r = h.expectCode(ExitFailed, "runtimes", "sources", "test", "--kind", "git", "--url", "https://git.example.com/nothing.git")
	contains(t, r.out, "unreachable")
}

func TestRuntimeSourcesOfAnOrganization(t *testing.T) {
	h := newHarness(t)
	h.loginWith(h.srv.ScopedToken("custom", "runtimes.use_custom"))
	h.srv.SetRemoteRuntimeSource(acmeRepo, runrtest.RemoteRuntimeSource{Runtimes: map[string]string{"bash": rtBashManifest}})

	r := h.mustRun("runtimes", "sources", "add", "--name", "acme", "--kind", "git", "--url", acmeRepo)
	contains(t, r.err, "(git, organization)")
	var list runr.RuntimeSourceList
	decodeJSON(t, h.mustRun("runtimes", "sources", "-o", "json").out, &list)
	if len(list.Sources) != 1 || list.Sources[0].Scope != "organization" || list.Sources[0].TenantID == "" {
		t.Fatalf("an organization sees %+v", list.Sources)
	}
	var bash runr.RuntimeDetail
	decodeJSON(t, h.mustRun("runtimes", "get", "bash", "-o", "json").out, &bash)
	if bash.Source != "custom" || bash.SourceName != "acme" {
		t.Fatalf("bash = %+v", bash.CatalogRuntime)
	}

	// The built-in catalog is the platform's
	h.expectCode(ExitAuth, "runtimes", "catalog")
	h.loginWith(h.srv.ScopedToken("viewer", "projects.view"))
	h.expectCode(ExitAuth, "runtimes", "sources")
}

func TestRuntimeCatalogSetting(t *testing.T) {
	h := newHarness(t).login()

	r := h.mustRun("runtimes", "catalog")
	contains(t, r.out, "RunR's catalog")
	contains(t, r.out, "every runtime of the catalog")
	contains(t, r.out, "not saved")
	contains(t, r.out, "python")

	r = h.mustRun("runtimes", "catalog", "set", "--only", "python,go", "--only", "nodejs")
	contains(t, r.err, "python, go, nodejs")
	if ids, saved := h.srv.BuiltinRuntimesSetting(); !saved || strings.Join(ids, ",") != "python,go,nodejs" {
		t.Fatalf("setting = %v %v", ids, saved)
	}
	var cat runr.BuiltinCatalog
	decodeJSON(t, h.mustRun("runtimes", "catalog", "-o", "json").out, &cat)
	for _, rt := range cat.Runtimes {
		if want := rt.ID != "php"; rt.Offered != want {
			t.Fatalf("%s offered = %v", rt.ID, rt.Offered)
		}
	}

	h.mustRun("runtimes", "catalog", "set", "--all")
	calls := h.srv.CallsTo("PUT /runtime-catalog")
	if v, sent := calls[len(calls)-1].Body["builtin_runtimes"]; !sent || v != nil {
		t.Fatalf("--all sends builtin_runtimes: null, got %v (%v)", v, sent)
	}
	if ids, _ := h.srv.BuiltinRuntimesSetting(); ids != nil {
		t.Fatalf("setting after --all = %v", ids)
	}

	h.expectCode(ExitUsage, "runtimes", "catalog", "set")
	h.expectCode(ExitUsage, "runtimes", "catalog", "set", "--all", "--only", "go")
	h.expectCode(ExitValidation, "runtimes", "catalog", "set", "--only", "cobol")
}

func TestRuntimeSourcesOnAnOlderAPI(t *testing.T) {
	h := newHarness(t).login()
	h.srv.Without("GET /runtime-sources", "GET /runtime-catalog")
	r := h.expectCode(ExitError, "runtimes", "sources")
	contains(t, r.err, "no runtime sources")
	h.expectCode(ExitError, "runtimes", "catalog")

	// runtimes ls works without the lifecycle
	r = h.mustRun("runtimes", "ls")
	contains(t, r.out, "python")
	notContains(t, r.out, "RunR runtimes")
}
