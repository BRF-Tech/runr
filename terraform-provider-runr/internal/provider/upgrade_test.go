package provider

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/hashicorp/go-hclog"
	"github.com/hashicorp/go-plugin"
	"github.com/hashicorp/hc-install/product"
	"github.com/hashicorp/hc-install/releases"
	"github.com/hashicorp/terraform-plugin-framework/providerserver"
	"github.com/hashicorp/terraform-plugin-go/tfprotov6/tf6server"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

// Upgrade from provider 0.5.10: a configuration written for 0.5.10 and the state 0.5.10 wrote
// plan no change against the 0.6.0 API with this provider.
//
// The state is made the way 0.5.10 leaves it: the configuration is applied, then every attribute
// the 0.5.10 schema does not have is removed from the state (testdata/provider-0.5.10-schema.json,
// the attribute names of `terraform providers schema -json` of the v0.5.10 provider), and what
// 0.5.10 did differently on the server is done the same way (a domain's TLS mode, which 0.5.10
// set to none where the region's default is now cert-manager). Terraform then decodes the old
// state with the new schema, refreshes it and plans: the plan must be empty.

const upgradeConfig = `
terraform {
  required_providers {
    runr = {
      source = "brf-tech/runr"
    }
  }
}

provider "runr" {
  api_url       = %q
  token         = %q
  poll_interval = "5ms"
}

data "runr_region" "eu" {
  name = "eu-1"
}

resource "runr_project" "api" {
  name      = "orders-api"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id
  handler   = "main.handler"
  resources = { memory_mb = 256, timeout_seconds = 30 }
  scale     = { min = 0, max = 10 }
  trigger   = { type = "http" }
}

resource "runr_project" "nightly" {
  name      = "nightly"
  type      = "caas"
  region_id = data.runr_region.eu.id
  image     = "nginx:1.27"
  trigger   = { type = "schedule", schedule = "0 2 * * *" }
}

resource "runr_project" "web" {
  name      = "web"
  type      = "caas"
  region_id = data.runr_region.eu.id
  image     = "nginx:1.27"
  ports     = [8080]

  lifecycle {
    ignore_changes = [image]
  }
}

resource "runr_env_var" "log_level" {
  project_id = runr_project.api.id
  name       = "LOG_LEVEL"
  value      = "info"
}

resource "runr_env_var" "database_url" {
  project_id = runr_project.api.id
  name       = "DATABASE_URL"
  value      = "postgres://orders@db/orders"
  secret     = true
}

resource "runr_domain" "api" {
  project_id = runr_project.api.id
  domain     = "api.example.com"
}

resource "runr_deployment" "web" {
  project_id = runr_project.web.id
  image      = "nginx:1.27"
}

resource "runr_binding" "cache" {
  name      = "cache"
  type      = "kv"
  region_id = data.runr_region.eu.id
}

resource "runr_binding_attachment" "cache" {
  project_id = runr_project.api.id
  binding_id = runr_binding.cache.id
  env_prefix = "CACHE_"
}

resource "runr_role" "release" {
  name        = "Release manager"
  permissions = ["projects.deploy", "logs.view"]
}

resource "runr_group" "platform" {
  name = "platform"
  role = "viewer"
}

resource "runr_project_access" "platform" {
  project_id = runr_project.api.id
  group_id   = runr_group.platform.id
  role       = runr_role.release.key
}

resource "runr_mcp_server" "api" {
  project_id  = runr_project.api.id
  access_mode = "token"
}

resource "runr_branding" "brand" {
  product_name  = "Acme Cloud"
  primary_color = "#0f766e"
}

resource "runr_model_service" "embed" {
  name      = "minilm"
  hub_id    = "sentence-transformers/all-MiniLM-L6-v2"
  region_id = data.runr_region.eu.id
}

resource "runr_runtime" "bash" {
  manifest = <<-EOT
%s
  EOT
}

data "runr_project" "flows" {
  name = "flows"
}

resource "runr_workflow_trigger" "paid" {
  project_id = data.runr_project.flows.id
  workflow   = "process-order"
  event      = "order.paid"
}
`

