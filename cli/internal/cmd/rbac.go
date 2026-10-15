package cmd

import (
	"context"
	"errors"
	"fmt"
	"strconv"
	"strings"

	"github.com/spf13/cobra"
	"gopkg.in/yaml.v3"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Role-based access control administration (docs/permissions.md): members, roles, groups and
// project access.

func init() {
	registerCommand(newMembersCmd)
	registerCommand(newRolesCmd)
	registerCommand(newGroupsCmd)
	registerCommand(newAccessCmd)
}

// rbacRoleKey resolves a role given by key or name to its key.
func rbacRoleKey(ctx context.Context, c *runr.Client, ref string) (string, error) {
	r, err := c.FindRole(ctx, ref)
	if err != nil {
		return "", err
	}
	return r.Key, nil
}

// rbacMultiTenant reports whether the API is the multi-tenant edition.
func rbacMultiTenant(ctx context.Context, c *runr.Client) (bool, error) {
	p, err := c.Permissions(ctx)
	if errors.Is(err, runr.ErrUnsupported) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	return p.Edition == "multi", nil
}

// rbacTenant returns the tenant the session works in (multi-tenant).
func rbacTenant(ctx context.Context, c *runr.Client) (*runr.TenantRef, error) {
	t, err := c.CurrentTenant(ctx)
	if err != nil {
		return nil, err
	}
	if t == nil {
		return nil, usageErrorf("no organization selected: switch to an organization in the console first")
	}
	return t, nil
}

func rbacMemberGroups(m runr.Member) string {
	names := make([]string, 0, len(m.Groups))
	for _, g := range m.Groups {
		names = append(names, g.Name)
	}
	return joinOrDash(names)
}

// ---------------------------------------------------------------------------------------
// runr members

func newMembersCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "members",
		Aliases: []string{"member"},
		Short:   "List the organization's members and change their roles",
		Long: "Members of the organization with their own organization-wide role and their groups.\n" +
			"Users are given by id, e-mail address or username.",
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List members, their role and groups",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListMembers(cmd.Context())
			if err != nil {
				return err
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("ID", "NAME", "EMAIL", "ROLE", "GROUPS")
				for _, m := range list {
					t.Row(m.ID.String(), m.Name, m.Email, output.Dash(m.Role), rbacMemberGroups(m))
				}
			})
		},
	}
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE

	cmd.AddCommand(&cobra.Command{
		Use:     "set-role USER ROLE",
		Short:   "Change a member's own organization-wide role",
		Example: "  runr members set-role grace@example.com viewer",
		Args:    cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			m, err := c.FindMember(ctx, args[0])
			if err != nil {
				return err
			}
			key, err := rbacRoleKey(ctx, c, args[1])
			if err != nil {
				return err
			}
			role, err := c.SetMemberRole(ctx, m.ID.String(), key)
			if err != nil {
				return err
			}
			m.Role = role
			app.Progress("%s now has the role %s.", m.Email, role)
			return app.Printer().Print(m, func(*output.TableWriter) {})
		},
	})

	var role, name, username string
	var passwordStdin bool
	add := &cobra.Command{
		Use:     "add EMAIL",
		Aliases: []string{"invite"},
		Short:   "Add a member (multi-tenant: invite by e-mail; single-tenant: create the account)",
		Long: "Multi-tenant installations invite the address into the current organization: an existing\n" +
			"account is added at once, anyone else gets an invitation e-mail. Single-tenant installations\n" +
			"create the account directly, with --name, --username (default: the part of the address\n" +
			"before @) and an initial password read from stdin (--password-stdin) or a prompt.\n" +
			"--role sets the member's role (default: the organization's default role).",
		Example: "  runr members add grace@example.com --role viewer\n" +
			"  echo \"$PASSWORD\" | runr members add grace@example.com --name \"Grace Hopper\" --password-stdin",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			email := strings.TrimSpace(args[0])
			if !strings.Contains(email, "@") {
				return usageErrorf("%q is not an e-mail address", email)
			}
			key := ""
			if role != "" {
				if key, err = rbacRoleKey(ctx, c, role); err != nil {
					return err
				}
			}
			multi, err := rbacMultiTenant(ctx, c)
			if err != nil {
				return err
			}
			if multi {
				t, err := rbacTenant(ctx, c)
				if err != nil {
					return err
				}
				res, err := c.InviteMember(ctx, t.ID, email, key)
				if err != nil {
					return err
				}
				app.Progress("%s", res.Message)
				return app.Printer().Print(map[string]any{"email": email, "organization": t.ID, "action": res.Action, "message": res.Message},
					func(*output.TableWriter) {})
			}
			local, _, _ := strings.Cut(email, "@")
			if username == "" {
				username = local
			}
			if name == "" {
				name = username
			}
			var password string
			switch {
			case passwordStdin:
				if password, err = app.readLine(""); err != nil {
					return fmt.Errorf("read the password from stdin: %w", err)
				}
			case app.interactive():
				if password, err = app.ReadSecret("Initial password for " + email + ": "); err != nil {
					return err
				}
			default:
				return usageErrorf("this installation creates the account directly: give its initial password with --password-stdin")
			}
			if len(password) < 8 {
				return usageErrorf("the password needs at least 8 characters")
			}
			m, err := c.CreateUser(ctx, runr.NewUserInput{Name: name, Username: username, Email: email, Password: password, Role: key})
			if err != nil {
				return err
			}
			app.Progress("Created %s (%s) with the role %s.", m.Email, m.Username, output.Dash(m.Role))
			return app.Printer().Print(m, func(*output.TableWriter) {})
		},
	}
	add.Flags().StringVar(&role, "role", "", "role of the new member (key or name; default: the default role)")
	add.Flags().StringVar(&name, "name", "", "full name (single-tenant; default: the username)")
	add.Flags().StringVar(&username, "username", "", "username (single-tenant; default: the part of the address before @)")
	add.Flags().BoolVar(&passwordStdin, "password-stdin", false, "read the initial password from stdin (single-tenant)")
	cmd.AddCommand(add)

	var yes bool
	rm := &cobra.Command{
		Use:     "rm USER",
		Aliases: []string{"remove"},
		Short:   "Remove a member (single-tenant: deletes the account)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			m, err := c.FindMember(ctx, args[0])
			if err != nil {
				return err
			}
			multi, err := rbacMultiTenant(ctx, c)
			if err != nil {
				return err
			}
			if multi {
				t, err := rbacTenant(ctx, c)
				if err != nil {
					return err
				}
				if err := app.confirm("Remove "+m.Email+" from the organization", yes); err != nil {
					return err
				}
				if err := c.RemoveTenantMember(ctx, t.ID, m.ID.String()); err != nil {
					return err
				}
				app.Progress("Removed %s from the organization.", m.Email)
				return nil
			}
			if err := app.confirm("Delete the account "+m.Email, yes); err != nil {
				return err
			}
			if err := c.DeleteUser(ctx, m.ID.String()); err != nil {
				return err
			}
			app.Progress("Deleted %s.", m.Email)
			return nil
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(rm)
	return cmd
}

