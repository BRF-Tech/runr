package provider

import (
	"fmt"
	"regexp"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func storeGone(srv *runrtest.Server, names ...string) resource.TestCheckFunc {
	return func(*terraform.State) error {
		for _, n := range names {
			if _, ok := srv.SecretStore(n); ok {
				return fmt.Errorf("secret store %s still exists", n)
			}
		}
		return nil
	}
}

func TestSecretStore(t *testing.T) {
	srv := fake(t)
	cfg := func(name, body string) string {
		return providerBlock(srv) + fmt.Sprintf(`
resource "runr_secret_store" "vault" {
  name     = %q
  type     = "vault"
%s
}
`, name, body)
	}
	base := `  settings = {
    server = "https://vault.example.com:8200 "
    role   = "runr"
  }`
	changed := `  auth             = "jwt"
  refresh_interval = 600
  settings = {
    server   = "https://vault.example.com:8200 "
    role     = "runr"
    mount    = "kv"
    audience = "runr-eu"
  }`
	leftOut := `  settings = {
    server   = "https://vault.example.com:8200 "
    role     = "runr"
    mount    = "kv"
    audience = "runr-eu"
  }`
	removed := `  settings = {
    server   = "https://vault.example.com:8200 "
    role     = "runr"
    audience = "runr-eu"
  }`
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             storeGone(srv, "vault-prod", "vault-main", "aws-eu"),
		Steps: []resource.TestStep{
			{
				Config: cfg("vault-prod", base),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_secret_store.vault", "auth", "kubernetes"),
					resource.TestCheckResourceAttr("runr_secret_store.vault", "refresh_interval", "3600"),
					resource.TestCheckResourceAttr("runr_secret_store.vault", "settings.server", "https://vault.example.com:8200 "),
					resource.TestCheckResourceAttr("runr_secret_store.vault", "references", "0"),
					resource.TestCheckNoResourceAttr("runr_secret_store.vault", "audience"),
					resource.TestMatchResourceAttr("runr_secret_store.vault", "kubernetes_name", regexp.MustCompile(`^runr-ss-`)),
					check(func() error {
						st, ok := srv.SecretStore("vault-prod")
						if !ok || st.Settings["server"] != "https://vault.example.com:8200" || len(st.Settings) != 2 {
							return fmt.Errorf("store = %+v", st)
						}
						if _, sent := srv.CallsTo("POST /secret-stores")[0].Body["refresh_interval"]; sent {
							return fmt.Errorf("refresh_interval sent without being set")
						}
						return nil
					}),
				),
			},
			{
				// Rename, auth, interval and settings change in place.
				Config: cfg("vault-main", changed),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_secret_store.vault", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_secret_store.vault", "audience", "runr-eu"),
					check(func() error {
						st, ok := srv.SecretStore("vault-main")
						if !ok || st.Auth != "jwt" || st.RefreshInterval != 600 || st.Settings["mount"] != "kv" {
							return fmt.Errorf("store = %+v", st)
						}
						return nil
					}),
				),
			},
			{
				// auth and refresh_interval left out: the store keeps them.
				Config: cfg("vault-main", leftOut),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectEmptyPlan(),
				}},
			},
			{
				// The state of the store in a region changes: no diff.
				PreConfig: func() { srv.SetSecretStoreStatus("vault-main", "eu-1", "failed", "permission denied") },
				Config:    cfg("vault-main", leftOut),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectEmptyPlan(),
				}},
				Check: func(s *terraform.State) error {
					attrs := s.RootModule().Resources["runr_secret_store.vault"].Primary.Attributes
					region := s.RootModule().Resources["data.runr_region.eu"].Primary.ID
					if attrs["regions.%"] != "1" || attrs["regions."+region+".status"] != "failed" ||
						attrs["regions."+region+".message"] != "permission denied" {
						return fmt.Errorf("regions = %v", attrs)
					}
					return nil
				},
			},
			{
				// A setting removed from the configuration is removed from the store.
				Config: cfg("vault-main", removed),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_secret_store.vault", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					st, _ := srv.SecretStore("vault-main")
					if _, kept := st.Settings["mount"]; kept || st.RefreshInterval != 600 || st.Auth != "jwt" {
						return fmt.Errorf("store = %+v", st)
					}
					return nil
				}),
			},
			{
				// Changed outside Terraform: put back.
				PreConfig: func() {
					st, _ := srv.SecretStore("vault-main")
					other := "other"
					if _, err := client(t, srv).UpdateSecretStore(t.Context(), st.ID, runr.SecretStoreUpdate{Settings: map[string]*string{"role": &other}}); err != nil {
						t.Fatal(err)
					}
				},
				Config: cfg("vault-main", removed),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_secret_store.vault", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					if st, _ := srv.SecretStore("vault-main"); st.Settings["role"] != "runr" {
						return fmt.Errorf("store = %+v", st)
					}
					return nil
				}),
			},
			{
				ResourceName:            "runr_secret_store.vault",
				ImportState:             true,
				ImportStateId:           "vault-main",
				ImportStateVerify:       true,
				ImportStateVerifyIgnore: []string{"settings.server"}, // the API's value is trimmed
			},
			{
				ResourceName: "runr_secret_store.vault",
				ImportState:  true,
				ImportStateIdFunc: func(s *terraform.State) (string, error) {
					return s.RootModule().Resources["runr_secret_store.vault"].Primary.ID, nil
				},
				ImportStateVerify:       true,
				ImportStateVerifyIgnore: []string{"settings.server"},
			},
			{
				// Deleted outside Terraform: created again.
				PreConfig: func() {
					st, _ := srv.SecretStore("vault-main")
					if err := client(t, srv).DeleteSecretStore(t.Context(), st.ID); err != nil {
						t.Fatal(err)
					}
				},
				Config: cfg("vault-main", removed),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_secret_store.vault", plancheck.ResourceActionCreate),
				}},
			},
			{
				// Another provider is another store.
				Config: providerBlock(srv) + `
resource "runr_secret_store" "vault" {
  name     = "aws-eu"
  type     = "aws"
  settings = {
    region   = "eu-central-1"
    role_arn = "arn:aws:iam::123456789012:role/runr-secrets"
  }
}
`,
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_secret_store.vault", plancheck.ResourceActionDestroyBeforeCreate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_secret_store.vault", "auth", "workload_identity"),
					resource.TestCheckResourceAttr("runr_secret_store.vault", "audience", "sts.amazonaws.com"),
					storeGone(srv, "vault-main"),
				),
			},
		},
	})
}

