package cmd

import (
	"context"
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() {
	registerCommand(newSecurityCmd)
	registerCommand(newResidencyCmd)
}

var securitySeverityRank = map[string]int{"critical": 4, "high": 3, "medium": 2, "low": 1}

func newSecurityCmd(app *App) *cobra.Command {
	var (
		versionRef, deploymentID, sbomFile, sbomFormat, failOn string
		live, scan, wait, allFindings                          bool
		timeout                                                time.Duration
	)
	cmd := &cobra.Command{
		Use:   "security [PROJECT]",
		Short: "Supply-chain report of a project: SBOM, vulnerabilities, signature, deploy policy",
		Long: "Without --version: every version's report, the deployments and the region's policy.\n" +
			"With --version (v3, 3, latest or an id), --deployment ID or --live: the full report of\n" +
			"that version with its findings and whether the policy lets it deploy.\n\n" +
			"--scan queues a new scan (with --wait: until it finished), --sbom FILE downloads the\n" +
			"version's SBOM (SPDX or CycloneDX JSON), --fail-on exits 6 when the report has findings\n" +
			"of that severity or higher (or, with policy, when the policy blocks the version).",
		Example: "  runr security\n" +
			"  runr security orders-api --version v7\n" +
			"  runr security --live --fail-on critical\n" +
			"  runr security --version latest --scan --wait\n" +
			"  runr security --version v7 --sbom sbom.spdx.json\n" +
			"  runr security --version v7 --sbom - --sbom-format cyclonedx-json > bom.cdx.json",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) == 1 {
				app.ProjectRef = args[0]
			}
			if failOn != "" && failOn != "policy" && securitySeverityRank[strings.ToLower(failOn)] == 0 {
				return usageErrorf("--fail-on must be critical, high, medium, low or policy")
			}
			switch sbomFormat {
			case runr.SBOMSPDX, runr.SBOMCycloneDX:
			default:
				return usageErrorf("--sbom-format must be %s or %s", runr.SBOMSPDX, runr.SBOMCycloneDX)
			}
			if wait && !scan {
				return usageErrorf("--wait goes with --scan")
			}
			n := 0
			for _, set := range []bool{versionRef != "", deploymentID != "", live} {
				if set {
					n++
				}
			}
			if n > 1 {
				return usageErrorf("give one of --version, --deployment and --live")
			}
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			if versionRef == "" && deploymentID == "" && !live && !scan && sbomFile == "" {
				ov, err := c.ProjectSecurity(ctx, p.ID)
				if err != nil {
					return err
				}
				if err := printSecurityOverview(app, p, ov); err != nil {
					return err
				}
				return securityGate(failOn, securityLatestReport(ov), nil)
			}

			var sec *runr.VersionSecurity
			var v *runr.Version
			switch {
			case deploymentID != "" || live:
				if live {
					d, err := c.CurrentDeployment(ctx, p.ID)
					if err != nil {
						return err
					}
					if d == nil {
						return &runr.NotFoundError{Kind: "deployment of project", Ref: p.Name}
					}
					deploymentID = d.ID
				}
				sec, err = c.DeploymentSecurityReport(ctx, p.ID, deploymentID)
				if err != nil {
					return err
				}
				v = &runr.Version{ID: sec.Version.ID, Number: sec.Version.Number, Label: sec.Version.Label}
			default:
				v, err = c.FindVersion(ctx, p.ID, versionRef)
				if err != nil {
					return err
				}
			}

			if scan {
				r, err := c.ScanVersion(ctx, p.ID, v.ID)
				if err != nil {
					return err
				}
				app.Progress("Scan of %s queued (%s).", v.Label, output.Dash(r.Status))
				if wait {
					sec, err = c.WaitSecurityReport(ctx, p.ID, v, app.waitOptions(timeout, "Scan of "+v.Label))
					if err != nil {
						if sec != nil {
							_ = printVersionSecurity(app, p, sec, allFindings)
						}
						return err
					}
				} else if sbomFile == "" {
					return app.Printer().Print(r, func(t *output.TableWriter) {
						t.KV("Version", v.Label)
						t.KV("Report", r.ID)
						t.KV("Status", output.Dash(r.Status))
						t.Note("Follow it with `runr security --version %s`.", v.Label)
					})
				}
			}

			if sbomFile != "" {
				w, closeOut, err := createOut(app, sbomFile)
				if err != nil {
					return err
				}
				d := &runr.Download{W: w}
				err = c.DownloadSBOM(ctx, p.ID, v.ID, sbomFormat, d)
				if cerr := closeOut(); err == nil {
					err = cerr
				}
				if err != nil {
					return err
				}
				if sbomFile != "-" {
					app.Progress("SBOM of %s (%s, %d bytes) written to %s.", v.Label, sbomFormat, d.Bytes, sbomFile)
				}
				if sec == nil {
					return nil
				}
			}

			if sec == nil {
				sec, err = c.VersionSecurityReport(ctx, p.ID, v.ID)
				if err != nil {
					return err
				}
			}
			if sbomFile != "-" {
				if err := printVersionSecurity(app, p, sec, allFindings); err != nil {
					return err
				}
			}
			return securityGate(failOn, sec.Report, sec.Decision)
		},
	}
	f := cmd.Flags()
	f.StringVar(&versionRef, "version", "", "version to report on (v3, 3, latest or an id)")
	f.StringVar(&deploymentID, "deployment", "", "report on the version a deployment runs")
	f.BoolVar(&live, "live", false, "report on the version of the live deployment")
	f.BoolVar(&scan, "scan", false, "queue a new scan of the version")
	f.BoolVar(&wait, "wait", false, "with --scan: wait until the scan finished")
	f.DurationVar(&timeout, "timeout", 15*time.Minute, "how long --wait waits")
	f.StringVar(&sbomFile, "sbom", "", "download the version's SBOM to this file (- for stdout)")
	f.StringVar(&sbomFormat, "sbom-format", runr.SBOMSPDX, "SBOM format: spdx-json or cyclonedx-json")
	f.StringVar(&failOn, "fail-on", "", "exit 6 on findings of this severity or higher (critical, high, medium, low) or when the policy blocks the version (policy)")
	f.BoolVar(&allFindings, "all-findings", false, "list every finding (default: the first 20)")
	return cmd
}