// ---------------------------------------------------------------------------------------
// runr roles

// rbacRoleFile is a role definition for `runr roles create|update --from-file`.
type rbacRoleFile struct {
	Name        string   `yaml:"name" json:"name"`
	Key         string   `yaml:"key" json:"key"`
	Description *string  `yaml:"description" json:"description"`
	Permissions []string `yaml:"permissions" json:"permissions"`
}

func rbacReadRoleFile(app *App, path string) (*rbacRoleFile, error) {
	data, err := readFileArg(app, path)
	if err != nil {
		return nil, err
	}
	var f rbacRoleFile
	dec := yaml.NewDecoder(strings.NewReader(string(data))) // YAML is a superset of JSON
	dec.KnownFields(true)
	if err := dec.Decode(&f); err != nil {
		return nil, usageErrorf("%s: %v", path, err)
	}
	return &f, nil
}

// rbacPermissionList splits repeatable, comma-separated --permission values.
func rbacPermissionList(values []string) []string {
	var out []string
	for _, v := range values {
		for _, p := range strings.Split(v, ",") {
			if p = strings.TrimSpace(p); p != "" {
				out = append(out, p)
			}
		}
	}
	return uniqSorted(out)
}

func rbacRoleKind(r runr.Role) string {
	if r.Builtin {
		return "built-in"
	}
	return "custom"
}

