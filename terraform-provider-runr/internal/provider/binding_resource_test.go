package provider

import (
	"fmt"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

// bindDeleted checks that the fake's bindings are deleted (with retention) after destroy.
func bindDeleted(srv *runrtest.Server, names ...string) resource.TestCheckFunc {
	return func(*terraform.State) error {
		for _, n := range names {
			if b, ok := srv.BindingByName(n); ok && !b.Deleted() {
				return fmt.Errorf("binding %s is %s after destroy", n, b.Status)
			}
		}
		return nil
	}
}

func TestBindingResource(t *testing.T) {
	srv := fake(t)
	cfg := func(memory int) string {
		return providerBlock(srv) + fmt.Sprintf(`
resource "runr_binding" "cache" {
  name      = "cache"
  type      = "kv"
  region_id = data.runr_region.eu.id
  memory_mb = %d
}
`, memory)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             bindDeleted(srv, "cache"),
		Steps: []resource.TestStep{
			{
				Config: cfg(128),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_binding.cache", "status", "ready"),
					resource.TestCheckResourceAttr("runr_binding.cache", "plan", "shared"),
					resource.TestCheckResourceAttr("runr_binding.cache", "memory_mb", "128"),
					resource.TestCheckResourceAttr("runr_binding.cache", "over_quota", "false"),
					resource.TestCheckResourceAttr("runr_binding.cache", "env_keys.0", "REDIS_URL"),
					resource.TestCheckResourceAttr("runr_binding.cache", "env_keys.1", "KV_PREFIX"),
					resource.TestCheckNoResourceAttr("runr_binding.cache", "storage_gb"),
					resource.TestCheckResourceAttrPair("runr_binding.cache", "region_id", "data.runr_region.eu", "id"),
					check(func() error {
						b, ok := srv.BindingByName("cache")
						if !ok || b.Settings["memory_mb"] != float64(128) {
							return fmt.Errorf("fake binding = %+v", b)
						}
						return nil
					}),
				),
			},
			{
				Config: cfg(256),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_binding.cache", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					b, _ := srv.BindingByName("cache")
					if b.Settings["memory_mb"] != float64(256) {
						return fmt.Errorf("settings = %v", b.Settings)
					}
					calls := srv.CallsTo("PATCH /bindings/{binding}")
					if len(calls) != 1 {
						return fmt.Errorf("%d updates, want 1", len(calls))
					}
					if s, _ := calls[0].Body["settings"].(map[string]any); len(s) != 1 || s["memory_mb"] != float64(256) {
						return fmt.Errorf("update sent %v (only the changed setting)", calls[0].Body)
					}
					return nil
				}),
			},
			{
				ResourceName:            "runr_binding.cache",
				ImportState:             true,
				ImportStateId:           "cache",
				ImportStateVerify:       true,
				ImportStateVerifyIgnore: []string{"status"},
			},
			{
				// Destroying deletes with retention.
				Config: providerBlock(srv),
				Check:  bindDeleted(srv, "cache"),
			},
		},
	})
}

func TestBindingResourceDeletedOutsideTerraform(t *testing.T) {
	srv := fake(t)
	cfg := providerBlock(srv) + `
resource "runr_binding" "files" {
  name      = "files"
  type      = "object"
  region_id = data.runr_region.eu.id
}
`
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: cfg,
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_binding.files", "quota_gb", "5"),
					resource.TestCheckResourceAttr("runr_binding.files", "env_keys.#", "6"),
				),
			},
			{
				PreConfig: func() {
					c := client(t, srv)
					b, err := c.FindBinding(t.Context(), "files")
					if err != nil {
						t.Fatal(err)
					}
					if _, _, err := c.DeleteBinding(t.Context(), b); err != nil {
						t.Fatal(err)
					}
				},
				Config:             cfg,
				PlanOnly:           true,
				ExpectNonEmptyPlan: true,
			},
		},
	})
}

