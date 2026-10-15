package cmd

import (
	"strings"
	"testing"

	"github.com/runrsh/core/cli/pkg/runr"
)

func TestShell(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders", runr.TypeFaaS, "python", "eu-1", nil)

	// No live deployment yet.
	r := h.expectCode(ExitNotFound, "shell", "-p", "orders")
	contains(t, r.err, "live deployment")

	h.srv.SeedFiles("orders", map[string]string{"main.py": "def handler(event, context):\n    return {}\n"})
	d := h.srv.SeedDeployment("orders", 1)

	r = h.runStdin("env\nexit\n", "shell", "-p", "orders")
	if r.code != 0 {
		t.Fatalf("shell: exit %d\nstdout: %s\nstderr: %s", r.code, r.out, r.err)
	}
	contains(t, r.out, "env")
	contains(t, r.out, "[Process exited]")
	contains(t, r.err, "Connected to fn-"+strings.ToLower(d.ID))
	calls := h.srv.CallsTo("POST /projects/{project}/deployments/{deployment}/exec")
	if len(calls) != 1 || !strings.HasSuffix(calls[0].Path, "/deployments/"+d.ID+"/exec") || calls[0].Body["cols"] != float64(80) {
		t.Fatalf("calls = %+v", calls)
	}
	logs := h.srv.SandboxTerminals()
	if len(logs) != 1 || logs[0].SandboxID != "deployment:"+d.ID || !strings.HasPrefix(string(logs[0].Input), "env\nexit\n") {
		t.Fatalf("sessions = %+v", logs)
	}

	// -o json prints the one-time session; --deployment names one.
	var sess runr.SandboxTerminalSession
	decodeJSON(t, h.mustRun("shell", "--deployment", d.ID, "-p", "orders", "-o", "json").out, &sess)
	if sess.SessionID == "" || !strings.Contains(sess.WebSocketURL, "/ws/shell?token=") {
		t.Fatalf("session = %+v", sess)
	}
	if len(h.srv.SandboxTerminals()) != 1 {
		t.Fatal("-o json attached to the shell")
	}
	h.expectCode(ExitNotFound, "shell", "--deployment", "01jnosuchdeployment0000000", "-p", "orders")

	// The session token stays out of --debug traces.
	r = h.runStdin("exit\n", "shell", "-p", "orders", "--debug")
	notContains(t, r.err, `"session_id"`)

	h.loginWith(h.srv.ScopedToken("dev", "projects.view"))
	r = h.expectCode(ExitAuth, "shell", "-p", "orders")
	contains(t, r.err, "shell.exec")
}