func TestUpgradeFrom0510PlansNoChange(t *testing.T) {
	srv := fake(t)
	// where the region issues certificates the API starts a domain in cert-manager; 0.5.10 set none
	srv.SetRegionCertManagerReady("eu-1")
	srv.SeedProject("flows", runr.TypeFaaS, "python", "eu-1", map[string]any{"trigger_type": "workflow"})
	srv.SeedDeclaredWorkflow("flows", "process-order", runr.WorkflowTrigger{Event: "order.created"})

	tf := terraformBinary(t)
	dir := t.TempDir()
	write := func(name, content string) {
		t.Helper()
		if err := os.WriteFile(filepath.Join(dir, name), []byte(content), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	write("main.tf", fmt.Sprintf(upgradeConfig, srv.URL, runrtest.DefaultToken, indent(rtTFManifest("bash", ""), "    ")))
	write("terraformrc", "")
	env := append(os.Environ(),
		"TF_REATTACH_PROVIDERS="+serveProvider(t),
		"TF_CLI_CONFIG_FILE="+filepath.Join(dir, "terraformrc"),
		"TF_IN_AUTOMATION=1", "CHECKPOINT_DISABLE=1", "TF_LOG=",
	)
	run := func(args ...string) (string, int) {
		t.Helper()
		cmd := exec.Command(tf, args...)
		cmd.Dir, cmd.Env = dir, env
		var out bytes.Buffer
		cmd.Stdout, cmd.Stderr = &out, &out
		err := cmd.Run()
		code := 0
		if err != nil {
			ee, ok := err.(*exec.ExitError)
			if !ok {
				t.Fatalf("terraform %s: %v", strings.Join(args, " "), err)
			}
			code = ee.ExitCode()
		}
		return out.String(), code
	}

	if out, code := run("init", "-input=false", "-no-color"); code != 0 {
		t.Fatalf("terraform init failed:\n%s", out)
	}
	if out, code := run("apply", "-auto-approve", "-input=false", "-no-color"); code != 0 {
		t.Fatalf("terraform apply failed:\n%s", out)
	}

	// The server as 0.5.10 left it: the domain in TLS mode none
	c := client(t, srv)
	api, _ := c.FindProject(t.Context(), "orders-api")
	d, err := c.FindDomain(t.Context(), api.ID, "api.example.com")
	if err != nil {
		t.Fatal(err)
	}
	if d.TLSMode != runr.TLSCertManager {
		t.Fatalf("the region's default TLS mode is %q, want cert-manager (the test covers a changed default)", d.TLSMode)
	}
	if _, err := c.SetDomainTLS(t.Context(), api.ID, d.ID, runr.TLSNone, "", ""); err != nil {
		t.Fatal(err)
	}
	downgradeState(t, filepath.Join(dir, "terraform.tfstate"), map[string]map[string]any{
		"runr_domain": {"tls_mode": runr.TLSNone},
	})

	out, code := run("plan", "-detailed-exitcode", "-input=false", "-no-color")
	switch code {
	case 0:
	case 2:
		t.Fatalf("a 0.5.10 configuration and state plan changes:\n%s", out)
	default:
		t.Fatalf("terraform plan failed:\n%s", out)
	}
	if !strings.Contains(out, "No changes.") {
		t.Fatalf("unexpected plan output:\n%s", out)
	}
	t.Logf("terraform plan with the 0.5.10 state:\n%s", out)
}

// The same with the previous provider itself: RUNR_TF_PREVIOUS_PROVIDER names a directory with
// a terraform-provider-runr binary of 0.5.10 (`git archive v0.5.10 terraform-provider-runr cli`,
// then `go build` in terraform-provider-runr). That provider applies the configuration, this one
// plans it: the plan must be empty. Skipped without the variable.
func TestUpgradeFromPreviousProviderBinary(t *testing.T) {
	prev := os.Getenv("RUNR_TF_PREVIOUS_PROVIDER")
	if prev == "" {
		t.Skip("RUNR_TF_PREVIOUS_PROVIDER is not set")
	}
	srv := fake(t)
	srv.SeedProject("flows", runr.TypeFaaS, "python", "eu-1", map[string]any{"trigger_type": "workflow"})
	srv.SeedDeclaredWorkflow("flows", "process-order", runr.WorkflowTrigger{Event: "order.created"})

	tf := terraformBinary(t)
	dir := t.TempDir()
	config := fmt.Sprintf(upgradeConfig, srv.URL, runrtest.DefaultToken, indent(rtTFManifest("bash", ""), "    "))
	if err := os.WriteFile(filepath.Join(dir, "main.tf"), []byte(config), 0o600); err != nil {
		t.Fatal(err)
	}
	overrides := fmt.Sprintf("provider_installation {\n  dev_overrides {\n    \"registry.terraform.io/brf-tech/runr\" = %q\n  }\n  direct {}\n}\n",
		filepath.ToSlash(prev))
	if err := os.WriteFile(filepath.Join(dir, "previous.tfrc"), []byte(overrides), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "current.tfrc"), nil, 0o600); err != nil {
		t.Fatal(err)
	}
	run := func(env []string, args ...string) (string, int) {
		t.Helper()
		cmd := exec.Command(tf, args...)
		cmd.Dir, cmd.Env = dir, append(os.Environ(), env...)
		var out bytes.Buffer
		cmd.Stdout, cmd.Stderr = &out, &out
		err := cmd.Run()
		if ee, ok := err.(*exec.ExitError); ok {
			return out.String(), ee.ExitCode()
		} else if err != nil {
			t.Fatalf("terraform %s: %v", strings.Join(args, " "), err)
		}
		return out.String(), 0
	}
	common := []string{"TF_IN_AUTOMATION=1", "CHECKPOINT_DISABLE=1", "TF_LOG="}
	previous := append([]string{"TF_CLI_CONFIG_FILE=" + filepath.Join(dir, "previous.tfrc"), "TF_REATTACH_PROVIDERS="}, common...)
	if out, code := run(previous, "apply", "-auto-approve", "-input=false", "-no-color"); code != 0 {
		t.Fatalf("terraform apply with the previous provider failed:\n%s", out)
	}
	// the upgrade sets up cert-manager in the region: new domains start in cert-manager from now on
	srv.SetRegionCertManagerReady("eu-1")
	current := append([]string{"TF_CLI_CONFIG_FILE=" + filepath.Join(dir, "current.tfrc"), "TF_REATTACH_PROVIDERS=" + serveProvider(t)}, common...)
	if out, code := run(current, "init", "-input=false", "-no-color"); code != 0 {
		t.Fatalf("terraform init failed:\n%s", out)
	}
	out, code := run(current, "plan", "-detailed-exitcode", "-input=false", "-no-color")
	if code != 0 || !strings.Contains(out, "No changes.") {
		t.Fatalf("the previous provider's state plans changes (exit %d):\n%s", code, out)
	}
	t.Logf("terraform plan with the previous provider's state:\n%s", out)
}

// downgradeState removes from a state file every attribute the 0.5.10 schema does not have and
// sets the attribute values set (per resource type).
func downgradeState(t *testing.T, file string, set map[string]map[string]any) {
	t.Helper()
	raw, err := os.ReadFile("testdata/provider-0.5.10-schema.json")
	if err != nil {
		t.Fatal(err)
	}
	var schema struct {
		Resources   map[string]map[string]any `json:"resources"`
		DataSources map[string]map[string]any `json:"data_sources"`
	}
	if err := json.Unmarshal(raw, &schema); err != nil {
		t.Fatal(err)
	}
	raw, err = os.ReadFile(file)
	if err != nil {
		t.Fatal(err)
	}
	var state map[string]any
	if err := json.Unmarshal(raw, &state); err != nil {
		t.Fatal(err)
	}
	resources, _ := state["resources"].([]any)
	removed := 0
	for _, r := range resources {
		rm, _ := r.(map[string]any)
		typ, _ := rm["type"].(string)
		shape := schema.Resources[typ]
		if rm["mode"] == "data" {
			shape = schema.DataSources[typ]
		}
		if shape == nil {
			t.Fatalf("%s %s is no type of provider 0.5.10", rm["mode"], typ)
		}
		instances, _ := rm["instances"].([]any)
		for _, inst := range instances {
			im, _ := inst.(map[string]any)
			attrs, _ := im["attributes"].(map[string]any)
			removed += pruneAttributes(attrs, shape)
			for k, v := range set[typ] {
				attrs[k] = v
			}
			// sensitive paths of attributes that are gone
			if paths, ok := im["sensitive_attributes"].([]any); ok {
				kept := []any{}
				for _, p := range paths {
					steps, _ := p.([]any)
					if len(steps) > 0 {
						first, _ := steps[0].(map[string]any)
						if name, _ := first["value"].(string); name != "" {
							if _, ok := attrs[name]; !ok {
								continue
							}
						}
					}
					kept = append(kept, p)
				}
				im["sensitive_attributes"] = kept
			}
		}
	}
	t.Logf("removed %d attributes the 0.5.10 schema does not have", removed)
	if removed == 0 {
		t.Fatal("no attribute removed: the state has the 0.5.10 shape already, the test proves nothing")
	}
	state["serial"] = float64(0) + toFloat(state["serial"]) + 1
	out, err := json.MarshalIndent(state, "", "  ")
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(file, out, 0o600); err != nil {
		t.Fatal(err)
	}
}

// pruneAttributes removes the attributes shape does not name (nested attributes: objects and
// lists or sets of objects) and returns how many it removed.
func pruneAttributes(attrs map[string]any, shape map[string]any) int {
	n := 0
	for name, v := range attrs {
		s, ok := shape[name]
		if !ok {
			delete(attrs, name)
			n++
			continue
		}
		nested, isNested := s.(map[string]any)
		if !isNested {
			continue
		}
		switch x := v.(type) {
		case map[string]any:
			n += pruneAttributes(x, nested)
		case []any:
			for _, e := range x {
				if em, ok := e.(map[string]any); ok {
					n += pruneAttributes(em, nested)
				}
			}
		}
	}
	return n
}

func toFloat(v any) float64 {
	f, _ := v.(float64)
	return f
}

// serveProvider serves the provider in this process and returns the TF_REATTACH_PROVIDERS value
// for registry.terraform.io/brf-tech/runr; the server stops when the test ends.
func serveProvider(t *testing.T) string {
	t.Helper()
	ctx, cancel := context.WithCancel(context.Background())
	reattach := make(chan *plugin.ReattachConfig)
	closed := make(chan struct{})
	go func() {
		err := tf6server.Serve("registry.terraform.io/brf-tech/runr", providerserver.NewProtocol6(New("test")()),
			tf6server.WithDebug(ctx, reattach, closed), tf6server.WithLoggingSink(t),
			tf6server.WithGoPluginLogger(hclog.NewNullLogger()))
		if err != nil {
			t.Errorf("serve provider: %v", err)
		}
	}()
	var cfg *plugin.ReattachConfig
	select {
	case cfg = <-reattach:
	case <-time.After(30 * time.Second):
		cancel()
		t.Fatal("the provider server did not start")
	}
	t.Cleanup(func() {
		cancel()
		select {
		case <-closed:
		case <-time.After(10 * time.Second):
		}
	})
	type addr struct{ Network, String string }
	type config struct {
		Protocol        string
		ProtocolVersion int
		Pid             int
		Test            bool
		Addr            addr
	}
	b, err := json.Marshal(map[string]config{"registry.terraform.io/brf-tech/runr": {
		Protocol: string(cfg.Protocol), ProtocolVersion: cfg.ProtocolVersion, Pid: cfg.Pid, Test: true,
		Addr: addr{Network: cfg.Addr.Network(), String: cfg.Addr.String()},
	}})
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

// terraformBinary finds terraform the way terraform-plugin-testing does: TF_ACC_TERRAFORM_PATH,
// else terraform on PATH, else the latest release downloaded for the test.
func terraformBinary(t *testing.T) string {
	t.Helper()
	if p := os.Getenv("TF_ACC_TERRAFORM_PATH"); p != "" {
		return p
	}
	if p, err := exec.LookPath("terraform"); err == nil {
		return p
	}
	installer := &releases.LatestVersion{Product: product.Terraform, InstallDir: t.TempDir()}
	p, err := installer.Install(t.Context())
	if err != nil {
		t.Fatalf("install terraform: %v", err)
	}
	return p
}
