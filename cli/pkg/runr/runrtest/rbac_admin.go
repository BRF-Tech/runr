package runrtest

import (
	"fmt"
	"net/mail"
	"sort"
	"strconv"
	"strings"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Role-based access control administration: the permission catalog, built-in and custom
// roles, members, groups and project access (RoleController, MemberController,
// GroupController, ProjectAccessController, UserController and the Tenancy module's member
// routes). The authenticated user (Server.User) is the organization's owner.

type rbacMember struct {
	id                    int
	name, email, username string
	role                  string
}

type rbacGroup struct {
	id, name, description, role string
	// members: user id → source (manual | sso), order of addition in memberOrder.
	members     map[string]string
	memberOrder []string
}

type rbacGrant struct {
	id, projectID, subjectType, subjectID, role string
}

type rbacState struct {
	custom      []*runr.Role
	defaultRole string
	members     []*rbacMember
	groups      []*rbacGroup
	grants      []*rbacGrant
	nextUser    int
	// edition is single or multi (the Tenancy module's routes answer only in multi).
	edition     string
	tenantID    string
	invitations []string
	// outsideTenant: GET /tenant/current answers without a tenant (a platform administrator
	// outside any organization).
	outsideTenant bool
}

func (s *Server) rbac() *rbacState {
	return featureState(s, "rbac", func() *rbacState {
		id, _ := strconv.Atoi(s.User.ID.String())
		return &rbacState{
			defaultRole: "developer",
			edition:     "single",
			tenantID:    "01JTENANT0000000000000000A",
			nextUser:    id + 1,
			members:     []*rbacMember{{id: id, name: s.User.Name, email: s.User.Email, username: s.User.Username, role: runr.RoleOwner}},
		}
	})
}

// rbacEdition is the edition GET /auth/permissions reports.
func (s *Server) rbacEdition() string { return s.rbac().edition }

// RBACMultiTenant switches the fake to the multi-tenant edition: GET /auth/permissions
// reports it, GET /tenant/current answers and members are invited through
// /tenants/{tenant}/users.
func (s *Server) RBACMultiTenant() {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.rbac().edition = "multi"
}

// RBACOutsideTenant makes the multi-tenant fake answer as for a platform administrator outside
// any organization: GET /tenant/current has no tenant.
func (s *Server) RBACOutsideTenant() {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.rbac().outsideTenant = true
}

// RBACSeedMember adds a member with a role ("" = the default role) and returns it.
func (s *Server) RBACSeedMember(name, email, username, role string) runr.Member {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.rbac()
	if role == "" {
		role = st.defaultRole
	}
	m := &rbacMember{id: st.nextUser, name: name, email: email, username: username, role: role}
	st.nextUser++
	st.members = append(st.members, m)
	return s.rbacMemberJSON(m)
}

// RBACMembers returns the members.
func (s *Server) RBACMembers() []runr.Member {
	s.mu.Lock()
	defer s.mu.Unlock()
	out := []runr.Member{}
	for _, m := range s.rbac().members {
		out = append(out, s.rbacMemberJSON(m))
	}
	return out
}

// RBACRole returns a built-in or custom role by key.
func (s *Server) RBACRole(key string) (runr.Role, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	r := s.rbacFindRole(key)
	if r == nil {
		return runr.Role{}, false
	}
	return *r, true
}

// RBACDefaultRole returns the role new members get.
func (s *Server) RBACDefaultRole() string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.rbac().defaultRole
}

// RBACSeedGroup creates a group and returns its id.
func (s *Server) RBACSeedGroup(name, role string) string {
	s.mu.Lock()
	defer s.mu.Unlock()
	g := &rbacGroup{id: s.newID(), name: name, role: role, members: map[string]string{}}
	s.rbac().groups = append(s.rbac().groups, g)
	return g.id
}

// RBACAddGroupMember adds a member to a group with source manual or sso.
func (s *Server) RBACAddGroupMember(groupID string, userID int, source string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if g := s.rbacGroupByID(groupID); g != nil {
		g.addMember(strconv.Itoa(userID), source)
	}
}