func TestSecretStoreValidation(t *testing.T) {
	srv := fake(t)
	store := func(body string) string {
		return providerBlock(srv) + `
resource "runr_secret_store" "s" {
  name = "s1"
` + body + `
}
`
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{Config: store(`  type     = "openbao"
  settings = {}`), ExpectError: regexp.MustCompile(`(?s)type.*must be one of`)},
			{Config: store(`  type     = "vault"
  settings = { server = "https://v", role = "r", token = "s.xyz" }`), ExpectError: phrase("Unknown setting")},
			{Config: store(`  type     = "vault"
  settings = { server = "https://v" }`), ExpectError: phrase(`needs the setting "role"`)},
			{Config: store(`  type     = "aws"
  auth     = "jwt"
  settings = { region = "eu-central-1", role_arn = "arn:aws:iam::123456789012:role/r" }`), ExpectError: phrase("Invalid auth")},
			{Config: store(`  type             = "gcp"
  refresh_interval = 30
  settings         = { project_id = "p", audience = "a" }`), ExpectError: regexp.MustCompile(`refresh_interval`)},
			{Config: providerBlock(srv) + `
resource "runr_secret_store" "s" {
  name     = "Vault Prod"
  type     = "vault"
  settings = { server = "https://v", role = "r" }
}
`, ExpectError: phrase("must be lower case letters")},
			{Config: store(`  type     = "vault"
  settings = { server = "https://v", role = "r", kv_version = "v3" }`), ExpectError: phrase("Invalid kv_version")},
			// The API's own checks (an AWS role ARN) come back at apply.
			{Config: store(`  type     = "aws"
  settings = { region = "eu-central-1", role_arn = "runr" }`), ExpectError: phrase("settings.role_arn")},
			{Config: providerBlock(srv)},
		},
	})
}