// securityLatestReport is the report of the newest version that has one.
func securityLatestReport(ov *runr.SecurityOverview) *runr.SecurityReport {
	for _, v := range ov.Versions {
		if v.Report != nil {
			return v.Report
		}
	}
	return nil
}

// securityGate turns --fail-on into an error (exit code 6).
func securityGate(failOn string, r *runr.SecurityReport, d *runr.SecurityDecision) error {
	if failOn == "" {
		return nil
	}
	if failOn == "policy" {
		if d != nil && d.Blocked {
			return &runr.FailedError{What: "security policy check of", Ref: securityReportRef(r), Status: "blocked",
				Message: "the region's policy blocks this version"}
		}
		return nil
	}
	if r == nil {
		return &runr.FailedError{What: "security check of", Ref: "the version", Status: "no report",
			Message: "the version has no supply-chain report"}
	}
	min := securitySeverityRank[strings.ToLower(failOn)]
	counts := map[string]int{"critical": r.Counts.Critical, "high": r.Counts.High, "medium": r.Counts.Medium, "low": r.Counts.Low}
	var hits []string
	for _, sev := range []string{"critical", "high", "medium", "low"} {
		if securitySeverityRank[sev] >= min && counts[sev] > 0 {
			hits = append(hits, fmt.Sprintf("%d %s", counts[sev], sev))
		}
	}
	if len(hits) > 0 {
		return &runr.FailedError{What: "security check of", Ref: securityReportRef(r), Status: "findings",
			Message: strings.Join(hits, ", ") + " (--fail-on " + failOn + ")"}
	}
	return nil
}

func securityReportRef(r *runr.SecurityReport) string {
	if r == nil {
		return "the version"
	}
	if r.ImageRef != "" {
		return r.ImageRef
	}
	return r.ID
}

func securityCounts(r *runr.SecurityReport) (string, string, string, string) {
	if r == nil {
		return "-", "-", "-", "-"
	}
	return strconv.Itoa(r.Counts.Critical), strconv.Itoa(r.Counts.High), strconv.Itoa(r.Counts.Medium), strconv.Itoa(r.Counts.Low)
}

func securityReportStatus(r *runr.SecurityReport) string {
	if r == nil {
		return "no report"
	}
	return r.Status
}

func printSecurityOverview(app *App, p *runr.Project, ov *runr.SecurityOverview) error {
	return app.Printer().Print(ov, func(t *output.TableWriter) {
		t.KV("Project", p.Name)
		if ov.Region != nil {
			loc := ""
			if ov.Region.DataLocation != "" {
				loc = " (data location " + ov.Region.DataLocation + ")"
			}
			t.KV("Region", ov.Region.Name+loc)
		}
		if mode, ok := ov.Policy["mode"].(string); ok {
			t.KV("Deploy policy", mode)
		}
		if ov.Pipeline != nil {
			var on []string
			for _, k := range []string{"sbom", "scan", "signing"} {
				if b, _ := ov.Pipeline[k].(bool); b {
					on = append(on, k)
				}
			}
			if adm, _ := ov.Pipeline["admission"].(string); adm != "" && adm != "off" {
				on = append(on, "admission "+adm)
			}
			t.KV("Pipeline", joinOrDash(on))
		}
		live := map[string]bool{}
		for _, d := range ov.Deployments {
			if d.Status == runr.DeploymentActive {
				live[d.VersionID] = true
			}
		}
		t.Header("VERSION", "STATUS", "CRITICAL", "HIGH", "MEDIUM", "LOW", "SIGNATURE", "LIVE", "IMAGE")
		for _, v := range ov.Versions {
			cr, hi, me, lo := securityCounts(v.Report)
			sig := "-"
			if v.Report != nil {
				sig = output.Dash(v.Report.SignatureStatus)
			}
			status := securityReportStatus(v.Report)
			if !v.Applicable {
				status = "not applicable"
			}
			t.Row(v.Label, status, cr, hi, me, lo, sig, yesNo(live[v.ID]), output.Dash(v.Image))
		}
		if len(ov.Versions) == 0 {
			t.Note("No versions yet.")
		} else {
			t.Note("Details of a version: runr security --version VERSION")
		}
	})
}