func rbacRoleDetail(t *output.TableWriter, r *runr.Role, defaultRole string) {
	t.KV("Key", r.Key)
	t.KV("Name", r.Name)
	if r.Description != "" {
		t.KV("Description", r.Description)
	}
	t.KV("Type", rbacRoleKind(*r))
	if defaultRole != "" && r.Key == defaultRole {
		t.KV("Default", "yes (new members get this role)")
	}
	t.Header("PERMISSION")
	for _, p := range r.Permissions {
		t.Row(p)
	}
}

func newRolesCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "roles",
		Aliases: []string{"role"},
		Short:   "Built-in and custom roles, the default role and the permission catalog",
		Long: "Roles grant permissions organization-wide (a member's own role, a group's role) or on single\n" +
			"projects (project access). Built-in roles (owner, admin, developer, viewer) are read-only;\n" +
			"custom roles need roles.manage, and nobody can give out permissions they do not hold.",
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List roles (* marks the default role)",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListRoles(cmd.Context())
			if err != nil {
				return err
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("KEY", "NAME", "TYPE", "PERMISSIONS", "IN USE")
				for _, r := range list.Roles {
					key := r.Key
					if r.Key == list.DefaultRole {
						key += " *"
					}
					use := "-"
					if r.Usage != nil {
						use = fmt.Sprintf("%d members, %d groups, %d projects", r.Usage.Members, r.Usage.Groups, r.Usage.Projects)
					}
					t.Row(key, r.Name, rbacRoleKind(r), strconv.Itoa(len(r.Permissions)), use)
				}
			})
		},
	}
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE

	cmd.AddCommand(&cobra.Command{
		Use:   "get ROLE",
		Short: "Show a role and its permissions (key or name)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			key, err := rbacRoleKey(ctx, c, args[0])
			if err != nil {
				return err
			}
			r, err := c.GetRole(ctx, key)
			if err != nil {
				return err
			}
			def := ""
			if list, err := c.ListRoles(ctx); err == nil {
				def = list.DefaultRole
			}
			return app.Printer().Print(r, func(t *output.TableWriter) { rbacRoleDetail(t, r, def) })
		},
	})

	var cKey, cDesc, cFile string
	var cPerms []string
	create := &cobra.Command{
		Use:   "create [NAME]",
		Short: "Create a custom role",
		Long: "Create a custom role from --permission flags or a file (--from-file role.yaml with name, key,\n" +
			"description and permissions). The key defaults to the slug of the name. The permissions a\n" +
			"permission implies are added (env.edit brings env.view). `runr roles permissions` lists them.",
		Example: "  runr roles create \"Release manager\" --permission projects.deploy,logs.view\n" +
			"  runr roles create --from-file release-manager.yaml",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			in := runr.RoleInput{Key: cKey}
			var perms []string
			if cFile != "" {
				f, err := rbacReadRoleFile(app, cFile)
				if err != nil {
					return err
				}
				in.Name, in.Description, perms = f.Name, f.Description, f.Permissions
				if in.Key == "" {
					in.Key = f.Key
				}
			}
			if len(args) == 1 {
				in.Name = args[0]
			}
			if strings.TrimSpace(in.Name) == "" {
				return usageErrorf("give the role's name (or name: in --from-file)")
			}
			if cmd.Flags().Changed("description") {
				in.Description = &cDesc
			}
			if cmd.Flags().Changed("permission") {
				perms = cPerms
			}
			list := rbacPermissionList(perms)
			in.Permissions = &list
			c, err := app.Client()
			if err != nil {
				return err
			}
			r, err := c.CreateRole(cmd.Context(), in)
			if err != nil {
				return err
			}
			app.Progress("Created role %s (%s).", r.Name, r.Key)
			return app.Printer().Print(r, func(t *output.TableWriter) { rbacRoleDetail(t, r, "") })
		},
	}
	create.Flags().StringVar(&cKey, "key", "", "role key (default: the slug of the name)")
	create.Flags().StringVar(&cDesc, "description", "", "description")
	create.Flags().StringSliceVar(&cPerms, "permission", nil, "permission (repeatable or comma-separated)")
	create.Flags().StringVarP(&cFile, "from-file", "f", "", "role definition (YAML or JSON; - for stdin)")
	cmd.AddCommand(create)

	var uName, uDesc, uFile string
	var uPerms, uAdd, uRemove []string
	update := &cobra.Command{
		Use:   "update ROLE",
		Short: "Change a custom role",
		Long: "--permission replaces the role's permissions, --add / --remove change single ones. Removing a\n" +
			"permission that another permission of the role implies keeps it.",
		Example: "  runr roles update release-manager --add metrics.view\n" +
			"  runr roles update release-manager --from-file release-manager.yaml",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			fl := cmd.Flags()
			if !fl.Changed("name") && !fl.Changed("description") && !fl.Changed("permission") && !fl.Changed("add") &&
				!fl.Changed("remove") && uFile == "" {
				return usageErrorf("give --name, --description, --permission, --add, --remove or --from-file")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			key, err := rbacRoleKey(ctx, c, args[0])
			if err != nil {
				return err
			}
			in := runr.RoleInput{}
			var perms []string
			permsSet := false
			if uFile != "" {
				f, err := rbacReadRoleFile(app, uFile)
				if err != nil {
					return err
				}
				in.Name, in.Description = f.Name, f.Description
				if f.Permissions != nil {
					perms, permsSet = f.Permissions, true
				}
			}
			if fl.Changed("name") {
				in.Name = uName
			}
			if fl.Changed("description") {
				in.Description = &uDesc
			}
			if fl.Changed("permission") {
				perms, permsSet = uPerms, true
			}
			if fl.Changed("add") || fl.Changed("remove") {
				if !permsSet {
					cur, err := c.GetRole(ctx, key)
					if err != nil {
						return err
					}
					perms, permsSet = cur.Permissions, true
				}
				perms = append(perms, rbacPermissionList(uAdd)...)
				drop := map[string]bool{}
				for _, p := range rbacPermissionList(uRemove) {
					drop[p] = true
				}
				kept := perms[:0]
				for _, p := range perms {
					if !drop[p] {
						kept = append(kept, p)
					}
				}
				perms = kept
			}
			if permsSet {
				list := rbacPermissionList(perms)
				in.Permissions = &list
			}
			r, err := c.UpdateRole(ctx, key, in)
			if err != nil {
				return err
			}
			app.Progress("Updated role %s.", r.Key)
			return app.Printer().Print(r, func(t *output.TableWriter) { rbacRoleDetail(t, r, "") })
		},
	}
	update.Flags().StringVar(&uName, "name", "", "new name (the key stays)")
	update.Flags().StringVar(&uDesc, "description", "", "new description")
	update.Flags().StringSliceVar(&uPerms, "permission", nil, "replace the permissions (repeatable or comma-separated)")
	update.Flags().StringSliceVar(&uAdd, "add", nil, "add permissions")
	update.Flags().StringSliceVar(&uRemove, "remove", nil, "remove permissions")
	update.Flags().StringVarP(&uFile, "from-file", "f", "", "role definition (YAML or JSON; - for stdin)")
	cmd.AddCommand(update)

	var yes bool
	rm := &cobra.Command{
		Use:     "rm ROLE",
		Aliases: []string{"delete"},
		Short:   "Delete a custom role (its members get the default role)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			r, err := c.FindRole(ctx, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Delete the role "+r.Key, yes); err != nil {
				return err
			}
			if err := c.DeleteRole(ctx, r.Key); err != nil {
				return err
			}
			app.Progress("Deleted role %s; its members now have the default role.", r.Key)
			return nil
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(rm)

	cmd.AddCommand(&cobra.Command{
		Use:     "default [ROLE]",
		Short:   "Show or set the role new members get",
		Example: "  runr roles default\n  runr roles default viewer",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			def := ""
			if len(args) == 0 {
				list, err := c.ListRoles(ctx)
				if err != nil {
					return err
				}
				def = list.DefaultRole
			} else {
				key, err := rbacRoleKey(ctx, c, args[0])
				if err != nil {
					return err
				}
				if def, err = c.SetDefaultRole(ctx, key); err != nil {
					return err
				}
				app.Progress("New members get the role %s.", def)
			}
			return app.Printer().Print(map[string]string{"default_role": def}, func(t *output.TableWriter) {
				t.KV("Default role", def)
			})
		},
	})

	cmd.AddCommand(&cobra.Command{
		Use:   "permissions",
		Short: "List the permission catalog (what roles and token scopes can contain)",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			cat, err := c.ListPermissions(cmd.Context())
			if err != nil {
				return err
			}
			return app.Printer().Print(cat, func(t *output.TableWriter) {
				t.Header("PERMISSION", "GROUP", "SCOPE", "IMPLIES")
				for _, p := range cat.Permissions {
					t.Row(p.Key, p.Group, p.Scope, joinOrDash(p.Implies))
				}
			})
		},
	})
	return cmd
}

