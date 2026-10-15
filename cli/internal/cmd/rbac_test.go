package cmd

import (
	"path/filepath"
	"strings"
	"testing"

	"github.com/runrsh/core/cli/pkg/runr"
)

func rbacHasPermission(perms []string, p string) bool {
	for _, x := range perms {
		if x == p {
			return true
		}
	}
	return false
}

func TestRBACMembersListAndSetRole(t *testing.T) {
	h := newHarness(t).login()
	grace := h.srv.RBACSeedMember("Grace Hopper", "grace@example.com", "grace", "developer")

	var members []runr.Member
	decodeJSON(t, h.mustRun("members", "ls", "-o", "json").out, &members)
	if len(members) != 2 || members[1].Email != "grace@example.com" || members[1].Role != "developer" {
		t.Fatalf("members = %+v", members)
	}
	r := h.mustRun("members")
	contains(t, r.out, "grace@example.com")
	contains(t, r.out, "owner")

	// The role may be given by name; the member by e-mail, username or id.
	h.mustRun("members", "set-role", "GRACE@example.com", "Viewer")
	calls := h.srv.CallsTo("PUT /members/{user}/role")
	if len(calls) != 1 || calls[0].Path != "/members/"+grace.ID.String()+"/role" || calls[0].Body["role"] != "viewer" {
		t.Fatalf("set-role calls = %+v", calls)
	}
	h.mustRun("members", "set-role", "grace", "admin")
	for _, m := range h.srv.RBACMembers() {
		if m.Email == "grace@example.com" && m.Role != "admin" {
			t.Fatalf("grace has role %s", m.Role)
		}
	}

	h.expectCode(ExitNotFound, "members", "set-role", "nobody@example.com", "viewer")
	h.expectCode(ExitNotFound, "members", "set-role", "grace", "no-such-role")
	// Nobody changes their own role.
	r = h.expectCode(ExitValidation, "members", "set-role", "ada", "viewer")
	contains(t, r.err, "You cannot change your own role.")
}

func TestRBACMembersAddSingleTenant(t *testing.T) {
	h := newHarness(t).login()
	r := h.runStdin("s3cret-password\n", "members", "add", "grace@example.com", "--name", "Grace Hopper",
		"--password-stdin", "--role", "viewer", "-o", "json")
	if r.code != 0 {
		t.Fatalf("members add: exit %d\n%s", r.code, r.err)
	}
	var m runr.Member
	decodeJSON(t, r.out, &m)
	if m.Email != "grace@example.com" || m.Username != "grace" || m.Role != "viewer" {
		t.Fatalf("created member = %+v", m)
	}
	call := h.srv.CallsTo("POST /users")[0]
	if call.Body["name"] != "Grace Hopper" || call.Body["username"] != "grace" || call.Body["password"] != "s3cret-password" {
		t.Fatalf("POST /users body = %v", call.Body)
	}

	// Without a password the command cannot create the account in a script.
	h.expectCode(ExitUsage, "members", "add", "alan@example.com")
	h.expectCode(ExitUsage, "members", "add", "not-an-address")
	// The API refuses a taken username.
	r = h.runStdin("another-password\n", "members", "add", "grace@other.example", "--password-stdin")
	if r.code != ExitValidation || !strings.Contains(r.err, "username has already been taken") {
		t.Fatalf("duplicate username: exit %d\n%s", r.code, r.err)
	}
}

func TestRBACMembersInviteMultiTenant(t *testing.T) {
	h := newHarness(t).login()
	h.srv.RBACMultiTenant()
	r := h.mustRun("members", "invite", "alan@example.com", "--role", "developer")
	contains(t, r.err, "Invitation sent.")
	if inv := h.srv.RBACInvitations(); len(inv) != 1 || inv[0] != "alan@example.com" {
		t.Fatalf("invitations = %v", inv)
	}
	if len(h.srv.CallsTo("POST /users")) != 0 {
		t.Fatal("a multi-tenant invitation created an account")
	}
	call := h.srv.CallsTo("POST /tenants/{tenant}/users")[0]
	if call.Body["email"] != "alan@example.com" || call.Body["role"] != "developer" {
		t.Fatalf("invite body = %v", call.Body)
	}
	// A pending invitation is not sent twice.
	h.expectCode(ExitValidation, "members", "invite", "alan@example.com")
}

