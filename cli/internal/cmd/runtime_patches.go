package cmd

import (
	"fmt"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Runtime patches (docs/runtimes.md#runtime-patches): why each patch of a project's runtime
// version came (level, reason, CVEs, link, deadline), and what to do with it — apply the newest,
// pin one of the newest five, or wait until a critical / zero-day deadline moves the project on.
//
//	runr projects patches [PROJECT]
//	runr projects patch apply [PROJECT] [--wait]
//	runr projects patch pin [PROJECT] PATCH [--no-deploy]
//	runr projects patch unpin [PROJECT]
//	runr runtimes patches [--runtime R] [--version V]

// addProjectPatchCommands adds `patches` and `patch` to `runr projects`.
func addProjectPatchCommands(app *App, projects *cobra.Command) {
	projects.AddCommand(&cobra.Command{
		Use:   "patches [PROJECT]",
		Short: "Show a project's runtime patch state and the newest patches of its runtime version",
		Example: "  runr projects patches orders\n" +
			"  runr projects patches -o json   # the project of runr.yaml",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) == 1 {
				app.ProjectRef = args[0]
			}
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			rp, err := c.ProjectRuntimePatches(cmd.Context(), p.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(rp, func(t *output.TableWriter) { projectPatches(t, p, rp) })
		},
	})

	patch := &cobra.Command{
		Use:   "patch",
		Short: "Apply the newest runtime patch to a project, or pin / unpin one",
	}

	var wait bool
	var timeout time.Duration
	apply := &cobra.Command{
		Use:   "apply [PROJECT]",
		Short: "Redeploy the live version onto the newest runtime patch (a pin is removed)",
		Long: "Redeploys the live version of a project onto the newest patch of its runtime version.\n" +
			"A version built into an image (dependencies, compiled runtimes) is built again on the\n" +
			"patch first. Without --wait the command returns once the redeploy started.",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) == 1 {
				app.ProjectRef = args[0]
			}
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			m, msg, err := c.ApplyRuntimePatch(ctx, p.ID)
			if err != nil {
				return err
			}
			if msg != "" {
				app.Progress("%s", msg)
			}
			res := patchActionResult{Migration: m}
			if wait {
				d, err := c.WaitRuntimePatchMigration(ctx, p.ID, m, app.waitOptions(timeout, "Patch migration"))
				res.Deployment = d
				if err != nil {
					return err
				}
				app.Progress("%s runs the newest runtime patch.", p.Name)
			}
			return app.Printer().Print(res, func(t *output.TableWriter) { patchActionDetail(t, res) })
		},
	}
	apply.Flags().BoolVar(&wait, "wait", false, "wait until the redeploy is live (or failed)")
	apply.Flags().DurationVar(&timeout, "timeout", 15*time.Minute, "how long to wait (a prebuilt version is built again first)")

	var noDeploy bool
	pin := &cobra.Command{
		Use:   "pin [PROJECT] PATCH",
		Short: "Pin a project to one of the newest patches of its runtime version (id or tag)",
		Long: "Pins a project to one of the newest patches of its runtime version and redeploys the live\n" +
			"version onto it (--no-deploy: the next deploy uses it). A pin waits only until a critical or\n" +
			"zero-day patch's deadline: then RunR moves the project to the newest patch.",
		Example: "  runr projects patch pin orders 8.3.35-r2\n" +
			"  runr projects patch pin php-8.3-8.3.35-r2 --no-deploy   # the project of runr.yaml",
		Args: cobra.RangeArgs(1, 2),
		RunE: func(cmd *cobra.Command, args []string) error {
			ref := args[len(args)-1]
			if len(args) == 2 {
				app.ProjectRef = args[0]
			}
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			rp, err := c.ProjectRuntimePatches(ctx, p.ID)
			if err != nil {
				return err
			}
			target := rp.Find(ref)
			if target == nil {
				tags := make([]string, 0, len(rp.Patches))
				for _, x := range rp.Patches {
					tags = append(tags, x.Tag)
				}
				return usageErrorf("%q is not one of the patches %s can be pinned to: %s", ref, p.Name, joinOrDash(tags))
			}
			state, m, err := c.PinRuntimePatch(ctx, p.ID, target.ID, !noDeploy)
			if err != nil {
				return err
			}
			if m != nil {
				app.Progress("Pinned %s to %s; redeploying the live version onto it.", p.Name, target.Tag)
			} else {
				app.Progress("Pinned %s to %s; the next deploy uses it.", p.Name, target.Tag)
			}
			res := patchActionResult{State: state, Migration: m}
			return app.Printer().Print(res, func(t *output.TableWriter) { patchActionDetail(t, res) })
		},
	}
	pin.Flags().BoolVar(&noDeploy, "no-deploy", false, "only pin; the next deploy uses the patch")

	unpin := &cobra.Command{
		Use:   "unpin [PROJECT]",
		Short: "Remove a project's pin: its next deploy uses the newest runtime patch",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) == 1 {
				app.ProjectRef = args[0]
			}
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			state, err := c.UnpinRuntimePatch(cmd.Context(), p.ID)
			if err != nil {
				return err
			}
			app.Progress("Unpinned %s: the next deploy uses the newest runtime patch.", p.Name)
			res := patchActionResult{State: state}
			return app.Printer().Print(res, func(t *output.TableWriter) { patchActionDetail(t, res) })
		},
	}

	patch.AddCommand(apply, pin, unpin)
	projects.AddCommand(patch)
}

