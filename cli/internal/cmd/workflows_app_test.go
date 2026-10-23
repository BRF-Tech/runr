package cmd

import (
	"net/url"
	"strings"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestWorkflowsAppShowAndEndpoint(t *testing.T) {
	h := wfHarness(t)

	r := h.mustRun("workflows", "app", "-p", "flows")
	contains(t, r.out, "Endpoint:")
	contains(t, r.out, "flows.eu-1.runr.test")
	contains(t, r.out, "Signing key:")
	contains(t, r.out, "sha256:")
	notContains(t, r.out, h.srv.WorkflowSigningKeyOf("flows"))

	r = h.mustRun("workflows", "app", "set", "--endpoint", "https://workflows.example.com/api/runr", "-p", "flows")
	contains(t, r.err, "The engine calls https://workflows.example.com/api/runr.")
	contains(t, r.out, "(override)")
	if got := h.srv.WorkflowEndpointOverride("flows"); got != "https://workflows.example.com/api/runr" {
		t.Fatalf("override = %q", got)
	}
	var wa runr.WorkflowApp
	decodeJSON(t, h.mustRun("workflows", "app", "get", "-p", "flows", "-o", "json").out, &wa)
	if wa.EndpointOverride != "https://workflows.example.com/api/runr" || !wa.IsWorkflowProject || wa.QueueBridge == nil {
		t.Fatalf("app = %+v", wa)
	}

	h.mustRun("workflows", "app", "set", "--reset-endpoint", "-p", "flows")
	if body := h.srv.CallsTo("PATCH /projects/{project}/workflows/app")[1].Body; body["endpoint_url"] != nil {
		t.Fatalf("reset body = %v", body)
	} else if _, sent := body["endpoint_url"]; !sent {
		t.Fatal("reset did not send endpoint_url: null")
	}
	if got := h.srv.WorkflowEndpointOverride("flows"); got != "" {
		t.Fatalf("override after reset = %q", got)
	}

	h.expectCode(ExitValidation, "workflows", "app", "set", "--endpoint", "http://127.0.0.1:9000/", "-p", "flows")
	h.expectCode(ExitUsage, "workflows", "app", "set", "-p", "flows")
	h.expectCode(ExitUsage, "workflows", "app", "set", "--endpoint", "https://x.example.com", "--reset-endpoint", "-p", "flows")
	h.expectCode(ExitUsage, "workflows", "app", "set", "--endpoint", "", "-p", "flows")
}

func TestWorkflowsAppKeys(t *testing.T) {
	h := wfHarness(t)
	key := h.srv.WorkflowSigningKeyOf("flows")

	r := h.mustRun("workflows", "app", "signing-key", "-p", "flows")
	if strings.TrimSpace(r.out) != key {
		t.Fatalf("signing-key = %q, want %q", r.out, key)
	}
	contains(t, r.err, "is secret")
	r = h.mustRun("workflows", "app", "signing-key", "--dotenv", "-p", "flows")
	if r.out != "RUNR_WORKFLOW_SIGNING_KEY="+key+"\n" {
		t.Fatalf("--dotenv = %q", r.out)
	}
	var k runr.WorkflowSigningKey
	decodeJSON(t, h.mustRun("workflows", "app", "signing-key", "-p", "flows", "-o", "json").out, &k)
	if k.SigningKey != key || k.Env != "RUNR_WORKFLOW_SIGNING_KEY" || !strings.HasPrefix(k.Fingerprint, "sha256:") {
		t.Fatalf("json = %+v", k)
	}

	// No live deployment: the rotation says so instead of deploying.
	r = h.mustRun("workflows", "app", "rotate-key", "-p", "flows")
	contains(t, r.err, "previous key keeps signing")
	contains(t, r.err, "No live deployment")
	contains(t, r.out, "Previous key signs until:")
	if h.srv.WorkflowSigningKeyOf("flows") == key {
		t.Fatal("the key did not change")
	}
	if n := len(h.srv.CallsTo("POST /projects/{project}/deploy")); n != 0 {
		t.Fatalf("%d deployments without a live one", n)
	}

	// A live deployment is deployed again, unless --no-deploy.
	h.srv.SeedFiles("flows", map[string]string{"main.py": "def handler(event, context):\n    return {}\n"})
	h.srv.SeedDeployment("flows", 1)
	var out struct {
		Redeployed bool             `json:"redeployed"`
		App        runr.WorkflowApp `json:"app"`
	}
	decodeJSON(t, h.mustRun("workflows", "app", "rotate-key", "-p", "flows", "-o", "json").out, &out)
	if !out.Redeployed || out.App.PreviousKeyExpiresAt == "" {
		t.Fatalf("rotate json = %+v", out)
	}
	if n := len(h.srv.Deployments("flows")); n != 2 {
		t.Fatalf("%d deployments after the rotation, want 2", n)
	}
	r = h.mustRun("workflows", "app", "rotate-key", "--no-deploy", "-p", "flows")
	contains(t, r.err, "before the previous key stops signing")
	if n := len(h.srv.Deployments("flows")); n != 2 {
		t.Fatalf("--no-deploy deployed (%d deployments)", n)
	}

	// An endpoint override is told to fetch the key; nothing is deployed.
	h.mustRun("workflows", "app", "set", "--endpoint", "https://workflows.example.com/", "-p", "flows")
	r = h.mustRun("workflows", "app", "rotate-key", "-p", "flows")
	contains(t, r.err, "runr workflows app signing-key")
	if n := len(h.srv.Deployments("flows")); n != 2 {
		t.Fatalf("an override deployed (%d deployments)", n)
	}

	// Revealing and rotating need workflows.manage.
	h.loginWith(h.srv.ScopedToken("viewer", "projects.view", "workflows.view"))
	h.mustRun("workflows", "app", "-p", "flows")
	r = h.expectCode(ExitAuth, "workflows", "app", "signing-key", "-p", "flows")
	contains(t, r.err, "workflows.manage")
	notContains(t, r.out, h.srv.WorkflowSigningKeyOf("flows"))
	h.expectCode(ExitAuth, "workflows", "app", "rotate-key", "-p", "flows")
}

func TestWorkflowsUsage(t *testing.T) {
	h := wfHarness(t)
	h.srv.SeedProject("billing-jobs", runr.TypeFaaS, "python", "eu-1", map[string]any{"trigger_type": "workflow"})
	today := time.Now().UTC().Format("2006-01-02")
	yesterday := time.Now().UTC().AddDate(0, 0, -1).Format("2006-01-02")
	h.srv.SeedWorkflowUsage("flows", "process-order", yesterday, 40, 10)
	h.srv.SeedWorkflowUsage("flows", "process-order", today, 12, 3)
	h.srv.SeedWorkflowUsage("billing-jobs", "invoice", today, 5, 1)
	h.srv.SeedWorkflowUsage("flows", "process-order", "2020-01-01", 999, 99)

	r := h.mustRun("workflows", "usage")
	contains(t, r.out, "Step executions:")
	contains(t, r.out, "57")
	contains(t, r.out, "process-order")
	contains(t, r.out, "invoice")
	notContains(t, r.out, "999")
	if n := len(h.srv.CallsTo("GET /workflow-usage")); n != 1 {
		t.Fatalf("%d organization usage calls", n)
	}

	var u runr.WorkflowUsage
	decodeJSON(t, h.mustRun("workflows", "usage", "-p", "flows", "--from", yesterday, "--to", today, "-o", "json").out, &u)
	if u.Totals.StepExecutions != 52 || u.Totals.RunsStarted != 13 || len(u.Daily) != 2 || len(u.ByWorkflow) != 1 ||
		u.ByWorkflow[0].Workflow != "process-order" {
		t.Fatalf("project usage = %+v", u)
	}
	call := h.srv.CallsTo("GET /projects/{project}/workflow-usage")[0]
	if q, _ := url.ParseQuery(call.Query); q.Get("from") != yesterday || q.Get("to") != today {
		t.Fatalf("query = %q", call.Query)
	}

	contains(t, h.mustRun("workflows", "usage", "--from", "2021-01-01", "--to", "2021-01-31").out, "No workflow usage")
	h.expectCode(ExitUsage, "workflows", "usage", "--from", "yesterday")
}