func TestRBACMembersRemove(t *testing.T) {
	h := newHarness(t).login()
	grace := h.srv.RBACSeedMember("Grace Hopper", "grace@example.com", "grace", "")
	h.expectCode(ExitUsage, "members", "rm", "grace") // needs --yes without a terminal
	h.mustRun("members", "rm", "grace", "--yes")
	if calls := h.srv.CallsTo("DELETE /users/{user}"); len(calls) != 1 || calls[0].Path != "/users/"+grace.ID.String() {
		t.Fatalf("delete calls = %+v", calls)
	}
	if n := len(h.srv.RBACMembers()); n != 1 {
		t.Fatalf("%d members left", n)
	}

	// Multi-tenant: the member leaves the organization, the account stays.
	h.srv.RBACMultiTenant()
	h.srv.RBACSeedMember("Alan Turing", "alan@example.com", "alan", "viewer")
	h.mustRun("members", "rm", "alan@example.com", "--yes")
	if len(h.srv.CallsTo("DELETE /tenants/{tenant}/users/{user}")) != 1 {
		t.Fatal("multi-tenant removal did not use the tenant route")
	}
}

func TestRBACRolesLifecycle(t *testing.T) {
	h := newHarness(t).login()
	var role runr.Role
	decodeJSON(t, h.mustRun("roles", "create", "Release manager", "--permission", "projects.deploy,logs.view",
		"--description", "Ships releases", "-o", "json").out, &role)
	if role.Key != "release-manager" || role.Builtin || !rbacHasPermission(role.Permissions, "projects.view") {
		t.Fatalf("created role = %+v (implied projects.view expected)", role)
	}
	if body := h.srv.CallsTo("POST /roles")[0].Body; body["name"] != "Release manager" || body["description"] != "Ships releases" {
		t.Fatalf("create body = %v", body)
	}

	h.mustRun("roles", "update", "release-manager", "--add", "metrics.view", "--remove", "logs.view")
	got, _ := h.srv.RBACRole("release-manager")
	if !rbacHasPermission(got.Permissions, "metrics.view") || rbacHasPermission(got.Permissions, "logs.view") {
		t.Fatalf("updated permissions = %v", got.Permissions)
	}
	h.mustRun("roles", "update", "Release manager", "--name", "Release captain")
	got, _ = h.srv.RBACRole("release-manager")
	if got.Name != "Release captain" {
		t.Fatalf("renamed role = %+v", got)
	}
	// The update sends only what changed.
	last := h.srv.CallsTo("PUT /roles/{role}")
	if body := last[len(last)-1].Body; len(body) != 1 || body["name"] != "Release captain" {
		t.Fatalf("rename body = %v", body)
	}

	r := h.mustRun("roles", "get", "release-manager")
	contains(t, r.out, "metrics.view")
	contains(t, r.out, "custom")

	h.expectCode(ExitUsage, "roles", "rm", "release-manager")
	h.mustRun("roles", "rm", "release-manager", "--yes")
	if _, ok := h.srv.RBACRole("release-manager"); ok {
		t.Fatal("role still exists")
	}
	h.expectCode(ExitNotFound, "roles", "get", "release-manager")
}

func TestRBACRolesFromFile(t *testing.T) {
	h := newHarness(t).login()
	file := filepath.Join(t.TempDir(), "auditor.yaml")
	write(t, file, "name: Auditor\nkey: auditor\ndescription: Reads the audit log\npermissions:\n  - audit.export\n")
	var role runr.Role
	decodeJSON(t, h.mustRun("roles", "create", "-f", file, "-o", "json").out, &role)
	if role.Key != "auditor" || !rbacHasPermission(role.Permissions, "audit.view") {
		t.Fatalf("role from file = %+v", role)
	}
	write(t, file, "name: Auditor\nunknown_key: 1\n")
	h.expectCode(ExitUsage, "roles", "create", "-f", file)
}