// patchActionResult is the answer of patch apply / pin / unpin.
type patchActionResult struct {
	State      *runr.RuntimePatchState     `json:"state,omitempty"`
	Migration  *runr.RuntimePatchMigration `json:"migration,omitempty"`
	Deployment *runr.Deployment            `json:"deployment,omitempty"`
}

func patchActionDetail(t *output.TableWriter, res patchActionResult) {
	if res.State != nil {
		t.KV("Runtime patch", patchStateLabel(res.State))
	}
	if m := res.Migration; m != nil {
		t.KV("Migration", m.ID+" ("+m.Trigger+", "+m.Status+")")
		if m.PatchID != "" {
			t.KV("Patch", m.PatchID)
		}
		switch {
		case res.Deployment != nil:
			t.KV("Deployment", res.Deployment.ID+" "+res.Deployment.Status)
		case m.DeploymentID != "":
			t.Note("Deployment %s started. Follow it with `runr status`.", m.DeploymentID)
		case m.QueuedDeployID != "":
			t.Note("The version is built again on the patch first (queued deploy %s); `runr projects patches` follows the migration.", m.QueuedDeployID)
		}
	}
}

// projectPatches is the table of `runr projects patches`.
func projectPatches(t *output.TableWriter, p *runr.Project, rp *runr.ProjectRuntimePatches) {
	if rp.State == nil {
		t.KV("Project", p.Name)
		t.Note("No runtime patch concerns %s (a CaaS project, or its runtime version has no patches).", p.Name)
		return
	}
	t.KV("Project", p.Name+" ("+runtimeLabel(p)+")")
	t.KV("Runtime patch", patchStateLabel(rp.State))
	t.KV("Running", output.Dash(rp.State.Current.Label()))
	t.KV("Newest", output.Dash(rp.State.Latest.Label()))
	if rp.State.DeadlineAt != "" {
		auto := "RunR redeploys the project onto the newest patch then"
		if !rp.AutoMigrate {
			auto = "automatic migration is off"
		}
		t.KV("Deadline", patchTime(rp.State.DeadlineAt)+" ("+auto+")")
	}
	t.Header("", "TAG", "LEVEL", "PUBLISHED", "DEADLINE", "CVES", "REASON")
	for _, x := range rp.Patches {
		mark := ""
		switch {
		case rp.State.Current != nil && rp.State.Current.ID == x.ID:
			mark = "*"
		case rp.State.Pin != nil && rp.State.Pin.ID == x.ID:
			mark = "p"
		}
		t.Row(mark, x.Tag, x.Level, gitAge(x.PublishedAt), output.Dash(patchTime(x.DeadlineAt)), joinOrDash(x.CVEs), output.Truncate(output.Dash(x.Reason), 60))
	}
	for _, x := range rp.Patches {
		if x.URL != "" {
			t.Note("%s: %s", x.Tag, x.URL)
		}
	}
	for _, m := range rp.Migrations {
		line := fmt.Sprintf("Migration %s onto %s: %s", m.Trigger, output.Dash(m.PatchID), m.Status)
		if m.Message != "" {
			line += " — " + m.Message
		}
		t.Note("%s", line)
	}
	t.Note("* runs now, p pinned. `runr projects patch apply` moves to the newest, `runr projects patch pin` pins one.")
}

