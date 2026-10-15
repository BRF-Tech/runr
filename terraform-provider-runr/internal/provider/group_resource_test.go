package provider

import (
	"fmt"
	"sort"
	"strconv"
	"strings"
	"testing"

	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/plancheck"
	"github.com/hashicorp/terraform-plugin-testing/terraform"

	"github.com/runrsh/core/cli/pkg/runr/runrtest"
)

func rbacGroupConfig(srv *runrtest.Server, body string) string {
	return providerBlock(srv) + `
resource "runr_group" "platform" {
  name = "platform"
` + body + `
}
`
}

// rbacGroupMembers returns the e-mail addresses of a group's members with their source.
func rbacGroupMembers(srv *runrtest.Server, name string) string {
	g, ok := srv.RBACGroup(name)
	if !ok {
		return "(no group)"
	}
	var out []string
	for _, m := range g.Members {
		out = append(out, m.Email+":"+m.Source)
	}
	sort.Strings(out)
	return strings.Join(out, ",")
}

func TestGroup(t *testing.T) {
	srv := fake(t)
	srv.RBACSeedMember("Grace Hopper", "grace@example.com", "grace", "viewer")
	alan := srv.RBACSeedMember("Alan Turing", "alan@example.com", "alan", "viewer")
	alanID, _ := strconv.Atoi(alan.ID.String())
	groupID := func() string { g, _ := srv.RBACGroup("platform"); return g.ID }

	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: providerFactories(),
		CheckDestroy: func(*terraform.State) error {
			if _, ok := srv.RBACGroup("platform"); ok {
				return fmt.Errorf("group platform still exists")
			}
			return nil
		},
		Steps: []resource.TestStep{
			{
				Config: rbacGroupConfig(srv, `
  description = "Platform team"
  role        = "developer"
  members     = ["grace@example.com", "alan"]`),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("runr_group.platform", "role", "developer"),
					resource.TestCheckResourceAttr("runr_group.platform", "members.#", "2"),
					resource.TestCheckTypeSetElemAttr("runr_group.platform", "members.*", "alan"),
					check(func() error {
						if got := rbacGroupMembers(srv, "platform"); got != "alan@example.com:manual,grace@example.com:manual" {
							return fmt.Errorf("members = %s", got)
						}
						return nil
					}),
				),
			},
			{
				// Members keep the form they are written in: no change.
				Config: rbacGroupConfig(srv, `
  description = "Platform team"
  role        = "developer"
  members     = ["grace@example.com", "alan"]`),
				PlanOnly: true,
			},
			{
				Config: rbacGroupConfig(srv, `
  members = ["grace@example.com"]`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_group.platform", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					g, _ := srv.RBACGroup("platform")
					if g.Role != "" || g.Description != "" {
						return fmt.Errorf("group = %+v", g)
					}
					if got := rbacGroupMembers(srv, "platform"); got != "grace@example.com:manual" {
						return fmt.Errorf("members = %s", got)
					}
					return nil
				}),
			},
			{
				// Members synced from single sign-on are not Terraform's.
				PreConfig: func() { srv.RBACAddGroupMember(groupID(), alanID, "sso") },
				Config: rbacGroupConfig(srv, `
  members = ["grace@example.com"]`),
				PlanOnly: true,
			},
			{
				// A member added by hand outside Terraform is removed again.
				PreConfig: func() {
					if _, err := client(t, srv).AddGroupMembers(t.Context(), groupID(), []string{alan.ID.String()}); err != nil {
						t.Fatal(err)
					}
				},
				Config: rbacGroupConfig(srv, `
  members = ["grace@example.com"]`),
				ConfigPlanChecks: resource.ConfigPlanChecks{PreApply: []plancheck.PlanCheck{
					plancheck.ExpectResourceAction("runr_group.platform", plancheck.ResourceActionUpdate),
				}},
				Check: check(func() error {
					if got := rbacGroupMembers(srv, "platform"); got != "grace@example.com:manual" {
						return fmt.Errorf("members = %s", got)
					}
					return nil
				}),
			},
			{
				ResourceName:            "runr_group.platform",
				ImportState:             true,
				ImportStateId:           "platform",
				ImportStateVerify:       true,
				ImportStateVerifyIgnore: []string{"members"},
			},
			{
				// Without members, Terraform leaves the membership alone.
				Config: rbacGroupConfig(srv, ``),
				Check: check(func() error {
					if got := rbacGroupMembers(srv, "platform"); got != "grace@example.com:manual" {
						return fmt.Errorf("members = %s", got)
					}
					return nil
				}),
			},
			{
				Config:      rbacGroupConfig(srv, `members = ["nobody@example.com"]`),
				ExpectError: phrase("Unknown member"),
			},
			{
				Config:      rbacGroupConfig(srv, `role = "owner"`),
				ExpectError: phrase("value must be none of"),
			},
			{
				// The refused changes left the group as it was (and the post-test destroy
				// runs with this valid configuration).
				Config: rbacGroupConfig(srv, ``),
				Check: check(func() error {
					if got := rbacGroupMembers(srv, "platform"); got != "grace@example.com:manual" {
						return fmt.Errorf("members = %s", got)
					}
					return nil
				}),
			},
		},
	})
}