func TestRBACRolesRejected(t *testing.T) {
	h := newHarness(t).login()
	r := h.expectCode(ExitValidation, "roles", "create", "Broken", "--permission", "projects.fly")
	contains(t, r.err, "Unknown permissions: projects.fly.")
	h.expectCode(ExitValidation, "roles", "create", "Developer") // the key of a built-in role
	r = h.expectCode(ExitValidation, "roles", "update", "developer", "--name", "Dev")
	contains(t, r.err, "Built-in roles cannot be changed.")
	h.expectCode(ExitValidation, "roles", "rm", "viewer", "--yes")
	h.expectCode(ExitUsage, "roles", "update", "developer")
	h.expectCode(ExitUsage, "roles", "create")
}

func TestRBACDefaultRoleAndCatalog(t *testing.T) {
	h := newHarness(t).login()
	contains(t, h.mustRun("roles", "ls").out, "developer *")
	h.mustRun("roles", "default", "viewer")
	if d := h.srv.RBACDefaultRole(); d != "viewer" {
		t.Fatalf("default role = %s", d)
	}
	var out map[string]string
	decodeJSON(t, h.mustRun("roles", "default", "-o", "json").out, &out)
	if out["default_role"] != "viewer" {
		t.Fatalf("default = %v", out)
	}
	r := h.expectCode(ExitValidation, "roles", "default", "owner")
	contains(t, r.err, "The Owner role can only be given to members directly.")

	var list runr.RoleList
	decodeJSON(t, h.mustRun("roles", "-o", "json").out, &list)
	if len(list.Roles) < 4 || list.DefaultRole != "viewer" || list.Roles[0].Usage == nil {
		t.Fatalf("roles = %+v", list)
	}

	var cat runr.PermissionCatalog
	decodeJSON(t, h.mustRun("roles", "permissions", "-o", "json").out, &cat)
	found := false
	for _, p := range cat.Permissions {
		if p.Key == "env.edit" {
			found = rbacHasPermission(p.Implies, "env.view")
		}
	}
	if !found || !cat.Has("roles.manage") {
		t.Fatalf("catalog = %+v", cat)
	}
}

func TestRBACGroups(t *testing.T) {
	h := newHarness(t).login()
	h.srv.RBACSeedMember("Grace Hopper", "grace@example.com", "grace", "viewer")
	h.srv.RBACSeedMember("Alan Turing", "alan@example.com", "alan", "viewer")

	var g runr.Group
	decodeJSON(t, h.mustRun("groups", "create", "platform", "--role", "Developer", "--description", "Platform team", "-o", "json").out, &g)
	if g.Name != "platform" || g.Role != "developer" || g.Description != "Platform team" {
		t.Fatalf("created group = %+v", g)
	}
	h.mustRun("groups", "add-member", "platform", "grace@example.com", "alan")
	got, _ := h.srv.RBACGroup("platform")
	if len(got.Members) != 2 {
		t.Fatalf("members = %+v", got.Members)
	}
	if ids := h.srv.CallsTo("POST /groups/{group}/members")[0].Body["user_ids"].([]any); len(ids) != 2 {
		t.Fatalf("user_ids = %v", ids)
	}
	h.expectCode(ExitNotFound, "groups", "add-member", "platform", "nobody@example.com")

	var detail runr.Group
	decodeJSON(t, h.mustRun("groups", "get", "platform", "-o", "json").out, &detail)
	if len(detail.Members) != 2 || detail.Members[0].Source != "manual" {
		t.Fatalf("group detail = %+v", detail)
	}
	var list []runr.Group
	decodeJSON(t, h.mustRun("groups", "-o", "json").out, &list)
	if len(list) != 1 || list[0].MemberCount != 2 {
		t.Fatalf("groups = %+v", list)
	}

	h.mustRun("groups", "update", "platform", "--role", "")
	got, _ = h.srv.RBACGroup("platform")
	if got.Role != "" {
		t.Fatalf("role not removed: %+v", got)
	}
	h.mustRun("groups", "rm-member", "platform", "alan")
	got, _ = h.srv.RBACGroup("platform")
	if len(got.Members) != 1 || got.Members[0].Email != "grace@example.com" {
		t.Fatalf("members after rm-member = %+v", got.Members)
	}

	h.expectCode(ExitValidation, "groups", "create", "platform")
	h.expectCode(ExitUsage, "groups", "update", "platform")
	h.mustRun("groups", "rm", "platform", "--yes")
	if _, ok := h.srv.RBACGroup("platform"); ok {
		t.Fatal("group still exists")
	}
	h.expectCode(ExitNotFound, "groups", "get", "platform")
}

