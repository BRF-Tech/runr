package cmd

import (
	"context"
	"encoding/json"
	"fmt"
	"strconv"
	"time"

	"github.com/spf13/cobra"

	"github.com/runrsh/core/cli/internal/output"
	"github.com/runrsh/core/cli/pkg/runr"
)

// `runr workflows app` (where the engine calls the project's function and the key it signs
// those calls with) and `runr workflows usage` (workflow metering).

func newWfAppCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "app",
		Short: "The function the engine drives: endpoint, signing key, sync state",
		Long: "The workflow app of a project: the URL the engine calls (the project's function, or an\n" +
			"endpoint override for a function served outside RunR), the fingerprint of the key every\n" +
			"engine request is signed with, the last definition sync and the queue bridge. Reading needs\n" +
			"workflows.view, the other commands workflows.manage.",
		Example: "  runr workflows app\n" +
			"  runr workflows app set --endpoint https://workflows.example.com/api/runr\n" +
			"  runr workflows app rotate-key\n" +
			"  runr workflows app signing-key --env >> .env",
		Args: cobra.NoArgs,
	}
	get := &cobra.Command{
		Use:   "get",
		Short: "Show the workflow app of the project",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			wa, err := c.WorkflowApp(cmd.Context(), p.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(wa, func(t *output.TableWriter) { wfAppDetail(t, p, wa) })
		},
	}
	cmd.RunE = get.RunE
	cmd.AddCommand(get, newWfAppSetCmd(app), newWfAppRotateKeyCmd(app), newWfAppSigningKeyCmd(app))
	return cmd
}

func wfAppDetail(t *output.TableWriter, p *runr.Project, wa *runr.WorkflowApp) {
	t.KV("Project", p.Name)
	t.KV("Trigger", output.Dash(wa.TriggerType))
	endpoint := output.Dash(wa.EndpointURL)
	if wa.EndpointOverride != "" {
		endpoint += " (override)"
	}
	t.KV("Endpoint", endpoint)
	t.KV("Signing key", output.Dash(wa.KeyFingerprint))
	if wa.PreviousKeyExpiresAt != "" {
		t.KV("Previous key signs until", wa.PreviousKeyExpiresAt)
	}
	sync := output.Dash(wa.SyncStatus)
	if wa.LastSyncedAt != "" {
		sync += ", " + wa.LastSyncedAt
	}
	if wa.SyncError != "" {
		sync += " (" + wa.SyncError + ")"
	}
	t.KV("Last sync", sync)
	if wa.SDK != nil {
		if b, err := json.Marshal(wa.SDK); err == nil {
			t.KV("SDK", string(b))
		}
	}
	if qb := wa.QueueBridge; qb != nil && qb.Enabled {
		t.KV("Queue bridge", fmt.Sprintf("stream %s → event %s", qb.Stream, qb.Event))
	}
}

func newWfAppSetCmd(app *App) *cobra.Command {
	var endpoint string
	var reset bool
	cmd := &cobra.Command{
		Use:   "set",
		Short: "Point the engine at a function served outside RunR, or back at the project's function",
		Long: "--endpoint URL makes the engine call that URL instead of the project's function (http or\n" +
			"https, no credentials in the URL, no loopback or private address unless the installation\n" +
			"allows them; redirects are not followed). The function there verifies the engine's\n" +
			"signature with the project's key (runr workflows app signing-key). --reset-endpoint goes\n" +
			"back to the project's function URL.",
		Example: "  runr workflows app set --endpoint https://workflows.example.com/api/runr\n" +
			"  runr workflows app set --reset-endpoint",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if cmd.Flags().Changed("endpoint") == reset {
				return usageErrorf("give --endpoint URL or --reset-endpoint")
			}
			if !reset && endpoint == "" {
				return usageErrorf("--endpoint needs a URL (--reset-endpoint goes back to the project's function)")
			}
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			wa, err := c.SetWorkflowEndpoint(cmd.Context(), p.ID, endpoint)
			if err != nil {
				return err
			}
			app.Progress("The engine calls %s.", wa.EndpointURL)
			return app.Printer().Print(wa, func(t *output.TableWriter) { wfAppDetail(t, p, wa) })
		},
	}
	cmd.Flags().StringVar(&endpoint, "endpoint", "", "URL the engine calls instead of the project's function")
	cmd.Flags().BoolVar(&reset, "reset-endpoint", false, "call the project's function again")
	return cmd
}

func newWfAppRotateKeyCmd(app *App) *cobra.Command {
	var noDeploy bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "rotate-key",
		Short: "Create a new signing key; the previous one keeps signing for a grace period",
		Long: "Create a new signing key for the engine's requests. The previous key keeps signing (as a\n" +
			"second signature) for the grace period (24 hours by default), so the running function keeps\n" +
			"working until it has the new key. A workflow project on RunR gets it with a deployment:\n" +
			"runr redeploys the live version unless --no-deploy is given. A function served at an\n" +
			"endpoint override needs the new key from runr workflows app signing-key.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			wa, msg, err := c.RotateWorkflowKey(ctx, p.ID)
			if err != nil {
				return err
			}
			if msg != "" {
				app.Progress("%s", msg)
			}
			out := map[string]any{"app": wa, "message": msg, "redeployed": false}
			switch {
			case wa.EndpointOverride != "":
				app.Progress("Give the function at %s the new key: runr workflows app signing-key.", wa.EndpointOverride)
			case !wa.IsWorkflowProject:
				app.Progress("%s does not have the workflow trigger; no deployment carries the key.", p.Name)
			case noDeploy:
				app.Progress("Deploy %s before the previous key stops signing.", p.Name)
			default:
				dep, err := redeployLive(ctx, app, c, p, timeout, "the next `runr deploy` gives the function the new key")
				if err != nil {
					return err
				}
				if dep != nil {
					out["redeployed"], out["deployment"] = true, dep
				}
			}
			return app.Printer().Print(out, func(t *output.TableWriter) { wfAppDetail(t, p, wa) })
		},
	}
	cmd.Flags().BoolVar(&noDeploy, "no-deploy", false, "only rotate; deploy before the previous key stops signing")
	cmd.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long to wait for the redeploy")
	return cmd
}

