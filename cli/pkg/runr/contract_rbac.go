package runr

// Role-based access control administration: permission catalog, roles, members, groups,
// project access (routes/rbac.php) and the member accounts of both editions.

var rbacRoleFields = []string{"key", "name", "description", "permissions", "builtin"}

var rbacGroupFields = []string{
	"id", "name", "description", "role", "members[].id", "members[].name", "members[].email",
	"members[].source", "projects[].id", "projects[].project_id", "projects[].project_name", "projects[].role",
}

// The list answers are collections built by hand (untyped in the OpenAPI document): their
// fields are checked against the controllers.
var (
	rbacRoleSources   = []string{"app/Http/Controllers/Api/RoleController.php", "app/Support/Rbac/PermissionCatalog.php", "app/Support/Rbac/AccessManager.php"}
	rbacGroupSources  = []string{"app/Http/Controllers/Api/GroupController.php"}
	rbacMemberSources = []string{"app/Http/Controllers/Api/MemberController.php"}
	rbacAccessSources = []string{"app/Http/Controllers/Api/ProjectAccessController.php"}
)

func init() {
	Contract = append(Contract,
		// Permission catalog
		Endpoint{Method: "GET", Path: "/permissions", Status: 200,
			Response: []string{"edition", "permissions[].key", "permissions[].group", "permissions[].scope", "permissions[].implies", "groups"},
			Sources:  []string{"app/Http/Controllers/Api/PermissionController.php"}},

		// Roles
		Endpoint{Method: "GET", Path: "/roles", Status: 200,
			Response: concat(prefix("roles[].", rbacRoleFields), []string{"roles[].grantable", "roles[].usage.members",
				"roles[].usage.groups", "roles[].usage.projects", "default_role"}),
			Sources: rbacRoleSources},
		Endpoint{Method: "POST", Path: "/roles", Status: 201, Permission: "roles.manage",
			Request: []string{"name", "key", "description", "permissions"}, Response: prefix("role.", rbacRoleFields)},
		Endpoint{Method: "PUT", Path: "/roles/default", Status: 200, Permission: "roles.manage",
			Request: []string{"role"}, Response: []string{"default_role"}},
		Endpoint{Method: "GET", Path: "/roles/{role}", Status: 200, Response: prefix("role.", rbacRoleFields), Sources: rbacRoleSources},
		Endpoint{Method: "PUT", Path: "/roles/{role}", Status: 200, Permission: "roles.manage",
			Request: []string{"name", "description", "permissions"}, Response: prefix("role.", rbacRoleFields)},
		Endpoint{Method: "DELETE", Path: "/roles/{role}", Status: 200, Permission: "roles.manage"},

		// Members
		Endpoint{Method: "GET", Path: "/members", Status: 200, Permission: "members.view",
			Response: prefix("members[].", []string{"id", "name", "email", "username", "role", "groups[].id",
				"groups[].name", "groups[].source"}),
			Sources: rbacMemberSources},
		Endpoint{Method: "PUT", Path: "/members/{user}/role", Status: 200, Permission: "roles.manage",
			Request: []string{"role"}, Response: []string{"message", "role"}},
		// Accounts of a single-tenant installation
		Endpoint{Method: "POST", Path: "/users", Status: 201, Permission: "members.invite",
			Request:  []string{"name", "username", "email", "password", "role"},
			Response: prefix("user.", []string{"id", "name", "email", "username", "role"})},
		Endpoint{Method: "DELETE", Path: "/users/{user}", Status: 200, Permission: "members.remove"},
		// Members of a tenant (multi-tenant edition; the Tenancy module's routes)
		Endpoint{Method: "GET", Path: "/tenant/current", Status: 200, Optional: true,
			Response: []string{"tenant.id", "tenant.name", "tenant.slug"},
			Sources:  []string{"Modules/Tenancy/app/Http/Controllers/TenantController.php", "Modules/Tenancy/app/Models/Tenant.php"}},
		Endpoint{Method: "POST", Path: "/tenants/{tenant}/users", Status: 201, Optional: true, Permission: "members.invite",
			Request: []string{"email", "role"}, Response: []string{"message", "action"}},
		Endpoint{Method: "DELETE", Path: "/tenants/{tenant}/users/{user}", Status: 200, Optional: true, Permission: "members.remove"},

		// Groups
		Endpoint{Method: "GET", Path: "/groups", Status: 200, Permission: "members.view",
			Response: prefix("groups[].", []string{"id", "name", "description", "role", "member_count", "project_grants"}),
			Sources:  rbacGroupSources},
		Endpoint{Method: "POST", Path: "/groups", Status: 201, Permission: "groups.manage",
			Request: []string{"name", "description", "role"}, Response: prefix("group.", rbacGroupFields), Sources: rbacGroupSources},
		Endpoint{Method: "GET", Path: "/groups/{group}", Status: 200, Permission: "members.view",
			Response: prefix("group.", rbacGroupFields), Sources: rbacGroupSources},
		Endpoint{Method: "PUT", Path: "/groups/{group}", Status: 200, Permission: "groups.manage",
			Request: []string{"name", "description", "role"}, Response: prefix("group.", rbacGroupFields), Sources: rbacGroupSources},
		Endpoint{Method: "DELETE", Path: "/groups/{group}", Status: 200, Permission: "groups.manage"},
		Endpoint{Method: "POST", Path: "/groups/{group}/members", Status: 200, Permission: "groups.manage",
			Request: []string{"user_ids"}, Response: prefix("group.", rbacGroupFields), Sources: rbacGroupSources},
		Endpoint{Method: "DELETE", Path: "/groups/{group}/members/{user}", Status: 200, Permission: "groups.manage",
			Response: prefix("group.", rbacGroupFields), Sources: rbacGroupSources},

		// Project access
		Endpoint{Method: "GET", Path: "/projects/{project}/access", Status: 200, Permission: "projects.view",
			Response: concat(prefix("grants[].", []string{"id", "subject_type", "subject_id", "subject_name", "subject_email",
				"role", "grantable"}), []string{"can_manage"}),
			Sources: rbacAccessSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/access", Status: 201, Permission: "projects.manage_access",
			Request:  []string{"subject_type", "subject_id", "role"},
			Response: prefix("grant.", []string{"id", "subject_type", "subject_id", "role"})},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/access/{assignment}", Status: 200, Permission: "projects.manage_access"},
	)
}