func TestRBACProjectAccess(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("orders-api", runr.TypeFaaS, "python", "eu-1", nil)
	grace := h.srv.RBACSeedMember("Grace Hopper", "grace@example.com", "grace", "viewer")
	h.srv.RBACSeedGroup("platform", "")

	var grant runr.AccessGrant
	decodeJSON(t, h.mustRun("access", "grant", "grace@example.com", "--role", "developer", "-p", "orders-api", "-o", "json").out, &grant)
	if grant.SubjectType != runr.SubjectUser || grant.SubjectID.String() != grace.ID.String() || grant.Role != "developer" {
		t.Fatalf("grant = %+v", grant)
	}
	// A second grant for the same user changes the role of the same grant.
	h.mustRun("access", "grant", "grace", "--role", "viewer", "-p", "orders-api")
	h.mustRun("access", "grant", "--group", "platform", "--role", "developer", "-p", "orders-api")
	grants := h.srv.RBACGrants("orders-api")
	if len(grants) != 2 || grants[0].ID != grant.ID || grants[0].Role != "viewer" || grants[1].SubjectType != runr.SubjectGroup {
		t.Fatalf("grants = %+v", grants)
	}

	var ls struct {
		Grants    []runr.AccessGrant `json:"grants"`
		CanManage bool               `json:"can_manage"`
	}
	decodeJSON(t, h.mustRun("access", "ls", "-p", "orders-api", "-o", "json").out, &ls)
	if len(ls.Grants) != 2 || !ls.CanManage || ls.Grants[0].SubjectEmail != "grace@example.com" || ls.Grants[1].SubjectName != "platform" {
		t.Fatalf("access ls = %+v", ls)
	}
	contains(t, h.mustRun("access", "-p", "orders-api").out, "grace@example.com")

	h.expectCode(ExitUsage, "access", "grant", "grace", "--group", "platform", "--role", "viewer", "-p", "orders-api")
	h.expectCode(ExitUsage, "access", "grant", "grace", "-p", "orders-api")
	r := h.expectCode(ExitValidation, "access", "grant", "grace", "--role", "owner", "-p", "orders-api")
	contains(t, r.err, "Owner role")

	h.mustRun("access", "revoke", "grace@example.com", "-p", "orders-api", "--yes")
	h.mustRun("access", "revoke", "--group", "platform", "-p", "orders-api", "--yes")
	if n := len(h.srv.RBACGrants("orders-api")); n != 0 {
		t.Fatalf("%d grants left", n)
	}
	h.expectCode(ExitNotFound, "access", "revoke", "grace", "-p", "orders-api", "--yes")
}

func TestRBACScopedTokenNeedsRolesManage(t *testing.T) {
	h := newHarness(t).login()
	h.loginWith(h.srv.ScopedToken("reader", "members.view"))
	h.mustRun("members", "ls")
	r := h.expectCode(ExitAuth, "roles", "create", "Ops", "--permission", "logs.view")
	contains(t, r.err, "roles.manage")
	if len(h.srv.RBACMembers()) != 1 {
		t.Fatal("members changed")
	}
}
