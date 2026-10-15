package cmd

import (
	"context"
	"fmt"
	"os"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

func newLogsCmd(app *App) *cobra.Command {
	var follow bool
	var deploymentID string
	cmd := &cobra.Command{
		Use:   "logs",
		Short: "Print the logs of the live deployment (-f to follow)",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			if deploymentID == "" {
				d, err := c.CurrentDeployment(ctx, p.ID)
				if err != nil {
					return err
				}
				if d == nil {
					return &runr.NotFoundError{Kind: "deployment of project", Ref: p.Name}
				}
				deploymentID = d.ID
			}
			logs, err := c.DeploymentLogs(ctx, p.ID, deploymentID, 0)
			if err != nil {
				return err
			}
			if app.Printer().Machine() && !follow {
				return app.Printer().Print(logs, nil)
			}
			if logs.Error != "" {
				app.Warn("%s", logs.Error)
			}
			writeLogs(app, logs.Logs)
			if !follow {
				return nil
			}
			return followLogs(ctx, app, c, p.ID, deploymentID, logs)
		},
	}
	cmd.Flags().BoolVarP(&follow, "follow", "f", false, "keep printing new lines")
	cmd.Flags().StringVar(&deploymentID, "deployment", "", "deployment id (default: the live deployment)")
	return cmd
}

func writeLogs(app *App, s string) {
	if s == "" {
		return
	}
	if !strings.HasSuffix(s, "\n") {
		s += "\n"
	}
	fmt.Fprint(app.Out, s)
}

func followLogs(ctx context.Context, app *App, c *runr.Client, projectID, deploymentID string, first *runr.Logs) error {
	lastTs := first.LastTsMs
	prev := first.Logs
	for polls := 0; app.MaxFollowPolls == 0 || polls < app.MaxFollowPolls; polls++ {
		select {
		case <-ctx.Done():
			return nil
		case <-time.After(app.PollInterval):
		}
		l, err := c.DeploymentLogs(ctx, projectID, deploymentID, lastTs)
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			return err
		}
		if lastTs > 0 || l.LastTsMs > 0 {
			// Loki: the answer holds only lines after `since`.
			writeLogs(app, l.Logs)
			if l.LastTsMs > lastTs {
				lastTs = l.LastTsMs
			}
			continue
		}
		// kubectl logs: the whole tail again; print what is new.
		switch {
		case l.Logs == prev:
		case strings.HasPrefix(l.Logs, prev):
			writeLogs(app, strings.TrimPrefix(l.Logs, prev))
		default:
			writeLogs(app, newTail(prev, l.Logs))
		}
		prev = l.Logs
	}
	return nil
}

// newTail returns the lines of cur after the last line it shares with prev.
func newTail(prev, cur string) string {
	pl := strings.Split(strings.TrimRight(prev, "\n"), "\n")
	cl := strings.Split(strings.TrimRight(cur, "\n"), "\n")
	if len(pl) == 0 {
		return cur
	}
	last := pl[len(pl)-1]
	for i := len(cl) - 1; i >= 0; i-- {
		if cl[i] == last {
			return strings.Join(cl[i+1:], "\n")
		}
	}
	return cur
}

// redeployIfLive deploys the live version again so a config change takes effect.
func redeployIfLive(ctx context.Context, app *App, c *runr.Client, p *runr.Project, noDeploy bool, timeout time.Duration) error {
	if noDeploy {
		app.Progress("Saved; the next deployment picks it up.")
		return app.Printer().Print(map[string]any{"project": p, "redeployed": false}, func(*output.TableWriter) {})
	}
	d, err := c.CurrentDeployment(ctx, p.ID)
	if err != nil {
		return err
	}
	if d == nil || !d.Live() || d.Version == nil {
		app.Progress("No live deployment; the next `runr deploy` applies it.")
		return app.Printer().Print(map[string]any{"project": p, "redeployed": false}, func(*output.TableWriter) {})
	}
	v, err := c.GetVersion(ctx, p.ID, d.Version.ID)
	if err != nil {
		return err
	}
	res, err := finishDeploy(ctx, app, c, &DeployResult{Project: p, Version: v}, v, false, timeout)
	if res != nil && res.Deployment != nil {
		if perr := printDeployResult(app, res); perr != nil && err == nil {
			err = perr
		}
	}
	return err
}

func newScaleCmd(app *App) *cobra.Command {
	var min, max int
	var toZero bool
	var noDeploy bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "scale",
		Short: "Set the autoscaling range and redeploy",
		Example: "  runr scale --min 1 --max 20\n" +
			"  runr scale --to-zero=false",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			fl := cmd.Flags()
			if !fl.Changed("min") && !fl.Changed("max") && !fl.Changed("to-zero") {
				return usageErrorf("give --min, --max or --to-zero")
			}
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			in := runr.ConfigInput{}
			if fl.Changed("min") {
				if min < 0 || min > 10 {
					return usageErrorf("--min must be between 0 and 10")
				}
				in.MinScale = &min
			}
			if fl.Changed("max") {
				if max < 1 || max > 1000 {
					return usageErrorf("--max must be between 1 and 1000")
				}
				in.MaxScale = &max
			}
			if in.MinScale != nil && in.MaxScale != nil && *in.MinScale > *in.MaxScale {
				return usageErrorf("--min must not be greater than --max")
			}
			if fl.Changed("to-zero") {
				in.ScaleToZero = &toZero
			}
			updated, err := c.UpdateProject(ctx, p.ID, runr.UpdateProjectInput{Config: &in, ConfigMessage: "runr scale"})
			if err != nil {
				return err
			}
			app.Progress("Scale of %s: %s", p.Name, scaleLabel(updated.Config))
			return redeployIfLive(ctx, app, c, updated, noDeploy, timeout)
		},
	}
	cmd.Flags().IntVar(&min, "min", 0, "minimum instances (0-10)")
	cmd.Flags().IntVar(&max, "max", 0, "maximum instances (1-1000)")
	cmd.Flags().BoolVar(&toZero, "to-zero", true, "scale to zero instances when idle")
	cmd.Flags().BoolVar(&noDeploy, "no-deploy", false, "only save; the next deployment picks the change up")
	cmd.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long to wait for the redeploy")
	return cmd
}

func newDomainsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "domains",
		Aliases: []string{"domain"},
		Short:   "Manage custom domains of a project",
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List custom domains and the DNS records they need",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			list, meta, err := c.ListDomains(cmd.Context(), p.ID)
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.Domain{}
			}
			return app.Printer().Print(map[string]any{"domains": list, "meta": meta}, func(t *output.TableWriter) {
				t.Header("DOMAIN", "STATUS", "HTTPS", "URL")
				for _, d := range list {
					t.Row(d.Domain, d.Status, d.HTTPSCertificate, d.URL)
				}
				if meta != nil && !meta.Eligible && meta.Reason != "" {
					t.Note("Custom domains need: %s", meta.Reason)
				}
			})
		},
	}
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE
	cmd.AddCommand(&cobra.Command{
		Use:   "add DOMAIN",
		Short: "Add a custom domain and print the DNS records to create",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			d, err := c.AddDomain(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(d, func(t *output.TableWriter) { domainRecords(t, d) })
		},
	})
	cmd.AddCommand(&cobra.Command{
		Use:   "verify DOMAIN",
		Short: "Check the DNS records and activate the domain",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			d, err := c.FindDomain(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			d2, err := c.VerifyDomain(cmd.Context(), p.ID, d.ID)
			if d2 != nil {
				_ = app.Printer().Print(d2, func(t *output.TableWriter) {
					t.KV("Domain", d2.Domain)
					t.KV("Status", d2.Status)
					if d2.StatusMessage != "" {
						t.KV("Message", d2.StatusMessage)
					}
					t.KV("URL", d2.URL)
				})
			}
			return err
		},
	})
	var yes bool
	rm := &cobra.Command{
		Use:     "rm DOMAIN",
		Aliases: []string{"remove", "delete"},
		Short:   "Remove a custom domain",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			d, err := c.FindDomain(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Remove "+d.Domain+" from "+p.Name, yes); err != nil {
				return err
			}
			if err := c.DeleteDomain(cmd.Context(), p.ID, d.ID); err != nil {
				return err
			}
			app.Progress("Removed %s.", d.Domain)
			return nil
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(rm)

	var mode, certFile, keyFile string
	tls := &cobra.Command{
		Use:   "tls DOMAIN",
		Short: "Set the HTTPS certificate: none, cert-manager or custom",
		Example: "  runr domains tls api.example.com --mode cert-manager\n" +
			"  runr domains tls api.example.com --mode custom --cert fullchain.pem --key privkey.pem",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			var cert, key string
			switch mode {
			case runr.TLSNone, runr.TLSCertManager:
			case runr.TLSCustom:
				if certFile == "" || keyFile == "" {
					return usageErrorf("--mode custom needs --cert and --key")
				}
				cb, err := os.ReadFile(certFile)
				if err != nil {
					return err
				}
				kb, err := os.ReadFile(keyFile)
				if err != nil {
					return err
				}
				cert, key = string(cb), string(kb)
			default:
				return usageErrorf("--mode must be none, cert-manager or custom")
			}
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			d, err := c.FindDomain(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			d2, err := c.SetDomainTLS(cmd.Context(), p.ID, d.ID, mode, cert, key)
			if err != nil {
				return err
			}
			return app.Printer().Print(d2, func(t *output.TableWriter) {
				t.KV("Domain", d2.Domain)
				t.KV("HTTPS", d2.HTTPSCertificate)
				if d2.TLSNote != "" {
					t.KV("Note", d2.TLSNote)
				}
				t.KV("URL", d2.URL)
			})
		},
	}
	tls.Flags().StringVar(&mode, "mode", "", "none, cert-manager or custom")
	tls.Flags().StringVar(&certFile, "cert", "", "PEM certificate chain (--mode custom)")
	tls.Flags().StringVar(&keyFile, "key", "", "PEM private key (--mode custom)")
	_ = tls.MarkFlagRequired("mode")
	cmd.AddCommand(tls)
	return cmd
}

func domainRecords(t *output.TableWriter, d *runr.Domain) {
	t.KV("Domain", d.Domain)
	t.KV("Status", d.Status)
	t.Header("TYPE", "NAME", "VALUE")
	t.Row(d.Verification.Type, d.Verification.Name, d.Verification.Value)
	for _, r := range d.DNSTargets {
		name := r.Name
		if name == "" {
			name = d.Domain
		}
		t.Row(r.Type, name, r.Value)
	}
	t.Note("")
	t.Note("Create these DNS records, then run `runr domains verify %s`.", d.Domain)
}
