package cmd

import (
	"context"
	"errors"
	"fmt"
	"sort"
	"strings"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// `runr projects global`: the regions of a global project (GlobalProjectController,
// docs/global-projects.md). A global project is created by `runr deploy` from runr.yaml's
// global: block (or --global-regions); these commands show and change its regions.

var globalModeTexts = map[string]string{
	runr.GlobalModeHA:        "the primary region answers; the others when its copy is unhealthy",
	runr.GlobalModeProximity: "the healthy region closest to the client answers",
}

var globalFailoverTexts = map[string]string{
	runr.GlobalFailoverAutomatic: "RunR moves the primary when its health check fails",
	runr.GlobalFailoverManual:    "the primary stays until an operator moves it",
}

func newProjectGlobalCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "global [PROJECT]",
		Short: "A global project's regions, their status and its global host name",
		Long: "A global project runs in several regions behind one global host name <slug>.<zone>.\n" +
			"The primary region builds and deploys first; RunR rolls the version out to the other\n" +
			"regions when it is ready. The zone's DNS answers with the regions whose copy is healthy:\n" +
			"the primary first (mode ha) or the closest one (mode proximity). Schedules, queues and event\n" +
			"sources run in the primary only; a failover of the primary (automatic, or runr projects global\n" +
			"failover) moves them with its data. Create one with global: in runr.yaml (or runr deploy\n" +
			"--global-regions). Reading needs projects.view; set and failover need projects.update, sync\n" +
			"projects.deploy.",
		Example: "  runr projects global shop\n" +
			"  runr projects global set shop --regions eu-1,us-1,ap-1\n" +
			"  runr projects global set shop --primary us-1 --mode proximity\n" +
			"  runr projects global set shop --failover manual\n" +
			"  runr projects global failover shop --region us-1\n" +
			"  runr projects global sync shop",
		Args: cobra.MaximumNArgs(1),
	}
	show := &cobra.Command{
		Use:     "show [PROJECT]",
		Aliases: []string{"get", "status"},
		Short:   "Show the regions of a global project, their status and health",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := globalProject(ctx, app, args)
			if err != nil {
				return err
			}
			g, err := projectGlobal(ctx, c, p)
			if err != nil {
				return err
			}
			return printProjectGlobal(app, p, g)
		},
	}
	cmd.AddCommand(show)
	cmd.RunE = show.RunE
	cmd.AddCommand(newProjectGlobalSetCmd(app), newProjectGlobalSyncCmd(app), newProjectGlobalFailoverCmd(app))
	return cmd
}

// globalProject resolves the PROJECT argument (default: the project of runr.yaml).
func globalProject(ctx context.Context, app *App, args []string) (*runr.Client, *runr.Project, error) {
	if len(args) == 1 {
		app.ProjectRef = args[0]
	}
	return app.Project(ctx)
}

// projectGlobal reads the regions of a global project; a regional project, or an API without
// global projects, is an error that says so.
func projectGlobal(ctx context.Context, c *runr.Client, p *runr.Project) (*runr.ProjectGlobal, error) {
	if p.Placement == runr.PlacementRegional {
		return nil, notGlobalError(p)
	}
	g, err := c.ProjectGlobal(ctx, p.ID)
	if runr.IsNotFound(err) {
		if p.Placement == "" {
			return nil, fmt.Errorf("this RunR API has no global projects: %w", runr.ErrUnsupported)
		}
		return nil, notGlobalError(p)
	}
	return g, err
}

func notGlobalError(p *runr.Project) error {
	return fmt.Errorf("project %s is a regional project (region %s); a global project is created with global: in runr.yaml",
		p.Name, output.Dash(p.RegionName()))
}

func globalVersion(r runr.GlobalRegion) string {
	v := "-"
	if r.Version != nil {
		v = fmt.Sprintf("v%d", *r.Version)
	}
	if r.TargetVersion != nil && (r.Version == nil || *r.TargetVersion != *r.Version) {
		v += fmt.Sprintf(" → v%d", *r.TargetVersion)
	}
	return v
}

