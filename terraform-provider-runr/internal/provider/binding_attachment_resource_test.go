package provider

import (
	"fmt"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
)

func bindAttachmentConfig(prefix string) string {
	return appProject + fmt.Sprintf(`
resource "runr_binding" "cache" {
  name      = "cache"
  type      = "kv"
  region_id = data.runr_region.eu.id
}

resource "runr_binding" "payments" {
  name   = "payments"
  type   = "external"
  values = { PAYMENTS_URL = "https://pay.example.com" }
}

resource "runr_binding_attachment" "cache" {
  project_id = runr_project.app.id
  binding_id = runr_binding.cache.id
  env_prefix = %q
}

resource "runr_binding_attachment" "payments" {
  project_id = runr_project.app.id
  binding_id = runr_binding.payments.id
}
`, prefix)
}

func TestBindingAttachmentResource(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "app"),
		Steps: []resource.TestStep{
			{
				Config: providerBlock(srv) + bindAttachmentConfig("CACHE_"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_binding_attachment.cache", "env_prefix", "CACHE_"),
					resource.TestCheckResourceAttr("runr_binding_attachment.cache", "env_names.0", "CACHE_REDIS_URL"),
					resource.TestCheckResourceAttr("runr_binding_attachment.payments", "env_prefix", ""),
					resource.TestCheckResourceAttr("runr_binding_attachment.payments", "env_names.0", "PAYMENTS_URL"),
					resource.TestCheckResourceAttrPair("runr_binding_attachment.cache", "binding_id", "runr_binding.cache", "id"),
					check(func() error {
						att := srv.ProjectAttachments("app")
						if len(att) != 2 {
							return fmt.Errorf("attachments = %+v", att)
						}
						return nil
					}),
				),
			},
			{
				Config: providerBlock(srv) + bindAttachmentConfig("KV_"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_binding_attachment.cache", plancheck.ResourceActionUpdate),
					plancheck.ExpectResourceAction("runr_binding_attachment.payments", plancheck.ResourceActionNoop),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_binding_attachment.cache", "env_names.1", "KV_KV_PREFIX"),
					check(func() error {
						for _, a := range srv.ProjectAttachments("app") {
							if a.Binding != nil && a.Binding.Name == "cache" && a.EnvPrefix != "KV_" {
								return fmt.Errorf("prefix = %q", a.EnvPrefix)
							}
						}
						if n := len(srv.CallsTo("POST /projects/{project}/bindings")); n != 2 {
							return fmt.Errorf("%d attach calls, want 2 (the prefix is changed in place)", n)
						}
						return nil
					}),
				),
			},
			{
				ResourceName:      "runr_binding_attachment.cache",
				ImportState:       true,
				ImportStateId:     "app/cache",
				ImportStateVerify: true,
			},
			{
				// Detached outside Terraform: attached again.
				PreConfig: func() {
					c := client(t, srv)
					p, err := c.FindProject(t.Context(), "app")
					if err != nil {
						t.Fatal(err)
					}
					a, err := c.FindAttachment(t.Context(), p.ID, "payments")
					if err != nil {
						t.Fatal(err)
					}
					if err := c.DetachBinding(t.Context(), p.ID, a.ID); err != nil {
						t.Fatal(err)
					}
				},
				Config: providerBlock(srv) + bindAttachmentConfig("KV_"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_binding_attachment.payments", plancheck.ResourceActionCreate),
				}},
			},
			{
				// Removing the attachments detaches; the bindings stay.
				Config: providerBlock(srv) + appProject + `
resource "runr_binding" "cache" {
  name      = "cache"
  type      = "kv"
  region_id = data.runr_region.eu.id
}
`,
				Check: check(func() error {
					if att := srv.ProjectAttachments("app"); len(att) != 0 {
						return fmt.Errorf("still attached: %+v", att)
					}
					if b, ok := srv.BindingByName("cache"); !ok || b.Deleted() {
						return fmt.Errorf("binding cache = %+v", b)
					}
					return nil
				}),
			},
		},
	})
}

func TestBindingAttachmentRejectsBadPrefix(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{{
			Config:      providerBlock(srv) + bindAttachmentConfig("cache_"),
			ExpectError: phrase("must be upper case letters"),
		}},
	})
}

func TestBindingAttachmentEnvConflict(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{{
			Config: providerBlock(srv) + appProject + `
resource "runr_binding" "a" {
  name      = "db-a"
  type      = "postgres"
  region_id = data.runr_region.eu.id
}

resource "runr_binding" "b" {
  name      = "db-b"
  type      = "postgres"
  region_id = data.runr_region.eu.id
}

resource "runr_binding_attachment" "a" {
  project_id = runr_project.app.id
  binding_id = runr_binding.a.id
}

resource "runr_binding_attachment" "b" {
  project_id = runr_project.app.id
  binding_id = runr_binding.b.id
}
`,
			ExpectError: phrase("already set by another binding of the project"),
		}},
	})
}
