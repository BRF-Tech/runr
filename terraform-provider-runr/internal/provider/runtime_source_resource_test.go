package provider

import (
	"fmt"
	"regexp"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"
	"github.com/hashicorp/terraform-plugin-testing/tfversion"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

const tfAcmeRepo = "https://git.example.com/acme/runtimes.git"

// tfSourceBash is a runtime of a source, deprecated with a sunset far ahead.
const tfSourceBash = `apiVersion: runr.sh/v1
kind: Runtime
id: bash
name: Bash
extensions: [sh]
deprecated: "2026-01-01"
sunset: "2099-01-01"
replacement: zsh
versions:
  - version: "5"
    image: bash:5
    default: true
run:
  mode: exec
  command: ["bash", "/app/code/{file}"]
`

func runtimeSourceConfig(srv *runrtest.Server, body string) string {
	return providerBlock(srv) + `
resource "runr_runtime_source" "acme" {
  name = "acme"
  kind = "git"
  url  = "` + tfAcmeRepo + `"
` + body + `
}
`
}

func runtimeSourceGone(srv *runrtest.Server, names ...string) resource.TestCheckFunc {
	return func(*terraform.State) error {
		for _, n := range names {
			if _, ok := srv.RuntimeSource(n); ok {
				return fmt.Errorf("runtime source %s still exists", n)
			}
		}
		return nil
	}
}

// A private Git source: connected and read, imported without its token, the token written again
// when it changes outside Terraform, read again when sync_trigger changes, made public by removing
// the token.
func TestRuntimeSourceResource(t *testing.T) {
	srv := fake(t)
	srv.SetRemoteRuntimeSource(tfAcmeRepo, runrtest.RemoteRuntimeSource{Private: true, Revision: "c0ffee1",
		Runtimes: map[string]string{"bash": tfSourceBash}})
	const res = "runr_runtime_source.acme"
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             runtimeSourceGone(srv, "acme"),
		Steps: []resource.TestStep{
			{
				Config: runtimeSourceConfig(srv, `
  ref              = "main"
  git_token        = "glpat-0123456789abcd"
  interval_minutes = 30
`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr(res, "status", "ok"),
					resource.TestCheckResourceAttr(res, "revision", "c0ffee1"),
					resource.TestCheckResourceAttr(res, "runtimes_count", "1"),
					resource.TestCheckResourceAttr(res, "scope", "platform"),
					resource.TestCheckResourceAttr(res, "enabled", "true"),
					resource.TestCheckResourceAttr(res, "interval_minutes", "30"),
					resource.TestCheckResourceAttr(res, "credentials.git", "token"),
					resource.TestCheckResourceAttr(res, "credentials.git_token_hint", "••••••abcd"),
					resource.TestCheckResourceAttr(res, "problems.#", "0"),
					resource.TestCheckNoResourceAttr(res, "path"),
					check(func() error {
						if c := srv.RuntimeSourceCredentials("acme"); c["git_token"] != "glpat-0123456789abcd" {
							return fmt.Errorf("credentials = %q", c)
						}
						body := srv.CallsTo("POST /runtime-sources")[0].Body
						if body["ref"] != "main" || body["path"] != nil || body["registry_password"] != nil {
							return fmt.Errorf("create body = %v", body)
						}
						return nil
					}),
				),
			},
			{
				ResourceName:            res,
				ImportState:             true,
				ImportStateId:           "acme",
				ImportStateVerify:       true,
				ImportStateVerifyIgnore: []string{"git_token"},
			},
			{
				// The token was rotated outside Terraform: the configured one is written again
				PreConfig: func() {
					c := client(t, srv)
					s, err := c.FindRuntimeSource(t.Context(), "acme")
					if err != nil {
						t.Fatal(err)
					}
					other := "glpat-rotated-elsewhere-9999"
					if _, err := c.UpdateRuntimeSource(t.Context(), s.ID, runr.RuntimeSourceInput{GitToken: &other}); err != nil {
						t.Fatal(err)
					}
				},
				Config: runtimeSourceConfig(srv, `
  ref              = "main"
  git_token        = "glpat-0123456789abcd"
  interval_minutes = 30
`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction(res, plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					if c := srv.RuntimeSourceCredentials("acme"); c["git_token"] != "glpat-0123456789abcd" {
						return fmt.Errorf("credentials after the rewrite = %q", c)
					}
					return nil
				}),
			},
			{
				// Nothing changed: an empty plan
				Config: runtimeSourceConfig(srv, `
  ref              = "main"
  git_token        = "glpat-0123456789abcd"
  interval_minutes = 30
`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
			},
			{
				// sync_trigger reads the source now, without a change
				PreConfig: func() {
					srv.ResetCalls()
					srv.SetRemoteRuntimeSource(tfAcmeRepo, runrtest.RemoteRuntimeSource{Private: true, Revision: "c0ffee2",
						Runtimes: map[string]string{"bash": tfSourceBash}})
				},
				Config: runtimeSourceConfig(srv, `
  ref              = "main"
  git_token        = "glpat-0123456789abcd"
  interval_minutes = 30
  sync_trigger     = "v2"
`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr(res, "revision", "c0ffee2"),
					check(func() error {
						if n, m := len(srv.CallsTo("POST /runtime-sources/{source}/sync")), len(srv.CallsTo("PUT /runtime-sources/{source}")); n != 1 || m != 0 {
							return fmt.Errorf("%d syncs, %d updates", n, m)
						}
						return nil
					}),
				),
			},
			{
				// The repository turned public: no token, another branch and directory; the
				// credentials are sent as null
				PreConfig: func() {
					srv.ResetCalls()
					srv.SetRemoteRuntimeSource(tfAcmeRepo, runrtest.RemoteRuntimeSource{Revision: "c0ffee3",
						Runtimes: map[string]string{"bash": tfSourceBash}})
				},
				Config: runtimeSourceConfig(srv, `
  ref          = "v2"
  path         = "catalog/runtimes"
  sync_trigger = "v2"
`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckNoResourceAttr(res, "credentials.git"),
					resource.TestCheckResourceAttr(res, "path", "catalog/runtimes"),
					resource.TestCheckResourceAttr(res, "interval_minutes", "30"),
					check(func() error {
						put := srv.CallsTo("PUT /runtime-sources/{source}")
						if len(put) != 1 || put[0].Body["ref"] != "v2" {
							return fmt.Errorf("updates = %+v", put)
						}
						if v, sent := put[0].Body["git_token"]; !sent || v != nil {
							return fmt.Errorf("git_token sent %v (%v), want null", v, sent)
						}
						if c := srv.RuntimeSourceCredentials("acme"); len(c) != 0 {
							return fmt.Errorf("credentials = %q", c)
						}
						return nil
					}),
				),
			},
			{
				// Removed outside Terraform: connected again
				PreConfig: func() {
					c := client(t, srv)
					s, _ := c.FindRuntimeSource(t.Context(), "acme")
					if err := c.DeleteRuntimeSource(t.Context(), s.ID); err != nil {
						t.Fatal(err)
					}
				},
				Config: runtimeSourceConfig(srv, `
  ref          = "v2"
  path         = "catalog/runtimes"
  sync_trigger = "v2"
`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction(res, plancheck.ResourceActionCreate),
				}},
			},
		},
	})
}