// RBACGroup returns a group with members and project grants by name.
func (s *Server) RBACGroup(name string) (runr.Group, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, g := range s.rbac().groups {
		if g.name == name {
			return s.rbacGroupJSON(g), true
		}
	}
	return runr.Group{}, false
}

// RBACGrants returns the access grants of a project by name.
func (s *Server) RBACGrants(projectName string) []runr.AccessGrant {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	out := []runr.AccessGrant{}
	if p == nil {
		return out
	}
	for _, g := range s.rbac().grants {
		if g.projectID == p.data.ID {
			out = append(out, runr.AccessGrant{ID: g.id, SubjectType: g.subjectType, SubjectID: runr.FlexID(g.subjectID), Role: g.role})
		}
	}
	return out
}

// RBACInvitations returns the e-mail addresses invited to the tenant.
func (s *Server) RBACInvitations() []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]string(nil), s.rbac().invitations...)
}

func (g *rbacGroup) addMember(userID, source string) {
	if _, ok := g.members[userID]; ok {
		if source == "manual" {
			g.members[userID] = "manual"
		}
		return
	}
	g.members[userID] = source
	g.memberOrder = append(g.memberOrder, userID)
}

func (g *rbacGroup) removeMember(userID string) {
	if _, ok := g.members[userID]; !ok {
		return
	}
	delete(g.members, userID)
	for i, id := range g.memberOrder {
		if id == userID {
			g.memberOrder = append(g.memberOrder[:i], g.memberOrder[i+1:]...)
			break
		}
	}
}

// ---------------------------------------------------------------------------------------
// Roles

var rbacDeveloperPermissions = []string{
	"projects.view", "projects.create", "projects.update", "projects.delete", "projects.deploy",
	"code.edit", "env.view", "env.edit", "domains.manage", "connections.manage", "shell.exec",
	"logs.view", "metrics.view", "workflows.view", "workflows.run", "workflows.manage", "events.send",
	"regions.view", "runtimes.use_custom", "models.view", "models.deploy", "bindings.view",
	"bindings.manage", "members.view", "tokens.manage",
}

var rbacViewerPermissions = []string{
	"projects.view", "logs.view", "metrics.view", "workflows.view", "regions.view", "models.view",
	"bindings.view", "members.view", "tokens.manage",
}

// rbacBuiltinRoles are config/permissions.php roles of the single-tenant edition.
func rbacBuiltinRoles() []runr.Role {
	all := expand(Catalog)
	return []runr.Role{
		{Key: "owner", Name: "Owner", Description: "Full control of the organization, including ownership.", Permissions: all, Builtin: true},
		{Key: "admin", Name: "Admin", Description: "Full control of the organization except granting ownership.", Permissions: all, Builtin: true},
		{Key: "developer", Name: "Developer", Description: "Builds, deploys and operates projects.", Permissions: expand(rbacDeveloperPermissions), Builtin: true},
		{Key: "viewer", Name: "Viewer", Description: "Read-only access to projects, deployments, logs and metrics.", Permissions: expand(rbacViewerPermissions), Builtin: true},
	}
}

func rbacIsBuiltin(key string) bool {
	for _, r := range rbacBuiltinRoles() {
		if r.Key == key {
			return true
		}
	}
	return false
}

func (s *Server) rbacFindRole(key string) *runr.Role {
	for _, r := range rbacBuiltinRoles() {
		if r.Key == key {
			rr := r
			return &rr
		}
	}
	for _, r := range s.rbac().custom {
		if r.Key == key {
			return r
		}
	}
	return nil
}