func newWfAppSigningKeyCmd(app *App) *cobra.Command {
	var env bool
	cmd := &cobra.Command{
		Use:   "signing-key",
		Short: "Print the signing key, for a function served outside RunR (workflows.manage)",
		Long: "Print the key the engine signs its requests with: the function verifies them with it, read\n" +
			"from RUNR_WORKFLOW_SIGNING_KEY. Functions on RunR get it with their deployment; this is for a\n" +
			"function served at an endpoint override. The key is secret; --env prints it as\n" +
			"RUNR_WORKFLOW_SIGNING_KEY=… for an env file.",
		Example: "  runr workflows app signing-key\n" +
			"  runr workflows app signing-key --env >> .env",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			k, err := c.WorkflowSigningKey(cmd.Context(), p.ID)
			if err != nil {
				return err
			}
			fmt.Fprintf(app.Err, "The signing key of %s is secret; keep it out of logs and shells' history.\n", p.Name)
			if app.Printer().Machine() {
				return app.Printer().Print(k, nil)
			}
			if env {
				name := k.Env
				if name == "" {
					name = "RUNR_WORKFLOW_SIGNING_KEY"
				}
				_, err = fmt.Fprintf(app.Out, "%s=%s\n", name, k.SigningKey)
				return err
			}
			_, err = fmt.Fprintln(app.Out, k.SigningKey)
			return err
		},
	}
	cmd.Flags().BoolVar(&env, "env", false, "print RUNR_WORKFLOW_SIGNING_KEY=<key>")
	return cmd
}

func newWfUsageCmd(app *App) *cobra.Command {
	var from, to string
	cmd := &cobra.Command{
		Use:   "usage",
		Short: "Step executions and started runs per day and workflow (-p limits it to a project)",
		Long: "Workflow metering of the organization, or of one project with -p: every step execution\n" +
			"(each attempt, sleep, wait, invoke and send) and every started run, per day and per\n" +
			"workflow, between --from and --to (default: the last 30 days).",
		Example: "  runr workflows usage\n" +
			"  runr workflows usage -p orders --from 2026-10-01 --to 2026-10-15",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			for _, d := range []string{from, to} {
				if d == "" {
					continue
				}
				if _, err := time.Parse("2006-01-02", d); err != nil {
					return usageErrorf("--from and --to are dates such as 2026-10-01")
				}
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			projectID := ""
			names := map[string]string{}
			if app.ProjectRef != "" {
				p, err := c.FindProject(cmd.Context(), app.ProjectRef)
				if err != nil {
					return err
				}
				projectID = p.ID
				names[p.ID] = p.Name
			}
			u, err := c.WorkflowUsage(cmd.Context(), projectID, from, to)
			if err != nil {
				return err
			}
			return app.Printer().Print(u, func(t *output.TableWriter) {
				t.KV("Period", u.From+" to "+u.To)
				t.KV("Step executions", strconv.Itoa(u.Totals.StepExecutions))
				t.KV("Runs started", strconv.Itoa(u.Totals.RunsStarted))
				t.Header("WORKFLOW", "PROJECT", "STEP EXECUTIONS", "RUNS STARTED")
				for _, w := range u.ByWorkflow {
					project := names[w.ProjectID]
					if project == "" {
						project = w.ProjectID
					}
					t.Row(output.Dash(w.Workflow), output.Dash(project), strconv.Itoa(w.StepExecutions), strconv.Itoa(w.RunsStarted))
				}
				if len(u.ByWorkflow) == 0 {
					t.Note("No workflow usage in this period.")
				} else {
					t.Note("%d days with usage; -o json lists them.", len(u.Daily))
				}
			})
		},
	}
	cmd.Flags().StringVar(&from, "from", "", "first day (YYYY-MM-DD; default: 29 days ago)")
	cmd.Flags().StringVar(&to, "to", "", "last day (YYYY-MM-DD; default: today)")
	return cmd
}

// redeployLive deploys the live version of a project again, so it picks up a setting that
// reaches the workload with a deployment (a key, a secret). Without a live deployment it says
// why nothing was deployed (noLive completes "No live deployment; …") and returns nil.
func redeployLive(ctx context.Context, app *App, c *runr.Client, p *runr.Project, timeout time.Duration, noLive string) (*runr.Deployment, error) {
	d, err := c.CurrentDeployment(ctx, p.ID)
	if err != nil {
		return nil, err
	}
	if d == nil || !d.Live() || d.Version == nil {
		app.Progress("No live deployment; %s.", noLive)
		return nil, nil
	}
	v, err := c.GetVersion(ctx, p.ID, d.Version.ID)
	if err != nil {
		return nil, err
	}
	res, err := finishDeploy(ctx, app, c, &DeployResult{Project: p, Version: v}, v, false, timeout)
	if res != nil {
		return res.Deployment, err
	}
	return nil, err
}