// A failed read is a warning: the source is connected, status says why. A manifest with problems
// is refused, the others read.
func TestRuntimeSourceReadFailuresAndProblems(t *testing.T) {
	srv := fake(t)
	srv.SetRemoteRuntimeSource(tfAcmeRepo, runrtest.RemoteRuntimeSource{Private: true, Runtimes: map[string]string{"bash": tfSourceBash}})
	const res = "runr_runtime_source.acme"
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             runtimeSourceGone(srv, "acme"),
		Steps: []resource.TestStep{
			{
				Config: runtimeSourceConfig(srv, ""),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr(res, "status", "not_found"),
					resource.TestCheckResourceAttrSet(res, "error"),
					resource.TestCheckResourceAttr(res, "runtimes_count", "0"),
				),
			},
			{
				PreConfig: func() {
					srv.SetRemoteRuntimeSource(tfAcmeRepo, runrtest.RemoteRuntimeSource{Runtimes: map[string]string{
						"bash": tfSourceBash, "broken": "apiVersion: runr.sh/v1\nkind: Runtime\nid: broken\nname: B\nextensions: [b]\nversions:\n  - version: \"1\"\n    image: b:1\n"}})
				},
				Config: runtimeSourceConfig(srv, `  sync_trigger = "1"`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr(res, "status", "partial"),
					resource.TestCheckResourceAttr(res, "runtimes_count", "1"),
					resource.TestCheckResourceAttr(res, "problems.#", "1"),
					resource.TestCheckResourceAttr(res, "problems.0.file", "runtimes/broken/runtime.yaml"),
					resource.TestCheckResourceAttr(res, "problems.0.path", "/run/mode"),
				),
			},
		},
	})
}