func rbacSlug(name string) string {
	var b strings.Builder
	dash := false
	for _, r := range strings.ToLower(strings.TrimSpace(name)) {
		switch {
		case (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9'):
			b.WriteRune(r)
			dash = false
		case b.Len() > 0 && !dash:
			b.WriteByte('-')
			dash = true
		}
	}
	return strings.TrimSuffix(b.String(), "-")
}

func rbacStrings(v any) ([]string, bool) {
	raw, ok := v.([]any)
	if !ok {
		return nil, false
	}
	out := make([]string, 0, len(raw))
	for _, x := range raw {
		out = append(out, fmt.Sprint(x))
	}
	return out, true
}

func rbacValidPermissions(perms []string) ([]string, *apiErr) {
	known := map[string]bool{}
	for _, p := range Catalog {
		known[p] = true
	}
	var unknown []string
	for _, p := range perms {
		if !known[p] {
			unknown = append(unknown, p)
		}
	}
	if len(unknown) > 0 {
		return nil, errValidation("permissions", "Unknown permissions: "+strings.Join(unknown, ", ")+".")
	}
	return expand(perms), nil
}

func (s *Server) rbacAssignable(role string) *apiErr {
	if role == runr.RoleOwner {
		return errValidation("role", "The Owner role can only be given to members directly.")
	}
	if s.rbacFindRole(role) == nil {
		return errValidation("role", "This role does not exist.")
	}
	return nil
}

func (s *Server) rbacRoleUsage(key string) map[string]int {
	st := s.rbac()
	u := map[string]int{"members": 0, "groups": 0, "projects": 0}
	for _, m := range st.members {
		if m.role == key {
			u["members"]++
		}
	}
	for _, g := range st.groups {
		if g.role == key {
			u["groups"]++
		}
	}
	for _, g := range st.grants {
		if g.role == key {
			u["projects"]++
		}
	}
	return u
}

func rbacCustomJSON(r *runr.Role) map[string]any {
	perms := r.Permissions
	if perms == nil {
		perms = []string{}
	}
	return map[string]any{"key": r.Key, "name": r.Name, "description": r.Description, "permissions": perms, "builtin": false}
}

func rbacRoleJSON(r runr.Role) map[string]any {
	return map[string]any{"key": r.Key, "name": r.Name, "description": r.Description, "permissions": r.Permissions, "builtin": r.Builtin}
}

func init() {
	handle("GET /permissions", func(s *Server, q *featureReq) (int, any, *apiErr) {
		list := []map[string]any{}
		groups := []string{}
		seen := map[string]bool{}
		for _, p := range Catalog {
			group, _, _ := strings.Cut(p, ".")
			scope := "org"
			if group == "projects" && p != "projects.create" || group == "code" || group == "env" || group == "secrets" ||
				group == "domains" || group == "logs" || group == "metrics" || group == "workflows" || group == "security" {
				scope = "project"
			}
			imp := implies[p]
			if imp == nil {
				imp = []string{}
			}
			list = append(list, map[string]any{"key": p, "group": group, "scope": scope, "implies": imp})
			if !seen[group] {
				seen[group] = true
				groups = append(groups, group)
			}
		}
		return 200, map[string]any{"edition": s.rbac().edition, "permissions": list, "groups": groups}, nil
	})

	handle("GET /roles", func(s *Server, q *featureReq) (int, any, *apiErr) {
		roles := []map[string]any{}
		for _, r := range rbacBuiltinRoles() {
			j := rbacRoleJSON(r)
			j["grantable"], j["usage"] = true, s.rbacRoleUsage(r.Key)
			roles = append(roles, j)
		}
		custom := append([]*runr.Role(nil), s.rbac().custom...)
		sort.Slice(custom, func(i, j int) bool { return custom[i].Name < custom[j].Name })
		for _, r := range custom {
			j := rbacCustomJSON(r)
			j["grantable"], j["usage"] = true, s.rbacRoleUsage(r.Key)
			roles = append(roles, j)
		}
		return 200, map[string]any{"roles": roles, "default_role": s.rbac().defaultRole}, nil
	})

	handle("GET /roles/{role}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		key := q.Segs[1]
		for _, r := range rbacBuiltinRoles() {
			if r.Key == key {
				return 200, map[string]any{"role": rbacRoleJSON(r)}, nil
			}
		}
		for _, r := range s.rbac().custom {
			if r.Key == key {
				return 200, map[string]any{"role": rbacCustomJSON(r)}, nil
			}
		}
		return 0, nil, errNotFound()
	})

	handle("POST /roles", func(s *Server, q *featureReq) (int, any, *apiErr) {
		name := strings.TrimSpace(q.str("name"))
		if name == "" {
			return 0, nil, errValidation("name", "The name field is required.")
		}
		raw, present := q.Body["permissions"]
		perms, ok := rbacStrings(raw)
		if !present || !ok {
			return 0, nil, errValidation("permissions", "The permissions field must be present.")
		}
		expanded, err := rbacValidPermissions(perms)
		if err != nil {
			return 0, nil, err
		}
		key := q.str("key")
		if key == "" {
			key = name
		}
		key = rbacSlug(key)
		if key == "" || rbacIsBuiltin(key) || key == "billing" || s.rbacFindRole(key) != nil {
			return 0, nil, errValidation("name", "A role with this name already exists.")
		}
		desc, _ := q.Body["description"].(string)
		r := &runr.Role{Key: key, Name: name, Description: desc, Permissions: expanded}
		s.rbac().custom = append(s.rbac().custom, r)
		return 201, map[string]any{"role": rbacCustomJSON(r)}, nil
	})

	handle("PUT /roles/default", func(s *Server, q *featureReq) (int, any, *apiErr) {
		role := q.str("role")
		if role == "" {
			return 0, nil, errValidation("role", "The role field is required.")
		}
		if err := s.rbacAssignable(role); err != nil {
			return 0, nil, err
		}
		s.rbac().defaultRole = role
		return 200, map[string]any{"default_role": role}, nil
	})

	handle("PUT /roles/{role}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		r, err := s.rbacCustomOrFail(q.Segs[1])
		if err != nil {
			return 0, nil, err
		}
		if name, ok := q.Body["name"].(string); ok {
			if strings.TrimSpace(name) == "" {
				return 0, nil, errValidation("name", "The name field must be a string.")
			}
			r.Name = name
		}
		if v, ok := q.Body["description"]; ok {
			d, _ := v.(string)
			r.Description = d
		}
		if v, ok := q.Body["permissions"]; ok {
			perms, isList := rbacStrings(v)
			if !isList {
				return 0, nil, errValidation("permissions", "The permissions field must be an array.")
			}
			expanded, err := rbacValidPermissions(perms)
			if err != nil {
				return 0, nil, err
			}
			r.Permissions = expanded
		}
		return 200, map[string]any{"role": rbacCustomJSON(r)}, nil
	})

	handle("DELETE /roles/{role}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		r, err := s.rbacCustomOrFail(q.Segs[1])
		if err != nil {
			return 0, nil, err
		}
		st := s.rbac()
		fallback := st.defaultRole
		if fallback == r.Key {
			fallback = "developer"
			st.defaultRole = fallback
		}
		for _, m := range st.members {
			if m.role == r.Key {
				m.role = fallback
			}
		}
		for _, g := range st.groups {
			if g.role == r.Key {
				g.role = ""
			}
		}
		kept := st.grants[:0]
		for _, g := range st.grants {
			if g.role != r.Key {
				kept = append(kept, g)
			}
		}
		st.grants = kept
		for i, x := range st.custom {
			if x == r {
				st.custom = append(st.custom[:i], st.custom[i+1:]...)
				break
			}
		}
		return 200, map[string]any{"message": "Role deleted. Its members now have the default role."}, nil
	})

	// Members
	handle("GET /members", func(s *Server, q *featureReq) (int, any, *apiErr) {
		list := []runr.Member{}
		for _, m := range s.rbac().members {
			list = append(list, s.rbacMemberJSON(m))
		}
		return 200, map[string]any{"members": list}, nil
	})

	handle("PUT /members/{user}/role", func(s *Server, q *featureReq) (int, any, *apiErr) {
		role := q.str("role")
		if role == "" {
			return 0, nil, errValidation("role", "The role field is required.")
		}
		m := s.rbacMemberByID(q.Segs[1])
		if m == nil {
			return 0, nil, errValidation("user", "This user is not a member of the organization.")
		}
		if strconv.Itoa(m.id) == s.User.ID.String() {
			return 0, nil, errValidation("role", "You cannot change your own role.")
		}
		if s.rbacFindRole(role) == nil {
			return 0, nil, errValidation("role", "This role does not exist.")
		}
		if m.role == runr.RoleOwner && role != runr.RoleOwner && s.rbacCountRole(runr.RoleOwner) <= 1 {
			return 0, nil, errValidation("role", "The organization needs at least one owner.")
		}
		m.role = role
		return 200, map[string]any{"message": "Role updated.", "role": role}, nil
	})

	handle("POST /users", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.rbac()
		for _, f := range []string{"name", "username", "email", "password"} {
			if strings.TrimSpace(q.str(f)) == "" {
				return 0, nil, errValidation(f, "The "+f+" field is required.")
			}
		}
		if _, err := mail.ParseAddress(q.str("email")); err != nil {
			return 0, nil, errValidation("email", "The email field must be a valid email address.")
		}
		if len(q.str("password")) < 8 {
			return 0, nil, errValidation("password", "The password field must be at least 8 characters.")
		}
		for _, m := range st.members {
			if strings.EqualFold(m.username, q.str("username")) {
				return 0, nil, errValidation("username", "The username has already been taken.")
			}
			if strings.EqualFold(m.email, q.str("email")) {
				return 0, nil, errValidation("email", "The email has already been taken.")
			}
		}
		role := st.defaultRole
		if r := q.str("role"); r != "" {
			if err := s.rbacAssignable(r); err != nil {
				return 0, nil, err
			}
			role = r
		}
		m := &rbacMember{id: st.nextUser, name: q.str("name"), email: q.str("email"), username: q.str("username"), role: role}
		st.nextUser++
		st.members = append(st.members, m)
		return 201, map[string]any{"user": map[string]any{"id": m.id, "name": m.name, "email": m.email, "username": m.username,
			"role": m.role, "two_factor_confirmed_at": nil, "created_at": nowRFC3339()}}, nil
	})

	handle("DELETE /users/{user}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		m := s.rbacMemberByID(q.Segs[1])
		if m == nil {
			return 0, nil, errNotFound()
		}
		if strconv.Itoa(m.id) == s.User.ID.String() {
			return 0, nil, &apiErr{status: 422, message: "Cannot delete yourself."}
		}
		if m.role == runr.RoleOwner && s.rbacCountRole(runr.RoleOwner) <= 1 {
			return 0, nil, errValidation("role", "The organization needs at least one owner.")
		}
		s.rbacRemoveMember(m)
		return 200, map[string]any{"message": "User deleted."}, nil
	})

	handle("GET /tenant/current", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.rbac()
		if st.edition != "multi" {
			return 0, nil, errNotFound()
		}
		if st.outsideTenant {
			return 200, map[string]any{"tenant": nil, "role": "admin", "mode": "central", "impersonating": false,
				"tenants": []any{map[string]any{"id": st.tenantID, "name": "Acme", "slug": "acme", "role": "admin", "member": false}}}, nil
		}
		return 200, map[string]any{"tenant": map[string]any{"id": st.tenantID, "name": "Acme", "slug": "acme"},
			"role": runr.RoleOwner, "mode": "tenant", "impersonating": false,
			"tenants": []any{map[string]any{"id": st.tenantID, "name": "Acme", "slug": "acme", "role": runr.RoleOwner, "member": true}}}, nil
	})

	handle("POST /tenants/{tenant}/users", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.rbac()
		if st.edition != "multi" || q.Segs[1] != st.tenantID {
			return 0, nil, errNotFound()
		}
		email := strings.TrimSpace(q.str("email"))
		if _, err := mail.ParseAddress(email); err != nil {
			return 0, nil, errValidation("email", "The email field must be a valid email address.")
		}
		if r := q.str("role"); r != "" {
			if err := s.rbacAssignable(r); err != nil {
				return 0, nil, err
			}
		}
		for _, m := range st.members {
			if strings.EqualFold(m.email, email) {
				return 0, nil, &apiErr{status: 422, message: "User already in tenant."}
			}
		}
		for _, e := range st.invitations {
			if strings.EqualFold(e, email) {
				return 0, nil, &apiErr{status: 422, message: "Invitation already pending."}
			}
		}
		st.invitations = append(st.invitations, email)
		return 201, map[string]any{"message": "Invitation sent.", "action": "invited"}, nil
	})

	handle("DELETE /tenants/{tenant}/users/{user}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.rbac()
		if st.edition != "multi" || q.Segs[1] != st.tenantID {
			return 0, nil, errNotFound()
		}
		m := s.rbacMemberByID(q.Segs[3])
		if m == nil {
			return 0, nil, errNotFound()
		}
		if m.role == runr.RoleOwner {
			return 0, nil, &apiErr{status: 422, message: "Cannot remove owner."}
		}
		s.rbacRemoveMember(m)
		return 200, map[string]any{"message": "User removed."}, nil
	})

	// Groups
	handle("GET /groups", func(s *Server, q *featureReq) (int, any, *apiErr) {
		groups := append([]*rbacGroup(nil), s.rbac().groups...)
		sort.Slice(groups, func(i, j int) bool { return groups[i].name < groups[j].name })
		list := []map[string]any{}
		for _, g := range groups {
			grants := 0
			for _, a := range s.rbac().grants {
				if a.subjectType == runr.SubjectGroup && a.subjectID == g.id {
					grants++
				}
			}
			list = append(list, map[string]any{"id": g.id, "name": g.name, "description": rbacNullable(g.description),
				"role": rbacNullable(g.role), "member_count": len(g.members), "project_grants": grants})
		}
		return 200, map[string]any{"groups": list}, nil
	})

	handle("POST /groups", func(s *Server, q *featureReq) (int, any, *apiErr) {
		name := strings.TrimSpace(q.str("name"))
		if name == "" {
			return 0, nil, errValidation("name", "The name field is required.")
		}
		for _, g := range s.rbac().groups {
			if g.name == name {
				return 0, nil, errValidation("name", "The name has already been taken.")
			}
		}
		g := &rbacGroup{id: s.newID(), name: name, members: map[string]string{}}
		g.description, _ = q.Body["description"].(string)
		if r := q.str("role"); r != "" {
			if err := s.rbacAssignable(r); err != nil {
				return 0, nil, err
			}
			g.role = r
		}
		s.rbac().groups = append(s.rbac().groups, g)
		return 201, map[string]any{"group": s.rbacGroupJSON(g)}, nil
	})

	handle("GET /groups/{group}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		g := s.rbacGroupByID(q.Segs[1])
		if g == nil {
			return 0, nil, errNotFound()
		}
		return 200, map[string]any{"group": s.rbacGroupJSON(g)}, nil
	})

	handle("PUT /groups/{group}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		g := s.rbacGroupByID(q.Segs[1])
		if g == nil {
			return 0, nil, errNotFound()
		}
		if name, ok := q.Body["name"].(string); ok {
			name = strings.TrimSpace(name)
			if name == "" {
				return 0, nil, errValidation("name", "The name field must be a string.")
			}
			for _, x := range s.rbac().groups {
				if x != g && x.name == name {
					return 0, nil, errValidation("name", "The name has already been taken.")
				}
			}
			g.name = name
		}
		if v, ok := q.Body["description"]; ok {
			g.description, _ = v.(string)
		}
		if v, ok := q.Body["role"]; ok {
			r, _ := v.(string)
			if r != "" {
				if err := s.rbacAssignable(r); err != nil {
					return 0, nil, err
				}
			}
			g.role = r
		}
		return 200, map[string]any{"group": s.rbacGroupJSON(g)}, nil
	})

	handle("DELETE /groups/{group}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.rbac()
		g := s.rbacGroupByID(q.Segs[1])
		if g == nil {
			return 0, nil, errNotFound()
		}
		for i, x := range st.groups {
			if x == g {
				st.groups = append(st.groups[:i], st.groups[i+1:]...)
				break
			}
		}
		kept := st.grants[:0]
		for _, a := range st.grants {
			if !(a.subjectType == runr.SubjectGroup && a.subjectID == g.id) {
				kept = append(kept, a)
			}
		}
		st.grants = kept
		return 200, map[string]any{"message": "Group deleted."}, nil
	})

	handle("POST /groups/{group}/members", func(s *Server, q *featureReq) (int, any, *apiErr) {
		g := s.rbacGroupByID(q.Segs[1])
		if g == nil {
			return 0, nil, errNotFound()
		}
		ids, ok := rbacStrings(q.Body["user_ids"])
		if !ok || len(ids) == 0 {
			return 0, nil, errValidation("user_ids", "The user ids field is required.")
		}
		for _, id := range ids {
			if s.rbacMemberByID(id) == nil {
				return 0, nil, errValidation("user_ids", "This user is not a member of the organization.")
			}
		}
		for _, id := range ids {
			g.addMember(id, "manual")
		}
		return 200, map[string]any{"group": s.rbacGroupJSON(g)}, nil
	})

	handle("DELETE /groups/{group}/members/{user}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		g := s.rbacGroupByID(q.Segs[1])
		if g == nil {
			return 0, nil, errNotFound()
		}
		g.removeMember(q.Segs[3])
		return 200, map[string]any{"group": s.rbacGroupJSON(g)}, nil
	})

	// Project access
	handle("GET /projects/{project}/access", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		list := []map[string]any{}
		for _, a := range s.rbac().grants {
			if a.projectID != p.data.ID {
				continue
			}
			j := map[string]any{"id": a.id, "subject_type": a.subjectType, "subject_id": a.subjectID, "role": a.role,
				"grantable": true, "subject_name": nil, "subject_email": nil}
			if a.subjectType == runr.SubjectUser {
				if m := s.rbacMemberByID(a.subjectID); m != nil {
					j["subject_name"], j["subject_email"] = m.name, m.email
				}
			} else if g := s.rbacGroupByID(a.subjectID); g != nil {
				j["subject_name"] = g.name
			}
			list = append(list, j)
		}
		return 200, map[string]any{"grants": list, "can_manage": true}, nil
	})

	handle("POST /projects/{project}/access", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		typ := q.str("subject_type")
		if typ != runr.SubjectUser && typ != runr.SubjectGroup {
			return 0, nil, errValidation("subject_type", "The selected subject type is invalid.")
		}
		subject := fmt.Sprint(q.Body["subject_id"])
		if q.Body["subject_id"] == nil || subject == "" {
			return 0, nil, errValidation("subject_id", "The subject id field is required.")
		}
		role := q.str("role")
		if role == "" {
			return 0, nil, errValidation("role", "The role field is required.")
		}
		if err := s.rbacAssignable(role); err != nil {
			return 0, nil, err
		}
		if (typ == runr.SubjectUser && s.rbacMemberByID(subject) == nil) || (typ == runr.SubjectGroup && s.rbacGroupByID(subject) == nil) {
			return 0, nil, errValidation("subject_id", "This user or group does not exist in the organization.")
		}
		var grant *rbacGrant
		for _, a := range s.rbac().grants {
			if a.projectID == p.data.ID && a.subjectType == typ && a.subjectID == subject {
				grant = a
			}
		}
		if grant == nil {
			grant = &rbacGrant{id: s.newID(), projectID: p.data.ID, subjectType: typ, subjectID: subject}
			s.rbac().grants = append(s.rbac().grants, grant)
		}
		grant.role = role
		return 201, map[string]any{"grant": map[string]any{"id": grant.id, "subject_type": grant.subjectType,
			"subject_id": grant.subjectID, "role": grant.role}}, nil
	})

	handle("DELETE /projects/{project}/access/{assignment}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		st := s.rbac()
		for i, a := range st.grants {
			if a.projectID == p.data.ID && a.id == q.Segs[3] {
				st.grants = append(st.grants[:i], st.grants[i+1:]...)
				return 200, map[string]any{"message": "Access removed."}, nil
			}
		}
		return 0, nil, errNotFound()
	})
}

