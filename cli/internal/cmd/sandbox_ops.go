package cmd

import (
	"context"
	"fmt"
	"strings"

	"github.com/spf13/cobra"

	"github.com/runrsh/core/cli/internal/output"
	"github.com/runrsh/core/cli/pkg/runr"
)

// `runr sandbox files mkdir|rm` and `runr sandbox logs ID --pod`.

// sandboxAbs makes a path of a sandbox absolute: a relative one is relative to /workspace.
func sandboxAbs(p string) string {
	if strings.HasPrefix(p, "/") {
		return p
	}
	return "/workspace/" + strings.TrimPrefix(p, "./")
}

func newSandboxMkdirCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "mkdir ID PATH...",
		Short: "Create directories in a sandbox, with their parents",
		Long: "Create directories in a running sandbox, with their parents (like mkdir -p); a relative\n" +
			"PATH is relative to /workspace. Writable places are /workspace, /output, /tmp and\n" +
			"/home/sandbox.",
		Example: "  runr sandbox files mkdir $SBX data/raw /output/charts",
		Args:    cobra.MinimumNArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			made := []runr.SandboxPathResult{}
			for _, p := range args[1:] {
				r, err := c.MakeSandboxDir(cmd.Context(), args[0], sandboxAbs(p))
				if err != nil {
					return err
				}
				made = append(made, *r)
				app.Progress("Created %s:%s.", args[0], r.Path)
			}
			return app.Printer().Print(made, func(*output.TableWriter) {})
		},
	}
}

func newSandboxRmPathCmd(app *App) *cobra.Command {
	var recursive, force bool
	c := &cobra.Command{
		Use:     "rm [-r] ID PATH...",
		Aliases: []string{"remove", "delete"},
		Short:   "Remove files and directories of a sandbox",
		Long: "Remove files, links and empty directories of a running sandbox; -r removes directories\n" +
			"with their contents. A relative PATH is relative to /workspace; / cannot be removed. A path\n" +
			"that does not exist ends with exit code 4, unless -f is given.",
		Example: "  runr sandbox files rm $SBX notes.txt\n" +
			"  runr sandbox files rm -r $SBX /workspace/build /output/old",
		Args: cobra.MinimumNArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			removed := []runr.SandboxPathResult{}
			for _, p := range args[1:] {
				abs := sandboxAbs(p)
				r, err := c.RemoveSandboxPath(cmd.Context(), args[0], abs, recursive)
				if force && runr.IsNotFound(err) && sandboxExists(cmd.Context(), c, args[0]) {
					app.Progress("%s:%s does not exist.", args[0], abs)
					continue
				}
				if err != nil {
					return err
				}
				removed = append(removed, *r)
				app.Progress("Removed %s:%s.", args[0], r.Path)
			}
			return app.Printer().Print(removed, func(*output.TableWriter) {})
		},
	}
	c.Flags().BoolVarP(&recursive, "recursive", "r", false, "remove directories with their contents")
	c.Flags().BoolVarP(&force, "force", "f", false, "a path that does not exist is not an error")
	return c
}

// sandboxExists tells a missing path (404 of a sandbox that exists) from a missing sandbox.
func sandboxExists(ctx context.Context, c *runr.Client, id string) bool {
	_, err := c.GetSandbox(ctx, id)
	return err == nil
}

// sandboxPodLogs prints the activity log of a sandbox with its pod.
func sandboxPodLogs(ctx context.Context, app *App, c *runr.Client, id string) error {
	events, pod, err := c.SandboxLogsWithPod(ctx, id)
	if err != nil {
		return err
	}
	out := map[string]any{"events": events, "pod": pod}
	return app.Printer().Print(out, func(t *output.TableWriter) {
		if pod == nil {
			t.KV("Pod", "- (the sandbox has ended, or its region did not answer)")
		} else {
			t.KV("Pod", pod.Namespace+"/"+pod.Name)
			t.KV("Node", output.Dash(pod.Node))
			t.KV("Phase", output.Dash(pod.Phase))
			conds := make([]string, 0, len(pod.Conditions))
			for _, cd := range pod.Conditions {
				s := cd.Type + "=" + cd.Status
				if cd.Reason != "" {
					s += " (" + cd.Reason + ")"
				}
				conds = append(conds, s)
			}
			t.KV("Conditions", joinOrDash(conds))
			for _, e := range pod.Events {
				t.KV("Pod event", strings.TrimSpace(fmt.Sprintf("%s %s %s: %s", output.Dash(e.At), e.Type, e.Reason, e.Message)))
			}
		}
		t.Header("TIME", "TYPE", "MESSAGE")
		for i := len(events) - 1; i >= 0; i-- {
			e := events[i]
			t.Row(output.Dash(e.CreatedAt), e.Type, output.Truncate(output.Dash(e.Message), 100))
		}
		if pod != nil && strings.TrimSpace(pod.Log) != "" {
			t.Note("\nLog of the pod (last lines):\n%s", strings.TrimRight(pod.Log, "\n"))
		}
	})
}
