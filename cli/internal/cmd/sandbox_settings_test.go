package cmd

import (
	"strings"
	"testing"

	"github.com/runrsh/core/cli/pkg/runr"
)

func TestSandboxNetworkCmd(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("python")

	r := h.mustRun("sandbox", "network", sb.ID)
	contains(t, r.out, "none")
	if len(h.srv.CallsTo("PUT /sandboxes/{sandbox}/network")) != 0 {
		t.Fatal("showing the network changed it")
	}

	r = h.mustRun("sandbox", "network", sb.ID, "--allow", "pypi.org:443", "--allow", "files.pythonhosted.org:443")
	contains(t, r.out, "allowlist")
	contains(t, r.out, "pypi.org:443, files.pythonhosted.org:443")
	contains(t, r.err, "network pypi.org:443, files.pythonhosted.org:443")
	if s, _ := h.srv.Sandbox(sb.ID); strings.Join(s.Network.EgressAllow, ",") != "pypi.org:443,files.pythonhosted.org:443" {
		t.Fatalf("network = %+v", s.Network)
	}

	var n runr.SandboxNetwork
	decodeJSON(t, h.mustRun("sandbox", "network", sb.ID, "--none", "-o", "json").out, &n)
	if n.Mode != "none" || len(n.EgressAllow) != 0 {
		t.Fatalf("after --none = %+v", n)
	}
	if got := h.srv.CallsTo("PUT /sandboxes/{sandbox}/network")[1].Body["network"]; got != "none" {
		t.Fatalf("--none body = %v", got)
	}

	h.expectCode(ExitUsage, "sandbox", "network", sb.ID, "--none", "--allow", "pypi.org")
	h.expectCode(ExitUsage, "sandbox", "network")
	h.expectCode(ExitValidation, "sandbox", "network", sb.ID, "--allow", "169.254.169.254")
	h.expectCode(ExitNotFound, "sandbox", "network", "01jnosuchsandbox0000000000", "--none")
}