func (s *Server) rbacCustomOrFail(key string) (*runr.Role, *apiErr) {
	if rbacIsBuiltin(key) {
		return nil, &apiErr{status: 422, message: "Built-in roles cannot be changed."}
	}
	for _, r := range s.rbac().custom {
		if r.Key == key {
			return r, nil
		}
	}
	return nil, errNotFound()
}

func (s *Server) rbacMemberByID(id string) *rbacMember {
	for _, m := range s.rbac().members {
		if strconv.Itoa(m.id) == id {
			return m
		}
	}
	return nil
}

func (s *Server) rbacGroupByID(id string) *rbacGroup {
	for _, g := range s.rbac().groups {
		if g.id == id {
			return g
		}
	}
	return nil
}

func (s *Server) rbacCountRole(role string) int {
	n := 0
	for _, m := range s.rbac().members {
		if m.role == role {
			n++
		}
	}
	return n
}

func (s *Server) rbacRemoveMember(m *rbacMember) {
	st := s.rbac()
	id := strconv.Itoa(m.id)
	for i, x := range st.members {
		if x == m {
			st.members = append(st.members[:i], st.members[i+1:]...)
			break
		}
	}
	for _, g := range st.groups {
		g.removeMember(id)
	}
	kept := st.grants[:0]
	for _, a := range st.grants {
		if !(a.subjectType == runr.SubjectUser && a.subjectID == id) {
			kept = append(kept, a)
		}
	}
	st.grants = kept
}