// patchStateLabel: "patch available (security): 8.3.35-r1 → 8.3.36-r1, deadline …, pinned to …".
func patchStateLabel(s *runr.RuntimePatchState) string {
	label := strings.ReplaceAll(s.State, "_", " ")
	if s.Level != "" {
		label += " (" + s.Level + ")"
	}
	parts := []string{label}
	cur, latest := s.Current.Label(), s.Latest.Label()
	switch {
	case len(s.Pending) > 0 && latest != "":
		from := cur
		if from == "" {
			from = "an older image"
		}
		parts = append(parts, from+" → "+latest)
	case cur != "":
		parts = append(parts, cur)
	case !s.Deployed:
		parts = append(parts, "not deployed")
	}
	if s.DeadlineAt != "" {
		parts = append(parts, "deadline "+patchTime(s.DeadlineAt))
	}
	if pin := s.Pin; pin != nil {
		p := "pinned to " + pin.Label()
		if !pin.Available {
			p += " (no longer offered: the next deploy uses the newest)"
		}
		if pin.By == "runr" {
			p += " by RunR after a failed migration"
		}
		parts = append(parts, p)
	}
	return strings.Join(parts, ", ")
}

// patchTime renders an API timestamp as local date and time ("" stays "").
func patchTime(s string) string {
	if s == "" {
		return ""
	}
	t, err := time.Parse(time.RFC3339, s)
	if err != nil {
		return s
	}
	return t.Local().Format("2006-01-02 15:04")
}

func runtimesPatchesCmd(app *App) *cobra.Command {
	var runtime, version string
	cmd := &cobra.Command{
		Use:   "patches",
		Short: "List the runtime patches of the feed: level, reason, CVEs and deadlines",
		Example: "  runr runtimes patches\n" +
			"  runr runtimes patches --runtime php --version 8.3",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListRuntimePatches(cmd.Context(), runtime, version)
			if err != nil {
				return err
			}
			if list.Patches == nil {
				list.Patches = []runr.RuntimePatch{}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("RUNTIME", "VERSION", "TAG", "LEVEL", "PUBLISHED", "DEADLINE", "CVES", "REASON")
				for _, x := range list.Patches {
					t.Row(x.Runtime, x.Version, x.Tag, x.Level, gitAge(x.PublishedAt), output.Dash(patchTime(x.DeadlineAt)),
						joinOrDash(x.CVEs), output.Truncate(output.Dash(x.Reason), 50))
				}
				switch {
				case !list.Feed.Enabled:
					t.Note("The patch feed is off on this platform (no public key configured).")
				case list.Feed.Status != "" && list.Feed.Status != "ok":
					t.Note("The patch feed's last check: %s.", list.Feed.Status)
				case len(list.Patches) == 0:
					t.Note("No runtime patches.")
				}
			})
		},
	}
	cmd.Flags().StringVar(&runtime, "runtime", "", "only this runtime (php, python, …)")
	cmd.Flags().StringVar(&version, "version", "", "only this runtime version (8.3, 3.14, …)")
	return cmd
}
