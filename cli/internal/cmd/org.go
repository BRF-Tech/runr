package cmd

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"os"
	"sort"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Environment variables the passphrase of an export / import is read from (or --passphrase-file):
// it never appears on the command line.
const (
	EnvExportPassphrase = "RUNR_EXPORT_PASSPHRASE"
	EnvImportPassphrase = "RUNR_IMPORT_PASSPHRASE"
)

// minPassphrase is the shortest passphrase the API accepts for exported secrets.
const minPassphrase = 12

func init() { registerCommand(newOrgCmd) }

func newOrgCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "org",
		Short: "Export the organization to a package, import a package into it",
		Long: "An export packages the organization (docs/org-export.md) — settings, roles and groups,\n" +
			"alert rules, the status page, projects with every version's code, env vars, domains,\n" +
			"gateways, workflows, bindings — into one versioned file; prebuilt images, binding data and\n" +
			"secrets (encrypted with a passphrase) are optional. An import uploads such a file into\n" +
			"another organization or installation, shows a dry-run plan and creates everything.\n" +
			"Both need the Owner role.",
	}
	cmd.AddCommand(newOrgExportCmd(app), newOrgImportCmd(app), newOrgTransfersCmd(app), newOrgTransferCmd(app))
	return cmd
}

func newOrgExportCmd(app *App) *cobra.Command {
	var (
		projects, bindingData             []string
		members, images, secrets, history bool
		passFile, to, prefix, outFile     string
		s3Endpoint, s3Region              string
		s3PathStyle, estimateOnly, noWait bool
		timeout                           time.Duration
	)
	export := &cobra.Command{
		Use:   "export",
		Short: "Export the organization into a package (download, an object binding or S3)",
		Long: "Export the organization into a package file. The export runs as a job on RunR; runr waits\n" +
			"for it and downloads the package (-f, default its own file name). --to binding:<name> puts\n" +
			"the package into an object binding of the organization, --to s3://bucket/prefix into an S3\n" +
			"bucket (credentials from AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY, used for this job only).\n\n" +
			"Secrets are left out unless --secrets is given; they are then encrypted with a passphrase\n" +
			"read from --passphrase-file or $" + EnvExportPassphrase + " (at least 12 characters).",
		Example: "  runr org export --estimate\n" +
			"  runr org export -f acme.tar.zst\n" +
			"  runr org export --project api --project web --binding-data db --images\n" +
			"  runr org export --history\n" +
			"  RUNR_EXPORT_PASSPHRASE=… runr org export --secrets --members\n" +
			"  runr org export --to binding:backups --no-wait\n" +
			"  runr org export --to s3://backups/runr --s3-endpoint https://s3.example.com",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			passphrase := ""
			if secrets {
				var err error
				if passphrase, err = readPassphrase(passFile, EnvExportPassphrase); err != nil {
					return err
				}
				if passphrase == "" {
					return usageErrorf("--secrets needs a passphrase: --passphrase-file FILE or $%s", EnvExportPassphrase)
				}
				if len(passphrase) < minPassphrase {
					return usageErrorf("the passphrase must have at least %d characters", minPassphrase)
				}
			}

			c, err := app.Client()
			if err != nil {
				return err
			}
			opts := runr.OrgExportOptions{IncludeMembers: members, IncludeImages: images, IncludeSecrets: secrets, IncludeHistory: history}
			for _, ref := range projects {
				p, err := c.FindProject(ctx, ref)
				if err != nil {
					return err
				}
				opts.Projects = append(opts.Projects, p.ID)
			}
			if len(bindingData) > 0 {
				ids, all, err := orgBindingRefs(cmd, c, bindingData)
				if err != nil {
					return err
				}
				if all {
					opts.BindingData = "all"
				} else {
					opts.BindingData = ids
				}
			}

			if estimateOnly {
				est, err := c.EstimateOrgExport(ctx, opts)
				if err != nil {
					return err
				}
				return app.Printer().Print(est, func(t *output.TableWriter) { estimateTable(t, est) })
			}

			dest, err := orgDestination(cmd, c, to, prefix, s3Endpoint, s3Region, s3PathStyle)
			if err != nil {
				return err
			}
			tr, err := c.StartOrgExport(ctx, runr.OrgExportRequest{OrgExportOptions: opts, Passphrase: passphrase, Destination: dest})
			if err != nil {
				return err
			}
			if noWait {
				app.Progress("Export %s started; follow it with `runr org transfer %s`.", tr.ID, tr.ID)
				return app.Printer().Print(tr, func(t *output.TableWriter) { transferTable(t, tr) })
			}

			app.Progress("Exporting the organization (%s)…", tr.ID)
			tr, err = c.WaitOrgTransfer(ctx, tr.ID, []string{runr.OrgTransferCompleted}, runr.OrgWaitOptions{
				Timeout: timeout, Interval: app.PollInterval, Progress: orgProgress(app, "Export"),
			})
			if err != nil {
				return err
			}

			saved := ""
			if tr.Destination == nil || tr.Destination.Type == "download" {
				if tr.Package == nil {
					return fmt.Errorf("export %s completed without a package", tr.ID)
				}
				if saved, err = downloadOrgPackage(cmd, app, c, tr, outFile); err != nil {
					return err
				}
				if saved == "-" {
					return nil // the package went to stdout
				}
			}
			for _, w := range reportWarnings(tr) {
				app.Warn("%s", w)
			}
			return app.Printer().Print(tr, func(t *output.TableWriter) {
				transferTable(t, tr)
				if saved != "" {
					t.KV("Saved to", saved)
				}
			})
		},
	}
	f := export.Flags()
	f.StringArrayVarP(&projects, "project", "p", nil, "export only this project (name or id; repeat for more; default every project)")
	f.BoolVar(&members, "members", false, "include the members' e-mails and roles (for invitations; never credentials)")
	f.BoolVar(&images, "images", false, "include the prebuilt image of each project's deployed / latest built version (can be large)")
	f.StringArrayVar(&bindingData, "binding-data", nil, "include the data of this binding (name or id; repeat), or \"all\"")
	f.BoolVar(&secrets, "secrets", false, "include secret values, encrypted with the passphrase")
	f.BoolVar(&history, "history", false, "include the run history: deployments, workflow runs, trigger events, usage and bills")
	f.StringVar(&passFile, "passphrase-file", "", "file holding the passphrase (default $"+EnvExportPassphrase+")")
	f.StringVar(&to, "to", "download", "destination: download, binding:<name> or s3://bucket[/prefix]")
	f.StringVar(&prefix, "prefix", "", "key prefix in the destination binding (default runr-exports)")
	f.StringVar(&s3Endpoint, "s3-endpoint", "", "S3 endpoint URL (--to s3://…)")
	f.StringVar(&s3Region, "s3-region", "", "S3 region (default $AWS_REGION, else us-east-1)")
	f.BoolVar(&s3PathStyle, "s3-path-style", false, "address the bucket in the URL path (S3-compatible stores that need it)")
	f.StringVarP(&outFile, "file", "f", "", "where to save the package (default its file name; - for stdout)")
	f.BoolVar(&estimateOnly, "estimate", false, "print what the export would hold and its size, then exit")
	f.BoolVar(&noWait, "no-wait", false, "start the export and print its id without waiting")
	f.DurationVar(&timeout, "timeout", 6*time.Hour, "how long to wait for the export (0: no limit)")
	return export
}