// ---------------------------------------------------------------------------------------
// runr groups

func rbacGroupDetail(t *output.TableWriter, g *runr.Group) {
	t.KV("ID", g.ID)
	t.KV("Name", g.Name)
	if g.Description != "" {
		t.KV("Description", g.Description)
	}
	t.KV("Role", output.Dash(g.Role))
	grants := make([]string, 0, len(g.Projects))
	for _, p := range g.Projects {
		name := p.ProjectName
		if name == "" {
			name = p.ProjectID
		}
		grants = append(grants, name+" ("+p.Role+")")
	}
	t.KV("Projects", joinOrDash(grants))
	t.Header("MEMBER", "NAME", "EMAIL", "SOURCE")
	for _, m := range g.Members {
		t.Row(m.ID.String(), m.Name, m.Email, m.Source)
	}
}

// rbacMemberIDs resolves users (id, e-mail or username) to member ids.
func rbacMemberIDs(ctx context.Context, c *runr.Client, refs []string) ([]string, error) {
	list, err := c.ListMembers(ctx)
	if err != nil {
		return nil, err
	}
	ids := make([]string, 0, len(refs))
	for _, ref := range refs {
		m, err := runr.PickMember(list, ref)
		if err != nil {
			return nil, err
		}
		ids = append(ids, m.ID.String())
	}
	return ids, nil
}

func newGroupsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "groups",
		Aliases: []string{"group"},
		Short:   "Groups (teams): members get the group's role and project access",
		Long: "Members of a group get the group's organization-wide role and the project access granted to\n" +
			"the group (runr access grant --group). Members synced from single sign-on have source sso.",
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List groups",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListGroups(cmd.Context())
			if err != nil {
				return err
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("ID", "NAME", "ROLE", "MEMBERS", "PROJECTS")
				for _, g := range list {
					t.Row(g.ID, g.Name, output.Dash(g.Role), strconv.Itoa(g.MemberCount), strconv.Itoa(g.ProjectGrants))
				}
			})
		},
	}
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE

	cmd.AddCommand(&cobra.Command{
		Use:   "get GROUP",
		Short: "Show a group with its members and project access (id or name)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			g, err := c.FindGroup(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(g, func(t *output.TableWriter) { rbacGroupDetail(t, g) })
		},
	})

	var cDesc, cRole string
	create := &cobra.Command{
		Use:     "create NAME",
		Short:   "Create a group",
		Example: "  runr groups create platform-team --role developer",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			in := runr.GroupInput{Name: args[0]}
			if cmd.Flags().Changed("description") {
				in.Description = &cDesc
			}
			if cRole != "" {
				key, err := rbacRoleKey(ctx, c, cRole)
				if err != nil {
					return err
				}
				in.Role = &key
			}
			g, err := c.CreateGroup(ctx, in)
			if err != nil {
				return err
			}
			app.Progress("Created group %s (%s).", g.Name, g.ID)
			return app.Printer().Print(g, func(t *output.TableWriter) { rbacGroupDetail(t, g) })
		},
	}
	create.Flags().StringVar(&cDesc, "description", "", "description")
	create.Flags().StringVar(&cRole, "role", "", "organization-wide role of the members (key or name)")
	cmd.AddCommand(create)

	var uName, uDesc, uRole string
	update := &cobra.Command{
		Use:     "update GROUP",
		Short:   "Rename a group, change its description or role (--role \"\" removes the role)",
		Example: "  runr groups update platform-team --role viewer",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			fl := cmd.Flags()
			if !fl.Changed("name") && !fl.Changed("description") && !fl.Changed("role") {
				return usageErrorf("give --name, --description or --role")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			g, err := c.FindGroup(ctx, args[0])
			if err != nil {
				return err
			}
			in := runr.GroupInput{}
			if fl.Changed("name") {
				in.Name = uName
			}
			if fl.Changed("description") {
				in.Description = &uDesc
			}
			if fl.Changed("role") {
				key := ""
				if uRole != "" {
					if key, err = rbacRoleKey(ctx, c, uRole); err != nil {
						return err
					}
				}
				in.Role = &key
			}
			g, err = c.UpdateGroup(ctx, g.ID, in)
			if err != nil {
				return err
			}
			app.Progress("Updated group %s.", g.Name)
			return app.Printer().Print(g, func(t *output.TableWriter) { rbacGroupDetail(t, g) })
		},
	}
	update.Flags().StringVar(&uName, "name", "", "new name")
	update.Flags().StringVar(&uDesc, "description", "", "new description")
	update.Flags().StringVar(&uRole, "role", "", "organization-wide role (key or name; \"\" removes it)")
	cmd.AddCommand(update)

	var yes bool
	rm := &cobra.Command{
		Use:     "rm GROUP",
		Aliases: []string{"delete"},
		Short:   "Delete a group (its members lose the group's role and project access)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			g, err := c.FindGroup(ctx, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Delete the group "+g.Name, yes); err != nil {
				return err
			}
			if err := c.DeleteGroup(ctx, g.ID); err != nil {
				return err
			}
			app.Progress("Deleted group %s.", g.Name)
			return nil
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(rm)

	cmd.AddCommand(&cobra.Command{
		Use:     "add-member GROUP USER...",
		Short:   "Add members to a group (id, e-mail or username)",
		Example: "  runr groups add-member platform-team grace@example.com alan",
		Args:    cobra.MinimumNArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			g, err := c.FindGroup(ctx, args[0])
			if err != nil {
				return err
			}
			ids, err := rbacMemberIDs(ctx, c, args[1:])
			if err != nil {
				return err
			}
			g, err = c.AddGroupMembers(ctx, g.ID, ids)
			if err != nil {
				return err
			}
			app.Progress("%s has %s.", g.Name, countLabel(len(g.Members), "member"))
			return app.Printer().Print(g, func(t *output.TableWriter) { rbacGroupDetail(t, g) })
		},
	})

	cmd.AddCommand(&cobra.Command{
		Use:     "rm-member GROUP USER...",
		Aliases: []string{"remove-member"},
		Short:   "Remove members from a group",
		Args:    cobra.MinimumNArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			g, err := c.FindGroup(ctx, args[0])
			if err != nil {
				return err
			}
			ids, err := rbacMemberIDs(ctx, c, args[1:])
			if err != nil {
				return err
			}
			for _, id := range ids {
				if g, err = c.RemoveGroupMember(ctx, g.ID, id); err != nil {
					return err
				}
			}
			app.Progress("%s has %s.", g.Name, countLabel(len(g.Members), "member"))
			return app.Printer().Print(g, func(t *output.TableWriter) { rbacGroupDetail(t, g) })
		},
	})
	return cmd
}

