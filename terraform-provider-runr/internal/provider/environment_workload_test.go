package provider

import (
	"fmt"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

// stagingWorkload is the project app with its environment staging and what staging has of its
// own: a binding attachment, a custom domain, a secret variable and a private connection.
func stagingWorkload(srv *runrtest.Server, prefix string) string {
	return providerBlock(srv) + appProject + `
resource "runr_environment" "staging" {
  project_id = runr_project.app.id
  name       = "staging"
}

resource "runr_binding" "cache" {
  name      = "cache-staging"
  type      = "kv"
  region_id = data.runr_region.eu.id
}

resource "runr_binding_attachment" "cache" {
  project_id  = runr_project.app.id
  environment = runr_environment.staging.name
  binding_id  = runr_binding.cache.id
  env_prefix  = "` + prefix + `"
}

resource "runr_domain" "staging" {
  project_id  = runr_project.app.id
  environment = runr_environment.staging.name
  domain      = "staging.example.com"
}

resource "runr_environment_secret" "key" {
  project_id  = runr_project.app.id
  environment = runr_environment.staging.name
  name        = "API_KEY"
  value       = "s3cret"
}

resource "runr_private_connection" "orders" {
  project_id  = runr_project.app.id
  environment = runr_environment.staging.name
  name        = "orders"
  routing_key = "pg-orders-staging"
  database    = "orders"
  username    = "app"
  password    = "x"
  link_token  = ` + skupperToken + `
}
`
}

// An environment with its own binding attachment, domain, secret and private connection in one
// configuration: each lands on the environment's workload, imports with its environment, reads
// back without turning the name into an id, and goes with the configuration.
func TestEnvironmentWithItsBindingDomainSecretAndConnection(t *testing.T) {
	srv := fake(t)
	stagingID := func() string {
		e, _ := srv.Environment("app", "staging")
		return e.ID
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "app"),
		Steps: []resource.TestStep{
			{
				Config: stagingWorkload(srv, "CACHE_"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_binding_attachment.cache", "environment", "staging"),
					resource.TestCheckResourceAttr("runr_binding_attachment.cache", "credentials", "static"),
					resource.TestCheckResourceAttr("runr_domain.staging", "environment", "staging"),
					resource.TestCheckResourceAttr("runr_domain.staging", "dns_targets.0.value", "app-staging.eu-1.runr.test"),
					resource.TestCheckResourceAttr("runr_private_connection.orders", "environment", "staging"),
					check(func() error {
						id := stagingID()
						att := srv.ProjectAttachments("app")
						if len(att) != 1 || att[0].EnvironmentID != id {
							return fmt.Errorf("attachments = %+v", att)
						}
						p, _ := srv.Project("app")
						d := srv.Domains("app")
						if len(d) != 1 || srv.DomainEnvironment(p.ID, d[0].ID) != id {
							return fmt.Errorf("domains = %+v", d)
						}
						if got := srv.EnvironmentSecrets("app", "staging"); got["API_KEY"] != "s3cret" {
							return fmt.Errorf("secrets = %v", got)
						}
						if c, ok := connectionOf(srv, "app", "orders"); !ok || c.EnvironmentID != id {
							return fmt.Errorf("connection = %+v", c)
						}
						return nil
					}),
				),
			},
			{
				ResourceName:      "runr_binding_attachment.cache",
				ImportState:       true,
				ImportStateId:     "app/staging/cache-staging",
				ImportStateVerify: true,
			},
			{
				// <project>/<binding> finds the environment's attachment too
				ResourceName:      "runr_binding_attachment.cache",
				ImportState:       true,
				ImportStateId:     "app/cache-staging",
				ImportStateVerify: true,
			},
			{
				ResourceName:      "runr_domain.staging",
				ImportState:       true,
				ImportStateId:     "app/staging/staging.example.com",
				ImportStateVerify: true,
			},
			{
				ResourceName:            "runr_environment_secret.key",
				ImportState:             true,
				ImportStateId:           "app/staging/API_KEY",
				ImportStateVerify:       true,
				ImportStateVerifyIgnore: []string{"value"},
			},
			{
				ResourceName:            "runr_private_connection.orders",
				ImportState:             true,
				ImportStateId:           "app/staging/orders",
				ImportStateVerify:       true,
				ImportStateVerifyIgnore: []string{"link_token", "database", "username", "password"},
			},
			{
				// The attachment's prefix changes in place, in the environment's workload
				Config: stagingWorkload(srv, "KV_"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_binding_attachment.cache", plancheck.ResourceActionUpdate),
					plancheck.ExpectResourceAction("runr_domain.staging", plancheck.ResourceActionNoop),
					plancheck.ExpectResourceAction("runr_environment_secret.key", plancheck.ResourceActionNoop),
					plancheck.ExpectResourceAction("runr_private_connection.orders", plancheck.ResourceActionNoop),
				}},
				Check: check(func() error {
					att := srv.ProjectAttachments("app")
					if len(att) != 1 || att[0].EnvPrefix != "KV_" || att[0].EnvironmentID != stagingID() {
						return fmt.Errorf("attachments = %+v", att)
					}
					return nil
				}),
			},
			{
				// The domain removed outside Terraform: added again to the environment
				PreConfig: func() {
					c := client(t, srv)
					p, _ := c.FindProject(t.Context(), "app")
					d, err := c.FindEnvironmentDomain(t.Context(), p.ID, "staging", "staging.example.com")
					if err != nil {
						t.Fatal(err)
					}
					if err := c.DeleteDomain(t.Context(), p.ID, d.ID); err != nil {
						t.Fatal(err)
					}
				},
				Config: stagingWorkload(srv, "KV_"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_domain.staging", plancheck.ResourceActionCreate),
				}},
			},
			{
				// The environment's things go with the configuration; the binding stays
				Config: providerBlock(srv) + appProject + `
resource "runr_binding" "cache" {
  name      = "cache-staging"
  type      = "kv"
  region_id = data.runr_region.eu.id
}
`,
				Check: check(func() error {
					if _, ok := srv.Environment("app", "staging"); ok {
						return fmt.Errorf("staging still exists")
					}
					if att, d, conns := srv.ProjectAttachments("app"), srv.Domains("app"), srv.PrivateConnections("app"); len(att)+len(d)+len(conns) != 0 {
						return fmt.Errorf("left: %+v %+v %+v", att, d, conns)
					}
					if b, ok := srv.BindingByName("cache-staging"); !ok || b.Deleted() {
						return fmt.Errorf("binding = %+v", b)
					}
					return nil
				}),
			},
		},
	})
}