func globalHealth(r runr.GlobalRegion) string {
	if r.Healthy == nil {
		return "-"
	}
	h := yesNoWord(*r.Healthy)
	if r.HealthCheckedAt != nil {
		h += " (" + output.Age(r.HealthCheckedAt) + " ago)"
	}
	return h
}

func printProjectGlobal(app *App, p *runr.Project, g *runr.ProjectGlobal) error {
	return app.Printer().Print(g, func(t *output.TableWriter) {
		t.KV("Project", p.Name)
		mode := output.Dash(g.Mode)
		if text := globalModeTexts[g.Mode]; text != "" {
			mode += " (" + text + ")"
		}
		t.KV("Mode", mode)
		if g.Hostname != "" {
			t.KV("Host name", g.Hostname)
			t.KV("URL", "https://"+g.Hostname)
		} else {
			t.KV("Host name", "- (the platform has no base domain yet)")
		}
		if primary := g.Primary(); primary != nil {
			t.KV("Primary", primary.RegionName)
		}
		if g.Failover != "" {
			failover := g.Failover
			if text := globalFailoverTexts[g.Failover]; text != "" {
				failover += " (" + text
				if g.Failover == runr.GlobalFailoverAutomatic && g.FailoverAfterSeconds > 0 {
					failover += fmt.Sprintf(" for %ds", g.FailoverAfterSeconds)
				}
				failover += ")"
			}
			t.KV("Failover", failover)
		}
		if g.MovingTo != "" {
			t.KV("Moving", "the primary moves to "+globalRegionName(g, g.MovingTo))
		}
		inDNS := 0
		for _, r := range g.Regions {
			if r.InDNS {
				inDNS++
			}
		}
		t.KV("In DNS", fmt.Sprintf("%d of %d regions", inDNS, len(g.Regions)))
		t.Header("REGION", "ROLE", "STATUS", "VERSION", "HEALTHY", "IN DNS", "TRIGGERS", "DATA", "ADDRESS", "MESSAGE")
		regions := append([]runr.GlobalRegion{}, g.Regions...)
		sort.SliceStable(regions, func(i, j int) bool { return regions[i].Position < regions[j].Position })
		for _, r := range regions {
			triggers := "-"
			if r.TriggersActive {
				triggers = "here"
			}
			message := r.StatusMessage
			if message == "" {
				message = r.DataMessage
			}
			t.Row(r.RegionName, r.Role, r.Status, globalVersion(r), globalHealth(r), yesNoWord(r.InDNS),
				triggers, output.Dash(r.DataStatus), output.Dash(r.Address), output.Truncate(output.Dash(message), 80))
		}
		// the bindings: how each region reaches them (one table per writer: key-value lines)
		for _, b := range g.Bindings {
			t.KV("Binding "+b.Name, globalBindingLine(b))
		}
		for _, w := range g.Warnings {
			t.Note("Warning: %s", w)
		}
	})
}

// globalBindingLine is one binding of a global project: its type, home and how every region
// reaches it, e.g. "postgres (dedicated), home eu-1, replicas: us-1 replica ready (lag 2s)".
func globalBindingLine(b runr.GlobalBindingData) string {
	kind := b.Type
	if b.Plan != "" && b.Plan != b.Type && b.Plan != "shared" && b.Plan != "external" {
		kind += " (" + b.Plan + ")"
	}
	line := kind
	if b.HomeRegionName != "" {
		line += ", home " + b.HomeRegionName
	}
	line += ", " + output.Dash(b.Access)
	var regions []string
	for _, r := range b.Regions {
		if r.Kind == "home" {
			continue
		}
		part := r.RegionName + " " + r.Kind + " " + r.Status
		if r.LagSeconds != nil {
			part += fmt.Sprintf(" (lag %ds)", *r.LagSeconds)
		}
		if r.StatusMessage != "" && r.Status != "ready" {
			part += ": " + output.Truncate(r.StatusMessage, 60)
		}
		regions = append(regions, part)
	}
	if len(regions) > 0 {
		line += ": " + strings.Join(regions, "; ")
	}
	return line
}

