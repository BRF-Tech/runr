package cmd

import (
	"path/filepath"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestRegionRedis(t *testing.T) {
	h := newHarness(t).login()
	pass := filepath.Join(t.TempDir(), "redis.pass")
	write(t, pass, "r3dis-pa55\n")

	r := h.mustRun("regions", "redis", "eu-1")
	contains(t, r.out, "managed")
	contains(t, r.out, "available")

	r = h.mustRun("--debug", "regions", "redis", "test", "eu-1", "--address", "redis.example:6379", "--password-file", pass)
	contains(t, r.out, "PONG")
	notContains(t, r.err, "r3dis-pa55")
	if b := h.srv.CallsTo("POST /knative-regions/{knativeRegion}/test-redis")[0].Body; b["password"] != "r3dis-pa55" {
		t.Fatalf("test body = %v", b)
	}
	r = h.expectCode(ExitFailed, "regions", "redis", "test", "eu-1", "--address", "unreachable:6379")
	contains(t, r.out, "Redis connection failed")
	h.expectCode(ExitUsage, "regions", "redis", "test", "eu-1")

	r = h.mustRun("--debug", "regions", "redis", "set", "eu-1", "--mode", "external", "--address", "redis.example:6379",
		"--password-file", pass, "--wait")
	contains(t, r.err, "Redis installation queued.")
	notContains(t, r.err, "r3dis-pa55")
	contains(t, r.out, "redis.example:6379")
	if lc, _ := h.srv.RegionLifecycleOf("eu-1"); lc.Detail.RedisMode != "external" || lc.RedisPassword != "r3dis-pa55" ||
		lc.Detail.RedisStatus != runr.KnativeReady {
		t.Fatalf("redis = %+v", lc)
	}
	h.expectCode(ExitUsage, "regions", "redis", "set", "eu-1", "--mode", "external")
	h.expectCode(ExitUsage, "regions", "redis", "set", "eu-1", "--mode", "managed", "--address", "x:1")
	h.expectCode(ExitUsage, "regions", "redis", "set", "eu-1", "--mode", "cluster")

	h.expectCode(ExitUsage, "regions", "redis", "rm", "eu-1")
	var st runr.RegionRedisStatus
	decodeJSON(t, h.mustRun("regions", "redis", "rm", "eu-1", "--yes", "--wait", "-o", "json").out, &st)
	if st.Mode != "" || st.HasRedis {
		t.Fatalf("after rm = %+v", st)
	}

	h.srv.RegionAdmin().FailInstall = true
	r = h.expectCode(ExitFailed, "regions", "redis", "set", "eu-1", "--mode", "managed", "--wait")
	contains(t, r.err, "Redis of region eu-1 failed")
}

func TestRegionRegistry(t *testing.T) {
	h := newHarness(t).login()
	dir := t.TempDir()
	kc := filepath.Join(dir, "kc.yaml")
	write(t, kc, testKubeconfig)
	h.mustRun("regions", "create", "eu-2", "--kubeconfig", kc, "--registry", "internal", "--storage-class", "fast-ssd", "--wait")

	r := h.mustRun("regions", "registry", "eu-2")
	contains(t, r.out, "internal")
	contains(t, r.out, "zot.runr-registry.svc:5000")
	r = h.mustRun("regions", "registry", "eu-1")
	contains(t, r.out, "has no registry ready")

	h.expectCode(ExitUsage, "regions", "registry", "rm", "eu-2")
	r = h.mustRun("regions", "registry", "rm", "eu-2", "--yes")
	contains(t, r.err, "Removed the registry of eu-2.")
	var st runr.RegionRegistryStatus
	decodeJSON(t, h.mustRun("regions", "registry", "status", "eu-2", "-o", "json").out, &st)
	if st.HasRegistry || st.Mode != "" {
		t.Fatalf("after rm = %+v", st)
	}

	// The setup token of an unknown setup has expired.
	h.expectCode(ExitNotFound, "regions", "registry", "setup-status", "no-such-token")

	// A registry for an existing region: external, then (after rm) internal on a named class
	creds := filepath.Join(dir, "registry.env")
	write(t, creds, "USERNAME=bot\nPASSWORD=reg-s3cr3t\n")
	r = h.mustRun("--debug", "regions", "registry", "set", "eu-2", "--registry", "external", "--registry-address", "registry.example.com/team",
		"--registry-credentials", creds, "--wait")
	contains(t, r.err, "Registry setup started.")
	notContains(t, r.err, "reg-s3cr3t")
	if b := h.srv.CallsTo("POST /knative-regions/{knativeRegion}/registry")[0].Body; b["external_password"] != "reg-s3cr3t" || b["registry_mode"] != "external" {
		t.Fatalf("registry body = %v", b)
	}
	if lc, _ := h.srv.RegionLifecycleOf("eu-2"); !lc.Detail.HasRegistry || lc.Detail.RegistryAddress != "registry.example.com/team" {
		t.Fatalf("after set = %+v", lc.Detail)
	}
	h.mustRun("regions", "registry", "rm", "eu-2", "--yes")
	h.mustRun("regions", "registry", "set", "eu-2", "--registry", "internal", "--storage-class", "fast-ssd", "--registry-size", "50Gi")
	if b := h.srv.CallsTo("POST /knative-regions/{knativeRegion}/registry")[1].Body; b["storage_type"] != "pvc" ||
		b["pvc_config"].(map[string]any)["size"] != "50Gi" {
		t.Fatalf("internal registry body = %v", b)
	}
	h.expectCode(ExitUsage, "regions", "registry", "set", "eu-2", "--registry", "external")
	h.expectCode(ExitUsage, "regions", "registry", "set", "eu-2", "--registry", "zot")
}

func TestRegionRegistryTests(t *testing.T) {
	h := newHarness(t).login()
	dir := t.TempDir()
	kc := filepath.Join(dir, "kc.yaml")
	write(t, kc, testKubeconfig)
	s3 := filepath.Join(dir, "s3.env")
	write(t, s3, "endpoint=https://s3.example.com\nbucket=images\nregion=eu\naccess_key=AK\nsecret_key=s3-s3cr3t\n")
	creds := filepath.Join(dir, "reg.env")
	write(t, creds, "username=bot\npassword=reg-s3cr3t\n")

	r := h.mustRun("regions", "registry", "test", "pvc", "--kubeconfig", kc, "--storage-class", "fast-ssd")
	contains(t, r.out, "passed")
	if b := h.srv.CallsTo("POST /registry-setup/test-pvc")[0].Body; b["size"] != "10Gi" || b["storageClass"] != "fast-ssd" {
		t.Fatalf("pvc body = %v", b)
	}
	r = h.expectCode(ExitFailed, "regions", "registry", "test", "pvc", "--kubeconfig", kc, "--storage-class", "missing")
	contains(t, r.out, "did not provision")
	r = h.mustRun("--debug", "regions", "registry", "test", "s3", "--kubeconfig", kc, "--s3", s3)
	notContains(t, r.err, "s3-s3cr3t")
	notContains(t, r.err, "kc-s3cr3t-token")
	r = h.mustRun("--debug", "regions", "registry", "test", "external", "--kubeconfig", kc, "--address", "registry.example.com",
		"--credentials", creds, "--namespace", "ci", "-o", "json")
	notContains(t, r.err, "reg-s3cr3t")
	contains(t, r.out, `"success": true`)
	if b := h.srv.CallsTo("POST /registry-setup/test-external")[0].Body; b["username"] != "bot" || b["password"] != "reg-s3cr3t" || b["namespace"] != "ci" {
		t.Fatalf("external body = %v", b)
	}

	h.expectCode(ExitUsage, "regions", "registry", "test", "nfs", "--kubeconfig", kc)
	h.expectCode(ExitUsage, "regions", "registry", "test", "pvc", "--kubeconfig", kc)
	h.expectCode(ExitUsage, "regions", "registry", "test", "pvc", "--kubeconfig", kc, "--storage-class", "x", "--s3", s3)
	h.expectCode(ExitUsage, "regions", "registry", "test", "external", "--kubeconfig", "-", "--credentials", "-", "--address", "r")
}

func TestRegionMigration(t *testing.T) {
	h := newHarness(t).login()
	kc := filepath.Join(t.TempDir(), "new.yaml")
	write(t, kc, testKubeconfig)

	r := h.mustRun("regions", "migration", "eu-1")
	contains(t, r.out, "no migration")

	h.expectCode(ExitUsage, "regions", "migration", "start", "eu-1", "--kubeconfig", kc)
	h.expectCode(ExitUsage, "regions", "migration", "start", "eu-1", "--yes")
	r = h.mustRun("--debug", "regions", "migration", "start", "eu-1", "--kubeconfig", kc, "--data-location", "EU", "--yes", "--wait")
	contains(t, r.err, "Migration started.")
	notContains(t, r.err, "kc-s3cr3t-token")
	contains(t, r.out, "dns_check")
	contains(t, r.out, "Point the region's DNS at 198.51.100.20")
	if b := h.srv.CallsTo("POST /knative-regions/{knativeRegion}/migrate")[0].Body; b["data_location"] != "EU" {
		t.Fatalf("migrate body = %v", b)
	}
	// A second start is refused while one runs.
	h.expectCode(ExitValidation, "regions", "migration", "start", "eu-1", "--kubeconfig", kc, "--yes")

	r = h.mustRun("regions", "migration", "complete", "eu-1")
	contains(t, r.err, "Migration completed.")
	h.expectCode(ExitValidation, "regions", "migration", "complete", "eu-1")
	if lc, _ := h.srv.RegionLifecycleOf("eu-1"); lc.Kubeconfig != testKubeconfig {
		t.Fatalf("kubeconfig after the move = %q", lc.Kubeconfig)
	}

	// A failed migration is rolled back.
	h.srv.RegionAdmin().FailInstall = true
	r = h.expectCode(ExitFailed, "regions", "migration", "start", "eu-1", "--kubeconfig", kc, "--yes", "--wait")
	contains(t, r.out, "rollback")
	h.expectCode(ExitUsage, "regions", "migration", "rollback", "eu-1")
	r = h.mustRun("regions", "migration", "rollback", "eu-1", "--yes", "-o", "json")
	contains(t, r.out, "Migration rolled back.")

	// A move to a new base domain, completed by hand
	h.expectCode(ExitValidation, "regions", "migration", "complete", "eu-1", "--domain")
	h.srv.SetRegionDomainMigrating("eu-1", true)
	r = h.mustRun("regions", "migration", "complete", "eu-1", "--domain")
	contains(t, r.err, "the region answers on its new function domain")
}

// While a move can be rolled back, migration status lists what it did with each project.
func TestRegionMigrationStatusListsProjects(t *testing.T) {
	h := newHarness(t).login()
	kc := filepath.Join(t.TempDir(), "new.yaml")
	write(t, kc, testKubeconfig)
	h.srv.SetRegionMigrationProjects("eu-1", []runr.MigrationProject{
		{ProjectID: "p1", Project: "orders", Outcome: runr.MigrationBuilding, Version: 3,
			Message: "Moving to the new cluster of the region: v3 is built again there; deployed there when it is ready."},
		{Organization: "acme", ProjectID: "p2", Project: "shop", Outcome: runr.MigrationMoved, Version: 1, DeploymentStatus: "active"},
		{ProjectID: "p3", Project: "upload", Outcome: runr.MigrationNotMoved,
			Message: "its image myapp:1.0 was uploaded into the registry of the cluster the region left"},
		// an environment of a project is a row of its own (docs/environments.md)
		{ProjectID: "p1", Project: "orders", Environment: "staging", Outcome: runr.MigrationMoved, Version: 2, DeploymentStatus: "active"},
	})

	// No move: no list
	r := h.mustRun("regions", "migration", "status", "eu-1")
	notContains(t, r.out, "PROJECT")

	h.mustRun("regions", "migration", "start", "eu-1", "--kubeconfig", kc, "--yes", "--wait")
	r = h.mustRun("regions", "migration", "status", "eu-1")
	contains(t, r.out, "dns_check")
	contains(t, r.out, "PROJECT")
	contains(t, r.out, "ORGANIZATION")
	contains(t, r.out, "OUTCOME")
	contains(t, r.out, "DEPLOYMENT")
	lines := strings.Split(r.out, "\n")
	var rows []string
	for _, l := range lines {
		if strings.HasPrefix(l, "orders") || strings.HasPrefix(l, "shop") || strings.HasPrefix(l, "upload") {
			rows = append(rows, strings.Join(strings.Fields(l), " "))
		}
	}
	want := []string{
		"orders - building v3 - Moving to the new cluster of the region: v3 is built again there; deployed there when it is ready.",
		"shop acme moved v1 active -",
		"upload - not_moved - - its image myapp:1.0 was uploaded into the registry of the cluster the region left",
		"orders (staging) - moved v2 active -",
	}
	if strings.Join(rows, "\n") != strings.Join(want, "\n") {
		t.Fatalf("rows:\n%s\nwant:\n%s\nout:\n%s", strings.Join(rows, "\n"), strings.Join(want, "\n"), r.out)
	}

	r = h.mustRun("regions", "migration", "eu-1", "-o", "json")
	var st runr.RegionMigration
	decodeJSON(t, r.out, &st)
	if len(st.Projects) != 4 || st.Projects[3].Environment != "staging" || st.Projects[1].Organization != "acme" || st.Projects[0].Version != 3 || st.Projects[2].Outcome != runr.MigrationNotMoved {
		t.Fatalf("json projects = %+v", st.Projects)
	}

	// Completed: the move can no longer be rolled back, nothing is listed
	h.mustRun("regions", "migration", "complete", "eu-1")
	r = h.mustRun("regions", "migration", "eu-1", "-o", "json")
	notContains(t, r.out, "projects")
}

func TestRegionFunctionTLS(t *testing.T) {
	h := newHarness(t).login()
	dir := t.TempDir()
	cert := filepath.Join(dir, "tls.crt")
	key := filepath.Join(dir, "tls.key")
	write(t, cert, "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n")
	write(t, key, "-----BEGIN PRIVATE KEY-----\nk3y-s3cr3t\n-----END PRIVATE KEY-----\n")

	r := h.mustRun("regions", "function-tls", "eu-1")
	contains(t, r.out, "not set")

	r = h.mustRun("--debug", "regions", "function-tls", "set", "eu-1", "--mode", "custom", "--cert", cert, "--key", key, "--replace-existing")
	contains(t, r.err, "Function domain TLS updated.")
	notContains(t, r.err, "k3y-s3cr3t")
	notContains(t, r.out, "k3y-s3cr3t")
	if lc, _ := h.srv.RegionLifecycleOf("eu-1"); lc.Detail.FunctionTLSMode != "custom" || lc.TLSKey == "" {
		t.Fatalf("tls = %+v", lc)
	}
	if b := h.srv.CallsTo("PUT /knative-regions/{knativeRegion}/function-tls")[0].Body; b["replace_existing"] != true {
		t.Fatalf("tls body = %v", b)
	}
	r = h.mustRun("regions", "function-tls", "get", "eu-1", "-o", "json")
	contains(t, r.out, `"mode": "custom"`)
	h.mustRun("regions", "function-tls", "set", "eu-1", "--mode", "console")

	h.expectCode(ExitUsage, "regions", "function-tls", "set", "eu-1", "--mode", "custom", "--cert", cert)
	h.expectCode(ExitUsage, "regions", "function-tls", "set", "eu-1", "--mode", "custom", "--cert", key, "--key", key)
	h.expectCode(ExitUsage, "regions", "function-tls", "set", "eu-1", "--mode", "console", "--cert", cert)
	h.expectCode(ExitUsage, "regions", "function-tls", "set", "eu-1", "--mode", "acme")

	// Off again: the region's default certificate
	h.expectCode(ExitUsage, "regions", "function-tls", "rm", "eu-1")
	r = h.mustRun("regions", "function-tls", "rm", "eu-1", "--yes")
	contains(t, r.err, "Function-domain TLS removed.")
	if lc, _ := h.srv.RegionLifecycleOf("eu-1"); lc.Detail.FunctionTLSMode != "" {
		t.Fatalf("after rm = %+v", lc.Detail)
	}
}

func TestRegionSkupper(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("regions", "skupper", "eu-1")
	contains(t, r.out, "installed (skupper.io/v2alpha1)")
	contains(t, r.out, "creates the site")
	r = h.mustRun("regions", "skupper", "site", "eu-1")
	contains(t, r.err, "Skupper site runr of eu-1: Pending.")
	var st runr.RegionSkupper
	decodeJSON(t, h.mustRun("regions", "skupper", "status", "eu-1", "-o", "json").out, &st)
	if !st.Available || st.Site == nil || st.Site.Name != "runr" {
		t.Fatalf("skupper = %+v", st)
	}
	h.srv.SetRegionSkupper("eu-1", false)
	r = h.mustRun("regions", "skupper", "eu-1")
	contains(t, r.out, "not installed")
	h.expectCode(ExitValidation, "regions", "skupper", "site", "eu-1")
}

func TestRegionCapacity(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("regions", "capacity", "eu-1")
	contains(t, r.out, "FaaS overcommit:")
	contains(t, r.out, "CPU ×3, memory ×1.5")
	contains(t, r.out, "cpu (m)")

	var st runr.RegionCapacityState
	decodeJSON(t, h.mustRun("regions", "capacity", "set", "eu-1", "--set", "faas_cpu_overcommit=4", "-o", "json").out, &st)
	if st.FaaSCPUOvercommit != 4 || st.FaaSMemoryOvercommit != 1.5 || st.CPUm.FaaSOvercommit != 4 {
		t.Fatalf("capacity = %+v", st)
	}
	if b := h.srv.CallsTo("PUT /knative-regions/{knativeRegion}/capacity")[0].Body; len(b) != 1 {
		t.Fatalf("capacity body = %v", b)
	}
	h.mustRun("regions", "capacity", "set", "eu-1", "--set", "memory=1.25")
	h.expectCode(ExitValidation, "regions", "capacity", "set", "eu-1", "--set", "cpu=2.555")
	h.expectCode(ExitUsage, "regions", "capacity", "set", "eu-1", "--set", "cpu=11")
	h.expectCode(ExitUsage, "regions", "capacity", "set", "eu-1", "--set", "gpu=2")
	h.expectCode(ExitUsage, "regions", "capacity", "set", "eu-1")
}

func TestRegionDemo(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("regions", "demo", "eu-1")
	contains(t, r.out, "none")
	r = h.mustRun("regions", "demo", "install", "eu-1", "--wait")
	contains(t, r.out, "ready")
	h.expectCode(ExitValidation, "regions", "demo", "install", "eu-1")
	h.expectCode(ExitUsage, "regions", "demo", "rm", "eu-1")
	r = h.mustRun("regions", "demo", "rm", "eu-1", "--yes", "--wait", "-o", "json")
	contains(t, r.out, `"region_demo_status": ""`)

	h.srv.RegionAdmin().FailInstall = true
	r = h.expectCode(ExitFailed, "regions", "demo", "install", "eu-1", "--wait")
	contains(t, r.err, "demo projects of region eu-1 failed")
}

func TestRegionServicesPermissions(t *testing.T) {
	h := newHarness(t).login()
	h.loginWith(h.srv.ScopedToken("viewer", "regions.view"))
	h.mustRun("regions", "registry", "eu-1")
	h.mustRun("regions", "migration", "eu-1")
	h.mustRun("regions", "skupper", "eu-1")
	h.mustRun("regions", "function-tls", "eu-1")
	h.mustRun("regions", "demo", "eu-1")
	h.expectCode(ExitAuth, "regions", "redis", "rm", "eu-1", "--yes")
	h.expectCode(ExitAuth, "regions", "capacity", "set", "eu-1", "--set", "cpu=2")
	h.expectCode(ExitAuth, "regions", "skupper", "site", "eu-1")
	h.expectCode(ExitAuth, "regions", "demo", "install", "eu-1")
	h.expectCode(ExitAuth, "regions", "migration", "complete", "eu-1")
	kc := filepath.Join(t.TempDir(), "kc.yaml")
	write(t, kc, testKubeconfig)
	r := h.expectCode(ExitAuth, "regions", "registry", "test", "pvc", "--kubeconfig", kc, "--storage-class", "x")
	contains(t, r.err, "registry.manage")
}
