package cmd

import (
	"errors"
	"fmt"
	"strconv"
	"strings"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// `runr projects rightsizing`: the rightsizing recommendations RunR makes every night from the
// projects' daily peaks (docs/monitoring.md#rightsizing) — list, show, apply, dismiss.

func newRightsizingCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "rightsizing [NAME]",
		Aliases: []string{"rightsize"},
		Short:   "Size recommendations from what the projects' pods used in the last days",
		Long: "Every night RunR compares what a running project's pods used at most in the last 14 days with\n" +
			"its size and recommends a smaller one (memory: the highest working set × 1.3, CPU: the 95th\n" +
			"percentile × 1.3, rounded up to the next size) — or a larger one after OOM kills, CPU throttling\n" +
			"or memory close to its limit. Without NAME (or --project) the organization's open\n" +
			"recommendations are listed, largest monthly saving first (savings need the Billing module);\n" +
			"with NAME the project's recommendation and its evidence, or why it has none yet.\n" +
			"`apply` saves the recommended size as a new version (deploy to run it), `dismiss` hides one.",
		Example: "  runr projects rightsizing\n" +
			"  runr projects rightsizing orders\n" +
			"  runr projects rightsizing apply orders --yes\n" +
			"  runr projects rightsizing dismiss orders --snooze 30",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			if len(args) == 1 {
				app.ProjectRef = args[0]
			}
			if app.ProjectRef != "" {
				c, p, err := app.Project(ctx)
				if err != nil {
					return err
				}
				st, err := c.ProjectRightsizing(ctx, p.ID)
				if err != nil {
					return rightsizingError(err, p.Name)
				}
				return app.Printer().Print(st, func(t *output.TableWriter) { rightsizingDetail(t, p, st) })
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.Rightsizing(ctx)
			if err != nil {
				return rightsizingError(err, "")
			}
			return app.Printer().Print(list, func(t *output.TableWriter) { rightsizingTable(t, list) })
		},
	}

	var yes bool
	apply := &cobra.Command{
		Use:   "apply [NAME]",
		Short: "Save a project's recommended size as a new version (deploy to run it)",
		Long: "The recommended CPU and memory go into the project's config with the checks of a manual edit\n" +
			"(region capacity, a fixed allocation) and are saved as a new version; the workload runs the\n" +
			"new size after the next deploy (`runr deploy`).",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			if len(args) == 1 {
				app.ProjectRef = args[0]
			}
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			st, err := c.ProjectRightsizing(ctx, p.ID)
			if err != nil {
				return rightsizingError(err, p.Name)
			}
			if st.Data == nil || st.Status != "open" {
				return &runr.NotFoundError{Kind: "open rightsizing recommendation of project", Ref: p.Name}
			}
			r := st.Data
			question := fmt.Sprintf("Change %s from %s to %s", p.Name, sizeText(r.Current), sizeText(r.Recommended))
			if err := app.confirm(question, yes); err != nil {
				return err
			}
			res, err := c.ApplyRightsizing(ctx, p.ID)
			if err != nil {
				return rightsizingError(err, p.Name)
			}
			app.Progress("Saved %s as %s. Deploy the project to run it: runr deploy -p %s", sizeText(res.Data.Recommended), res.Version.Label, p.Name)
			return app.Printer().Print(res, func(t *output.TableWriter) {
				t.KV("Project", p.Name)
				t.KV("Size", sizeText(res.Data.Current)+" → "+sizeText(res.Data.Recommended))
				t.KV("Version", res.Version.Label)
				t.Note("The running deployment keeps its size until the next deploy.")
			})
		},
	}
	apply.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")

	var snooze int
	dismiss := &cobra.Command{
		Use:   "dismiss [NAME]",
		Short: "Hide a project's recommendation (for good, or --snooze DAYS)",
		Long: "A dismissed recommendation stays hidden while the recommended size stays the same; with\n" +
			"--snooze it comes back after that many days (1-365).",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if cmd.Flags().Changed("snooze") && (snooze < 1 || snooze > 365) {
				return usageErrorf("--snooze must be between 1 and 365 days")
			}
			ctx := cmd.Context()
			if len(args) == 1 {
				app.ProjectRef = args[0]
			}
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			r, err := c.DismissRightsizing(ctx, p.ID, snooze)
			if err != nil {
				return rightsizingError(err, p.Name)
			}
			if r.Status == "snoozed" {
				app.Progress("The recommendation of %s is hidden for %d days.", p.Name, snooze)
			} else {
				app.Progress("The recommendation of %s is dismissed.", p.Name)
			}
			return app.Printer().Print(r, func(t *output.TableWriter) {})
		},
	}
	dismiss.Flags().IntVar(&snooze, "snooze", 0, "hide it for this many days instead of for good")

	cmd.AddCommand(apply, dismiss)
	return cmd
}