func TestSandboxRegionsCmd(t *testing.T) {
	h := newHarness(t).login()

	r := h.mustRun("sandbox", "regions")
	contains(t, r.out, "eu-1")
	contains(t, r.out, "kata-fc,gvisor")
	contains(t, r.out, "15m")
	contains(t, r.out, "24h")

	r = h.mustRun("sandbox", "regions", "get", "eu-1")
	contains(t, r.out, "every installed class")
	contains(t, r.out, "4000m")

	r = h.mustRun("sandbox", "regions", "set", "eu-1", "--allowed-class", "kata", "--allowed-class", "gvisor",
		"--default-class", "gvisor", "--default-ttl", "30m", "--max-ttl", "4h", "--max-cpu", "2", "--max-memory", "4Gi",
		"--max-disk", "2048", "--disable")
	contains(t, r.err, "Updated the sandbox settings of eu-1")
	body := h.srv.CallsTo("PUT /sandbox-regions/{knativeRegion}")[0].Body
	if body["enabled"] != false || body["default_class"] != "gvisor" || body["default_ttl_seconds"] != float64(1800) ||
		body["max_ttl_seconds"] != float64(14400) || body["max_cpu_m"] != float64(2000) || body["max_memory_mb"] != float64(4096) ||
		body["max_disk_mb"] != float64(2048) || len(body["allowed_classes"].([]any)) != 2 {
		t.Fatalf("set body = %v", body)
	}
	region := h.srv.CallsTo("GET /sandbox-regions")
	if len(region) == 0 {
		t.Fatal("set did not resolve the region name")
	}
	settings := h.mustRun("sandbox", "regions", "get", "eu-1", "-o", "json").out
	var s runr.SandboxRegionSettings
	decodeJSON(t, settings, &s)
	if s.Enabled || s.EffectiveDefaultClass != "gvisor" || s.DefaultTTLSeconds != 1800 || s.MaxTTLSeconds != 14400 ||
		s.MaxCPUm != 2000 || strings.Join(s.AllowedClasses, ",") != "gvisor,kata" {
		t.Fatalf("settings = %+v", s)
	}
	if !strings.HasSuffix(h.srv.CallsTo("PUT /sandbox-regions/{knativeRegion}")[0].Path, "/"+s.Region.ID) {
		t.Fatal("set did not address the region by id")
	}

	h.mustRun("sandbox", "regions", "set", s.Region.ID, "--unset", "max-ttl", "--all-classes", "--enable", "--allow-runc")
	body = h.srv.CallsTo("PUT /sandbox-regions/{knativeRegion}")[1].Body
	if v, ok := body["max_ttl_seconds"]; !ok || v != nil {
		t.Fatalf("--unset body = %v", body)
	}
	if v, ok := body["allowed_classes"]; !ok || v != nil || body["enabled"] != true || body["allow_runc"] != true {
		t.Fatalf("--all-classes body = %v", body)
	}
	h.mustRun("sandbox", "regions", "set", "eu-1", "--allow-runc=false", "--unset", "default-class")
	body = h.srv.CallsTo("PUT /sandbox-regions/{knativeRegion}")[2].Body
	if body["allow_runc"] != false || body["default_class"] != nil || len(body) != 2 {
		t.Fatalf("--allow-runc=false body = %v", body)
	}

	h.expectCode(ExitUsage, "sandbox", "regions", "set", "eu-1")
	h.expectCode(ExitUsage, "sandbox", "regions", "set", "eu-1", "--enable", "--disable")
	h.expectCode(ExitUsage, "sandbox", "regions", "set", "eu-1", "--all-classes", "--allowed-class", "kata")
	h.expectCode(ExitUsage, "sandbox", "regions", "set", "eu-1", "--max-ttl", "30s")
	h.expectCode(ExitUsage, "sandbox", "regions", "set", "eu-1", "--max-cpu", "lots")
	h.expectCode(ExitUsage, "sandbox", "regions", "set", "eu-1", "--unset", "everything")
	h.expectCode(ExitUsage, "sandbox", "regions", "set", "eu-1", "--unset", "max-ttl", "--max-ttl", "1h")
	h.expectCode(ExitValidation, "sandbox", "regions", "set", "eu-1", "--default-class", "runc")
	h.expectCode(ExitNotFound, "sandbox", "regions", "set", "mars-1", "--disable")
	h.expectCode(ExitNotFound, "sandbox", "regions", "get", "mars-1")
}

// A region the caller may not change, and a token without sandboxes.manage, end with exit
// code 3 and say why.
func TestSandboxRegionsSetForbidden(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SetSandboxRegionReadOnly("eu-1")

	r := h.mustRun("sandbox", "regions", "get", "eu-1")
	changeRow := ""
	for _, line := range strings.Split(r.out, "\n") {
		if strings.Contains(line, "you can change it") {
			changeRow = strings.Join(strings.Fields(line), " ")
		}
	}
	if changeRow != "you can change it no" {
		t.Fatalf("get = %s", r.out)
	}
	var s runr.SandboxRegionSettings
	decodeJSON(t, h.mustRun("sandbox", "regions", "get", "eu-1", "-o", "json").out, &s)
	if s.CanManage {
		t.Fatal("can_manage of a platform region")
	}

	r = h.expectCode(ExitAuth, "sandbox", "regions", "set", "eu-1", "--disable")
	contains(t, r.err, "you may not change the sandbox settings of eu-1")
	contains(t, r.err, "Platform regions are managed by the platform administrators.")
	contains(t, r.err, "runr sandbox regions get eu-1")
	if st, _ := h.srv.SandboxRegionSettings(s.Region.ID); !st.Enabled {
		t.Fatal("a refused change was applied")
	}

	// A token that may only look: the answer names the permission.
	h2 := newHarness(t).login()
	h2.loginWith(h2.srv.ScopedToken("viewer", "sandboxes.view"))
	h2.mustRun("sandbox", "regions")
	r = h2.expectCode(ExitAuth, "sandbox", "regions", "set", "eu-1", "--max-ttl", "1h")
	contains(t, r.err, "sandboxes.manage")
	contains(t, r.err, "runr tokens create NAME --ability sandboxes.manage")
	if strings.Contains(r.err, "you may not change") {
		t.Fatalf("a missing token scope was explained as a platform region: %s", r.err)
	}
}
