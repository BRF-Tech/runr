package cmd

import (
	"context"
	"sort"
	"strings"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/internal/projectfile"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// newNetworkCmd: a project's network (docs/internal-network.md) — its access mode and the
// projects allowed to call it on the region's internal network.
func newNetworkCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "network",
		Short: "Access mode and internal callers of a project",
		Long: "Who reaches a project: from outside the cluster (access mode public, allowlist or internal)\n" +
			"and from other projects of the region (the allow list of its internal callers).\n" +
			"A project's own pods are known by their address; a caller elsewhere sends the\n" +
			"project's RUNR_INTERNAL_TOKEN in the X-Runr-Internal-Token header.",
	}
	show := &cobra.Command{
		Use:   "show",
		Short: "Show the access mode, the internal URL and the allowed callers",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			n, err := c.GetNetwork(cmd.Context(), p.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(n, func(t *output.TableWriter) { networkTable(t, n) })
		},
	}
	cmd.AddCommand(show)
	cmd.RunE = show.RunE

	var access string
	var cidrs []string
	set := &cobra.Command{
		Use:   "set",
		Short: "Set the access mode: public, allowlist (with --allow-cidr) or internal",
		Example: "  runr network set --access internal\n" +
			"  runr network set --access allowlist --allow-cidr 203.0.113.0/24 --allow-cidr 198.51.100.7",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			switch access {
			case runr.AccessPublic, runr.AccessInternal:
				if len(cidrs) > 0 {
					return usageErrorf("--allow-cidr applies to --access allowlist")
				}
			case runr.AccessAllowlist:
				if len(cidrs) == 0 {
					return usageErrorf("--access allowlist needs at least one --allow-cidr")
				}
			default:
				return usageErrorf("--access is public, allowlist or internal")
			}
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			n, err := c.SetNetwork(cmd.Context(), p.ID, access, cidrs)
			if err != nil {
				return err
			}
			app.Progress("Access mode of %s: %s", p.Name, n.AccessMode)
			return app.Printer().Print(n, func(t *output.TableWriter) { networkTable(t, n) })
		},
	}
	set.Flags().StringVar(&access, "access", "", "public, allowlist or internal")
	set.Flags().StringArrayVar(&cidrs, "allow-cidr", nil, "an IP address or CIDR range the public URL answers (repeatable; --access allowlist)")
	cmd.AddCommand(set)
	cmd.AddCommand(newNetworkCallersCmd(app))
	return cmd
}

func newNetworkCallersCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "callers",
		Aliases: []string{"caller"},
		Short:   "The projects allowed to call this one on the internal network",
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the allowed callers",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			n, err := c.GetNetwork(cmd.Context(), p.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(map[string]any{"callers": n.Callers}, func(t *output.TableWriter) {
				t.Header("CALLER", "ORGANIZATION", "GRANT")
				for _, cl := range n.Callers {
					t.Row(cl.Caller, cl.Organization, map[bool]string{true: "other organization", false: ""}[cl.CrossTenant])
				}
				if len(n.Callers) == 0 {
					t.Note("No project may call %s yet; it calls itself only.", n.InternalName)
				}
			})
		},
	}
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE
	cmd.AddCommand(&cobra.Command{
		Use:   "add CALLER",
		Short: "Allow a project to call this one (a project name, or <project>.<organization>)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			cl, err := c.AddNetworkCaller(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			if cl.CrossTenant {
				app.Progress("%s (organization %s) may call %s: a grant to another organization.", cl.Caller, cl.Organization, p.Name)
			} else {
				app.Progress("%s may call %s.", cl.Caller, p.Name)
			}
			return app.Printer().Print(cl, func(t *output.TableWriter) {
				t.KV("Caller", cl.Caller)
				t.KV("Organization", cl.Organization)
			})
		},
	})
	var yes bool
	rm := &cobra.Command{
		Use:     "rm CALLER",
		Aliases: []string{"remove", "delete"},
		Short:   "Stop a project from calling this one",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			caller := strings.ToLower(strings.TrimSpace(args[0]))
			if !strings.Contains(caller, ".") {
				n, err := c.GetNetwork(cmd.Context(), p.ID)
				if err != nil {
					return err
				}
				caller += "." + n.Organization
			}
			if err := app.confirm("Stop "+caller+" from calling "+p.Name, yes); err != nil {
				return err
			}
			if err := c.RemoveNetworkCaller(cmd.Context(), p.ID, caller); err != nil {
				return err
			}
			app.Progress("%s may no longer call %s.", caller, p.Name)
			return nil
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(rm)
	return cmd
}

func networkTable(t *output.TableWriter, n *runr.Network) {
	t.KV("Access", n.AccessMode)
	if n.AccessMode == runr.AccessAllowlist {
		t.KV("Allowed ranges", strings.Join(n.AllowedCIDRs, ", "))
	}
	t.KV("Internal name", n.InternalName)
	t.KV("Internal URL", n.InternalURL)
	callers := make([]string, 0, len(n.Callers))
	for _, cl := range n.Callers {
		callers = append(callers, cl.Caller)
	}
	if len(callers) == 0 {
		callers = []string{"(none: the project calls itself only)"}
	}
	t.KV("Allowed callers", strings.Join(callers, ", "))
	if n.Enforced != nil && !*n.Enforced {
		t.Note("An operator set the region to audit the internal network: calls the allow list refuses are admitted and logged.")
	}
	t.Note("A project's own pods are known by their address; a caller elsewhere sends the project's %s in the %s header.", n.TokenEnv, n.TokenHeader)
}

// syncNetwork brings the project's network to runr.yaml's network section: the access mode
// (with its ranges) and, when callers is given, the allow list.
func syncNetwork(ctx context.Context, app *App, c *runr.Client, p *runr.Project, pf *projectfile.File) error {
	if pf == nil || pf.Network == nil {
		return nil
	}
	want := pf.Network
	n, err := c.GetNetwork(ctx, p.ID)
	if err != nil {
		return err
	}
	if want.Access != "" && (want.Access != n.AccessMode || (want.Access == runr.AccessAllowlist && !sameSet(want.AllowCIDRs, n.AllowedCIDRs))) {
		app.Progress("Access mode of %s: %s", p.Name, want.Access)
		if n, err = c.SetNetwork(ctx, p.ID, want.Access, want.AllowCIDRs); err != nil {
			return err
		}
	}
	if want.Callers == nil {
		return nil
	}
	have := map[string]bool{}
	for _, cl := range n.Callers {
		have[cl.Caller] = true
	}
	wanted := map[string]bool{}
	for _, raw := range want.Callers {
		name := strings.ToLower(strings.TrimSpace(raw))
		if !strings.Contains(name, ".") {
			name += "." + n.Organization
		}
		wanted[name] = true
		if !have[name] {
			app.Progress("Allowing %s to call %s", name, p.Name)
			if _, err := c.AddNetworkCaller(ctx, p.ID, name); err != nil {
				return err
			}
		}
	}
	for name := range have {
		if !wanted[name] {
			app.Progress("Removing %s from the callers of %s", name, p.Name)
			if err := c.RemoveNetworkCaller(ctx, p.ID, name); err != nil {
				return err
			}
		}
	}
	return nil
}

func sameSet(a, b []string) bool {
	if len(a) != len(b) {
		return false
	}
	x := append([]string(nil), a...)
	y := append([]string(nil), b...)
	sort.Strings(x)
	sort.Strings(y)
	for i := range x {
		if x[i] != y[i] {
			return false
		}
	}
	return true
}