func newOrgImportCmd(app *App) *cobra.Command {
	var (
		regionMap, renames, skipData                               []string
		passFile, from, key, s3Endpoint, s3Region, sum, resume     string
		planOnly, deploy, noData, noImages, invite, yes, noHistory bool
		s3PathStyle                                                bool
		timeout                                                    time.Duration
	)
	imp := &cobra.Command{
		Use:   "import [FILE]",
		Short: "Import a package into the organization: upload (or read from a bucket), plan (dry run), then apply",
		Long: "Upload a package (resuming an interrupted upload of the same file), let RunR verify it and\n" +
			"show the plan: where each project lands, name collisions, missing runtimes, quota and\n" +
			"residency checks, and what is left to do by hand (DNS of domains, secrets to enter again).\n" +
			"--region-map maps a region of the package onto one here (package region=region here),\n" +
			"--rename gives a project of the package a new name. Applying it creates everything, pushes\n" +
			"the code, restores binding data and images and, with --deploy, deploys the projects; a\n" +
			"failed import continues where it stopped when run again.\n\n" +
			"--from reads the package where an export wrote it instead of uploading a file:\n" +
			"binding:<name> (an object binding of the organization) or s3://bucket[/key] (with\n" +
			"--s3-endpoint; credentials from AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY, used for this\n" +
			"import only). RunR reads it in the background and resumes after the bytes it has;\n" +
			"--resume ID continues a read that failed.\n\n" +
			"With run history in the package (runr org export --history) it is imported as history\n" +
			"(--no-history leaves it out). --skip-data BINDING leaves one binding's data out, e.g. KV\n" +
			"data a region with an older Redis cannot take.\n\n" +
			"The passphrase of the package's secrets is read from --passphrase-file or $" + EnvImportPassphrase + ".",
		Example: "  runr org import acme.tar.zst --plan-only\n" +
			"  runr org import acme.tar.zst --region-map eu-1=fra-1 --rename api=api-copy --yes\n" +
			"  RUNR_IMPORT_PASSPHRASE=… runr org import acme.tar.zst --deploy --yes\n" +
			"  runr org import --from binding:backups --key runr-exports/runr-org-acme-20261021-120000.tar.zst\n" +
			"  runr org import --from s3://backups/dr/acme.tar.zst --s3-endpoint https://s3.example.com --plan-only\n" +
			"  runr org import --resume 01J… --yes",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			file := ""
			if len(args) == 1 {
				file = args[0]
			}
			sources := 0
			for _, set := range []bool{file != "", from != "", resume != ""} {
				if set {
					sources++
				}
			}
			if sources != 1 {
				return usageErrorf("give the package: a FILE to upload, --from binding:<name> | s3://bucket/key, or --resume ID")
			}
			if file != "" {
				if st, err := os.Stat(file); err != nil {
					return usageErrorf("%v", err)
				} else if st.IsDir() {
					return usageErrorf("%s is a directory, not a package file", file)
				}
			}
			passphrase, err := readPassphrase(passFile, EnvImportPassphrase)
			if err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}

			last := ""
			opts := runr.ImportPackageOptions{
				PollInterval: app.PollInterval,
				Progress: func(phase string, uploaded, total int64) {
					switch phase {
					case "uploading", runr.OrgTransferFetching:
						verb, what := "Uploading", file
						if phase == runr.OrgTransferFetching {
							verb, what = "Reading", "the package"
						}
						if total > 0 {
							pct := uploaded * 100 / total
							line := fmt.Sprintf("%s %s: %d%% (%s / %s)", verb, what, pct, bytesText(uploaded), bytesText(total))
							if line != last && (pct%10 == 0 || uploaded == total) {
								app.Progress("%s", line)
								last = line
							}
						} else if phase != last {
							app.Progress("%s %s…", verb, what)
							last = phase
						}
					case "resume":
						app.Progress("Resuming an earlier upload of %s at %s", file, bytesText(uploaded))
					case runr.OrgTransferVerifying:
						if phase != last {
							app.Progress("Verifying the package…")
							last = phase
						}
					}
				},
			}
			var tr *runr.OrgTransfer
			switch {
			case file != "":
				tr, err = c.ImportPackage(ctx, file, opts)
			case resume != "":
				akid, secret := os.Getenv("AWS_ACCESS_KEY_ID"), os.Getenv("AWS_SECRET_ACCESS_KEY")
				tr, err = c.ResumeImportFromSource(ctx, resume, akid, secret, opts)
			default:
				var src *runr.OrgImportSource
				if src, err = orgImportSource(cmd, c, from, key, s3Endpoint, s3Region, s3PathStyle); err != nil {
					return err
				}
				tr, err = c.ImportFromSource(ctx, *src, strings.ToLower(sum), opts)
			}
			if err != nil {
				return err
			}
			if tr.Plan == nil {
				return fmt.Errorf("import %s has no plan", tr.ID)
			}

			choices, err := importChoices(cmd, c, tr.Plan, regionMap, renames)
			if err != nil {
				return err
			}
			choices.Passphrase = passphrase
			if choices.SkipBindingData, err = planBindingRefs(tr.Plan, skipData); err != nil {
				return err
			}
			if noHistory {
				off := false
				choices.History = &off
			}
			if len(choices.RegionMap) > 0 || len(choices.Renames) > 0 || choices.Passphrase != "" || len(choices.SkipBindingData) > 0 || noHistory {
				if tr, err = c.PlanOrgImport(ctx, tr.ID, choices); err != nil {
					return err
				}
			}

			machine := app.Printer().Machine()
			if planOnly {
				return app.Printer().Print(tr, func(t *output.TableWriter) { planTable(t, tr) })
			}
			if !machine {
				if err := app.Printer().Print(tr, func(t *output.TableWriter) { planTable(t, tr) }); err != nil {
					return err
				}
			}
			plan := tr.Plan
			if plan.Blocking > 0 || !plan.CanApply {
				return &runr.PlanBlockedError{Blocking: plan.Blocking}
			}

			n := 0
			for _, p := range plan.Projects {
				if p.Status != "skip" && p.Status != "imported" {
					n++
				}
			}
			if err := app.confirm(fmt.Sprintf("Import %s from %s into this organization", countLabel(n, "project"),
				output.Dash(plan.Source.Organization)), yes); err != nil {
				return err
			}

			if tr, err = c.ApplyOrgImport(ctx, tr.ID, runr.OrgImportApply{
				OrgImportChoices: choices, RestoreData: !noData, Images: !noImages, InviteMembers: invite, Deploy: deploy,
			}); err != nil {
				return err
			}
			app.Progress("Importing (%s)…", tr.ID)
			tr, err = c.WaitOrgTransfer(ctx, tr.ID, []string{runr.OrgTransferCompleted}, runr.OrgWaitOptions{
				Timeout: timeout, Interval: app.PollInterval, Progress: orgProgress(app, "Import"),
			})
			if err != nil {
				if tr != nil && tr.Report != nil && !machine {
					// what failed goes to stderr; the exit code says the import failed
					for _, it := range tr.Report.Items {
						if it.Status == "failed" {
							app.Warn("%s %s: %s", it.Section, output.Dash(it.Name), it.Message)
						}
					}
					app.Warn("run the same `runr org import` again to continue where the import stopped")
				}
				return err
			}
			return app.Printer().Print(tr, func(t *output.TableWriter) { reportTable(t, tr) })
		},
	}
	f := imp.Flags()
	f.StringArrayVar(&regionMap, "region-map", nil, "map a region of the package onto one here: PACKAGE_REGION=REGION (repeat)")
	f.StringArrayVar(&renames, "rename", nil, "give a project of the package a new name: PROJECT=NEW_NAME (repeat)")
	f.StringVar(&passFile, "passphrase-file", "", "file holding the passphrase of the package's secrets (default $"+EnvImportPassphrase+")")
	f.BoolVar(&planOnly, "plan-only", false, "upload and print the plan (dry run) without importing")
	f.BoolVar(&deploy, "deploy", false, "deploy the imported projects")
	f.BoolVar(&invite, "invite-members", false, "invite the members listed in the package (multi-tenant installations; else a manual step)")
	f.BoolVar(&noData, "no-data", false, "do not restore the binding data of the package")
	f.BoolVar(&noImages, "no-images", false, "do not import prebuilt images (versions are built again when deployed)")
	f.BoolVar(&noHistory, "no-history", false, "do not import the run history of the package")
	f.StringArrayVar(&skipData, "skip-data", nil, "do not restore the data of this binding of the package (name or id; repeat)")
	f.StringVar(&from, "from", "", "read the package from binding:<name> or s3://bucket[/key] instead of uploading a FILE")
	f.StringVar(&key, "key", "", "object key of the package (--from binding:…; or the rest of s3://bucket/key)")
	f.StringVar(&s3Endpoint, "s3-endpoint", "", "S3 endpoint URL (--from s3://…)")
	f.StringVar(&s3Region, "s3-region", "", "S3 region (default $AWS_REGION, else us-east-1)")
	f.BoolVar(&s3PathStyle, "s3-path-style", false, "address the bucket in the URL path (S3-compatible stores that need it)")
	f.StringVar(&sum, "sha256", "", "the package's SHA-256, checked once RunR read it (--from)")
	f.StringVar(&resume, "resume", "", "continue reading the package of this import (one that failed reading it)")
	f.BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	f.DurationVar(&timeout, "timeout", 6*time.Hour, "how long to wait for the import (0: no limit)")
	return imp
}

func newOrgTransfersCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "transfers",
		Short: "List the organization's latest exports and imports",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListOrgTransfers(cmd.Context())
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.OrgTransfer{}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("ID", "KIND", "STATUS", "PROGRESS", "PACKAGE", "SIZE", "CREATED")
				for _, tr := range list {
					name, size := "-", "-"
					if tr.Package != nil {
						name = output.Dash(tr.Package.Filename)
						if tr.Package.SizeBytes > 0 {
							size = bytesText(tr.Package.SizeBytes)
						}
					}
					t.Row(tr.ID, tr.Kind, statusText(&tr), fmt.Sprintf("%d%%", tr.Progress), name, size, output.Dash(tr.CreatedAt))
				}
				if len(list) == 0 {
					t.Note("No exports or imports yet.")
				}
			})
		},
	}
}

func newOrgTransferCmd(app *App) *cobra.Command {
	show := &cobra.Command{
		Use:   "transfer ID",
		Short: "Show an export or import (cancel: stop it and remove its files)",
		Example: "  runr org transfer 01J…\n" +
			"  runr org transfer cancel 01J… --yes\n" +
			"  runr org transfer history 01J… --kind bill",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			tr, err := c.GetOrgTransfer(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(tr, func(t *output.TableWriter) {
				transferTable(t, tr)
				if tr.Plan != nil && tr.Status == runr.OrgTransferPlanned {
					planNotes(t, tr.Plan)
				}
				if tr.Report != nil && tr.Kind == "import" {
					for _, line := range reportLines(tr.Report) {
						t.Note("%s", line)
					}
				}
			})
		},
	}
	var yes bool
	cancel := &cobra.Command{
		Use:     "cancel ID",
		Aliases: []string{"rm", "delete"},
		Short:   "Cancel a running export or import, or remove a finished one and its package",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if err := app.confirm("Cancel transfer "+args[0]+" and remove its files", yes); err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			if err := c.CancelOrgTransfer(cmd.Context(), args[0]); err != nil {
				return err
			}
			app.Progress("Cancelled transfer %s.", args[0])
			return app.Printer().Print(map[string]any{"cancelled": args[0]}, func(*output.TableWriter) {})
		},
	}
	cancel.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")

	var kind string
	var page, perPage int
	history := &cobra.Command{
		Use:   "history ID",
		Short: "List the usage records and bills of the source organization an import kept (read-only)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if kind != "" && kind != "usage" && kind != "bill" {
				return usageErrorf("--kind is usage or bill")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			rows, total, err := c.ListOrgImportHistory(cmd.Context(), args[0], kind, page, perPage)
			if err != nil {
				return err
			}
			if rows == nil {
				rows = []runr.OrgHistoryRecord{}
			}
			return app.Printer().Print(rows, func(t *output.TableWriter) {
				t.Header("KIND", "SOURCE", "DATE", "SUBJECT", "PROJECT")
				for _, r := range rows {
					t.Row(r.Kind, output.Dash(r.SourceTable), output.Dash(r.PeriodDate), output.Dash(r.Subject), output.Dash(r.ProjectID))
				}
				t.Note("%d of %d record(s); -o json shows each record's data.", len(rows), total)
			})
		},
	}
	history.Flags().StringVar(&kind, "kind", "", "usage or bill (default both)")
	history.Flags().IntVar(&page, "page", 1, "page")
	history.Flags().IntVar(&perPage, "per-page", 100, "records per page (at most 500)")
	show.AddCommand(cancel, history)
	return show
}

