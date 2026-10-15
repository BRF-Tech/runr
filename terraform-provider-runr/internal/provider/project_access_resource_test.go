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

func rbacAccessConfig(srv *runrtest.Server, userRole string) string {
	return providerBlock(srv) + appProject + fmt.Sprintf(`
resource "runr_group" "ops" {
  name = "ops"
}

resource "runr_project_access" "grace" {
  project_id = runr_project.app.id
  user       = "grace@example.com"
  role       = %q
}

resource "runr_project_access" "ops" {
  project_id = runr_project.app.id
  group_id   = runr_group.ops.id
  role       = "viewer"
}
`, userRole)
}

func rbacGrantRoles(srv *runrtest.Server) map[string]string {
	out := map[string]string{}
	for _, g := range srv.RBACGrants("app") {
		out[g.SubjectType] = g.Role
	}
	return out
}

func TestProjectAccess(t *testing.T) {
	srv := fake(t)
	grace := srv.RBACSeedMember("Grace Hopper", "grace@example.com", "grace", "viewer")
	var firstGrant string

	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy:             projectGone(srv, "app"),
		Steps: []resource.TestStep{
			{
				Config: rbacAccessConfig(srv, "developer"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_project_access.grace", "subject_type", runr.SubjectUser),
					resource.TestCheckResourceAttr("runr_project_access.grace", "subject_id", grace.ID.String()),
					resource.TestCheckResourceAttr("runr_project_access.ops", "subject_type", runr.SubjectGroup),
					resource.TestCheckResourceAttrPair("runr_project_access.ops", "subject_id", "runr_group.ops", "id"),
					func(s *terraform.State) error {
						firstGrant = s.RootModule().Resources["runr_project_access.grace"].Primary.ID
						return nil
					},
					check(func() error {
						if got := rbacGrantRoles(srv); got[runr.SubjectUser] != "developer" || got[runr.SubjectGroup] != "viewer" {
							return fmt.Errorf("grants = %v", got)
						}
						return nil
					}),
				),
			},
			{
				// The role changes on the same grant.
				Config: rbacAccessConfig(srv, "viewer"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_project_access.grace", plancheck.ResourceActionUpdate),
				}},
				Check: resource.ComposeAggregateTestCheckFunc(
					func(s *terraform.State) error {
						if id := s.RootModule().Resources["runr_project_access.grace"].Primary.ID; id != firstGrant {
							return fmt.Errorf("grant id changed: %s → %s", firstGrant, id)
						}
						return nil
					},
					check(func() error {
						if got := rbacGrantRoles(srv); got[runr.SubjectUser] != "viewer" {
							return fmt.Errorf("grants = %v", got)
						}
						return nil
					}),
				),
			},
			{
				ResourceName: "runr_project_access.grace",
				ImportState:  true,
				ImportStateIdFunc: func(s *terraform.State) (string, error) {
					return "app/" + s.RootModule().Resources["runr_project_access.grace"].Primary.ID, nil
				},
				ImportStateVerify: true,
			},
			{
				ResourceName: "runr_project_access.ops",
				ImportState:  true,
				ImportStateIdFunc: func(s *terraform.State) (string, error) {
					return "app/" + s.RootModule().Resources["runr_project_access.ops"].Primary.ID, nil
				},
				ImportStateVerify: true,
			},
			{
				// Revoked outside Terraform: granted again.
				PreConfig: func() {
					c := client(t, srv)
					p, _ := c.FindProject(t.Context(), "app")
					if err := c.RevokeAccess(t.Context(), p.ID, firstGrant); err != nil {
						t.Fatal(err)
					}
				},
				Config: rbacAccessConfig(srv, "viewer"),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_project_access.grace", plancheck.ResourceActionCreate),
				}},
			},
			{
				Config: providerBlock(srv) + appProject,
				Check: check(func() error {
					if n := len(srv.RBACGrants("app")); n != 0 {
						return fmt.Errorf("%d grants left after destroy", n)
					}
					return nil
				}),
			},
		},
	})
}

func TestProjectAccessRejected(t *testing.T) {
	srv := fake(t)
	srv.RBACSeedMember("Grace Hopper", "grace@example.com", "grace", "viewer")
	cfg := func(body string) string {
		return providerBlock(srv) + appProject + `
resource "runr_project_access" "x" {
  project_id = runr_project.app.id
` + body + `
}
`
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		Steps: []resource.TestStep{
			{
				Config:      cfg(`role = "viewer"`),
				ExpectError: phrase("Set exactly one of user and group_id."),
			},
			{
				Config:      cfg(`user = "grace"` + "\n" + `group_id = "01J00000000000000000000099"` + "\n" + `role = "viewer"`),
				ExpectError: phrase("Set exactly one of user and group_id."),
			},
			{
				Config:      cfg(`user = "grace"` + "\n" + `role = "owner"`),
				ExpectError: phrase("value must be none of"),
			},
			{
				Config:      cfg(`user = "nobody@example.com"` + "\n" + `role = "viewer"`),
				ExpectError: phrase("Unknown user"),
			},
			{
				Config:      cfg(`group_id = "01J00000000000000000000099"` + "\n" + `role = "viewer"`),
				ExpectError: phrase("This user or group does not exist in the organization."),
			},
		},
	})
}