// A store a secret variable refers to is not deleted; once nothing refers to it, it is.
func TestSecretStoreInUse(t *testing.T) {
	srv := fake(t)
	other := srv.SeedProject("other", runr.TypeFaaS, "python", "eu-1", nil)
	store := providerBlock(srv) + `
resource "runr_secret_store" "vault" {
  name     = "vault-prod"
  type     = "vault"
  settings = { server = "https://vault.example.com", role = "runr" }
}
`
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             storeGone(srv, "vault-prod"),
		Steps: []resource.TestStep{
			{Config: store},
			{
				PreConfig: func() {
					st, _ := srv.SecretStore("vault-prod")
					if _, err := client(t, srv).SetSecretReference(t.Context(), other.ID, "DB_PASSWORD",
						runr.SecretReferenceInput{StoreID: st.ID, Path: "apps/other"}); err != nil {
						t.Fatal(err)
					}
				},
				Config:      providerBlock(srv),
				ExpectError: phrase("Secret variables of other refer to this store"),
			},
			{
				PreConfig: func() {
					if err := client(t, srv).DeleteSecret(t.Context(), other.ID, "DB_PASSWORD"); err != nil {
						t.Fatal(err)
					}
				},
				Config: providerBlock(srv),
				Check:  storeGone(srv, "vault-prod"),
			},
		},
	})
}

func TestSecretStoreTrustDataSource(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: providerBlock(srv) + `
resource "runr_secret_store" "vault" {
  name     = "vault-prod"
  type     = "vault"
  auth     = "jwt"
  settings = { server = "https://vault.example.com", role = "runr" }
}

data "runr_secret_store_trust" "eu" {
  store_id  = runr_secret_store.vault.id
  region_id = data.runr_region.eu.id
}

data "runr_secret_store_trust" "by_name" {
  store_id   = "vault-prod"
  region_id  = data.runr_region.eu.id
  depends_on = [runr_secret_store.vault]
}
`,
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttrPair("data.runr_secret_store_trust.eu", "service_account", "runr_secret_store.vault", "kubernetes_name"),
					resource.TestCheckResourceAttr("data.runr_secret_store_trust.eu", "region_name", "eu-1"),
					resource.TestCheckResourceAttr("data.runr_secret_store_trust.eu", "audience", "vault"),
					resource.TestCheckResourceAttr("data.runr_secret_store_trust.eu", "publicly_reachable", "false"),
					resource.TestCheckResourceAttr("data.runr_secret_store_trust.eu", "pem_keys.#", "1"),
					resource.TestMatchResourceAttr("data.runr_secret_store_trust.eu", "subject", regexp.MustCompile(`^system:serviceaccount:runr-projects:runr-ss-`)),
					resource.TestMatchResourceAttr("data.runr_secret_store_trust.eu", "jwks", regexp.MustCompile(`"keys"`)),
					resource.TestCheckResourceAttrPair("data.runr_secret_store_trust.by_name", "subject", "data.runr_secret_store_trust.eu", "subject"),
				),
			},
			{
				Config: providerBlock(srv) + `
data "runr_secret_store_trust" "nope" {
  store_id  = "nope"
  region_id = data.runr_region.eu.id
}
`,
				ExpectError: phrase(`secret store "nope" not found`),
			},
		},
	})
}