func printVersionSecurity(app *App, p *runr.Project, sec *runr.VersionSecurity, all bool) error {
	return app.Printer().Print(sec, func(t *output.TableWriter) {
		t.KV("Project", p.Name)
		t.KV("Version", sec.Version.Label)
		if sec.Version.Image != "" {
			t.KV("Image", sec.Version.Image)
		}
		r := sec.Report
		if r == nil {
			if !sec.Version.Applicable && sec.Version.ID != "" {
				t.KV("Report", "not applicable (no image to scan)")
			} else {
				t.KV("Report", "no report; queue a scan with runr security --version "+sec.Version.Label+" --scan")
			}
		} else {
			if r.ImageDigest != "" {
				t.KV("Digest", r.ImageDigest)
			}
			t.KV("Status", fmt.Sprintf("%s (SBOM %s, scan %s, signature %s)", r.Status, output.Dash(r.SBOMStatus),
				output.Dash(r.ScanStatus), output.Dash(r.SignatureStatus)))
			t.KV("Vulnerabilities", fmt.Sprintf("%d critical, %d high, %d medium, %d low (fixable: %d critical, %d high)",
				r.Counts.Critical, r.Counts.High, r.Counts.Medium, r.Counts.Low, r.Fixable.Critical, r.Fixable.High))
			if r.Packages != nil {
				t.KV("Packages", strconv.Itoa(*r.Packages))
			}
			if r.ScannedAt != "" {
				t.KV("Scanned", r.ScannedAt)
			}
			if len(r.SBOMFormats) > 0 {
				t.KV("SBOM formats", strings.Join(r.SBOMFormats, ", "))
			}
			if r.Error != "" {
				t.KV("Error", r.Error)
			}
		}
		if d := sec.Decision; d != nil {
			verdict := "allowed"
			switch {
			case !d.Applicable:
				verdict = "not applicable"
			case d.Blocked:
				verdict = "blocked"
			case d.Overridden:
				verdict = "allowed (policy overridden)"
			case len(d.Violations) > 0:
				verdict = fmt.Sprintf("allowed with %d warning(s)", len(d.Violations))
			}
			t.KV("Deploy policy", verdict)
		}
		if r != nil && len(r.Findings) > 0 {
			t.Header("ID", "SEVERITY", "PACKAGE", "INSTALLED", "FIXED")
			limit := len(r.Findings)
			if !all && limit > 20 {
				limit = 20
			}
			for _, f := range r.Findings[:limit] {
				t.Row(f.ID, f.Severity, f.Package, output.Dash(f.Installed), output.Dash(f.Fixed))
			}
			if limit < len(r.Findings) {
				t.Note("%d of %d findings; --all-findings lists all, -o json includes them.", limit, len(r.Findings))
			}
		}
	})
}

func newResidencyCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "residency",
		Short: "Data residency: the regions your organization may deploy to",
		Long: "Without a subcommand: the regions your organization may create projects in. On an\n" +
			"installation with several organizations, `residency policy` shows an organization's\n" +
			"security policy (its residency and its own policy gate) and `residency set` changes it.",
		Example: "  runr residency\n" +
			"  runr residency policy\n" +
			"  runr residency set --allow-location TR --default-region ist-1\n" +
			"  runr residency set --tenant 01J… --gate-mode enforce --gate-max-critical 0",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			res, err := c.GetResidency(cmd.Context())
			if err != nil {
				return err
			}
			return printResidency(cmd.Context(), app, res)
		},
	}
	cmd.AddCommand(newResidencyPolicyCmd(app), newResidencySetCmd(app))
	return cmd
}

func printResidency(_ context.Context, app *App, res *runr.Residency) error {
	return app.Printer().Print(res, func(t *output.TableWriter) {
		state := "every region"
		if res.Restricted {
			state = "restricted to the allowed regions"
		}
		t.KV("Projects", state)
		t.KV("Enforced", yesNo(res.ResidencyEnforced))
		if len(res.AllowedLocations) > 0 {
			t.KV("Allowed locations", strings.Join(res.AllowedLocations, ", "))
		}
		t.Header("REGION", "DATA LOCATION", "ALLOWED", "DEFAULT")
		for _, r := range res.Regions {
			t.Row(r.Name, output.Dash(r.DataLocation), yesNo(r.Allowed), yesNo(r.ID == res.DefaultRegionID && r.ID != ""))
		}
	})
}
