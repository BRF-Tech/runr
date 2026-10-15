package runr_test

import (
	"context"
	"errors"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func TestSandboxNetworkClient(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	sb := srv.SeedSandbox("python")

	s, err := c.SetSandboxNetwork(ctx, sb.ID, []string{"PyPI.org:443", "files.pythonhosted.org:443", "pypi.org:443"})
	if err != nil {
		t.Fatal(err)
	}
	if s.Network.Mode != "allowlist" || strings.Join(s.Network.EgressAllow, ",") != "pypi.org:443,files.pythonhosted.org:443" {
		t.Fatalf("network = %+v", s.Network)
	}
	body := srv.CallsTo("PUT /sandboxes/{sandbox}/network")[0].Body
	if n, ok := body["network"].(map[string]any); !ok || len(n["egress_allow"].([]any)) != 3 {
		t.Fatalf("body = %v", body)
	}

	// No list: the network is taken away.
	s, err = c.SetSandboxNetwork(ctx, sb.ID, nil)
	if err != nil {
		t.Fatal(err)
	}
	if s.Network.Mode != "none" || len(s.Network.EgressAllow) != 0 {
		t.Fatalf("network after none = %+v", s.Network)
	}
	if got := srv.CallsTo("PUT /sandboxes/{sandbox}/network")[1].Body["network"]; got != "none" {
		t.Fatalf("none body = %v", got)
	}

	var ae *runr.APIError
	if _, err := c.SetSandboxNetwork(ctx, sb.ID, []string{"169.254.169.254"}); !errors.As(err, &ae) || ae.StatusCode != 422 {
		t.Fatalf("metadata range: %v", err)
	}
	if _, err := c.DeleteSandbox(ctx, sb.ID); err != nil {
		t.Fatal(err)
	}
	if _, err := c.SetSandboxNetwork(ctx, sb.ID, []string{"pypi.org"}); !errors.As(err, &ae) || ae.StatusCode != 409 {
		t.Fatalf("ended sandbox: %v", err)
	}
}

func TestSandboxRegionsClient(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()

	list, err := c.ListSandboxRegions(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if len(list) != 1 || list[0].RegionName() != "eu-1" || !list[0].Enabled || list[0].AllowedClasses != nil ||
		strings.Join(list[0].InstalledClasses, ",") != "kata-fc,gvisor" || list[0].EffectiveDefaultClass != "kata-fc" ||
		list[0].MaxTTLSeconds != 86400 || list[0].DefaultTTLSeconds != 900 || !list[0].CanManage {
		t.Fatalf("regions = %+v", list)
	}
	r, err := c.FindSandboxRegion(ctx, "EU-1")
	if err != nil || r.Region.ID != list[0].Region.ID {
		t.Fatalf("find by name = %+v, %v", r, err)
	}
	if r, err = c.FindSandboxRegion(ctx, list[0].Region.ID); err != nil || r.RegionName() != "eu-1" {
		t.Fatalf("find by id = %+v, %v", r, err)
	}
	var ae *runr.APIError
	if _, err := c.FindSandboxRegion(ctx, "mars-1"); !errors.As(err, &ae) || ae.StatusCode != 404 {
		t.Fatalf("unknown region: %v", err)
	}

	r, err = c.UpdateSandboxRegion(ctx, list[0].Region.ID, runr.SandboxRegionChange{
		"allowed_classes": []string{"gvisor"}, "default_class": "gvisor", "max_ttl_seconds": 7200, "max_memory_mb": 4096,
	})
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(r.AllowedClasses, ",") != "gvisor" || strings.Join(r.EffectiveClasses, ",") != "gvisor" ||
		r.EffectiveDefaultClass != "gvisor" || r.MaxTTLSeconds != 7200 || r.MaxMemoryMB != 4096 {
		t.Fatalf("after update = %+v", r)
	}

	// nil puts a setting back to the platform's.
	r, err = c.UpdateSandboxRegion(ctx, list[0].Region.ID, runr.SandboxRegionChange{"allowed_classes": nil, "max_ttl_seconds": nil})
	if err != nil {
		t.Fatal(err)
	}
	if r.AllowedClasses != nil || r.MaxTTLSeconds != 86400 || r.MaxMemoryMB != 4096 {
		t.Fatalf("after reset = %+v", r)
	}

	// runc can be the default only when the region allows it.
	if _, err := c.UpdateSandboxRegion(ctx, list[0].Region.ID, runr.SandboxRegionChange{"default_class": "runc"}); !errors.As(err, &ae) || ae.StatusCode != 422 {
		t.Fatalf("runc default without allow_runc: %v", err)
	}
	if _, err := c.UpdateSandboxRegion(ctx, "01jnosuchregion00000000000", runr.SandboxRegionChange{"enabled": false}); !errors.As(err, &ae) || ae.StatusCode != 404 {
		t.Fatalf("unknown region id: %v", err)
	}
}