// readPassphrase reads a passphrase from a file (trailing line break dropped) or the environment
// variable env. Empty when neither is set.
func readPassphrase(file, env string) (string, error) {
	if file != "" {
		data, err := os.ReadFile(file)
		if err != nil {
			return "", usageErrorf("--passphrase-file: %v", err)
		}
		return strings.TrimRight(string(data), "\r\n"), nil
	}
	return os.Getenv(env), nil
}

// orgBindingRefs resolves --binding-data values: binding names or ids, or "all".
func orgBindingRefs(cmd *cobra.Command, c *runr.Client, refs []string) ([]string, bool, error) {
	var ids []string
	for _, ref := range refs {
		if strings.EqualFold(strings.TrimSpace(ref), "all") {
			return nil, true, nil
		}
		b, err := c.FindBinding(cmd.Context(), ref)
		if err != nil {
			return nil, false, err
		}
		ids = append(ids, b.ID)
	}
	return ids, false, nil
}

// orgDestination turns --to into the export's destination. S3 credentials come from the
// environment only.
func orgDestination(cmd *cobra.Command, c *runr.Client, to, prefix, endpoint, region string, pathStyle bool) (*runr.OrgExportDestination, error) {
	to = strings.TrimSpace(to)
	switch {
	case to == "" || to == "download":
		return &runr.OrgExportDestination{Type: "download"}, nil
	case strings.HasPrefix(to, "binding:"):
		ref := strings.TrimPrefix(to, "binding:")
		if ref == "" {
			return nil, usageErrorf("--to binding:<name> needs the name of an object binding")
		}
		b, err := c.FindBinding(cmd.Context(), ref)
		if err != nil {
			return nil, err
		}
		if b.Type != "object" {
			return nil, usageErrorf("binding %s is a %s binding; the destination must be an object binding", b.Name, b.Type)
		}
		return &runr.OrgExportDestination{Type: "binding", BindingID: b.ID, Prefix: prefix}, nil
	case strings.HasPrefix(to, "s3://"):
		bucket, keyPrefix, _ := strings.Cut(strings.TrimPrefix(to, "s3://"), "/")
		if bucket == "" {
			return nil, usageErrorf("--to s3://bucket[/prefix] needs a bucket")
		}
		if endpoint == "" {
			return nil, usageErrorf("--to s3://… needs --s3-endpoint")
		}
		key, secret := os.Getenv("AWS_ACCESS_KEY_ID"), os.Getenv("AWS_SECRET_ACCESS_KEY")
		if key == "" || secret == "" {
			return nil, usageErrorf("--to s3://… needs AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY in the environment")
		}
		if region == "" {
			region = os.Getenv("AWS_REGION")
		}
		if region == "" {
			region = os.Getenv("AWS_DEFAULT_REGION")
		}
		if region == "" {
			region = "us-east-1"
		}
		if prefix != "" {
			keyPrefix = strings.Trim(strings.TrimSuffix(keyPrefix, "/")+"/"+prefix, "/")
		}
		dest := &runr.OrgExportDestination{Type: "s3", Endpoint: endpoint, Bucket: bucket, Region: region,
			AccessKeyID: key, SecretAccessKey: secret, Prefix: strings.Trim(keyPrefix, "/")}
		if cmd.Flags().Changed("s3-path-style") {
			dest.PathStyle = &pathStyle
		}
		return dest, nil
	}
	return nil, usageErrorf("unknown --to %q (download, binding:<name> or s3://bucket[/prefix])", to)
}

