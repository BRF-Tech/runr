package runr_test

import (
	"context"
	"errors"
	"reflect"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func TestRBACRolesClient(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()

	list, err := c.ListRoles(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if list.DefaultRole != "developer" || len(list.Roles) != 4 || !list.Roles[0].Builtin || list.Roles[0].Grantable == nil {
		t.Fatalf("roles = %+v", list)
	}

	perms := []string{"env.edit"}
	r, err := c.CreateRole(ctx, runr.RoleInput{Name: "Config editor", Permissions: &perms})
	if err != nil {
		t.Fatal(err)
	}
	if r.Key != "config-editor" || !reflect.DeepEqual(r.Permissions, []string{"env.edit", "env.view", "projects.view"}) {
		t.Fatalf("created = %+v", r)
	}
	// Without permissions the create still sends the (required) field.
	if _, err := c.CreateRole(ctx, runr.RoleInput{Name: "Empty"}); err != nil {
		t.Fatal(err)
	}
	if body := srv.CallsTo("POST /roles")[1].Body; body["permissions"] == nil {
		t.Fatalf("permissions not sent: %v", body)
	}

	found, err := c.FindRole(ctx, "config EDITOR")
	if err != nil || found.Key != "config-editor" {
		t.Fatalf("FindRole by name = %+v, %v", found, err)
	}
	desc := "Edits variables"
	if _, err := c.UpdateRole(ctx, "config-editor", runr.RoleInput{Key: "ignored", Description: &desc}); err != nil {
		t.Fatal(err)
	}
	if body := srv.CallsTo("PUT /roles/{role}")[0].Body; body["key"] != nil || body["description"] != desc {
		t.Fatalf("update body = %v", body)
	}
	if err := c.DeleteRole(ctx, "config-editor"); err != nil {
		t.Fatal(err)
	}
	if _, err := c.GetRole(ctx, "config-editor"); !runr.IsNotFound(err) {
		t.Fatalf("deleted role: %v", err)
	}
	var nf *runr.NotFoundError
	if _, err := c.FindRole(ctx, "nope"); !errors.As(err, &nf) {
		t.Fatalf("FindRole(nope) = %v", err)
	}
	if _, err := c.UpdateRole(ctx, "admin", runr.RoleInput{Name: "Boss"}); !runr.IsValidation(err) {
		t.Fatalf("built-in update = %v", err)
	}
	def, err := c.SetDefaultRole(ctx, "viewer")
	if err != nil || def != "viewer" {
		t.Fatalf("SetDefaultRole = %q, %v", def, err)
	}
}

func TestRBACPermissionCatalogExpand(t *testing.T) {
	srv := runrtest.New(t)
	cat, err := client(t, srv).ListPermissions(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if !cat.Has("projects.manage_access") || cat.Has("projects.fly") || len(cat.Groups) == 0 {
		t.Fatalf("catalog = %+v", cat)
	}
	if got := cat.Expand([]string{"secrets.view_values"}); !reflect.DeepEqual(got, []string{"env.view", "projects.view", "secrets.view_values"}) {
		t.Fatalf("Expand = %v", got)
	}
	if got := cat.Expand([]string{"*"}); len(got) != len(cat.Permissions) {
		t.Fatalf("Expand(*) = %d permissions, catalog has %d", len(got), len(cat.Permissions))
	}
}

func TestRBACPickMember(t *testing.T) {
	list := []runr.Member{
		{ID: "1", Name: "Ada Lovelace", Email: "ada@example.com", Username: "ada"},
		{ID: "2", Name: "Grace Hopper", Email: "grace@example.com", Username: "grace"},
		{ID: "3", Name: "Grace Hopper", Email: "g.hopper@example.com", Username: "gh"},
	}
	for ref, want := range map[string]string{"2": "2", "GRACE@example.com": "2", "gh": "3", "Ada Lovelace": "1"} {
		m, err := runr.PickMember(list, ref)
		if err != nil || m.ID.String() != want {
			t.Errorf("PickMember(%q) = %+v, %v; want id %s", ref, m, err, want)
		}
	}
	// An ambiguous name is not a match.
	var nf *runr.NotFoundError
	if _, err := runr.PickMember(list, "Grace Hopper"); !errors.As(err, &nf) {
		t.Fatalf("ambiguous name = %v", err)
	}
}

func TestRBACMembersGroupsAccessClient(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	grace := srv.RBACSeedMember("Grace Hopper", "grace@example.com", "grace", "")
	p := srv.SeedProject("billing", runr.TypeFaaS, "python", "eu-1", nil)

	members, err := c.ListMembers(ctx)
	if err != nil || len(members) != 2 || members[1].Role != "developer" {
		t.Fatalf("members = %+v, %v", members, err)
	}
	if role, err := c.SetMemberRole(ctx, grace.ID.String(), "viewer"); err != nil || role != "viewer" {
		t.Fatalf("SetMemberRole = %q, %v", role, err)
	}

	role := "viewer"
	g, err := c.CreateGroup(ctx, runr.GroupInput{Name: "ops", Role: &role})
	if err != nil || g.Role != "viewer" {
		t.Fatalf("CreateGroup = %+v, %v", g, err)
	}
	if g, err = c.AddGroupMembers(ctx, g.ID, []string{grace.ID.String()}); err != nil || len(g.Members) != 1 {
		t.Fatalf("AddGroupMembers = %+v, %v", g, err)
	}
	if _, err := c.AddGroupMembers(ctx, g.ID, []string{"999"}); !runr.IsValidation(err) {
		t.Fatalf("unknown member = %v", err)
	}
	found, err := c.FindGroup(ctx, "OPS")
	if err != nil || found.ID != g.ID || len(found.Members) != 1 {
		t.Fatalf("FindGroup = %+v, %v", found, err)
	}
	empty := ""
	if g, err = c.UpdateGroup(ctx, g.ID, runr.GroupInput{Role: &empty}); err != nil || g.Role != "" {
		t.Fatalf("UpdateGroup = %+v, %v", g, err)
	}

	grant, err := c.GrantAccess(ctx, p.ID, runr.SubjectGroup, g.ID, "developer")
	if err != nil || grant.SubjectType != runr.SubjectGroup || grant.Role != "developer" {
		t.Fatalf("GrantAccess = %+v, %v", grant, err)
	}
	grants, canManage, err := c.ListAccess(ctx, p.ID)
	if err != nil || len(grants) != 1 || !canManage || grants[0].SubjectName != "ops" {
		t.Fatalf("ListAccess = %+v %v %v", grants, canManage, err)
	}
	if detail, _ := c.GetGroup(ctx, g.ID); len(detail.Projects) != 1 || detail.Projects[0].ProjectName != "billing" {
		t.Fatalf("group projects = %+v", detail.Projects)
	}
	if _, err := c.GrantAccess(ctx, p.ID, runr.SubjectUser, "999", "viewer"); !runr.IsValidation(err) {
		t.Fatalf("unknown subject = %v", err)
	}
	if err := c.RevokeAccess(ctx, p.ID, grant.ID); err != nil {
		t.Fatal(err)
	}
	if err := c.RevokeAccess(ctx, p.ID, grant.ID); !runr.IsNotFound(err) {
		t.Fatalf("second revoke = %v", err)
	}
	if err := c.DeleteGroup(ctx, g.ID); err != nil {
		t.Fatal(err)
	}

	// Single-tenant accounts; the Tenancy routes answer 404 there.
	if _, err := c.CurrentTenant(ctx); !errors.Is(err, runr.ErrUnsupported) {
		t.Fatalf("CurrentTenant (single) = %v", err)
	}
	u, err := c.CreateUser(ctx, runr.NewUserInput{Name: "Alan", Username: "alan", Email: "alan@example.com", Password: "long-enough"})
	if err != nil || u.Role != "developer" || u.ID.String() == "" {
		t.Fatalf("CreateUser = %+v, %v", u, err)
	}
	if err := c.DeleteUser(ctx, u.ID.String()); err != nil {
		t.Fatal(err)
	}
	if err := c.DeleteUser(ctx, "1"); !runr.IsValidation(err) {
		t.Fatalf("deleting yourself = %v", err)
	}

	srv.RBACMultiTenant()
	tenant, err := c.CurrentTenant(ctx)
	if err != nil || tenant == nil || tenant.ID == "" {
		t.Fatalf("CurrentTenant (multi) = %+v, %v", tenant, err)
	}
	res, err := c.InviteMember(ctx, tenant.ID, "new@example.com", "viewer")
	if err != nil || res.Action != "invited" {
		t.Fatalf("InviteMember = %+v, %v", res, err)
	}
	if err := c.RemoveTenantMember(ctx, tenant.ID, grace.ID.String()); err != nil {
		t.Fatal(err)
	}
}

func TestBrandingClient(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()

	scope, err := c.BrandingScope(ctx)
	if err != nil || scope != runr.BrandingInstall {
		t.Fatalf("BrandingScope (single) = %q, %v", scope, err)
	}
	b, err := c.GetBranding(ctx, scope)
	if err != nil || len(b.Stored) != 0 || b.Branding["product_name"] != "RunR" {
		t.Fatalf("GetBranding = %+v, %v", b, err)
	}
	b, err = c.UpdateBranding(ctx, scope, map[string]any{
		"product_name": "Acme Cloud", "primary_color": "#ff6600",
		"footer_links": []runr.FooterLink{{Label: "Status", URL: "https://status.acme.example"}},
	})
	if err != nil {
		t.Fatal(err)
	}
	if v, ok := b.StoredString("product_name"); !ok || v != "Acme Cloud" {
		t.Fatalf("stored = %v", b.Stored)
	}
	if links := b.FooterLinks(); len(links) != 1 || links[0].Label != "Status" {
		t.Fatalf("footer links = %+v", links)
	}
	if _, err := c.UpdateBranding(ctx, scope, map[string]any{"primary_color": "orange"}); !runr.IsValidation(err) {
		t.Fatalf("bad colour = %v", err)
	}
	// nil resets a field to its default.
	b, err = c.UpdateBranding(ctx, scope, map[string]any{"product_name": nil})
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := b.StoredString("product_name"); ok || b.Branding["product_name"] != "RunR" {
		t.Fatalf("after reset = %+v", b)
	}
	srv.BrandingSeedUpload(runr.BrandingInstall, "logo_light")
	if b, _ = c.GetBranding(ctx, scope); !b.Uploaded("logo_light_url") || b.Uploaded("favicon_url") {
		t.Fatalf("upload markers = %v", b.Stored)
	}

	// Multi-tenant members manage their organization's brand while the platform allows it.
	srv.RBACMultiTenant()
	if scope, _ = c.BrandingScope(ctx); scope != runr.BrandingOrganization {
		t.Fatalf("BrandingScope (multi) = %q", scope)
	}
	if _, err := c.UpdateBranding(ctx, scope, map[string]any{"product_name": "Team"}); !runr.IsUnauthorized(err) {
		t.Fatalf("organization brand while disabled = %v", err)
	}
	srv.BrandingAllowTenants(true)
	b, err = c.UpdateBranding(ctx, scope, map[string]any{"product_name": "Team"})
	if err != nil || b.Enabled == nil || !*b.Enabled || b.Scope != runr.BrandingOrganization {
		t.Fatalf("organization brand = %+v, %v", b, err)
	}
	if got := srv.BrandingStored(runr.BrandingOrganization)["product_name"]; got != "Team" {
		t.Fatalf("stored organization brand = %v", got)
	}
}