func TestBindingResourceExternalAndPostgres(t *testing.T) {
	srv := fake(t)
	cfg := func(url string) string {
		return providerBlock(srv) + fmt.Sprintf(`
resource "runr_binding" "payments" {
  name = "payments"
  type = "external"
  values = {
    PAYMENTS_URL   = %q
    PAYMENTS_TOKEN = "tok-123"
  }
}

resource "runr_binding" "reports" {
  name              = "reports"
  type              = "postgres"
  plan              = "dedicated"
  region_id         = data.runr_region.eu.id
  storage_gb        = 10
  instances         = 2
  backups           = true
  statement_timeout = "15s"
}
`, url)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             bindDeleted(srv, "payments", "reports"),
		Steps: []resource.TestStep{
			{
				Config: cfg("https://pay.example.com"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_binding.payments", "plan", "external"),
					resource.TestCheckNoResourceAttr("runr_binding.payments", "region_id"),
					resource.TestCheckResourceAttr("runr_binding.payments", "env_keys.0", "PAYMENTS_TOKEN"),
					resource.TestCheckResourceAttr("runr_binding.reports", "plan", "dedicated"),
					resource.TestCheckResourceAttr("runr_binding.reports", "instances", "2"),
					resource.TestCheckResourceAttr("runr_binding.reports", "backups", "true"),
					resource.TestCheckResourceAttr("runr_binding.reports", "connection_limit", "20"),
					resource.TestCheckResourceAttr("runr_binding.reports", "statement_timeout", "15s"),
					check(func() error {
						if got := srv.BindingCredentialsOf("payments")["PAYMENTS_TOKEN"]; got != "tok-123" {
							return fmt.Errorf("external values = %v", srv.BindingCredentialsOf("payments"))
						}
						return nil
					}),
				),
			},
			{
				Config: cfg("https://v2.example.com"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_binding.payments", plancheck.ResourceActionUpdate),
					plancheck.ExpectResourceAction("runr_binding.reports", plancheck.ResourceActionNoop),
				}},
				Check: check(func() error {
					if got := srv.BindingCredentialsOf("payments")["PAYMENTS_URL"]; got != "https://v2.example.com" {
						return fmt.Errorf("values after update = %v", srv.BindingCredentialsOf("payments"))
					}
					return nil
				}),
			},
		},
	})
}

func TestBindingResourceValidation(t *testing.T) {
	srv := fake(t)
	cases := map[string]string{
		`
resource "runr_binding" "x" {
  name      = "x"
  type      = "external"
  region_id = data.runr_region.eu.id
  values    = { A = "1" }
}`: "External bindings have no region",
		`
resource "runr_binding" "x" {
  name = "x"
  type = "kv"
}`: "region_id required",
		`
resource "runr_binding" "x" {
  name       = "x"
  type       = "postgres"
  region_id  = data.runr_region.eu.id
  memory_mb  = 64
}`: "memory_mb does not apply",
		`
resource "runr_binding" "x" {
  name      = "x"
  type      = "kv"
  plan      = "dedicated"
  region_id = data.runr_region.eu.id
}`: "plan applies to postgres bindings",
		`
resource "runr_binding" "x" {
  name      = "x"
  type      = "postgres"
  region_id = data.runr_region.eu.id
  instances = 2
}`: "instances does not apply",
		`
resource "runr_binding" "x" {
  name = "Bad_Name"
  type = "external"
  values = { A = "1" }
}`: "must be lower case letters",
	}
	for config, want := range cases {
		resource.UnitTest(t, resource.TestCase{
			ProtoV6ProviderFactories: providerFactories(),
			Steps: []resource.TestStep{{
				Config:      providerBlock(srv) + config,
				ExpectError: phrase(want),
			}},
		})
	}
}

func TestBindingResourceProvisioningFails(t *testing.T) {
	srv := fake(t)
	srv.Bindings().FailProvision = true
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{{
			Config: providerBlock(srv) + `
resource "runr_binding" "db" {
  name      = "db"
  type      = "postgres"
  region_id = data.runr_region.eu.id
}
`,
			ExpectError: phrase("binding db failed"),
		}},
	})
	// The failed binding was kept in the state (tainted) and deleted by the destroy.
	if b, ok := srv.BindingByName("db"); !ok || b.Status != runr.BindingDeleting {
		t.Fatalf("binding after destroy = %+v", b)
	}
}
