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

const vaultStore = `
resource "runr_secret_store" "vault" {
  name     = "vault-prod"
  type     = "vault"
  settings = { server = "https://vault.example.com", role = "runr" }
}
`

func referenceConfig(srv *runrtest.Server, body string) string {
	return providerBlock(srv) + appProject + vaultStore + `
resource "runr_env_var" "db" {
  project_id = runr_project.app.id
  name       = "DB_PASSWORD"
` + body + `
}
`
}

func hasReference(srv *runrtest.Server, path, key string) resource.TestCheckFunc {
	return check(func() error {
		st, _ := srv.SecretStore("vault-prod")
		ref, ok := srv.SecretReferences("app")["DB_PASSWORD"]
		if !ok || ref.StoreID != st.ID || ref.Path != path || ref.Key != key {
			return fmt.Errorf("reference = %+v (%v), store %s", ref, ok, st.ID)
		}
		return nil
	})
}

// A secret variable that refers to a secret store: created, changed in place, imported with
// its reference, switched to a value and back, and a change outside Terraform shows up.
func TestEnvVarReference(t *testing.T) {
	srv := fake(t)
	byID := func(key string) string {
		return referenceConfig(srv, fmt.Sprintf(`  reference = {
    store = runr_secret_store.vault.id
    path  = "apps/orders"
    key   = %q
  }`, key))
	}
	byName := referenceConfig(srv, `  reference = {
    store = "vault-prod"
    path  = "apps/orders"
    key   = "password"
  }
  depends_on = [runr_secret_store.vault]`)
	asValue := referenceConfig(srv, `  value  = "s3cr3t"
  secret = true`)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "app"),
		Steps: []resource.TestStep{
			{
				Config: byID("db"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_env_var.db", "secret", "true"),
					resource.TestCheckNoResourceAttr("runr_env_var.db", "value"),
					resource.TestCheckResourceAttrPair("runr_env_var.db", "reference.store", "runr_secret_store.vault", "id"),
					hasReference(srv, "apps/orders", "db"),
					check(func() error {
						if p, _ := srv.Project("app"); p.Config.EnvVars["DB_PASSWORD"] != "" {
							return fmt.Errorf("a reference went into the plain variables: %v", p.Config.EnvVars)
						}
						return nil
					}),
				),
			},
			{
				Config: byID("password"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_env_var.db", plancheck.ResourceActionUpdate),
				}},
				Check: hasReference(srv, "apps/orders", "password"),
			},
			{
				ResourceName:      "runr_env_var.db",
				ImportState:       true,
				ImportStateId:     "app/DB_PASSWORD",
				ImportStateVerify: true,
			},
			{
				// The store by name: the same reference, kept in the configuration's spelling.
				Config: byName,
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_env_var.db", "reference.store", "vault-prod"),
					hasReference(srv, "apps/orders", "password"),
				),
			},
			{
				Config: byName,
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectEmptyPlan(),
				}},
			},
			{
				// Made a value outside Terraform: the reference comes back.
				PreConfig: func() {
					p, _ := srv.Project("app")
					if _, err := client(t, srv).SetSecret(t.Context(), p.ID, "DB_PASSWORD", "typed-in"); err != nil {
						t.Fatal(err)
					}
				},
				Config: byName,
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_env_var.db", plancheck.ResourceActionUpdate),
				}},
				Check: hasReference(srv, "apps/orders", "password"),
			},
			{
				// A reference becomes a value in place.
				Config: asValue,
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_env_var.db", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckNoResourceAttr("runr_env_var.db", "reference"),
					check(func() error {
						if _, ref := srv.SecretReferences("app")["DB_PASSWORD"]; ref || srv.Secrets("app")["DB_PASSWORD"] != "s3cr3t" {
							return fmt.Errorf("refs %v secrets %v", srv.SecretReferences("app"), srv.Secrets("app"))
						}
						return nil
					}),
				),
			},
			{
				// Made a reference outside Terraform: the value comes back.
				PreConfig: func() {
					p, _ := srv.Project("app")
					st, _ := srv.SecretStore("vault-prod")
					if _, err := client(t, srv).SetSecretReference(t.Context(), p.ID, "DB_PASSWORD",
						runr.SecretReferenceInput{StoreID: st.ID, Path: "elsewhere"}); err != nil {
						t.Fatal(err)
					}
				},
				Config: asValue,
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_env_var.db", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					if _, ref := srv.SecretReferences("app")["DB_PASSWORD"]; ref || srv.Secrets("app")["DB_PASSWORD"] != "s3cr3t" {
						return fmt.Errorf("refs %v secrets %v", srv.SecretReferences("app"), srv.Secrets("app"))
					}
					return nil
				}),
			},
			{
				// And a reference again; removed outside Terraform, it is created again.
				Config: byName,
				Check:  hasReference(srv, "apps/orders", "password"),
			},
			{
				PreConfig: func() {
					p, _ := srv.Project("app")
					if err := client(t, srv).DeleteSecret(t.Context(), p.ID, "DB_PASSWORD"); err != nil {
						t.Fatal(err)
					}
				},
				Config: byName,
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_env_var.db", plancheck.ResourceActionCreate),
				}},
				Check: hasReference(srv, "apps/orders", "password"),
			},
		},
	})
}

// A plain variable of 0.5.10 (secret left out) plans as before: secret defaults to false.
func TestEnvVarSecretDefaultWithoutReference(t *testing.T) {
	srv := fake(t)
	cfg := providerBlock(srv) + appProject + `
resource "runr_env_var" "plain" {
  project_id = runr_project.app.id
  name       = "LOG_LEVEL"
  value      = "info"
}
`
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{Config: cfg, Check: resource.ComposeAggregateTestCheckFunc(
				resource.TestCheckResourceAttr("runr_env_var.plain", "secret", "false"),
				resource.TestCheckNoResourceAttr("runr_env_var.plain", "reference"),
			)},
			{Config: cfg, ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}}},
		},
	})
}

func TestEnvVarReferenceRules(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config: referenceConfig(srv, `  secret    = false
  reference = { store = runr_secret_store.vault.id, path = "apps/orders" }`),
				ExpectError: phrase("A reference is a secret variable"),
			},
			{
				Config: referenceConfig(srv, `  value     = "x"
  reference = { store = runr_secret_store.vault.id, path = "apps/orders" }`),
				ExpectError: regexp.MustCompile(`(?s)value.*reference`),
			},
			{
				Config:      referenceConfig(srv, `  reference = { store = runr_secret_store.vault.id, path = "apps orders" }`),
				ExpectError: phrase("must not contain whitespace"),
			},
			{
				Config:      referenceConfig(srv, `  reference = { store = "nope", path = "apps/orders" }`),
				ExpectError: phrase(`secret store "nope" not found`),
			},
			{Config: providerBlock(srv), Check: func(*terraform.State) error { return nil }},
		},
	})
}
