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
	res, err := finishDeploy(ctx, app, c, &DeployResult{Project: p, Version: v}, v, deployWait{timeout: timeout})
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
	var warm []string
	var noWarm bool
	var noDeploy bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "scale",
		Short: "Set the autoscaling range or the timed warmer",
		Long: "Set the autoscaling range (--min, --max, --to-zero) and redeploy, or the timed warmer.\n\n" +
			"The timed warmer keeps containers warm on a schedule: each --warm \"<cron>=<count>\" row keeps at\n" +
			"least <count> containers running from the moment its five-field cron expression fires until the\n" +
			"next row fires. The expressions run in the organization's time zone (Settings, default\n" +
			"Europe/Istanbul). While a schedule is set it replaces --min; --max still applies. The rows replace\n" +
			"the whole schedule, --no-warm turns it off. A schedule change alone needs no redeploy: RunR\n" +
			"applies it to the running workload within a minute. Environments run this schedule unless\n" +
			"they have their own (runr environments update NAME --warm … | --no-warm | --project-warm).",
		Example: "  runr scale --min 1 --max 20\n" +
			"  runr scale --to-zero=false\n" +
			"  runr scale --warm \"0 9 * * 1-5=3\" --warm \"0 18 * * 1-5=0\"\n" +
			"  runr scale --no-warm",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			fl := cmd.Flags()
			warmChanged := fl.Changed("warm") || noWarm
			if !fl.Changed("min") && !fl.Changed("max") && !fl.Changed("to-zero") && !warmChanged {
				return usageErrorf("give --min, --max, --to-zero, --warm or --no-warm")
			}
			if fl.Changed("warm") && noWarm {
				return usageErrorf("--warm and --no-warm exclude each other")
			}
			var rows []runr.ScaleScheduleRow
			for _, w := range warm {
				row, err := runr.ParseScaleScheduleRow(w)
				if err != nil {
					return usageErrorf("--warm %s", err.Error())
				}
				rows = append(rows, row)
			}
			if err := runr.ValidateScaleSchedule(rows, 0); err != nil {
				return usageErrorf("--warm: %s", err.Error())
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
			if warmChanged {
				// The rows (none for --no-warm) replace the whole schedule; the count may not
				// exceed max_scale, the new one or the project's
				maxScale := max
				if !fl.Changed("max") {
					maxScale = 0
					if p.Config.MaxScale != nil {
						maxScale = *p.Config.MaxScale
					}
				}
				if err := runr.ValidateScaleSchedule(rows, maxScale); err != nil {
					return usageErrorf("--warm: %s", err.Error())
				}
				if rows == nil {
					rows = []runr.ScaleScheduleRow{}
				}
				in.ScaleSchedule = &rows
			}
			updated, err := c.UpdateProject(ctx, p.ID, runr.UpdateProjectInput{Config: &in, ConfigMessage: "runr scale"})
			if err != nil {
				return err
			}
			app.Progress("Scale of %s: %s", p.Name, scaleLabel(updated.Config))
			if warmChanged {
				app.Progress("Timed warmer of %s: %s", p.Name, warmLabel(updated))
			}
			if warmChanged && !fl.Changed("min") && !fl.Changed("max") && !fl.Changed("to-zero") {
				// The timed warmer sets the running revision's warm containers itself: no new revision
				app.Progress("RunR applies it to the running workload within a minute; no redeploy needed.")
				return app.Printer().Print(map[string]any{"project": updated, "redeployed": false}, func(*output.TableWriter) {})
			}
			return redeployIfLive(ctx, app, c, updated, noDeploy, timeout)
		},
	}
	cmd.Flags().IntVar(&min, "min", 0, "minimum instances (0-10)")
	cmd.Flags().IntVar(&max, "max", 0, "maximum instances (1-1000)")
	cmd.Flags().BoolVar(&toZero, "to-zero", true, "scale to zero instances when idle")
	cmd.Flags().StringArrayVar(&warm, "warm", nil, "timed warmer row \"<cron>=<count>\" (repeatable; the rows replace the schedule)")
	cmd.Flags().BoolVar(&noWarm, "no-warm", false, "turn the timed warmer off")
	cmd.Flags().BoolVar(&noDeploy, "no-deploy", false, "only save; the next deployment picks the change up")
	cmd.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long to wait for the redeploy")
	return cmd
}