// The _wo credentials stay out of the state; credentials_wo_version writes them again.
func TestRuntimeSourceWriteOnlyCredentials(t *testing.T) {
	srv := fake(t)
	const catalog = "registry.example.com/acme/runtimes:catalog"
	srv.SetRemoteRuntimeSource(catalog, runrtest.RemoteRuntimeSource{Private: true, Signed: true, Runtimes: map[string]string{"bash": tfSourceBash}})
	cfg := func(password, version string) string {
		return providerBlock(srv) + fmt.Sprintf(`
resource "runr_runtime_source" "catalog" {
  name                   = "acme-catalog"
  kind                   = "oci-catalog"
  url                    = %q
  public_key             = <<-EOT
    -----BEGIN PUBLIC KEY-----
    MFkw
    -----END PUBLIC KEY-----
  EOT
  registry_username      = "ci"
  registry_password_wo   = %q
  credentials_wo_version = %q
}
`, catalog, password, version)
	}
	const res = "runr_runtime_source.catalog"
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		TerraformVersionChecks:   []tfversion.TerraformVersionCheck{tfversion.SkipBelow(tfversion.Version1_11_0)},
		CheckDestroy:             runtimeSourceGone(srv, "acme-catalog"),
		Steps: []resource.TestStep{
			{
				Config: cfg("first-password", "1"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckNoResourceAttr(res, "registry_password_wo"),
					resource.TestCheckNoResourceAttr(res, "registry_password"),
					resource.TestCheckResourceAttr(res, "credentials.registry_password_set", "true"),
					resource.TestCheckResourceAttr(res, "credentials.registry_username", "ci"),
					resource.TestCheckResourceAttr(res, "signature_verified", "true"),
					resource.TestCheckResourceAttr(res, "status", "ok"),
					check(func() error {
						if c := srv.RuntimeSourceCredentials("acme-catalog"); c["registry_password"] != "first-password" {
							return fmt.Errorf("credentials = %q", c)
						}
						return nil
					}),
				),
			},
			{
				// A new password without a new version: not written (Terraform cannot compare it)
				Config:           cfg("second-password", "1"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
			},
			{
				Config: cfg("second-password", "2"),
				Check: check(func() error {
					if c := srv.RuntimeSourceCredentials("acme-catalog"); c["registry_password"] != "second-password" {
						return fmt.Errorf("credentials = %q", c)
					}
					return nil
				}),
			},
		},
	})
}

