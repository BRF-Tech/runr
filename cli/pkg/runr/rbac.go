package runr

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"sort"
	"strings"
)

// Role-based access control administration (docs/permissions.md): the permission catalog,
// built-in and custom roles, members and their own role, groups and project access.

// Subject types of a project access grant.
const (
	SubjectUser  = "user"
	SubjectGroup = "group"
)

// RoleOwner is the built-in role that cannot be granted through project access or groups.
const RoleOwner = "owner"

// Role is a built-in or custom role (RoleController).
type Role struct {
	Key         string   `json:"key"`
	Name        string   `json:"name"`
	Description string   `json:"description"`
	Permissions []string `json:"permissions"`
	Builtin     bool     `json:"builtin"`
	// Grantable reports whether the caller may give this role to others (GET /roles only).
	Grantable *bool `json:"grantable,omitempty"`
	// Usage counts members, groups and project grants with this role (roles.manage only).
	Usage *RoleUsage `json:"usage,omitempty"`
}

// RoleUsage is where a role is in use.
type RoleUsage struct {
	Members  int `json:"members"`
	Groups   int `json:"groups"`
	Projects int `json:"projects"`
}

// UnmarshalJSON tolerates permissions encoded as an object (a PHP array with gaps).
func (r *Role) UnmarshalJSON(b []byte) error {
	type plain struct {
		Key         string          `json:"key"`
		Name        string          `json:"name"`
		Description string          `json:"description"`
		Permissions json.RawMessage `json:"permissions"`
		Builtin     bool            `json:"builtin"`
		Grantable   *bool           `json:"grantable"`
		Usage       *RoleUsage      `json:"usage"`
	}
	var p plain
	if err := json.Unmarshal(b, &p); err != nil {
		return err
	}
	*r = Role{Key: p.Key, Name: p.Name, Description: p.Description, Builtin: p.Builtin, Grantable: p.Grantable, Usage: p.Usage}
	r.Permissions = stringsOf(p.Permissions)
	sort.Strings(r.Permissions)
	return nil
}

// stringsOf decodes a JSON list of strings, or the values of an object (PHP arrays whose keys
// are not 0…n-1).
func stringsOf(raw json.RawMessage) []string {
	raw = bytes.TrimSpace(raw)
	out := []string{}
	if len(raw) == 0 {
		return out
	}
	switch raw[0] {
	case '[':
		_ = json.Unmarshal(raw, &out)
	case '{':
		var m map[string]string
		if json.Unmarshal(raw, &m) == nil {
			for _, v := range m {
				out = append(out, v)
			}
		}
	}
	return out
}

// RoleList is the answer of GET /roles.
type RoleList struct {
	Roles       []Role `json:"roles"`
	DefaultRole string `json:"default_role"`
}

// ListRoles returns the built-in and custom roles and the default role of new members.
func (c *Client) ListRoles(ctx context.Context) (*RoleList, error) {
	var out RoleList
	if err := c.do(ctx, request{method: http.MethodGet, path: "/roles"}, &out); err != nil {
		return nil, err
	}
	if out.Roles == nil {
		out.Roles = []Role{}
	}
	return &out, nil
}