// rightsizingError turns the client's sentinel errors into what the CLI reports.
func rightsizingError(err error, project string) error {
	switch {
	case errors.Is(err, runr.ErrNoRecommendation):
		return &runr.NotFoundError{Kind: "open rightsizing recommendation of project", Ref: project}
	case errors.Is(err, runr.ErrUnsupported):
		return errors.New("this RunR API has no rightsizing recommendations (upgrade RunR)")
	}
	return err
}

func rightsizingTable(t *output.TableWriter, list *runr.RightsizingList) {
	t.Header("PROJECT", "NOW", "RECOMMENDED", "WHY", "FREES", "MONTHLY")
	for i := range list.Data {
		r := &list.Data[i]
		t.Row(r.ProjectName(), sizeText(r.Current), sizeText(r.Recommended), reasonsText(r.Reasons), freedText(r.Freed), savingsText(r.Savings))
	}
	if len(list.Data) == 0 {
		t.Note("No open recommendation: the running projects are right-sized, or RunR is still collecting their usage.")
		return
	}
	if list.Summary.MonthlySavings != nil {
		t.Note("%d recommendation(s), about %s %s a month in all.", list.Summary.Count,
			strconv.FormatFloat(float64(*list.Summary.MonthlySavings), 'f', 2, 64), list.Summary.Currency)
	}
}

func rightsizingDetail(t *output.TableWriter, p *runr.Project, st *runr.ProjectRightsizing) {
	t.KV("Project", p.Name)
	t.KV("Status", st.Status)
	r := st.Data
	if r == nil {
		switch st.Status {
		case "collecting":
			if c := st.Collecting; c != nil {
				t.KV("Data", fmt.Sprintf("%d of %d days", c.Days, c.RequiredDays))
				if c.RequiredSessions > 0 {
					t.KV("Sessions", fmt.Sprintf("%d of %d", c.Sessions, c.RequiredSessions))
				}
			}
			t.Note("Not enough usage yet for a recommendation.")
		case "right_sized":
			t.Note("The project's size fits what its pods used.")
		default:
			t.KV("Reason", output.Dash(st.Reason))
		}
		return
	}
	t.KV("Now", sizeText(r.Current))
	t.KV("Recommended", sizeText(r.Recommended))
	t.KV("Why", reasonsText(r.Reasons))
	e := r.Evidence
	t.KV("Window", fmt.Sprintf("%s … %s (%d days with readings, %d sessions)", output.Dash(e.From), output.Dash(e.To), e.Days, e.Sessions))
	t.KV("Memory used", fmt.Sprintf("%s at most, %s 95th percentile", unitText(e.MemoryMaxMB, 0, "MiB"), unitText(e.MemoryP95MB, 0, "MiB")))
	t.KV("CPU used", fmt.Sprintf("%s at most, %s 95th percentile", milliText(e.CPUMaxM), milliText(e.CPUP95M)))
	if e.OOMKills > 0 {
		t.KV("OOM kills", strconv.Itoa(e.OOMKills))
	}
	if e.ThrottledRatio > 0 {
		t.KV("CPU throttled", strconv.FormatFloat(float64(e.ThrottledRatio)*100, 'f', 1, 64)+" % of periods")
	}
	t.KV("Frees", freedText(r.Freed))
	t.KV("Monthly", savingsText(r.Savings))
	if r.Blocked != nil {
		t.KV("Blocked", r.Blocked.Message)
	}
	if st.PendingDeploy {
		t.Note("Applied: deploy the project to run the new size.")
	}
}

func sizeText(s runr.RightsizingSize) string {
	return fmt.Sprintf("%d MiB / %dm", s.MemoryMB, s.CPUM)
}

func milliText(v *int) string {
	if v == nil {
		return "-"
	}
	return strconv.Itoa(*v) + "m"
}

func reasonsText(reasons []string) string {
	labels := map[string]string{
		"memory_over_provisioned": "memory unused", "cpu_over_provisioned": "CPU unused",
		"memory_oom_killed": "OOM killed", "memory_near_limit": "memory near limit", "cpu_throttled": "CPU throttled",
	}
	out := make([]string, 0, len(reasons))
	for _, r := range reasons {
		if l, ok := labels[r]; ok {
			out = append(out, l)
		} else {
			out = append(out, r)
		}
	}
	return output.Dash(strings.Join(out, ", "))
}

func freedText(f runr.RightsizingFreed) string {
	parts := []string{}
	if f.MemoryMB != 0 {
		parts = append(parts, fmt.Sprintf("%+d MiB", f.MemoryMB))
	}
	if f.CPUM != 0 {
		parts = append(parts, fmt.Sprintf("%+dm", f.CPUM))
	}
	if len(parts) == 0 {
		return "-"
	}
	return strings.Join(parts, " ")
}

func savingsText(s *runr.RightsizingSavings) string {
	if s == nil || s.Monthly == nil {
		return "-"
	}
	if s.Basis == "usage" {
		return "billed by use"
	}
	return strings.TrimSpace(strconv.FormatFloat(float64(*s.Monthly), 'f', 2, 64) + " " + s.Currency)
}
