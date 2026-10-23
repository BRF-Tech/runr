package cmd

import (
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestNetworkAccessAndCallers(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()

	r := h.mustRun("network", "--dir", dir)
	contains(t, r.out, "public")
	contains(t, r.out, "hello-api.platform.runr.internal")
	contains(t, r.out, "X-Runr-Internal-Token")

	h.expectCode(ExitUsage, "network", "set", "--access", "allowlist", "--dir", dir)
	h.expectCode(ExitUsage, "network", "set", "--access", "internal", "--allow-cidr", "10.0.0.0/8", "--dir", dir)
	h.expectCode(ExitUsage, "network", "set", "--access", "private", "--dir", dir)
	r = h.mustRun("network", "set", "--access", "allowlist", "--allow-cidr", "203.0.113.0/24", "--dir", dir)
	contains(t, r.out, "203.0.113.0/24")
	call := h.srv.CallsTo("PUT /projects/{project}/network")[0]
	if call.Body["access_mode"] != "allowlist" {
		t.Fatalf("access mode sent: %v", call.Body)
	}

	h.mustRun("network", "callers", "add", "shop.platform", "--dir", dir)
	r = h.mustRun("network", "callers", "add", "billing.acme", "--dir", dir)
	contains(t, r.err, "a grant to another organization")
	r = h.mustRun("network", "callers", "--dir", dir)
	contains(t, r.out, "shop.platform")
	contains(t, r.out, "other organization")

	h.mustRun("network", "callers", "rm", "billing.acme", "--yes", "--dir", dir)
	h.expectCode(ExitNotFound, "network", "callers", "rm", "nobody.platform", "--yes", "--dir", dir)
	var out struct {
		Callers []runr.NetworkCaller `json:"callers"`
	}
	decodeJSON(t, h.mustRun("network", "callers", "--dir", dir, "-o", "json").out, &out)
	if len(out.Callers) != 1 || out.Callers[0].Caller != "shop.platform" {
		t.Fatalf("callers: %+v", out.Callers)
	}

	h.srv.CrossTenantGrantsOff()
	h.expectCode(ExitValidation, "network", "callers", "add", "billing.acme", "--dir", dir)
}

// runr deploy brings the network to runr.yaml: access mode and the whole allow list
func TestDeployAppliesTheNetworkSection(t *testing.T) {
	h := newHarness(t).login()
	h.deployHello()
	dir := h.project(helloYAML+"network:\n  access: internal\n  callers: [shop, billing.acme]\n", map[string]string{"main.py": pythonHandler})

	h.mustRun("deploy", "--dir", dir)
	n := h.srv.Network("hello-api")
	if n == nil || n.AccessMode != runr.AccessInternal || len(n.Callers) != 2 {
		t.Fatalf("network after deploy: %+v", n)
	}

	// a caller left out of runr.yaml is removed
	dir = h.project(helloYAML+"network:\n  access: internal\n  callers: [shop]\n", map[string]string{"main.py": pythonHandler})
	h.mustRun("deploy", "--dir", dir)
	if n := h.srv.Network("hello-api"); len(n.Callers) != 1 || n.Callers[0].Caller != "shop.platform" {
		t.Fatalf("callers: %+v", n.Callers)
	}
	if len(h.srv.CallsTo("PUT /projects/{project}/network")) != 1 {
		t.Fatal("an unchanged access mode is not set again")
	}
}