// A binding attachment's credentials change in place (static ↔ dynamic); an attachment from
// before the environment attribute plans no change, also with environment = "production".
func TestBindingAttachmentCredentialsAndProduction(t *testing.T) {
	srv := fake(t)
	srv.SetRegionOpenBao("eu-1", runr.KnativeReady, runr.KnativeReady, 0)
	cfg := func(extra string) string {
		return providerBlock(srv) + appProject + `
resource "runr_binding" "db" {
  name      = "orders-db"
  type      = "postgres"
  region_id = data.runr_region.eu.id
}

resource "runr_binding_attachment" "db" {
  project_id = runr_project.app.id
  binding_id = runr_binding.db.id
` + extra + `
}
`
	}
	attachment := func() (runr.BindingAttachment, error) {
		att := srv.ProjectAttachments("app")
		if len(att) != 1 {
			return runr.BindingAttachment{}, fmt.Errorf("attachments = %+v", att)
		}
		return att[0], nil
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config:      cfg(`  credentials = "rotating"`),
				PlanOnly:    true,
				ExpectError: phrase(`value must be one of`),
			},
			{
				Config: cfg(""),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_binding_attachment.db", "credentials", "static"),
					resource.TestCheckNoResourceAttr("runr_binding_attachment.db", "environment"),
				),
			},
			{
				// production names the project's own workload: no replacement
				Config: cfg(`  environment = "production"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_binding_attachment.db", plancheck.ResourceActionUpdate),
				}},
				Check: resource.TestCheckResourceAttr("runr_binding_attachment.db", "environment", "production"),
			},
			{
				Config:           cfg(`  environment = "production"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
			},
			{
				Config: cfg(`  credentials = "dynamic"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_binding_attachment.db", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					a, err := attachment()
					if err != nil || !a.Dynamic() {
						return fmt.Errorf("attachment = %+v %v", a, err)
					}
					return nil
				}),
			},
			{
				ResourceName:      "runr_binding_attachment.db",
				ImportState:       true,
				ImportStateId:     "app/orders-db",
				ImportStateVerify: true,
			},
			{
				// Left out: the attachment keeps its credentials
				Config:           cfg(""),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{plancheck.ExpectEmptyPlan()}},
			},
			{
				Config: cfg(`  credentials = "static"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_binding_attachment.db", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					a, err := attachment()
					if err != nil || a.Dynamic() {
						return fmt.Errorf("attachment = %+v %v", a, err)
					}
					return nil
				}),
			},
		},
	})
}
