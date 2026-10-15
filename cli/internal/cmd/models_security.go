package cmd

import (
	"fmt"
	"strconv"
	"strings"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// `runr models services security`: the supply-chain report of a model service's image.

func newModelServiceSecurityCmd(app *App) *cobra.Command {
	var sbomFile, sbomFormat, failOn string
	var allFindings bool
	cmd := &cobra.Command{
		Use:   "security SERVICE",
		Short: "Supply-chain report of a model service's image: SBOM, vulnerabilities, signature",
		Long: "The supply-chain report of the image a model service runs (the engine's image or the\n" +
			"service's own), scanned like a container project's image when the region scans external\n" +
			"images. --sbom FILE downloads its SBOM (SPDX or CycloneDX JSON, - for stdout); --fail-on\n" +
			"exits 6 when the report has findings of that severity or higher.",
		Example: "  runr models services security chat\n" +
			"  runr models services security chat --fail-on critical\n" +
			"  runr models services security chat --sbom chat.spdx.json",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if failOn != "" && securitySeverityRank[strings.ToLower(failOn)] == 0 {
				return usageErrorf("--fail-on must be critical, high, medium or low")
			}
			switch sbomFormat {
			case runr.SBOMSPDX, runr.SBOMCycloneDX:
			default:
				return usageErrorf("--sbom-format must be %s or %s", runr.SBOMSPDX, runr.SBOMCycloneDX)
			}
			ctx := cmd.Context()
			c, svc, err := findModelService(ctx, app, args[0])
			if err != nil {
				return err
			}
			if sbomFile != "" {
				w, closeOut, err := createOut(app, sbomFile)
				if err != nil {
					return err
				}
				d := &runr.Download{W: w}
				err = c.DownloadModelServiceSBOM(ctx, svc.ID, sbomFormat, d)
				if cerr := closeOut(); err == nil {
					err = cerr
				}
				if err != nil {
					return err
				}
				if sbomFile == "-" {
					return nil
				}
				app.Progress("SBOM of %s (%s, %d bytes) written to %s.", svc.Name, sbomFormat, d.Bytes, sbomFile)
			}
			sec, err := c.ModelServiceSecurity(ctx, svc.ID)
			if err != nil {
				return err
			}
			if err := printModelServiceSecurity(app, svc, sec, allFindings); err != nil {
				return err
			}
			if failOn != "" && sec.Report == nil {
				return &runr.FailedError{What: "security check of model service", Ref: svc.Name, Status: "no report",
					Message: "the image has no supply-chain report"}
			}
			return securityGate(strings.ToLower(failOn), sec.Report, nil)
		},
	}
	f := cmd.Flags()
	f.StringVar(&sbomFile, "sbom", "", "download the image's SBOM to this file (- for stdout)")
	f.StringVar(&sbomFormat, "sbom-format", runr.SBOMSPDX, "SBOM format: spdx-json or cyclonedx-json")
	f.StringVar(&failOn, "fail-on", "", "exit 6 on findings of this severity or higher: critical, high, medium or low")
	f.BoolVar(&allFindings, "all-findings", false, "list every finding (default: the first 20)")
	return cmd
}

func printModelServiceSecurity(app *App, svc *runr.ModelService, sec *runr.ModelServiceSecurity, all bool) error {
	return app.Printer().Print(sec, func(t *output.TableWriter) {
		t.KV("Model service", svc.Name)
		t.KV("Image", output.Dash(sec.Image))
		t.KV("Region scans external images", yesNoWord(sec.Scanned))
		r := sec.Report
		if r == nil {
			if sec.Scanned {
				t.KV("Report", "none yet; the image is scanned when the service is deployed")
			} else {
				t.KV("Report", "none; the region does not scan external images")
			}
			return
		}
		if !sec.Current {
			t.KV("Report", "of an earlier image ("+output.Dash(r.ImageRef)+")")
		}
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
		if r.Error != "" {
			t.KV("Error", r.Error)
		}
		if len(r.Findings) > 0 {
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