// orgImportSource turns --from (and --key) into the source an import reads its package from.
// S3 credentials come from the environment only.
func orgImportSource(cmd *cobra.Command, c *runr.Client, from, key, endpoint, region string, pathStyle bool) (*runr.OrgImportSource, error) {
	from = strings.TrimSpace(from)
	key = strings.TrimLeft(strings.TrimSpace(key), "/")
	switch {
	case strings.HasPrefix(from, "binding:"):
		ref := strings.TrimPrefix(from, "binding:")
		if ref == "" {
			return nil, usageErrorf("--from binding:<name> needs the name of an object binding")
		}
		if key == "" {
			return nil, usageErrorf("--from binding:… needs --key (the package's object key)")
		}
		b, err := c.FindBinding(cmd.Context(), ref)
		if err != nil {
			return nil, err
		}
		if b.Type != "object" {
			return nil, usageErrorf("binding %s is a %s binding; the package is read from an object binding", b.Name, b.Type)
		}
		return &runr.OrgImportSource{Type: "binding", BindingID: b.ID, Key: key}, nil
	case strings.HasPrefix(from, "s3://"):
		bucket, rest, _ := strings.Cut(strings.TrimPrefix(from, "s3://"), "/")
		if bucket == "" {
			return nil, usageErrorf("--from s3://bucket/key needs a bucket")
		}
		switch {
		case key != "" && rest != "":
			key = strings.TrimSuffix(rest, "/") + "/" + key
		case key == "":
			key = rest
		}
		if key == "" || strings.HasSuffix(key, "/") {
			return nil, usageErrorf("--from s3://bucket/key needs the package's key (in the URL or --key)")
		}
		if endpoint == "" {
			return nil, usageErrorf("--from s3://… needs --s3-endpoint")
		}
		akid, secret := os.Getenv("AWS_ACCESS_KEY_ID"), os.Getenv("AWS_SECRET_ACCESS_KEY")
		if akid == "" || secret == "" {
			return nil, usageErrorf("--from s3://… needs AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY in the environment")
		}
		if region == "" {
			region = os.Getenv("AWS_REGION")
		}
		if region == "" {
			region = os.Getenv("AWS_DEFAULT_REGION")
		}
		if region == "" {
			region = "us-east-1"
		}
		src := &runr.OrgImportSource{Type: "s3", Endpoint: endpoint, Bucket: bucket, Region: region, Key: key, AccessKeyID: akid, SecretAccessKey: secret}
		if cmd.Flags().Changed("s3-path-style") {
			src.PathStyle = &pathStyle
		}
		return src, nil
	}
	return nil, usageErrorf("unknown --from %q (binding:<name> or s3://bucket/key)", from)
}