// GetRole returns a role by key.
func (c *Client) GetRole(ctx context.Context, key string) (*Role, error) {
	var out struct {
		Role Role `json:"role"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/roles/" + seg(key)}, &out); err != nil {
		return nil, err
	}
	return &out.Role, nil
}

// FindRole resolves a role by key or (case-insensitive) name.
func (c *Client) FindRole(ctx context.Context, ref string) (*Role, error) {
	list, err := c.ListRoles(ctx)
	if err != nil {
		return nil, err
	}
	ref = strings.TrimSpace(ref)
	for i := range list.Roles {
		if list.Roles[i].Key == ref {
			return &list.Roles[i], nil
		}
	}
	for i := range list.Roles {
		if strings.EqualFold(list.Roles[i].Name, ref) || strings.EqualFold(list.Roles[i].Key, ref) {
			return &list.Roles[i], nil
		}
	}
	return nil, &NotFoundError{Kind: "role", Ref: ref}
}

// RoleInput is the body of POST /roles and PUT /roles/{role}. Nil fields are not sent on an
// update; a create always sends the permissions (the API requires the field).
type RoleInput struct {
	Name        string    `json:"name,omitempty"`
	Key         string    `json:"key,omitempty"`
	Description *string   `json:"description,omitempty"`
	Permissions *[]string `json:"permissions,omitempty"`
}

// CreateRole creates a custom role. Its key defaults to the slug of the name; the answer lists
// the permissions with the ones they imply.
func (c *Client) CreateRole(ctx context.Context, in RoleInput) (*Role, error) {
	if in.Permissions == nil {
		empty := []string{}
		in.Permissions = &empty
	}
	var out struct {
		Role Role `json:"role"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/roles", body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Role, nil
}

// UpdateRole changes a custom role (built-in roles are read-only: 422). The key cannot change.
func (c *Client) UpdateRole(ctx context.Context, key string, in RoleInput) (*Role, error) {
	in.Key = ""
	var out struct {
		Role Role `json:"role"`
	}
	if err := c.do(ctx, request{method: http.MethodPut, path: "/roles/" + seg(key), body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Role, nil
}

// DeleteRole deletes a custom role; members who had it get the default role.
func (c *Client) DeleteRole(ctx context.Context, key string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/roles/" + seg(key)}, nil)
}

// SetDefaultRole sets the role new members get and returns it.
func (c *Client) SetDefaultRole(ctx context.Context, role string) (string, error) {
	var out struct {
		DefaultRole string `json:"default_role"`
	}
	if err := c.do(ctx, request{method: http.MethodPut, path: "/roles/default", body: map[string]string{"role": role}}, &out); err != nil {
		return "", err
	}
	return out.DefaultRole, nil
}

// PermissionInfo is one permission of the catalog.
type PermissionInfo struct {
	Key     string   `json:"key"`
	Group   string   `json:"group"`
	Scope   string   `json:"scope"`
	Implies []string `json:"implies"`
}

// PermissionCatalog is the answer of GET /permissions: the permissions of this edition.
type PermissionCatalog struct {
	Edition     string           `json:"edition"`
	Permissions []PermissionInfo `json:"permissions"`
	Groups      []string         `json:"groups"`
}

// ListPermissions returns the permission catalog.
func (c *Client) ListPermissions(ctx context.Context) (*PermissionCatalog, error) {
	var out PermissionCatalog
	if err := c.do(ctx, request{method: http.MethodGet, path: "/permissions"}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// Has reports whether key is a permission of the catalog.
func (pc *PermissionCatalog) Has(key string) bool {
	for _, p := range pc.Permissions {
		if p.Key == key {
			return true
		}
	}
	return false
}

// Expand returns perms with every permission they imply, sorted ("*" expands to all).
func (pc *PermissionCatalog) Expand(perms []string) []string {
	implies := map[string][]string{}
	for _, p := range pc.Permissions {
		implies[p.Key] = p.Implies
	}
	set := map[string]bool{}
	var add func(p string)
	add = func(p string) {
		if set[p] {
			return
		}
		set[p] = true
		for _, q := range implies[p] {
			add(q)
		}
	}
	for _, p := range perms {
		if p == "*" {
			for _, x := range pc.Permissions {
				add(x.Key)
			}
			continue
		}
		add(p)
	}
	out := make([]string, 0, len(set))
	for p := range set {
		out = append(out, p)
	}
	sort.Strings(out)
	return out
}

// Member is one member of the organization with their own role and groups (GET /members).
type Member struct {
	ID       FlexID        `json:"id"`
	Name     string        `json:"name"`
	Email    string        `json:"email"`
	Username string        `json:"username,omitempty"`
	Role     string        `json:"role,omitempty"`
	Groups   []MemberGroup `json:"groups"`
}

// MemberGroup is a group a member belongs to; Source is manual or sso.
type MemberGroup struct {
	ID     string `json:"id"`
	Name   string `json:"name"`
	Source string `json:"source"`
}

// ListMembers returns the members of the current organization.
func (c *Client) ListMembers(ctx context.Context) ([]Member, error) {
	var out struct {
		Members []Member `json:"members"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/members"}, &out); err != nil {
		return nil, err
	}
	if out.Members == nil {
		out.Members = []Member{}
	}
	return out.Members, nil
}

// MatchMember reports whether ref names m exactly: its id, e-mail or username.
func MatchMember(m *Member, ref string) bool {
	ref = strings.TrimSpace(ref)
	return ref != "" && (m.ID.String() == ref || strings.EqualFold(m.Email, ref) ||
		(m.Username != "" && strings.EqualFold(m.Username, ref)))
}

// FindMember resolves a member by id, e-mail, username or name.
func (c *Client) FindMember(ctx context.Context, ref string) (*Member, error) {
	list, err := c.ListMembers(ctx)
	if err != nil {
		return nil, err
	}
	return PickMember(list, ref)
}

// PickMember resolves ref in a member list (id, e-mail, username, then a unique name).
func PickMember(list []Member, ref string) (*Member, error) {
	for i := range list {
		if MatchMember(&list[i], ref) {
			return &list[i], nil
		}
	}
	var byName []*Member
	for i := range list {
		if strings.EqualFold(list[i].Name, strings.TrimSpace(ref)) {
			byName = append(byName, &list[i])
		}
	}
	if len(byName) == 1 {
		return byName[0], nil
	}
	return nil, &NotFoundError{Kind: "member", Ref: ref}
}

// SetMemberRole changes a member's own organization-wide role and returns the role now held.
func (c *Client) SetMemberRole(ctx context.Context, userID, role string) (string, error) {
	var out struct {
		Message string `json:"message"`
		Role    string `json:"role"`
	}
	if err := c.do(ctx, request{method: http.MethodPut, path: "/members/" + seg(userID) + "/role", body: map[string]string{"role": role}}, &out); err != nil {
		return "", err
	}
	return out.Role, nil
}

// NewUserInput is the body of POST /users: an account of a single-tenant installation.
type NewUserInput struct {
	Name     string `json:"name"`
	Username string `json:"username"`
	Email    string `json:"email"`
	Password string `json:"password"`
	Role     string `json:"role,omitempty"`
}

// CreateUser creates an account in a single-tenant installation; it becomes a member with
// Role (default: the default role).
func (c *Client) CreateUser(ctx context.Context, in NewUserInput) (*Member, error) {
	var out struct {
		User Member `json:"user"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/users", body: in}, &out); err != nil {
		return nil, err
	}
	return &out.User, nil
}

// DeleteUser deletes an account of a single-tenant installation.
func (c *Client) DeleteUser(ctx context.Context, userID string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/users/" + seg(userID)}, nil)
}

// TenantRef is the organization (tenant) a request runs in, multi-tenant edition.
type TenantRef struct {
	ID   string `json:"id"`
	Name string `json:"name"`
	Slug string `json:"slug"`
}

// CurrentTenant returns the tenant of the session (GET /tenant/current); ErrUnsupported on a
// single-tenant installation, nil when the caller works outside any tenant.
func (c *Client) CurrentTenant(ctx context.Context) (*TenantRef, error) {
	var out struct {
		Tenant *TenantRef `json:"tenant"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: "/tenant/current"}, &out)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	return out.Tenant, nil
}

// InviteResult is the answer of an invitation: the user was added directly ("added", an
// existing account) or invited by e-mail ("invited").
type InviteResult struct {
	Message string `json:"message"`
	Action  string `json:"action"`
}

// InviteMember adds an existing account to a tenant or sends an invitation (multi-tenant).
func (c *Client) InviteMember(ctx context.Context, tenantID, email, role string) (*InviteResult, error) {
	body := map[string]string{"email": email}
	if role != "" {
		body["role"] = role
	}
	var out InviteResult
	if err := c.do(ctx, request{method: http.MethodPost, path: "/tenants/" + seg(tenantID) + "/users", body: body}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// RemoveTenantMember removes a member from a tenant (multi-tenant; the account stays).
func (c *Client) RemoveTenantMember(ctx context.Context, tenantID, userID string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/tenants/" + seg(tenantID) + "/users/" + seg(userID)}, nil)
}

// Group is a group (team) of members (GroupController). List answers carry the counts,
// single answers the members and project grants.
type Group struct {
	ID            string         `json:"id"`
	Name          string         `json:"name"`
	Description   string         `json:"description,omitempty"`
	Role          string         `json:"role,omitempty"`
	MemberCount   int            `json:"member_count,omitempty"`
	ProjectGrants int            `json:"project_grants,omitempty"`
	Members       []GroupMember  `json:"members,omitempty"`
	Projects      []GroupProject `json:"projects,omitempty"`
}

// UnmarshalJSON tolerates description and role sent as null.
func (g *Group) UnmarshalJSON(b []byte) error {
	type plain struct {
		ID            string         `json:"id"`
		Name          string         `json:"name"`
		Description   *string        `json:"description"`
		Role          *string        `json:"role"`
		MemberCount   int            `json:"member_count"`
		ProjectGrants int            `json:"project_grants"`
		Members       []GroupMember  `json:"members"`
		Projects      []GroupProject `json:"projects"`
	}
	var p plain
	if err := json.Unmarshal(b, &p); err != nil {
		return err
	}
	*g = Group{ID: p.ID, Name: p.Name, MemberCount: p.MemberCount, ProjectGrants: p.ProjectGrants,
		Members: p.Members, Projects: p.Projects}
	if p.Description != nil {
		g.Description = *p.Description
	}
	if p.Role != nil {
		g.Role = *p.Role
	}
	if g.MemberCount == 0 {
		g.MemberCount = len(p.Members)
	}
	return nil
}

// GroupMember is a member of a group; Source is manual or sso (kept in sync by SSO).
type GroupMember struct {
	ID     FlexID `json:"id"`
	Name   string `json:"name"`
	Email  string `json:"email"`
	Source string `json:"source"`
}

// GroupProject is a project access grant of a group.
type GroupProject struct {
	ID          string `json:"id"`
	ProjectID   string `json:"project_id"`
	ProjectName string `json:"project_name,omitempty"`
	Role        string `json:"role"`
}

// GroupInput is the body of POST /groups and PUT /groups/{group}. Role is the group's
// organization-wide role; a pointer to "" removes it.
type GroupInput struct {
	Name        string  `json:"name,omitempty"`
	Description *string `json:"description,omitempty"`
	Role        *string `json:"role,omitempty"`
}

// ListGroups returns the groups with member counts.
func (c *Client) ListGroups(ctx context.Context) ([]Group, error) {
	var out struct {
		Groups []Group `json:"groups"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/groups"}, &out); err != nil {
		return nil, err
	}
	if out.Groups == nil {
		out.Groups = []Group{}
	}
	return out.Groups, nil
}

// GetGroup returns a group with its members and project grants.
func (c *Client) GetGroup(ctx context.Context, id string) (*Group, error) {
	return c.groupCall(ctx, http.MethodGet, "/groups/"+seg(id), nil)
}

// FindGroup resolves a group by id or (case-insensitive) name and returns its details.
func (c *Client) FindGroup(ctx context.Context, ref string) (*Group, error) {
	ref = strings.TrimSpace(ref)
	if LooksLikeULID(ref) {
		g, err := c.GetGroup(ctx, ref)
		if err == nil || !IsNotFound(err) {
			return g, err
		}
	}
	list, err := c.ListGroups(ctx)
	if err != nil {
		return nil, err
	}
	for _, g := range list {
		if g.ID == ref || strings.EqualFold(g.Name, ref) {
			return c.GetGroup(ctx, g.ID)
		}
	}
	return nil, &NotFoundError{Kind: "group", Ref: ref}
}

// CreateGroup creates a group.
func (c *Client) CreateGroup(ctx context.Context, in GroupInput) (*Group, error) {
	return c.groupCall(ctx, http.MethodPost, "/groups", in)
}

// UpdateGroup changes a group's name, description or role.
func (c *Client) UpdateGroup(ctx context.Context, id string, in GroupInput) (*Group, error) {
	return c.groupCall(ctx, http.MethodPut, "/groups/"+seg(id), in)
}

// DeleteGroup deletes a group; its members lose the group's roles.
func (c *Client) DeleteGroup(ctx context.Context, id string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/groups/" + seg(id)}, nil)
}

// AddGroupMembers adds members (user ids) to a group.
func (c *Client) AddGroupMembers(ctx context.Context, id string, userIDs []string) (*Group, error) {
	return c.groupCall(ctx, http.MethodPost, "/groups/"+seg(id)+"/members", map[string][]string{"user_ids": userIDs})
}

// RemoveGroupMember removes a member (user id) from a group.
func (c *Client) RemoveGroupMember(ctx context.Context, id, userID string) (*Group, error) {
	return c.groupCall(ctx, http.MethodDelete, "/groups/"+seg(id)+"/members/"+seg(userID), nil)
}

func (c *Client) groupCall(ctx context.Context, method, path string, body any) (*Group, error) {
	var out struct {
		Group Group `json:"group"`
	}
	if err := c.do(ctx, request{method: method, path: path, body: body}, &out); err != nil {
		return nil, err
	}
	return &out.Group, nil
}

// AccessGrant is a role a user or group holds on one project (ProjectAccessController).
type AccessGrant struct {
	ID           string `json:"id"`
	SubjectType  string `json:"subject_type"`
	SubjectID    FlexID `json:"subject_id"`
	SubjectName  string `json:"subject_name,omitempty"`
	SubjectEmail string `json:"subject_email,omitempty"`
	Role         string `json:"role"`
	Grantable    *bool  `json:"grantable,omitempty"`
}

// ListAccess returns the access grants of a project and whether the caller may change them.
func (c *Client) ListAccess(ctx context.Context, projectID string) ([]AccessGrant, bool, error) {
	var out struct {
		Grants    []AccessGrant `json:"grants"`
		CanManage bool          `json:"can_manage"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/access"}, &out); err != nil {
		return nil, false, err
	}
	if out.Grants == nil {
		out.Grants = []AccessGrant{}
	}
	return out.Grants, out.CanManage, nil
}

// GrantAccess gives a user or group (SubjectUser / SubjectGroup) a role on a project. A
// subject that has a grant already gets the new role on the same grant.
func (c *Client) GrantAccess(ctx context.Context, projectID, subjectType, subjectID, role string) (*AccessGrant, error) {
	var out struct {
		Grant AccessGrant `json:"grant"`
	}
	body := map[string]string{"subject_type": subjectType, "subject_id": subjectID, "role": role}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/access", body: body}, &out); err != nil {
		return nil, err
	}
	return &out.Grant, nil
}

// RevokeAccess removes an access grant of a project.
func (c *Client) RevokeAccess(ctx context.Context, projectID, grantID string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/projects/" + projectID + "/access/" + seg(grantID)}, nil)
}
