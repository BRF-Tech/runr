package runr_test

import (
	"bytes"
	"context"
	"encoding/json"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

// PHP sends empty maps as [] and unset columns as null.
func TestRegionDetailDecodesLooseFields(t *testing.T) {
	var d runr.RegionDetail
	raw := `{"id":"r1","name":"eu-1","is_active":true,"knative_settings":[],"selected_workers":null,"dns_instruction":null,
		"knative_status":"ready","knative_redis_status":null,"faas_cpu_overcommit":3,"faas_memory_overcommit":1.5,"is_migrating":false}`
	if err := json.Unmarshal([]byte(raw), &d); err != nil {
		t.Fatal(err)
	}
	if d.Name != "eu-1" || d.KnativeSettings != nil || d.SelectedWorkers == nil || len(d.SelectedWorkers) != 0 ||
		d.DNSInstruction != nil || d.FaaSMemoryOvercommit != 1.5 || d.KnativeStatus != "ready" {
		t.Fatalf("detail = %+v", d)
	}
	raw = `{"id":"r1","name":"eu-1","knative_settings":{"request_timeout":300},"selected_workers":["w1"],
		"dns_instruction":{"wildcard":"*.eu-1.example.com","type":"A","target":"203.0.113.10"}}`
	if err := json.Unmarshal([]byte(raw), &d); err != nil {
		t.Fatal(err)
	}
	if d.KnativeSettings["request_timeout"] != float64(300) || d.SelectedWorkers[0] != "w1" || d.DNSInstruction.Type != "A" {
		t.Fatalf("detail = %+v", d)
	}
	// 0.6.0 answers a region without settings with {} (the API document types it as an object)
	var empty runr.RegionDetail
	if err := json.Unmarshal([]byte(`{"id":"r1","name":"eu-1","knative_settings":{}}`), &empty); err != nil || len(empty.KnativeSettings) != 0 {
		t.Fatalf("detail = %+v, %v", empty, err)
	}

	var n runr.RegionNode
	if err := json.Unmarshal([]byte(`{"name":"w1","labels":[],"cpu_m":4000}`), &n); err != nil || n.Labels != nil || n.CPUm != 4000 {
		t.Fatalf("node = %+v, %v", n, err)
	}
	if err := json.Unmarshal([]byte(`{"name":"w1","labels":{"zone":"a","n":1}}`), &n); err != nil || n.Labels["n"] != "1" {
		t.Fatalf("node = %+v, %v", n, err)
	}

	var w runr.RegionWasm
	if err := json.Unmarshal([]byte(`{"status":"ready","shims":null,"nodes":null,"cluster":null}`), &w); err != nil ||
		w.Nodes != nil || w.Shims == nil || w.Cluster != nil {
		t.Fatalf("wasm = %+v, %v", w, err)
	}
	if err := json.Unmarshal([]byte(`{"status":"ready","shims":["spin"],"nodes":["w1"]}`), &w); err != nil || w.Nodes[0] != "w1" {
		t.Fatalf("wasm = %+v, %v", w, err)
	}

	var s runr.RegistrySetupStatus
	if err := json.Unmarshal([]byte(`{"status":"ready","phase":"done","region_id":42}`), &s); err != nil || s.RegionID != "42" {
		t.Fatalf("setup = %+v, %v", s, err)
	}

	var cs runr.RegionClusterStatus
	if err := json.Unmarshal([]byte(`{"status":"not_installed","message":"","details":[]}`), &cs); err != nil || cs.Details != nil {
		t.Fatalf("cluster status = %+v, %v", cs, err)
	}
}

// A region's kubeconfig and passwords stay out of the debug trace: the YAML of the download (no
// JSON field redaction reaches it) and the fields of a create.
func TestRegionTraceRedactsKubeconfig(t *testing.T) {
	srv := runrtest.New(t)
	var trace bytes.Buffer
	c, _ := runr.New(runr.Options{APIURL: srv.URL, Token: runrtest.DefaultToken, HTTPClient: srv.Client(), Debug: &trace})
	ctx := context.Background()
	reg, err := c.FindRegion(ctx, "eu-1")
	if err != nil {
		t.Fatal(err)
	}
	kc, err := c.RegionKubeconfig(ctx, reg.ID)
	if err != nil || !strings.Contains(string(kc), "kc-secret-eu-1") {
		t.Fatalf("kubeconfig = %q, %v", kc, err)
	}
	if strings.Contains(trace.String(), "kc-secret-eu-1") || !strings.Contains(trace.String(), "< [redacted:") {
		t.Fatalf("trace leaks the kubeconfig:\n%s", trace.String())
	}

	trace.Reset()
	in := runr.RegionCreateInput{Name: "eu-2", Kubeconfig: "apiVersion: v1\nkind: Config\n# kc-new-secret\n",
		RedisMode: "external", RedisAddress: "redis.example:6379", RedisPassword: "r3dis-pa55"}
	if _, err := c.CreateRegion(ctx, in); err != nil {
		t.Fatal(err)
	}
	setup := runr.RegistrySetupInput{RegionCreateInput: runr.RegionCreateInput{Name: "eu-3", Kubeconfig: in.Kubeconfig},
		RegistryConfig: runr.RegistryConfig{RegistryMode: "external", ExternalAddress: "registry.example.com", ExternalUsername: "bot",
			ExternalPassword: "reg-s3cr3t"}}
	if _, err := c.StartRegistrySetup(ctx, setup); err != nil {
		t.Fatal(err)
	}
	for _, secret := range []string{"kc-new-secret", "r3dis-pa55", "reg-s3cr3t"} {
		if strings.Contains(trace.String(), secret) {
			t.Fatalf("trace leaks %s:\n%s", secret, trace.String())
		}
	}
	if !strings.Contains(trace.String(), `"redis_password":"[redacted]"`) || !strings.Contains(trace.String(), `"external_password":"[redacted]"`) {
		t.Fatalf("trace does not show the redaction:\n%s", trace.String())
	}
}