// planBindingRefs resolves --skip-data values against the bindings of the plan (source name or
// id) to source binding ids.
func planBindingRefs(plan *runr.OrgImportPlan, refs []string) ([]string, error) {
	var ids []string
	for _, ref := range refs {
		found := ""
		for _, b := range plan.Bindings {
			if b.SourceID == ref || b.Name == ref {
				found = b.SourceID
				break
			}
		}
		if found == "" {
			return nil, usageErrorf("--skip-data: the package has no binding %q", ref)
		}
		ids = append(ids, found)
	}
	return ids, nil
}

// downloadOrgPackage saves the package of a completed export to outFile (default the package's
// file name; - for stdout), resuming a partial file of the same name, and checks its SHA-256.
// It returns where the package went.
func downloadOrgPackage(cmd *cobra.Command, app *App, c *runr.Client, tr *runr.OrgTransfer, outFile string) (string, error) {
	pkg := tr.Package
	if !pkg.Downloadable {
		return "", fmt.Errorf("the package of export %s cannot be downloaded (expired or removed)", tr.ID)
	}
	if outFile == "-" {
		if _, err := c.DownloadOrgExport(cmd.Context(), tr.ID, app.Out, 0, nil); err != nil {
			return "", err
		}
		return "-", nil
	}
	if outFile == "" {
		outFile = pkg.Filename
	}

	var offset int64
	if st, err := os.Stat(outFile); err == nil && !st.IsDir() && st.Size() > 0 && st.Size() <= pkg.SizeBytes {
		if st.Size() == pkg.SizeBytes {
			if sum, err := sha256File(outFile); err == nil && sum == pkg.SHA256 {
				app.Progress("%s is already downloaded.", outFile)
				return outFile, nil
			}
		} else {
			offset = st.Size()
			app.Progress("Resuming the download of %s at %s", outFile, bytesText(offset))
		}
	}
	// Not O_APPEND for a resume: on Windows a file opened for appending cannot be truncated, which
	// restart() needs when the API ignores the range — write from the offset instead
	flags := os.O_CREATE | os.O_WRONLY | os.O_TRUNC
	if offset > 0 {
		flags = os.O_CREATE | os.O_WRONLY
	}
	f, err := os.OpenFile(outFile, flags, 0o600)
	if err != nil {
		return "", err
	}
	defer f.Close()
	if offset > 0 {
		if _, err := f.Seek(offset, io.SeekStart); err != nil {
			return "", err
		}
	}
	restart := func() error {
		// the API sent the whole file: start over
		if err := f.Truncate(0); err != nil {
			return err
		}
		_, err := f.Seek(0, io.SeekStart)
		return err
	}
	app.Progress("Downloading %s (%s)…", pkg.Filename, bytesText(pkg.SizeBytes))
	if _, err := c.DownloadOrgExport(cmd.Context(), tr.ID, f, offset, restart); err != nil {
		return "", err
	}
	if err := f.Close(); err != nil {
		return "", err
	}
	if pkg.SHA256 != "" {
		sum, err := sha256File(outFile)
		if err != nil {
			return "", err
		}
		if sum != pkg.SHA256 {
			return "", fmt.Errorf("%s does not match the package's SHA-256 (%s); remove it and download again", outFile, pkg.SHA256)
		}
	}
	app.Progress("Saved %s (%s).", outFile, bytesText(pkg.SizeBytes))
	return outFile, nil
}

func sha256File(path string) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}

// importChoices turns --region-map and --rename into the import's choices, naming the package's
// regions and projects by name or id, and the regions here by name or id.
func importChoices(cmd *cobra.Command, c *runr.Client, plan *runr.OrgImportPlan, regionMap, renames []string) (runr.OrgImportChoices, error) {
	var choices runr.OrgImportChoices
	pairs, err := parseKeyValues("--region-map", regionMap)
	if err != nil {
		return choices, err
	}
	for src, dst := range pairs {
		var source *runr.OrgPlanRegion
		for i := range plan.Regions {
			if r := &plan.Regions[i]; r.SourceID == src || r.SourceName == src {
				source = r
				break
			}
		}
		if source == nil {
			names := make([]string, 0, len(plan.Regions))
			for _, r := range plan.Regions {
				names = append(names, r.SourceName)
			}
			return choices, usageErrorf("--region-map: the package has no region %q (it has: %s)", src, joinOrDash(names))
		}
		target, err := c.FindRegion(cmd.Context(), dst)
		if err != nil {
			return choices, err
		}
		if choices.RegionMap == nil {
			choices.RegionMap = map[string]string{}
		}
		choices.RegionMap[source.SourceID] = target.ID
	}
	pairs, err = parseKeyValues("--rename", renames)
	if err != nil {
		return choices, err
	}
	for src, name := range pairs {
		var source *runr.OrgPlanProject
		for i := range plan.Projects {
			if p := &plan.Projects[i]; p.SourceID == src || p.Name == src {
				source = p
				break
			}
		}
		if source == nil {
			return choices, usageErrorf("--rename: the package has no project %q", src)
		}
		if strings.TrimSpace(name) == "" {
			return choices, usageErrorf("--rename %s= needs a new name", src)
		}
		if choices.Renames == nil {
			choices.Renames = map[string]string{}
		}
		choices.Renames[source.SourceID] = strings.TrimSpace(name)
	}
	return choices, nil
}