// ---------------------------------------------------------------------------------------
// runr access

func rbacGrantSubject(g runr.AccessGrant) string {
	switch {
	case g.SubjectEmail != "":
		return g.SubjectEmail
	case g.SubjectName != "":
		return g.SubjectName
	}
	return g.SubjectID.String()
}

func newAccessCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "access",
		Short: "Project access: a role for a user or group on one project",
		Long: "Project access gives a user or group a role on the project of -p / runr.yaml only; the\n" +
			"role's project-scoped permissions apply there. Organization-wide roles cover every project\n" +
			"already. Changing access needs projects.manage_access on the project.",
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List who has access to the project",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			list, canManage, err := c.ListAccess(cmd.Context(), p.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(map[string]any{"project": p.Name, "grants": list, "can_manage": canManage}, func(t *output.TableWriter) {
				t.Header("ID", "TYPE", "SUBJECT", "ROLE")
				for _, g := range list {
					t.Row(g.ID, g.SubjectType, rbacGrantSubject(g), g.Role)
				}
				if len(list) == 0 {
					t.Note("No project access grants on %s; organization-wide roles apply.", p.Name)
				}
			})
		},
	}
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE

	var group, role string
	grant := &cobra.Command{
		Use:   "grant [USER]",
		Short: "Give a user or a group (--group) a role on the project",
		Long: "A user or group that has access already gets the new role. The Owner role is given to\n" +
			"members directly only (runr members set-role).",
		Example: "  runr access grant grace@example.com --role developer -p orders-api\n" +
			"  runr access grant --group platform-team --role viewer",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if (len(args) == 1) == (group != "") {
				return usageErrorf("give either a USER or --group")
			}
			if role == "" {
				return usageErrorf("give --role")
			}
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			key, err := rbacRoleKey(ctx, c, role)
			if err != nil {
				return err
			}
			subjectType, subjectID, label := runr.SubjectUser, "", ""
			if group != "" {
				g, err := c.FindGroup(ctx, group)
				if err != nil {
					return err
				}
				subjectType, subjectID, label = runr.SubjectGroup, g.ID, "group "+g.Name
			} else {
				m, err := c.FindMember(ctx, args[0])
				if err != nil {
					return err
				}
				subjectID, label = m.ID.String(), m.Email
			}
			g, err := c.GrantAccess(ctx, p.ID, subjectType, subjectID, key)
			if err != nil {
				return err
			}
			app.Progress("%s has the role %s on %s.", label, g.Role, p.Name)
			return app.Printer().Print(g, func(*output.TableWriter) {})
		},
	}
	grant.Flags().StringVar(&group, "group", "", "group (id or name) instead of a user")
	grant.Flags().StringVar(&role, "role", "", "role on the project (key or name)")
	cmd.AddCommand(grant)

	var rGroup string
	var yes bool
	revoke := &cobra.Command{
		Use:     "revoke [GRANT|USER]",
		Aliases: []string{"rm"},
		Short:   "Remove a grant: by its id, a user, or a group (--group)",
		Example: "  runr access revoke grace@example.com --yes\n" +
			"  runr access revoke --group platform-team --yes",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if (len(args) == 1) == (rGroup != "") {
				return usageErrorf("give either a GRANT id or USER, or --group")
			}
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			list, _, err := c.ListAccess(ctx, p.ID)
			if err != nil {
				return err
			}
			var target *runr.AccessGrant
			ref := ""
			if rGroup != "" {
				g, err := c.FindGroup(ctx, rGroup)
				if err != nil {
					return err
				}
				ref = g.Name
				for i := range list {
					if list[i].SubjectType == runr.SubjectGroup && list[i].SubjectID.String() == g.ID {
						target = &list[i]
					}
				}
			} else {
				ref = args[0]
				for i := range list {
					if list[i].ID == ref {
						target = &list[i]
					}
				}
				if target == nil {
					m, err := c.FindMember(ctx, ref)
					if err != nil {
						return err
					}
					for i := range list {
						if list[i].SubjectType == runr.SubjectUser && list[i].SubjectID.String() == m.ID.String() {
							target = &list[i]
						}
					}
				}
			}
			if target == nil {
				return &runr.NotFoundError{Kind: "access grant on " + p.Name + " for", Ref: ref}
			}
			if err := app.confirm(fmt.Sprintf("Remove the %s access of %s on %s", target.Role, rbacGrantSubject(*target), p.Name), yes); err != nil {
				return err
			}
			if err := c.RevokeAccess(ctx, p.ID, target.ID); err != nil {
				return err
			}
			app.Progress("Removed the access of %s on %s.", rbacGrantSubject(*target), p.Name)
			return nil
		},
	}
	revoke.Flags().StringVar(&rGroup, "group", "", "group (id or name) instead of a user")
	revoke.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(revoke)
	return cmd
}