func TestRuntimeSourceValidation(t *testing.T) {
	srv := fake(t)
	oci := func(body string) string {
		return providerBlock(srv) + `
resource "runr_runtime_source" "x" {
  name = "x"
  kind = "oci-catalog"
  url  = "registry.example.com/x:catalog"
` + body + `
}
`
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{Config: oci(`  ref = "main"`), ExpectError: phrase("ref applies to a git source only"), PlanOnly: true},
			{Config: oci(`  git_token = "abc"`), ExpectError: phrase("git_token applies to a git source only"), PlanOnly: true},
			{Config: oci(`  patch_feed = "registry.example.com/x:patches"`), ExpectError: phrase("needs the public_key"), PlanOnly: true},
			{Config: oci(`  registry_username = "ci"`), ExpectError: phrase("registry_username together with registry_password"), PlanOnly: true},
			{Config: runtimeSourceConfig(srv, `
  git_token       = "abc"
  ssh_private_key = "key"
`), ExpectError: regexp.MustCompile(`(?s)git_token.*ssh_private_key|Invalid Attribute Combination`), PlanOnly: true},
			{Config: runtimeSourceConfig(srv, `  ssh_known_hosts = "host ssh-ed25519 AAAA"`), ExpectError: phrase("goes with ssh_private_key"), PlanOnly: true},
			{Config: runtimeSourceConfig(srv, `  kind_typo = 1`), ExpectError: regexp.MustCompile(`Unsupported argument`), PlanOnly: true},
		},
	})
}

// RunR's catalog cannot be imported; the data source reads it and any source with its runtimes.
func TestRuntimeSourceDataSourceAndDefault(t *testing.T) {
	srv := fake(t)
	srv.SetRemoteRuntimeSource(tfAcmeRepo, runrtest.RemoteRuntimeSource{Runtimes: map[string]string{"bash": tfSourceBash}})
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             runtimeSourceGone(srv, "acme"),
		Steps: []resource.TestStep{
			{
				Config: runtimeSourceConfig(srv, "") + `
data "runr_runtime_source" "runr" {
  name = "RunR runtimes"
}

data "runr_runtime_source" "acme" {
  id = runr_runtime_source.acme.id
}
`,
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("data.runr_runtime_source.runr", "is_default", "true"),
					resource.TestCheckResourceAttr("data.runr_runtime_source.runr", "kind", "oci-catalog"),
					resource.TestCheckResourceAttr("data.runr_runtime_source.runr", "status", "ok"),
					resource.TestCheckResourceAttr("data.runr_runtime_source.runr", "runtimes.#", "4"),
					resource.TestCheckResourceAttr("data.runr_runtime_source.runr", "runtimes.0.state", "active"),
					resource.TestCheckResourceAttr("data.runr_runtime_source.acme", "name", "acme"),
					resource.TestCheckResourceAttr("data.runr_runtime_source.acme", "runtimes.#", "1"),
					resource.TestCheckResourceAttr("data.runr_runtime_source.acme", "runtimes.0.id", "bash"),
					resource.TestCheckResourceAttr("data.runr_runtime_source.acme", "runtimes.0.path", "bash"),
					resource.TestCheckResourceAttr("data.runr_runtime_source.acme", "runtimes.0.state", "deprecated"),
					resource.TestCheckResourceAttr("data.runr_runtime_source.acme", "runtimes.0.replacement", "zsh"),
					resource.TestCheckResourceAttr("data.runr_runtime_source.acme", "runtimes.0.versions.0.state", "deprecated"),
					resource.TestCheckResourceAttr("data.runr_runtime_source.acme", "runtimes.0.versions.0.ends_at", "2099-01-01"),
				),
			},
			{
				Config: runtimeSourceConfig(srv, "") + `
resource "runr_runtime_source" "runr" {
  name = "RunR runtimes"
  kind = "oci-catalog"
  url  = "ghcr.io/brf-tech/runr/runtimes/catalog:1"
}
`,
				ResourceName:  "runr_runtime_source.runr",
				ImportState:   true,
				ImportStateId: "RunR runtimes",
				ExpectError:   phrase("RunR's catalog cannot be imported"),
			},
		},
	})
}