// orgProgress prints a line per status / phase change and per 10% of progress.
func orgProgress(app *App, what string) func(t *runr.OrgTransfer) {
	last := ""
	return func(t *runr.OrgTransfer) {
		line := fmt.Sprintf("%s: %s %d%%", what, statusText(t), t.Progress/10*10)
		if t.Message != "" {
			line += " — " + t.Message
		}
		key := fmt.Sprintf("%s/%s/%d", t.Status, t.Phase, t.Progress/10)
		if key != last {
			app.Progress("%s", line)
			last = key
		}
	}
}

func statusText(t *runr.OrgTransfer) string {
	if t.Phase != "" && (t.Status == runr.OrgTransferRunning || t.Status == runr.OrgTransferApplying) {
		return t.Status + " (" + t.Phase + ")"
	}
	return t.Status
}

func transferTable(t *output.TableWriter, tr *runr.OrgTransfer) {
	t.KV("ID", tr.ID)
	t.KV("Kind", tr.Kind)
	t.KV("Status", statusText(tr))
	t.KV("Progress", fmt.Sprintf("%d%%", tr.Progress))
	if tr.Message != "" {
		t.KV("Message", tr.Message)
	}
	if d := tr.Destination; d != nil {
		switch d.Type {
		case "binding":
			t.KV("Destination", "binding "+output.Dash(d.BindingName)+" ("+output.Dash(d.Key)+")")
		case "s3":
			t.KV("Destination", "s3://"+d.Bucket+"/"+d.Key+" ("+d.Endpoint+")")
		default:
			t.KV("Destination", d.Type)
		}
	}
	if p := tr.Package; p != nil && p.Filename != "" {
		t.KV("Package", p.Filename)
		if p.SizeBytes > 0 {
			t.KV("Size", bytesText(p.SizeBytes))
		}
		if p.SHA256 != "" {
			t.KV("SHA-256", p.SHA256)
		}
		if p.SchemaVersion > 0 {
			t.KV("Format", fmt.Sprintf("schema %d, RunR %s", p.SchemaVersion, output.Dash(p.RunrVersion)))
		}
		if p.Source.Organization != "" {
			t.KV("Source", p.Source.Organization+" ("+output.Dash(p.Source.ExportedAt)+")")
		}
	}
	if s := tr.Source; s != nil && s.Type != "" && s.Type != "upload" {
		switch s.Type {
		case "binding":
			t.KV("Source", "binding "+output.Dash(s.BindingName)+" ("+output.Dash(s.Key)+")")
		case "s3":
			t.KV("Source", "s3://"+s.Bucket+"/"+s.Key+" ("+s.Endpoint+")")
		}
	}
	if u := tr.Upload; u != nil && (tr.Status == runr.OrgTransferUploading || tr.Status == runr.OrgTransferFetching) {
		label := "Uploaded"
		if tr.Status == runr.OrgTransferFetching {
			label = "Read"
		}
		t.KV(label, bytesText(u.UploadedBytes)+" / "+bytesText(u.SizeBytes))
	}
	if tr.CreatedBy != nil {
		t.KV("Started by", tr.CreatedBy.Name)
	}
	t.KV("Created", output.Dash(tr.CreatedAt))
	if tr.ExpiresAt != "" {
		t.KV("Expires", tr.ExpiresAt)
	}
}

func estimateTable(t *output.TableWriter, est *runr.OrgExportEstimate) {
	t.KV("Projects", fmt.Sprint(est.Projects))
	t.KV("Versions", fmt.Sprint(est.Versions))
	t.KV("Code", bytesText(est.CodeBytes))
	t.KV("Binding data", bytesText(est.BindingDataBytes))
	images := fmt.Sprintf("%d", est.Images.Count)
	if est.Images.Bytes != nil {
		images += " (" + bytesText(*est.Images.Bytes) + ")"
	} else if est.Images.Count > 0 {
		images += " (size known when pulled)"
	}
	t.KV("Images", images)
	t.KV("Total (at least)", bytesText(est.TotalBytes))
	if len(est.Bindings) > 0 {
		t.Header("BINDING", "TYPE", "REGION", "DATA", "SIZE")
		for _, b := range est.Bindings {
			data := "yes"
			if !b.DataSupported {
				data = "no"
			}
			t.Row(b.Name, b.Type, output.Dash(b.Region), data, bytesText(b.UsageBytes))
		}
	}
	for _, w := range est.Warnings {
		t.Note("Note: %s", w)
	}
}

