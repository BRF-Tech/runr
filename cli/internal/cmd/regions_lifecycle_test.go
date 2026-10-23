package cmd

import (
	"encoding/json"
	"os"
	"path/filepath"
	"runtime"
	"slices"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

const testKubeconfig = "apiVersion: v1\nkind: Config\nusers:\n- name: admin\n  user: {token: kc-s3cr3t-token}\n"

func TestRegionGetAndStatus(t *testing.T) {
	h := newHarness(t).login()

	r := h.mustRun("regions", "get", "eu-1")
	contains(t, r.out, "Name:")
	contains(t, r.out, "eu-1")
	contains(t, r.out, "operator:")
	contains(t, r.out, "Redis (queues):")
	contains(t, r.out, "every schedulable node")
	var d runr.RegionDetail
	decodeJSON(t, h.mustRun("regions", "get", "eu-1", "-o", "json").out, &d)
	if d.Name != "eu-1" || d.ServingStatus != runr.KnativeReady || !d.HasKubeconfig || d.RedisMode != "managed" {
		t.Fatalf("detail = %+v", d)
	}
	// One active region: the argument may be left out.
	h.mustRun("regions", "get")

	r = h.mustRun("regions", "status", "eu-1", "--refresh")
	contains(t, r.out, "Status:")
	contains(t, r.out, "ready")
	if q := h.srv.CallsTo("GET /knative-regions/{knativeRegion}/knative-status")[0].Query; q != "force=1" {
		t.Fatalf("status query = %q", q)
	}
	var st runr.RegionClusterStatus
	decodeJSON(t, h.mustRun("regions", "status", "eu-1", "-o", "json").out, &st)
	if st.Status != runr.KnativeReady || st.Region == nil || st.Region.Name != "eu-1" {
		t.Fatalf("status = %+v", st)
	}
	h.expectCode(ExitNotFound, "regions", "get", "mars-1")
}

func TestRegionCreateCheckSetRm(t *testing.T) {
	h := newHarness(t).login()
	dir := t.TempDir()
	kc := filepath.Join(dir, "eu-2.yaml")
	write(t, kc, testKubeconfig)
	pass := filepath.Join(dir, "redis.pass")
	write(t, pass, "r3dis-pa55\n")

	// check: version, nodes, StorageClasses, the namespace
	r := h.mustRun("regions", "check", "eu-2", "--kubeconfig", kc)
	contains(t, r.out, "Kubernetes:")
	contains(t, r.out, "1.31")
	contains(t, r.out, "node-a")
	contains(t, r.out, "local-path (default)")
	contains(t, r.out, "free")
	r = h.expectCode(ExitValidation, "regions", "check", "eu-1", "--kubeconfig", kc)
	contains(t, r.out, "exists")
	var chk regionCheck
	decodeJSON(t, h.runStdin(testKubeconfig, "regions", "check", "--kubeconfig", "-", "-o", "json").out, &chk)
	if chk.Version != "1.31" || len(chk.Nodes) != 2 || len(chk.StorageClasses) != 2 || chk.NamespaceExists != nil {
		t.Fatalf("check = %+v", chk)
	}
	r = h.expectCode(ExitValidation, "regions", "check", "--kubeconfig", pass)
	contains(t, r.err, "Cannot connect to the cluster")

	// create: the kubeconfig never comes from an argument, nor reaches the trace
	h.expectCode(ExitUsage, "regions", "create", "eu-2")
	h.expectCode(ExitUsage, "regions", "create", "eu-2", "--kubeconfig", kc, "--redis", "external")
	h.expectCode(ExitUsage, "regions", "create", "eu-2", "--kubeconfig", kc, "--storage-class", "x")
	h.expectCode(ExitUsage, "regions", "create", "eu-2", "--kubeconfig", "-", "--redis", "external",
		"--redis-address", "r:6379", "--redis-password-file", "-")
	r = h.mustRun("--debug", "regions", "create", "eu-2", "--kubeconfig", kc, "--node", "worker-1", "--redis", "external",
		"--redis-address", "redis.example:6379", "--redis-password-file", pass, "--wait")
	contains(t, r.err, "Created region eu-2")
	notContains(t, r.err, "kc-s3cr3t-token")
	notContains(t, r.err, "r3dis-pa55")
	contains(t, r.out, "worker-1")
	contains(t, r.out, "Next: runr regions knative install eu-2 --wait")
	body := h.srv.CallsTo("POST /knative-regions")[0].Body
	if body["name"] != "eu-2" || body["kubeconfig"] != testKubeconfig || body["redis_password"] != "r3dis-pa55" ||
		len(body["nodes"].([]any)) != 1 || body["is_active"] != nil {
		t.Fatalf("create body = %v", body)
	}
	lc, _ := h.srv.RegionLifecycleOf("eu-2")
	if lc.Detail.RedisStatus != runr.KnativeReady || lc.RedisPassword != "r3dis-pa55" {
		t.Fatalf("eu-2 = %+v", lc)
	}
	h.expectCode(ExitValidation, "regions", "create", "eu-2", "--kubeconfig", kc)
	var created runr.RegionDetail
	decodeJSON(t, h.runStdin(testKubeconfig, "regions", "create", "eu-3", "--kubeconfig", "-", "--inactive", "--redis", "none",
		"-o", "json").out, &created)
	if created.Name != "eu-3" || created.IsActive || created.RedisMode != "" {
		t.Fatalf("eu-3 = %+v", created)
	}

	// set: status to the region, the rest to its settings, a new kubeconfig from stdin
	r = h.mustRun("regions", "set", "eu-2", "--set", "status=maintenance", "--set", "request_timeout=900",
		"--set", "ingress_trusted_ips=10.0.0.0/8, 192.0.2.10", "--set", "agent_ws_url=")
	contains(t, r.err, "Updated region eu-2.")
	contains(t, r.out, "maintenance")
	contains(t, r.out, "request_timeout=900")
	put := h.srv.CallsTo("PUT /knative-regions/{region}")[0].Body
	if put["region_status"] != "maintenance" || len(put) != 1 {
		t.Fatalf("update body = %v", put)
	}
	settings := h.srv.CallsTo("PUT /knative-regions/{knativeRegion}/settings")[0].Body
	if settings["request_timeout"] != float64(900) || len(settings["ingress_trusted_ips"].([]any)) != 2 || settings["agent_ws_url"] != nil {
		t.Fatalf("settings body = %v", settings)
	}
	// monitoring keys: an existing Prometheus, RunR's own Loki again
	h.mustRun("regions", "set", "eu-2", "--set", "prometheus_url=http://kps-prometheus.monitoring.svc:9090", "--set", "loki_url=")
	monitoring := h.srv.CallsTo("PUT /knative-regions/{knativeRegion}/monitoring")[0].Body
	if monitoring["prometheus_url"] != "http://kps-prometheus.monitoring.svc:9090" || monitoring["loki_url"] != nil || len(monitoring) != 2 {
		t.Fatalf("monitoring body = %v", monitoring)
	}
	h.expectCode(ExitValidation, "regions", "set", "eu-2", "--set", "prometheus_url=http://prometheus.example.com:9090")

	// internal_auth: the internal network audits (or enforces again); only the API's two values
	r = h.mustRun("regions", "set", "eu-2", "--set", "internal_auth=audit")
	contains(t, r.out, "internal_auth=audit")
	if body := h.srv.CallsTo("PUT /knative-regions/{knativeRegion}/settings")[1].Body; body["internal_auth"] != "audit" || len(body) != 1 {
		t.Fatalf("settings body = %v", body)
	}
	h.mustRun("regions", "set", "eu-2", "--set", "Internal-Auth=enforce")
	if body := h.srv.CallsTo("PUT /knative-regions/{knativeRegion}/settings")[2].Body; body["internal_auth"] != "enforce" {
		t.Fatalf("settings body = %v", body)
	}
	h.expectCode(ExitUsage, "regions", "set", "eu-2", "--set", "internal_auth=off")
	h.expectCode(ExitUsage, "regions", "set", "eu-2", "--set", "internal_auth=")
	if n := len(h.srv.CallsTo("PUT /knative-regions/{knativeRegion}/settings")); n != 3 {
		t.Fatalf("settings calls = %d, a refused value reached the API", n)
	}

	rotated := strings.Replace(testKubeconfig, "kc-s3cr3t-token", "r0tated", 1)
	h.runStdin(rotated, "regions", "set", "eu-2", "--kubeconfig", "-")
	if lc, _ := h.srv.RegionLifecycleOf("eu-2"); lc.Kubeconfig != rotated {
		t.Fatalf("kubeconfig = %q", lc.Kubeconfig)
	}
	h.expectCode(ExitUsage, "regions", "set", "eu-2")
	h.expectCode(ExitUsage, "regions", "set", "eu-2", "--set", "status=paused")
	h.expectCode(ExitUsage, "regions", "set", "eu-2", "--set", "request_timeout=0")
	h.expectCode(ExitUsage, "regions", "set", "eu-2", "--set", "colour=blue")

	// rm asks first
	h.expectCode(ExitUsage, "regions", "rm", "eu-2")
	h.terminal = true
	r = h.runStdin("n\n", "regions", "rm", "eu-2")
	contains(t, r.err, "Aborted.")
	h.terminal = false
	r = h.mustRun("regions", "rm", "eu-2", "--yes", "-o", "json")
	contains(t, r.out, `"deleted": true`)
	h.expectCode(ExitNotFound, "regions", "get", "eu-2")
}

func TestRegionCreateWithRegistry(t *testing.T) {
	h := newHarness(t).login()
	dir := t.TempDir()
	kc := filepath.Join(dir, "kc.yaml")
	write(t, kc, testKubeconfig)
	s3 := filepath.Join(dir, "s3.env")
	write(t, s3, "ENDPOINT=https://s3.example.com\nBUCKET=images\nREGION=eu-central-1\nAWS_ACCESS_KEY_ID=AKIA1\nAWS_SECRET_ACCESS_KEY=s3-s3cr3t\n")
	creds := filepath.Join(dir, "reg.env")
	write(t, creds, "USERNAME=bot\nPASSWORD='reg-s3cr3t'\n")

	// internal on a volume of the cluster's default StorageClass
	r := h.mustRun("--debug", "regions", "create", "eu-2", "--kubeconfig", kc, "--registry", "internal", "--wait")
	contains(t, r.err, "StorageClass local-path (the cluster's default)")
	contains(t, r.err, "Registry and region created successfully.")
	notContains(t, r.err, "kc-s3cr3t-token")
	contains(t, r.out, "internal, ready")
	body := h.srv.CallsTo("POST /registry-setup/start")[0].Body
	if pvc := body["pvc_config"].(map[string]any); pvc["storageClass"] != "local-path" || pvc["size"] != "10Gi" || body["storage_type"] != "pvc" {
		t.Fatalf("setup body = %v", body)
	}

	// s3, without --wait: the token to follow
	var setup runr.RegistrySetup
	decodeJSON(t, h.mustRun("--debug", "regions", "create", "eu-3", "--kubeconfig", kc, "--registry", "internal",
		"--registry-storage", "s3", "--registry-s3", s3, "-o", "json").out, &setup)
	if setup.Token == "" {
		t.Fatalf("setup = %+v", setup)
	}
	body = h.srv.CallsTo("POST /registry-setup/start")[1].Body
	if s := body["s3_config"].(map[string]any); s["access_key"] != "AKIA1" || s["secret_key"] != "s3-s3cr3t" || s["region"] != "eu-central-1" {
		t.Fatalf("s3 body = %v", body)
	}
	r = h.mustRun("regions", "registry", "setup-status", setup.Token, "--wait")
	contains(t, r.out, "eu-3")

	// external, failing: the hint names --retry; the retry with the token succeeds
	h.srv.RegionAdmin().FailInstall = true
	r = h.expectCode(ExitFailed, "--debug", "regions", "create", "eu-4", "--kubeconfig", kc, "--registry", "external",
		"--registry-address", "registry.example.com/team", "--registry-credentials", creds, "--wait")
	notContains(t, r.err, "reg-s3cr3t")
	contains(t, r.err, "--retry")
	body = h.srv.CallsTo("POST /registry-setup/start")[2].Body
	if body["external_username"] != "bot" || body["external_password"] != "reg-s3cr3t" {
		t.Fatalf("external body = %v", body)
	}
	token := h.srv.CallsTo("GET /registry-setup/{token}/status")[len(h.srv.CallsTo("GET /registry-setup/{token}/status"))-1].Path
	token = strings.TrimSuffix(strings.TrimPrefix(token, "/registry-setup/"), "/status")
	h.srv.RegionAdmin().FailInstall = false
	h.mustRun("regions", "create", "eu-4", "--kubeconfig", kc, "--registry", "external", "--registry-address", "registry.example.com/team",
		"--registry-credentials", creds, "--retry", token, "--wait")
	if len(h.srv.CallsTo("POST /registry-setup/{token}/retry")) != 1 {
		t.Fatal("no retry")
	}
	if lc, ok := h.srv.RegionLifecycleOf("eu-4"); !ok || lc.Detail.RegistryAddress != "registry.example.com/team" {
		t.Fatalf("eu-4 = %+v", lc)
	}

	// usage errors
	h.expectCode(ExitUsage, "regions", "create", "eu-5", "--kubeconfig", kc, "--registry", "cloud")
	h.expectCode(ExitUsage, "regions", "create", "eu-5", "--kubeconfig", kc, "--registry", "external")
	h.expectCode(ExitUsage, "regions", "create", "eu-5", "--kubeconfig", kc, "--registry", "internal", "--registry-storage", "s3")
	h.expectCode(ExitUsage, "regions", "create", "eu-5", "--kubeconfig", kc, "--registry", "internal", "--registry-size", "10GB")
	h.expectCode(ExitUsage, "regions", "create", "eu-5", "--kubeconfig", kc, "--registry", "internal", "--inactive")
	h.expectCode(ExitUsage, "regions", "create", "eu-5", "--kubeconfig", kc, "--registry", "external", "--registry-address", "r",
		"--storage-class", "x")
	write(t, s3, "BUCKET=images\n")
	h.expectCode(ExitUsage, "regions", "create", "eu-5", "--kubeconfig", kc, "--registry", "internal", "--registry-storage", "s3",
		"--registry-s3", s3)
	write(t, s3, "COLOUR=blue\n")
	h.expectCode(ExitUsage, "regions", "create", "eu-5", "--kubeconfig", kc, "--registry", "internal", "--registry-storage", "s3",
		"--registry-s3", s3)
}

func TestRegionKubeconfig(t *testing.T) {
	h := newHarness(t).login()
	dir := t.TempDir()
	file := filepath.Join(dir, "eu-1.yaml")

	r := h.mustRun("--debug", "regions", "kubeconfig", "eu-1", "-f", file)
	contains(t, r.err, "Wrote the kubeconfig of eu-1 to "+file+" (mode 0600).")
	notContains(t, r.err, "kc-secret-eu-1")
	notContains(t, r.out, "kc-secret-eu-1")
	contains(t, r.err, "[redacted:")
	data, err := os.ReadFile(file)
	if err != nil || !strings.Contains(string(data), "kc-secret-eu-1") {
		t.Fatalf("kubeconfig file = %q, %v", data, err)
	}
	if runtime.GOOS != "windows" {
		if fi, _ := os.Stat(file); fi.Mode().Perm() != 0o600 {
			t.Fatalf("mode = %v", fi.Mode().Perm())
		}
	}

	// An existing file is kept unless --force (which also tightens its mode).
	r = h.expectCode(ExitUsage, "regions", "kubeconfig", "eu-1", "-f", file)
	contains(t, r.err, "--force")
	if err := os.Chmod(file, 0o644); err != nil {
		t.Fatal(err)
	}
	r = h.mustRun("regions", "kubeconfig", "eu-1", "-f", file, "--force", "-o", "json")
	contains(t, r.out, `"file"`)
	notContains(t, r.out, "kc-secret-eu-1")
	if runtime.GOOS != "windows" {
		if fi, _ := os.Stat(file); fi.Mode().Perm() != 0o600 {
			t.Fatalf("mode after --force = %v", fi.Mode().Perm())
		}
	}

	// Default file name in the working directory; --stdout only when asked.
	t.Chdir(dir)
	h.mustRun("regions", "kubeconfig", "eu-1")
	if _, err := os.Stat(filepath.Join(dir, "eu-1-kubeconfig.yaml")); err != nil {
		t.Fatal(err)
	}
	r = h.mustRun("regions", "kubeconfig", "eu-1", "--stdout")
	contains(t, r.out, "kc-secret-eu-1")
	h.expectCode(ExitUsage, "regions", "kubeconfig", "eu-1", "--stdout", "-f", file)
	h.expectCode(ExitUsage, "regions", "kubeconfig", "eu-1", "--stdout", "-o", "json")
}

func TestRegionNodes(t *testing.T) {
	h := newHarness(t).login()

	r := h.mustRun("regions", "nodes", "eu-1")
	contains(t, r.out, "worker-1")
	contains(t, r.out, "every schedulable node")
	var nodes []runr.RegionNode
	decodeJSON(t, h.mustRun("regions", "nodes", "ls", "eu-1", "-o", "json").out, &nodes)
	if len(nodes) != 2 || nodes[0].CPUm != 4000 {
		t.Fatalf("nodes = %+v", nodes)
	}

	r = h.mustRun("regions", "nodes", "set", "eu-1", "worker-1", "worker-2")
	contains(t, r.err, "Worker nodes updated.")
	contains(t, r.out, "7000m CPU")
	// Releasing a node asks first.
	h.expectCode(ExitUsage, "regions", "nodes", "set", "eu-1", "worker-1")
	h.mustRun("regions", "nodes", "set", "eu-1", "worker-1", "--yes")
	if b := h.srv.CallsTo("POST /knative-regions/{knativeRegion}/workers")[1].Body; len(b["nodes"].([]any)) != 1 {
		t.Fatalf("workers body = %v", b)
	}
	h.expectCode(ExitUsage, "regions", "nodes", "set", "eu-1", "--all") // releases worker-1
	h.mustRun("regions", "nodes", "set", "eu-1", "--all", "--yes")
	if b := h.srv.CallsTo("POST /knative-regions/{knativeRegion}/workers")[2].Body; len(b["nodes"].([]any)) != 0 {
		t.Fatalf("workers body = %v", b)
	}
	h.expectCode(ExitUsage, "regions", "nodes", "set", "eu-1")
	h.expectCode(ExitUsage, "regions", "nodes", "set", "eu-1", "worker-1", "--all")
	h.expectCode(ExitUsage, "regions", "nodes", "set", "eu-1", "gpu-9")
}

func TestRegionLifecyclePermissions(t *testing.T) {
	h := newHarness(t).login()
	h.loginWith(h.srv.ScopedToken("viewer", "regions.view"))
	h.mustRun("regions", "get", "eu-1")
	h.mustRun("regions", "status", "eu-1")
	h.mustRun("regions", "nodes", "eu-1")
	h.mustRun("regions", "knative", "eu-1")
	h.mustRun("regions", "redis", "eu-1")
	h.mustRun("regions", "capacity", "eu-1")
	r := h.expectCode(ExitAuth, "regions", "kubeconfig", "eu-1", "-f", filepath.Join(t.TempDir(), "kc"))
	contains(t, r.err, "regions.manage")
	h.expectCode(ExitAuth, "regions", "set", "eu-1", "--set", "status=inactive")
	h.expectCode(ExitAuth, "regions", "rm", "eu-1", "--yes")
	h.expectCode(ExitAuth, "regions", "knative", "install", "eu-1")
	h.expectCode(ExitAuth, "regions", "registry", "rm", "eu-1", "--yes")
	kc := filepath.Join(t.TempDir(), "kc.yaml")
	write(t, kc, testKubeconfig)
	h.expectCode(ExitAuth, "regions", "create", "eu-2", "--kubeconfig", kc)
	r = h.expectCode(ExitAuth, "regions", "create", "eu-2", "--kubeconfig", kc, "--registry", "internal", "--storage-class", "x")
	contains(t, r.err, "registry.manage")
}

// TestRegionSetKeysMatchTheAPI keeps `runr regions set` equal to the API: every field of the
// settings and monitoring requests (pkg/runr/testdata/openapi.json, `make openapi`) is a key, with
// the values the API takes, and every key reaches its request. A field the API adds (as
// internal_auth in 0.6.0) fails here until the CLI sets it too.
func TestRegionSetKeysMatchTheAPI(t *testing.T) {
	data, err := os.ReadFile(filepath.Join("..", "..", "pkg", "runr", "testdata", "openapi.json"))
	if err != nil {
		t.Fatalf("read openapi.json: %v", err)
	}
	var spec struct {
		Components struct {
			Schemas map[string]struct {
				Properties map[string]struct {
					Enum []string `json:"enum"`
				} `json:"properties"`
			} `json:"schemas"`
		} `json:"components"`
	}
	if err := json.Unmarshal(data, &spec); err != nil {
		t.Fatalf("parse openapi.json: %v", err)
	}
	fields := func(name string) []string {
		props := spec.Components.Schemas[name].Properties
		if len(props) == 0 {
			t.Fatalf("schema %s has no fields in openapi.json", name)
		}
		keys := make([]string, 0, len(props))
		for k := range props {
			keys = append(keys, k)
		}
		return keys
	}
	sameSet := func(what string, got, want []string) {
		t.Helper()
		g, w := slices.Sorted(slices.Values(got)), slices.Sorted(slices.Values(want))
		if !slices.Equal(g, w) {
			t.Errorf("%s: CLI %v, API %v", what, g, w)
		}
	}
	sameSet("settings keys (UpdateRegionSettingsRequest)", regionSettingKeys, fields("UpdateRegionSettingsRequest"))
	sameSet("monitoring keys (UpdateRegionMonitoringRequest)", regionMonitoringKeys, fields("UpdateRegionMonitoringRequest"))
	settings := spec.Components.Schemas["UpdateRegionSettingsRequest"].Properties
	sameSet("internal_auth values", runr.InternalAuthModes, settings["internal_auth"].Enum)
	sameSet("cert_manager_issuer_mode values", runr.CertManagerIssuerModes, settings["cert_manager_issuer_mode"].Enum)
	sameSet("region_status values", runr.RegionStatuses, spec.Components.Schemas["UpdateKnativeRegionRequest"].Properties["region_status"].Enum)

	// every key reaches its request
	h := newHarness(t).login()
	samples := map[string]string{
		"request_timeout": "600", "agent_ws_url": "wss://runr.example.com", "cert_manager_cluster_issuer": "my-issuer",
		"cert_manager_issuer_mode": "ca", "cert_manager_acme_email": "ops@example.com", "ingress_trusted_ips": "10.0.0.0/8",
		"internal_auth": "audit", "prometheus_url": "http://kps-prometheus.monitoring.svc:9090",
		"loki_url": "http://loki.monitoring.svc:3100", "loki_org_id": "runr",
	}
	for _, group := range []struct {
		keys []string
		call string
	}{{regionSettingKeys, "PUT /knative-regions/{knativeRegion}/settings"}, {regionMonitoringKeys, "PUT /knative-regions/{knativeRegion}/monitoring"}} {
		for _, key := range group.keys {
			value, ok := samples[key]
			if !ok {
				t.Errorf("no sample value for %s", key)
				continue
			}
			before := len(h.srv.CallsTo(group.call))
			h.mustRun("regions", "set", "eu-1", "--set", key+"="+value)
			calls := h.srv.CallsTo(group.call)
			if len(calls) != before+1 {
				t.Errorf("--set %s did not call %s", key, group.call)
				continue
			}
			if _, sent := calls[len(calls)-1].Body[key]; !sent {
				t.Errorf("--set %s: body %v", key, calls[len(calls)-1].Body)
			}
		}
	}
}