func (s *Server) rbacMemberJSON(m *rbacMember) runr.Member {
	out := runr.Member{ID: runr.FlexID(strconv.Itoa(m.id)), Name: m.name, Email: m.email, Username: m.username,
		Role: m.role, Groups: []runr.MemberGroup{}}
	for _, g := range s.rbac().groups {
		if src, ok := g.members[strconv.Itoa(m.id)]; ok {
			out.Groups = append(out.Groups, runr.MemberGroup{ID: g.id, Name: g.name, Source: src})
		}
	}
	return out
}

func (s *Server) rbacGroupJSON(g *rbacGroup) runr.Group {
	out := runr.Group{ID: g.id, Name: g.name, Description: g.description, Role: g.role,
		Members: []runr.GroupMember{}, Projects: []runr.GroupProject{}}
	for _, m := range s.rbac().members {
		if src, ok := g.members[strconv.Itoa(m.id)]; ok {
			out.Members = append(out.Members, runr.GroupMember{ID: runr.FlexID(strconv.Itoa(m.id)), Name: m.name, Email: m.email, Source: src})
		}
	}
	for _, a := range s.rbac().grants {
		if a.subjectType == runr.SubjectGroup && a.subjectID == g.id {
			gp := runr.GroupProject{ID: a.id, ProjectID: a.projectID, Role: a.role}
			if p := s.projectByID(a.projectID); p != nil {
				gp.ProjectName = p.data.Name
			}
			out.Projects = append(out.Projects, gp)
		}
	}
	out.MemberCount = len(out.Members)
	return out
}

// rbacNullable returns nil for "" (the API sends null for unset strings).
func rbacNullable(s string) any {
	if s == "" {
		return nil
	}
	return s
}