func planTable(t *output.TableWriter, tr *runr.OrgTransfer) {
	plan := tr.Plan
	t.KV("Import", tr.ID)
	src := plan.Source
	t.KV("Source", fmt.Sprintf("%s (RunR %s, schema %d, exported %s)", output.Dash(src.Organization), output.Dash(src.RunrVersion), src.SchemaVersion, output.Dash(src.ExportedAt)))
	if len(plan.Contents) > 0 {
		keys := make([]string, 0, len(plan.Contents))
		for k := range plan.Contents {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		parts := make([]string, 0, len(keys))
		for _, k := range keys {
			parts = append(parts, fmt.Sprintf("%s %v", k, plan.Contents[k]))
		}
		t.KV("Contents", strings.Join(parts, ", "))
	}
	for _, r := range plan.Regions {
		t.KV("Region "+r.SourceName, "→ "+output.Dash(r.TargetName))
	}
	t.KV("Blocking issues", fmt.Sprint(plan.Blocking))
	t.KV("Warnings", fmt.Sprint(plan.Warnings))

	t.Header("PROJECT", "TARGET NAME", "TYPE", "RUNTIME", "REGION", "VERSIONS", "STATUS", "ISSUES")
	for _, p := range plan.Projects {
		runtime := p.Runtime
		if p.RuntimeVersion != "" {
			runtime += " " + p.RuntimeVersion
		}
		t.Row(p.Name, p.TargetName, p.ServiceType, output.Dash(runtime), output.Dash(p.TargetRegion),
			fmt.Sprint(p.Versions), output.Dash(p.Status), issueSummary(p.Issues))
	}
	for _, b := range plan.Bindings {
		t.Row("binding "+b.Name, b.TargetName, b.Type, "-", "-", "-", output.Dash(b.Status), issueSummary(b.Issues))
	}
	planNotes(t, plan)
}

// planNotes lists the plan's errors and warnings and the steps it leaves to the user.
func planNotes(t *output.TableWriter, plan *runr.OrgImportPlan) {
	for _, p := range plan.Projects {
		for _, is := range p.Issues {
			if is.Level == "error" || is.Level == "warning" {
				t.Note("%s %s: %s", strings.ToUpper(is.Level), p.Name, is.Message)
			}
		}
	}
	for _, b := range plan.Bindings {
		for _, is := range b.Issues {
			if is.Level == "error" || is.Level == "warning" {
				t.Note("%s binding %s: %s", strings.ToUpper(is.Level), b.Name, is.Message)
			}
		}
	}
	for _, is := range plan.Issues {
		if is.Level == "error" || is.Level == "warning" {
			t.Note("%s %s", strings.ToUpper(is.Level), is.Message)
		}
	}
	if h := plan.History; h != nil {
		deployments, runs, events, dropped := 0, 0, 0, 0
		for _, p := range h.Projects {
			deployments += p.Deployments
			runs += p.WorkflowRuns
			events += p.TriggerEvents
			for _, n := range p.DroppedByRetention {
				dropped += n
			}
		}
		state := "imported"
		if !h.Import {
			state = "not imported"
		}
		t.Note("History (%s): %d deployment(s), %d workflow run(s), %d trigger event(s), %d usage record(s), %d bill(s); %d past the retention here", state, deployments, runs, events, h.UsageRecords, h.Bills, dropped)
	}
	for _, step := range plan.ManualSteps {
		line := "TO DO: " + step.Message
		if len(step.Items) > 0 {
			line += " (" + strings.Join(step.Items, ", ") + ")"
		}
		t.Note("%s", line)
	}
}

func issueSummary(issues []runr.OrgPlanIssue) string {
	if len(issues) == 0 {
		return "-"
	}
	counts := map[string]int{}
	for _, is := range issues {
		counts[is.Level]++
	}
	var parts []string
	for _, level := range []string{"error", "warning", "manual"} {
		if n := counts[level]; n > 0 {
			parts = append(parts, fmt.Sprintf("%d %s", n, level))
		}
	}
	return strings.Join(parts, ", ")
}

func reportTable(t *output.TableWriter, tr *runr.OrgTransfer) {
	t.KV("Import", tr.ID)
	t.KV("Status", tr.Status)
	if tr.Report == nil {
		return
	}
	for _, line := range reportLines(tr.Report) {
		t.Note("%s", line)
	}
}

// reportLines summarizes an import report: the counts, what failed or needs a manual step, and
// the steps left to the user.
func reportLines(r *runr.OrgTransferReport) []string {
	var lines []string
	if len(r.Counts) > 0 {
		var parts []string
		for _, k := range []string{"created", "exists", "skipped", "failed", "manual"} {
			if n, ok := r.Counts[k]; ok {
				parts = append(parts, fmt.Sprintf("%d %s", n, k))
			}
		}
		lines = append(lines, "Result: "+strings.Join(parts, ", "))
	}
	for _, it := range r.Items {
		if it.Status == "failed" || it.Status == "manual" {
			line := fmt.Sprintf("%s %s %s", strings.ToUpper(it.Status), it.Section, output.Dash(it.Name))
			if it.Message != "" {
				line += ": " + it.Message
			}
			lines = append(lines, line)
		}
	}
	for _, step := range r.ManualSteps {
		line := "TO DO: " + step.Message
		if len(step.Items) > 0 {
			line += " (" + strings.Join(step.Items, ", ") + ")"
		}
		lines = append(lines, line)
	}
	for _, w := range r.Warnings {
		lines = append(lines, "Warning: "+w)
	}
	return lines
}

func reportWarnings(tr *runr.OrgTransfer) []string {
	if tr.Report == nil {
		return nil
	}
	out := append([]string(nil), tr.Report.Warnings...)
	for _, s := range tr.Report.Skipped {
		out = append(out, fmt.Sprintf("left out %s %s: %s", s.Section, s.Name, s.Message))
	}
	return out
}