func newDomainsCmd(app *App) *cobra.Command {
	// --env: the custom domains of one environment of the project (docs/environments.md#domains)
	var envName string
	cmd := &cobra.Command{
		Use:     "domains",
		Aliases: []string{"domain"},
		Short:   "Manage custom domains of a project or of one of its environments",
		Long: "Custom domains route a host name to the project's own workload, or with --env to one of\n" +
			"its environments (runr environments): each environment has domains of its own, verified\n" +
			"and served in the region it runs in.",
		Example: "  runr domains add api.example.com\n" +
			"  runr domains add staging-api.example.com --env staging\n" +
			"  runr domains ls --env staging",
	}
	cmd.PersistentFlags().StringVar(&envName, "env", "", envFlagUsage)
	// scope resolves the project and --env ("" the project's own workload)
	scope := func(cmd *cobra.Command) (*runr.Client, *runr.Project, string, error) {
		c, p, err := app.Project(cmd.Context())
		if err != nil {
			return nil, nil, "", err
		}
		env, err := environmentScope(cmd.Context(), c, p, envName)
		if err != nil {
			return nil, nil, "", err
		}
		return c, p, env, nil
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List custom domains and the DNS records they need",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, env, err := scope(cmd)
			if err != nil {
				return err
			}
			list, meta, err := c.ListEnvironmentDomains(cmd.Context(), p.ID, env)
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.Domain{}
			}
			out := map[string]any{"domains": list, "meta": meta}
			if env != "" {
				out["environment"] = env
			}
			return app.Printer().Print(out, func(t *output.TableWriter) {
				t.Header("DOMAIN", "STATUS", "HTTPS", "URL")
				for _, d := range list {
					t.Row(d.Domain, d.Status, d.HTTPSCertificate, d.URL)
				}
				if meta != nil && !meta.Eligible && meta.Reason != "" {
					t.Note("Custom domains need: %s", meta.Reason)
				}
				if cm := domainsCertManager(meta); cm != nil && !cm.Available && cm.Reason != "" {
					t.Note("TLS mode cert-manager: %s", cm.Reason)
				} else if cm != nil && cm.Kind == "ca" {
					t.Note("Certificates come from the region CA: runr domains ca saves it for your clients.")
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
			c, p, env, err := scope(cmd)
			if err != nil {
				return err
			}
			d, err := c.AddEnvironmentDomain(cmd.Context(), p.ID, env, args[0])
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
			c, p, env, err := scope(cmd)
			if err != nil {
				return err
			}
			d, err := c.FindEnvironmentDomain(cmd.Context(), p.ID, env, args[0])
			if err != nil {
				return err
			}
			d2, err := c.VerifyEnvironmentDomain(cmd.Context(), p.ID, env, d.ID)
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
			c, p, env, err := scope(cmd)
			if err != nil {
				return err
			}
			d, err := c.FindEnvironmentDomain(cmd.Context(), p.ID, env, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Remove "+d.Domain+" from "+projectIn(p, env), yes); err != nil {
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
			c, p, env, err := scope(cmd)
			if err != nil {
				return err
			}
			d, err := c.FindEnvironmentDomain(cmd.Context(), p.ID, env, args[0])
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

	var caFile string
	ca := &cobra.Command{
		Use:   "ca",
		Short: "The region CA of the project's custom-domain certificates (PEM), for your clients",
		Long: "When the project's region issues custom-domain certificates from its own CA (TLS mode\n" +
			"cert-manager, issuer runr-ca), print that CA's certificate or write it to a file (-f).\n" +
			"With --env: the CA of the region that environment runs in.",
		Example: "  runr domains ca -f region-ca.crt",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if app.Printer().Machine() && (caFile == "" || caFile == "-") {
				return usageErrorf("ca prints the certificate itself: leave out -o, or write it with -f FILE")
			}
			c, p, env, err := scope(cmd)
			if err != nil {
				return err
			}
			pem, err := c.EnvironmentDomainsCACertificate(cmd.Context(), p.ID, env)
			if err != nil {
				return err
			}
			return writeOut(app, caFile, pem)
		},
	}
	ca.Flags().StringVarP(&caFile, "file", "f", "", "file to write (default: stdout)")
	cmd.AddCommand(ca)
	return cmd
}

// domainsCertManager is meta.cert_manager of the domain list (APIs with cert-manager management).
func domainsCertManager(meta *runr.DomainsMeta) *runr.CertManagerDomains {
	if meta == nil || !meta.Eligible {
		return nil
	}
	return meta.CertManager
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
