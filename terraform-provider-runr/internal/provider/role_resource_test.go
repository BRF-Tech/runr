package provider

import (
	"fmt"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/runrsh/core/cli/pkg/runr"
	"github.com/runrsh/core/cli/pkg/runr/runrtest"
)

func rbacRoleConfig(srv *runrtest.Server, name, extra, perms string) string {
	return providerBlock(srv) + fmt.Sprintf(`
resource "runr_role" "release" {
  name        = %q
  description = "Ships releases"
  permissions = [%s]
  %s
}
`, name, perms, extra)
}

func rbacRoleGone(srv *runrtest.Server, keys ...string) resource.TestCheckFunc {
	return func(*terraform.State) error {
		for _, k := range keys {
			if _, ok := srv.RBACRole(k); ok {
				return fmt.Errorf("role %s still exists", k)
			}
		}
		return nil
	}
}

func TestRole(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             rbacRoleGone(srv, "release-manager", "release"),
		Steps: []resource.TestStep{
			{
				Config: rbacRoleConfig(srv, "Release manager", "", `"projects.deploy", "logs.view"`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_role.release", "id", "release-manager"),
					resource.TestCheckResourceAttr("runr_role.release", "key", "release-manager"),
					resource.TestCheckResourceAttr("runr_role.release", "permissions.#", "2"),
					// projects.view comes with both permissions
					resource.TestCheckResourceAttr("runr_role.release", "effective_permissions.#", "3"),
					resource.TestCheckTypeSetElemAttr("runr_role.release", "effective_permissions.*", "projects.view"),
					check(func() error {
						r, ok := srv.RBACRole("release-manager")
						if !ok || r.Description != "Ships releases" || r.Builtin {
							return fmt.Errorf("role = %+v", r)
						}
						return nil
					}),
				),
			},
			{
				// The configured permissions stay as written although the API stores the implied ones.
				Config:   rbacRoleConfig(srv, "Release manager", "", `"projects.deploy", "logs.view"`),
				PlanOnly: true,
			},
			{
				Config: rbacRoleConfig(srv, "Release captain", "", `"projects.deploy", "logs.view", "metrics.view"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_role.release", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					r, _ := srv.RBACRole("release-manager")
					if r.Name != "Release captain" || len(r.Permissions) != 4 {
						return fmt.Errorf("role = %+v", r)
					}
					return nil
				}),
			},
			{
				ResourceName:            "runr_role.release",
				ImportState:             true,
				ImportStateId:           "release-manager",
				ImportStateVerify:       true,
				ImportStateVerifyIgnore: []string{"permissions"},
			},
			{
				// Changed outside Terraform: the next apply restores the configured permissions.
				PreConfig: func() {
					perms := []string{"logs.view"}
					if _, err := client(t, srv).UpdateRole(t.Context(), "release-manager", runr.RoleInput{Permissions: &perms}); err != nil {
						t.Fatal(err)
					}
				},
				Config: rbacRoleConfig(srv, "Release captain", "", `"projects.deploy", "logs.view", "metrics.view"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_role.release", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					r, _ := srv.RBACRole("release-manager")
					for _, p := range r.Permissions {
						if p == "metrics.view" {
							return nil
						}
					}
					return fmt.Errorf("permissions not restored: %v", r.Permissions)
				}),
			},
			{
				// A new key replaces the role.
				Config: rbacRoleConfig(srv, "Release captain", `key = "release"`, `"projects.deploy"`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_role.release", plancheck.ResourceActionReplace),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_role.release", "id", "release"),
					rbacRoleGone(srv, "release-manager"),
				),
			},
		},
	})
}

func TestRoleRejected(t *testing.T) {
	srv := fake(t)
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config:      rbacRoleConfig(srv, "Broken", "", `"projects.fly"`),
				ExpectError: phrase("Unknown permissions: projects.fly."),
			},
			{
				// The key of a built-in role is taken.
				Config:      rbacRoleConfig(srv, "Developer", "", `"logs.view"`),
				ExpectError: phrase("A role with this name already exists."),
			},
			{
				Config: rbacRoleConfig(srv, "Auditor", "", `"audit.view"`),
			},
			{
				Config:        rbacRoleConfig(srv, "Auditor", "", `"audit.view"`),
				ResourceName:  "runr_role.release",
				ImportState:   true,
				ImportStateId: "developer",
				ExpectError:   phrase("cannot be managed with Terraform"),
			},
		},
	})
}