// globalRegionName is the name of a region of the project (its id when it is not listed).
func globalRegionName(g *runr.ProjectGlobal, regionID string) string {
	for _, r := range g.Regions {
		if r.RegionID == regionID {
			return r.RegionName
		}
	}
	return regionID
}

func newProjectGlobalSetCmd(app *App) *cobra.Command {
	var mode, primary, failover string
	var regions []string
	var yes bool
	cmd := &cobra.Command{
		Use:   "set [PROJECT]",
		Short: "Change a global project's mode, failover, primary region or regions (projects.update)",
		Long: "--regions is the complete new list (the primary included): a new region gets the active\n" +
			"version, a region left out has its copy torn down (asks for confirmation; --yes skips it).\n" +
			"--primary must name a region whose copy is ready: a switchover — the replica clusters of\n" +
			"dedicated Postgres bindings are promoted without losing a write, the triggers move with the\n" +
			"primary. --mode is ha or proximity. --failover automatic moves the primary to the next\n" +
			"healthy region when its health check fails long enough; manual leaves that to an operator\n" +
			"(runr projects global failover).",
		Example: "  runr projects global set shop --regions eu-1,us-1,ap-1\n" +
			"  runr projects global set shop --primary us-1\n" +
			"  runr projects global set shop --mode proximity\n" +
			"  runr projects global set shop --failover manual",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if mode == "" && primary == "" && failover == "" && len(regions) == 0 {
				return usageErrorf("nothing to change: pass --mode, --failover, --primary or --regions")
			}
			var in runr.GlobalUpdateInput
			if failover != "" {
				failover = strings.ToLower(strings.TrimSpace(failover))
				if globalFailoverTexts[failover] == "" {
					return usageErrorf("unknown failover %q (%s)", failover, strings.Join(runr.GlobalFailovers, " or "))
				}
				in.Failover = &failover
			}
			if mode != "" {
				mode = strings.ToLower(strings.TrimSpace(mode))
				if globalModeTexts[mode] == "" {
					return usageErrorf("unknown global mode %q (%s)", mode, strings.Join(runr.GlobalModes, " or "))
				}
				in.Mode = &mode
			}
			ctx := cmd.Context()
			c, p, err := globalProject(ctx, app, args)
			if err != nil {
				return err
			}
			cur, err := projectGlobal(ctx, c, p)
			if err != nil {
				return err
			}
			if len(regions) > 0 {
				var refs []string
				for _, r := range regions {
					if r = strings.TrimSpace(r); r != "" {
						refs = append(refs, r)
					}
				}
				if len(refs) < 2 {
					return usageErrorf("a global project runs in at least 2 regions")
				}
				resolved, err := resolveGlobalRegions(ctx, c, refs)
				if err != nil {
					return err
				}
				keep := map[string]bool{}
				for _, r := range resolved {
					in.RegionIDs = append(in.RegionIDs, r.ID)
					keep[r.ID] = true
				}
				var removed []string
				for _, r := range cur.Regions {
					if !keep[r.RegionID] {
						removed = append(removed, r.RegionName)
					}
				}
				if len(removed) > 0 {
					question := fmt.Sprintf("Remove %s from global project %s: its copy there is torn down", strings.Join(removed, ", "), p.Name)
					if err := app.confirm(question, yes); err != nil {
						return err
					}
				}
			}
			if primary != "" {
				reg, err := c.FindRegion(ctx, primary)
				if err != nil {
					return err
				}
				in.PrimaryRegionID = &reg.ID
			}
			g, err := c.UpdateProjectGlobal(ctx, p.ID, in)
			if err != nil {
				return err
			}
			app.Progress("Global project %s updated.", p.Name)
			return printProjectGlobal(app, p, g)
		},
	}
	cmd.Flags().StringVar(&mode, "mode", "", "ha or proximity")
	cmd.Flags().StringVar(&failover, "failover", "", "automatic or manual")
	cmd.Flags().StringVar(&primary, "primary", "", "the new primary region (name or id; its copy must be ready)")
	cmd.Flags().StringSliceVar(&regions, "regions", nil, "the complete region list, comma-separated (names or ids)")
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask before a region's copy is torn down")
	return cmd
}

func newProjectGlobalFailoverCmd(app *App) *cobra.Command {
	var region string
	var yes bool
	cmd := &cobra.Command{
		Use:   "failover [PROJECT]",
		Short: "Move the primary of a global project whose primary does not answer (projects.update)",
		Long: "For a primary region that does not answer: the target region (--region, else the first\n" +
			"healthy region in HA order) becomes the primary at once — its replica clusters are\n" +
			"promoted without waiting for the old primary, so writes after their last received WAL are\n" +
			"lost — and the triggers move there. The old primary is fenced: out of the DNS answers until\n" +
			"its cluster answers again, then its data is rebuilt from the new primary and it serves as a\n" +
			"secondary. A primary that answers moves without loss with runr projects global set\n" +
			"--primary. Asks for confirmation (--yes skips it); the move runs in the background.",
		Example: "  runr projects global failover shop\n" +
			"  runr projects global failover shop --region us-1 --yes",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := globalProject(ctx, app, args)
			if err != nil {
				return err
			}
			cur, err := projectGlobal(ctx, c, p)
			if err != nil {
				return err
			}
			regionID, target := "", "the first healthy region"
			if region != "" {
				reg, err := c.FindRegion(ctx, region)
				if err != nil {
					return err
				}
				regionID, target = reg.ID, reg.Name
			}
			from := "the primary"
			if primary := cur.Primary(); primary != nil {
				from = primary.RegionName
			}
			question := fmt.Sprintf("Fail global project %s over from %s to %s: writes after the last WAL its replicas received are lost, %s is fenced until it rejoins",
				p.Name, from, target, from)
			if err := app.confirm(question, yes); err != nil {
				return err
			}
			g, err := c.FailoverProjectGlobal(ctx, p.ID, regionID)
			if err != nil {
				return err
			}
			if g.MovingTo != "" {
				app.Progress("Failing %s over to %s in the background.", p.Name, globalRegionName(g, g.MovingTo))
			} else {
				app.Progress("Global project %s failed over.", p.Name)
			}
			return printProjectGlobal(app, p, g)
		},
	}
	cmd.Flags().StringVar(&region, "region", "", "the region that becomes the primary (name or id; default: the first healthy one)")
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}

func newProjectGlobalSyncCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "sync [PROJECT]",
		Short: "Roll the active version out again to the regions that are not ready (projects.deploy)",
		Long: "Rolls the project's active version out again to every region whose copy is not ready\n" +
			"(failed, or never deployed) and applies the global routes again. It runs in the\n" +
			"background: runr projects global shows the progress.",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := globalProject(ctx, app, args)
			if err != nil {
				return err
			}
			if p.Placement == runr.PlacementRegional {
				return notGlobalError(p)
			}
			err = c.SyncProjectGlobal(ctx, p.ID)
			if runr.IsNotFound(err) {
				_, err = projectGlobal(ctx, c, p)
				if err == nil {
					err = errors.New("the API did not find the project's global configuration")
				}
			}
			if err != nil {
				return err
			}
			app.Progress("Rolling the active version of %s out to the regions that are not ready.", p.Name)
			return app.Printer().Print(map[string]any{"queued": true}, func(t *output.TableWriter) {
				t.Note("runr projects global %s shows the progress.", p.Name)
			})
		},
	}
}
