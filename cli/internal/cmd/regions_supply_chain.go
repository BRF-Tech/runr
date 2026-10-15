package cmd

import (
	"context"
	"fmt"
	"io"
	"os"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// `runr regions supply-chain`: a region's SBOM and scan switches, scanner DB, policy gate,
// signing key, admission policy, CaaS image policy and data location (RegionSupplyChainController).

func newRegionSupplyChainCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "supply-chain [REGION]",
		Aliases: []string{"supplychain"},
		Short:   "The supply chain of a region: scans, scanner DB, policy gate, signing key, admission",
		Long: "The supply-chain settings of a region (docs/supply-chain.md): SBOMs and vulnerability scans\n" +
			"of the images it builds and runs, the scanner's vulnerability DB, the region's policy gate\n" +
			"for deploys, the signing key, the admission policy that checks images in the cluster, the\n" +
			"policy for CaaS images and the region's data location. Reading needs regions.view, changes\n" +
			"regions.supply_chain.manage on a region your organization may change.",
		Example: "  runr regions supply-chain eu-1\n" +
			"  runr regions supply-chain set eu-1 --set gate_mode=enforce --set gate_max_critical=0 --set gate_max_high=\n" +
			"  runr regions supply-chain signing-key rotate eu-1 --wait --yes\n" +
			"  runr regions supply-chain admission install eu-1 --mode warn --wait\n" +
			"  runr regions supply-chain public-key eu-1 > cosign.pub",
		Args: cobra.MaximumNArgs(1),
	}
	get := &cobra.Command{
		Use:   "get [REGION]",
		Short: "Show the supply-chain settings and state of a region",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			sc, err := c.RegionSupplyChain(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return printSupplyChain(app, sc)
		},
	}
	cmd.RunE = get.RunE
	cmd.AddCommand(get, newSupplyChainSetCmd(app), newSupplyChainPublicKeyCmd(app), newSupplyChainSigningKeyCmd(app),
		newSupplyChainScannerDBCmd(app), newSupplyChainAdmissionCmd(app), newSupplyChainRuntimeImagesCmd(app))
	return cmd
}

func onOff(b bool) string {
	if b {
		return "on"
	}
	return "off"
}

func scStatus(status, msg string) string {
	s := output.Dash(status)
	if msg != "" {
		s += " (" + output.Truncate(strings.TrimSpace(msg), 100) + ")"
	}
	return s
}

func scLimit(v *int) string {
	if v == nil {
		return "no limit"
	}
	return strconv.Itoa(*v)
}

func printSupplyChain(app *App, sc *runr.RegionSupplyChain) error {
	return app.Printer().Print(sc, func(t *output.TableWriter) {
		t.KV("Region", output.Dash(sc.Region.Name))
		t.KV("SBOM", onOff(sc.SBOMEnabled))
		scan := onOff(sc.ScanEnabled)
		if sc.ScanEnabled {
			scan += fmt.Sprintf(", external images %s, rescan every %dh", onOff(sc.ScanExternalImages), sc.RescanIntervalHours)
		}
		t.KV("Vulnerability scan", scan)
		db := sc.ScannerDBMode
		if sc.ScannerDBMode != "online" {
			db += ", " + scStatus(sc.ScannerDBStatus, sc.ScannerDBMessage)
			if sc.ScannerDBUpdatedAt != "" {
				db += ", updated " + sc.ScannerDBUpdatedAt
			}
		}
		if sc.ScannerDBSource != "" {
			db += ", from " + sc.ScannerDBSource
		}
		t.KV("Scanner DB", db)
		t.KV("Policy gate", fmt.Sprintf("%s: max critical %s, max high %s, only fixable %s, require a scan %s", sc.GateMode,
			scLimit(sc.GateMaxCritical), scLimit(sc.GateMaxHigh), yesNoWord(sc.GateOnlyFixable), yesNoWord(sc.GateRequireScan)))
		signing := onOff(sc.SigningEnabled) + ", key " + scStatus(sc.SigningStatus, sc.SigningMessage)
		if sc.SigningKeyFingerprint != "" {
			signing += " " + output.Truncate(sc.SigningKeyFingerprint, 24)
		}
		if n := len(sc.SigningRetiredKeys); n > 0 {
			signing += fmt.Sprintf(", %s", countLabel(n, "retired key"))
		}
		t.KV("Signing", signing)
		t.KV("Verify on deploy", yesNoWord(sc.VerifyOnDeploy))
		t.KV("Attestations", onOff(sc.AttestEnabled))
		admission := sc.AdmissionMode + ", " + scStatus(sc.AdmissionStatus, sc.AdmissionMessage)
		if sc.AdmissionRequireSBOM {
			admission += ", SBOM required"
		}
		if len(sc.AdmissionExemptImages) > 0 {
			admission += ", exempt: " + strings.Join(sc.AdmissionExemptImages, " ")
		}
		t.KV("Admission policy", admission)
		caas := sc.CaaSPolicy
		if len(sc.CaaSAllowedRegistries) > 0 {
			caas += ", registries: " + strings.Join(sc.CaaSAllowedRegistries, " ")
		}
		if n := len(sc.CaaSTrustedKeys); n > 0 {
			caas += ", " + countLabel(n, "trusted key")
		}
		t.KV("CaaS images", caas)
		t.KV("Data location", output.Dash(sc.DataLocation))
		t.KV("You can change it", yesNoWord(sc.CanManage))
	})
}

// scKind is how a --set value of a supply-chain setting is read.
type scKind int

const (
	scBool scKind = iota
	scInt
	scNullInt
	scString
	scNullString
	scList
)

// supplyChainKeys are the settings `set` takes (UpdateRegionSupplyChainRequest).
var supplyChainKeys = map[string]scKind{
	"sbom_enabled": scBool, "scan_enabled": scBool, "scan_external_images": scBool, "rescan_interval_hours": scInt,
	"scanner_db_mode": scString, "scanner_db_source": scNullString,
	"gate_mode": scString, "gate_max_critical": scNullInt, "gate_max_high": scNullInt, "gate_only_fixable": scBool,
	"gate_require_scan": scBool,
	"signing_enabled":   scBool, "verify_on_deploy": scBool, "attest_enabled": scBool,
	"admission_mode": scString, "admission_require_sbom": scBool, "admission_exempt_images": scList,
	"caas_policy": scString, "caas_allowed_registries": scList, "caas_trusted_keys": scList,
	"data_location": scNullString,
}

func supplyChainKeyNames() string {
	names := make([]string, 0, len(supplyChainKeys))
	for k := range supplyChainKeys {
		names = append(names, k)
	}
	sort.Strings(names)
	return strings.Join(names, ", ")
}

// parseSupplyChainSet reads KEY=VALUE pairs into a change.
func parseSupplyChainSet(pairs []string) (runr.RegionSupplyChainChange, error) {
	change := runr.RegionSupplyChainChange{}
	for _, p := range pairs {
		k, v, ok := strings.Cut(p, "=")
		key := strings.ReplaceAll(strings.ToLower(strings.TrimSpace(k)), "-", "_")
		kind, known := supplyChainKeys[key]
		if !ok || !known {
			return nil, usageErrorf("--set takes KEY=VALUE with one of: %s", supplyChainKeyNames())
		}
		v = strings.TrimSpace(v)
		switch kind {
		case scBool:
			b, err := parseOnOff(v)
			if err != nil {
				return nil, usageErrorf("--set %s: %v", key, err)
			}
			change[key] = b
		case scInt:
			n, err := strconv.Atoi(v)
			if err != nil {
				return nil, usageErrorf("--set %s: %q is not a whole number", key, v)
			}
			change[key] = n
		case scNullInt:
			if v == "" || strings.EqualFold(v, "none") {
				change[key] = nil
				continue
			}
			n, err := strconv.Atoi(v)
			if err != nil {
				return nil, usageErrorf("--set %s: %q is not a whole number (empty: no limit)", key, v)
			}
			change[key] = n
		case scString:
			change[key] = v
		case scNullString:
			if v == "" {
				change[key] = nil
			} else {
				change[key] = v
			}
		case scList:
			if key == "caas_trusted_keys" && v != "" {
				return nil, usageErrorf("--set caas_trusted_keys takes no value (it clears them); --trusted-key FILE adds PEM keys")
			}
			list := []string{}
			for _, item := range strings.Split(v, ",") {
				if item = strings.TrimSpace(item); item != "" {
					list = append(list, item)
				}
			}
			change[key] = list
		}
	}
	return change, nil
}

// parseOnOff reads true/false, yes/no, on/off and 1/0.
func parseOnOff(v string) (bool, error) {
	switch strings.ToLower(v) {
	case "true", "yes", "on", "1":
		return true, nil
	case "false", "no", "off", "0":
		return false, nil
	}
	return false, fmt.Errorf("%q is not true or false", v)
}

func newSupplyChainSetCmd(app *App) *cobra.Command {
	var sets, keyFiles []string
	cmd := &cobra.Command{
		Use:   "set REGION --set KEY=VALUE...",
		Short: "Change supply-chain settings of a region",
		Long: "Change settings with --set KEY=VALUE (repeatable); settings left out are kept.\n" +
			"  sbom_enabled, scan_enabled, scan_external_images        true|false\n" +
			"  rescan_interval_hours                                   0-720 (0: no rescans)\n" +
			"  scanner_db_mode                                         online|mirror|offline\n" +
			"  scanner_db_source                                       an OCI reference (empty: the default)\n" +
			"  gate_mode                                               off|warn|enforce\n" +
			"  gate_max_critical, gate_max_high                        a number (empty: no limit)\n" +
			"  gate_only_fixable, gate_require_scan                    true|false\n" +
			"  signing_enabled, verify_on_deploy, attest_enabled       true|false\n" +
			"  admission_mode                                          off|warn|enforce\n" +
			"  admission_require_sbom                                  true|false\n" +
			"  admission_exempt_images, caas_allowed_registries        comma-separated (empty: none)\n" +
			"  caas_policy                                             any|allowlist|signed\n" +
			"  caas_trusted_keys=                                      clears the trusted keys\n" +
			"  data_location                                           a label such as TR or EU-DE (empty: none)\n" +
			"--trusted-key FILE (repeatable, - for stdin) sets the PEM public keys signed CaaS images\n" +
			"need. An installed admission policy follows the admission and CaaS settings.",
		Example: "  runr regions supply-chain set eu-1 --set gate_mode=enforce --set gate_max_critical=0 --set gate_require_scan=true\n" +
			"  runr regions supply-chain set eu-1 --set caas_policy=signed --set caas_allowed_registries=ghcr.io/acme --trusted-key acme.pub\n" +
			"  runr regions supply-chain set eu-1 --set scanner_db_mode=mirror --set data_location=TR",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			change, err := parseSupplyChainSet(sets)
			if err != nil {
				return err
			}
			if len(keyFiles) > 0 {
				if _, set := change["caas_trusted_keys"]; set {
					return usageErrorf("give --trusted-key or --set caas_trusted_keys=, not both")
				}
				keys := []string{}
				for _, f := range keyFiles {
					data, err := readFileArg(app, f)
					if err != nil {
						return err
					}
					if !strings.Contains(string(data), "BEGIN PUBLIC KEY") {
						return usageErrorf("%s is not a PEM public key", f)
					}
					keys = append(keys, strings.TrimSpace(string(data)))
				}
				change["caas_trusted_keys"] = keys
			}
			if len(change) == 0 {
				return usageErrorf("give at least one --set KEY=VALUE")
			}
			c, reg, err := radmRegion(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			sc, err := c.UpdateRegionSupplyChain(cmd.Context(), reg.ID, change)
			if err != nil {
				return err
			}
			app.Progress("Updated the supply chain of %s.", reg.Name)
			return printSupplyChain(app, sc)
		},
	}
	cmd.Flags().StringArrayVar(&sets, "set", nil, "KEY=VALUE of a setting (repeatable)")
	cmd.Flags().StringArrayVar(&keyFiles, "trusted-key", nil, "PEM public key file signed CaaS images may be signed with (repeatable; replaces the list)")
	return cmd
}

func newSupplyChainPublicKeyCmd(app *App) *cobra.Command {
	var file string
	cmd := &cobra.Command{
		Use:   "public-key [REGION]",
		Short: "Print the public key of the region's signing key (PEM, for cosign verify --key)",
		Example: "  runr regions supply-chain public-key eu-1 > cosign.pub\n" +
			"  cosign verify --key cosign.pub registry.example.com/runr/orders@sha256:…",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if app.Printer().Machine() {
				return usageErrorf("public-key prints the PEM; leave out -o")
			}
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			w, closeOut, err := createOut(app, file)
			if err != nil {
				return err
			}
			d, err := c.WriteRegionPublicKey(cmd.Context(), reg.ID, w)
			if cerr := closeOut(); err == nil {
				err = cerr
			}
			if err != nil {
				return err
			}
			if file != "" && file != "-" {
				app.Progress("Public key of %s written to %s (%d bytes).", reg.Name, file, d.Bytes)
			}
			return nil
		},
	}
	cmd.Flags().StringVar(&file, "file", "", "write the key to this file (default: stdout)")
	return cmd
}

// scWait follows a background change of a region's supply chain and prints the settings.
func scWait(ctx context.Context, app *App, c *runr.Client, reg *runr.Region, part string, timeout time.Duration) error {
	sc, err := c.WaitRegionSupplyChain(ctx, reg.ID, reg.Name, part, app.waitOptions(timeout, strings.ReplaceAll(part, "_", " ")+" of "+reg.Name))
	if sc == nil {
		return err
	}
	if perr := printSupplyChain(app, sc); perr != nil && err == nil {
		err = perr
	}
	return err
}

// scResult prints the settings after a change that runs in the background, or follows it.
func scResult(ctx context.Context, app *App, c *runr.Client, reg *runr.Region, sc *runr.RegionSupplyChain, part string,
	wait bool, timeout time.Duration) error {
	if wait {
		return scWait(ctx, app, c, reg, part, timeout)
	}
	return printSupplyChain(app, sc)
}

func newSupplyChainSigningKeyCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "signing-key",
		Short: "Generate or rotate the region's signing key, or remove retired keys",
		Args:  cobra.NoArgs,
	}
	var wait, yes bool
	var timeout time.Duration
	rotate := &cobra.Command{
		Use:     "rotate REGION",
		Aliases: []string{"generate"},
		Short:   "Generate the signing key, or a new one (the current key is retired)",
		Long: "Generate the region's cosign signing key in the cluster, or rotate it: the new key signs\n" +
			"versions built from now on, the current one is retired — images it signed still verify\n" +
			"while it is listed (`forget-retired` removes retired keys). Rotating asks first (--yes).\n" +
			"The key is generated in the background: --wait waits (exit code 6 when it fails).",
		Example: "  runr regions supply-chain signing-key rotate eu-1 --wait --yes",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			cur, err := c.RegionSupplyChain(ctx, reg.ID)
			if err != nil {
				return err
			}
			if cur.SigningKeyFingerprint != "" {
				question := "Rotate the signing key of " + reg.Name + ": the current key is retired and its private key is gone"
				if err := app.confirm(question, yes); err != nil {
					return err
				}
			}
			sc, err := c.RotateRegionSigningKey(ctx, reg.ID)
			if err != nil {
				return err
			}
			app.Progress("Generating a signing key for %s.", reg.Name)
			return scResult(ctx, app, c, reg, sc, runr.SupplyChainSigning, wait, timeout)
		},
	}
	rotate.Flags().BoolVar(&wait, "wait", false, "wait until the key is ready (exit code 6 when it fails)")
	rotate.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long --wait waits")
	rotate.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask before a rotation")

	var forgetYes bool
	forget := &cobra.Command{
		Use:   "forget-retired REGION",
		Short: "Remove the retired signing keys: images only they signed no longer verify",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			cur, err := c.RegionSupplyChain(ctx, reg.ID)
			if err != nil {
				return err
			}
			if len(cur.SigningRetiredKeys) == 0 {
				app.Progress("%s has no retired signing keys.", reg.Name)
				return printSupplyChain(app, cur)
			}
			question := fmt.Sprintf("Remove the %s of %s: images only they signed no longer verify",
				countLabel(len(cur.SigningRetiredKeys), "retired signing key"), reg.Name)
			if err := app.confirm(question, forgetYes); err != nil {
				return err
			}
			sc, err := c.ForgetRetiredSigningKeys(ctx, reg.ID)
			if err != nil {
				return err
			}
			app.Progress("Removed the retired signing keys of %s.", reg.Name)
			return printSupplyChain(app, sc)
		},
	}
	forget.Flags().BoolVarP(&forgetYes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(rotate, forget)
	return cmd
}

func newSupplyChainScannerDBCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "scanner-db",
		Short: "The scanner's vulnerability DB in the region's registry (mirror and offline modes)",
		Args:  cobra.NoArgs,
	}
	var wait bool
	var timeout time.Duration
	refresh := &cobra.Command{
		Use:   "refresh REGION",
		Short: "Copy the vulnerability DB into the region's registry now",
		Long: "Copy the scanner's vulnerability DB into the region's registry (scanner_db_mode mirror or\n" +
			"offline; the region needs a registry). It runs in the background: --wait waits.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			sc, err := c.RefreshScannerDB(ctx, reg.ID)
			if err != nil {
				return err
			}
			app.Progress("Refreshing the scanner DB of %s.", reg.Name)
			return scResult(ctx, app, c, reg, sc, runr.SupplyChainScannerDB, wait, timeout)
		},
	}
	refresh.Flags().BoolVar(&wait, "wait", false, "wait until the copy finished (exit code 6 when it fails)")
	refresh.Flags().DurationVar(&timeout, "timeout", 30*time.Minute, "how long --wait waits")
	cmd.AddCommand(refresh)
	return cmd
}

func newSupplyChainAdmissionCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "admission",
		Short: "The admission policy that checks images in the cluster",
		Long: "The admission policy (Sigstore policy-controller) checks the images of the region's project\n" +
			"namespace when pods start: RunR's signature on built images, the CaaS image policy, and\n" +
			"with admission_require_sbom an SBOM attestation. warn admits and reports, enforce refuses.",
		Args: cobra.NoArgs,
	}
	var mode string
	var wait bool
	var timeout time.Duration
	install := &cobra.Command{
		Use:   "install REGION",
		Short: "Install the admission policy, or apply an installed one again",
		Example: "  runr regions supply-chain admission preview eu-1\n" +
			"  runr regions supply-chain admission install eu-1 --mode warn --wait",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if mode != "" && mode != "warn" && mode != "enforce" {
				return usageErrorf("--mode must be warn or enforce")
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			sc, err := c.InstallAdmission(ctx, reg.ID, mode)
			if err != nil {
				return err
			}
			app.Progress("Installing the admission policy of %s (%s).", reg.Name, sc.AdmissionMode)
			return scResult(ctx, app, c, reg, sc, runr.SupplyChainAdmission, wait, timeout)
		},
	}
	install.Flags().StringVar(&mode, "mode", "", "warn or enforce (default: the region's admission_mode)")
	install.Flags().BoolVar(&wait, "wait", false, "wait until it is installed (exit code 6 when it fails)")
	install.Flags().DurationVar(&timeout, "timeout", 15*time.Minute, "how long --wait waits")

	var uwait, yes bool
	var utimeout time.Duration
	uninstall := &cobra.Command{
		Use:     "uninstall REGION",
		Aliases: []string{"rm"},
		Short:   "Remove the admission policy from the cluster",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Remove the admission policy of "+reg.Name+": images are no longer checked when pods start", yes); err != nil {
				return err
			}
			sc, err := c.UninstallAdmission(ctx, reg.ID)
			if err != nil {
				return err
			}
			app.Progress("Removing the admission policy of %s.", reg.Name)
			return scResult(ctx, app, c, reg, sc, runr.SupplyChainAdmission, uwait, utimeout)
		},
	}
	uninstall.Flags().BoolVar(&uwait, "wait", false, "wait until it is removed")
	uninstall.Flags().DurationVar(&utimeout, "timeout", 15*time.Minute, "how long --wait waits")
	uninstall.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")

	preview := &cobra.Command{
		Use:   "preview [REGION]",
		Short: "What an install applies: release, values and the policies (-o yaml for the documents)",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			p, err := c.AdmissionPreview(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(p, func(t *output.TableWriter) {
				t.KV("Release", fmt.Sprintf("%s (%s %s) in %s", p.Release, p.Chart, p.ChartVersion, p.Namespace))
				t.KV("Checked namespace", fmt.Sprintf("%s (label %s=true)", p.ProjectNamespace, p.NamespaceLabel))
				t.KV("Images no policy matches", p.NoMatchPolicy)
				t.KV("Policies", joinOrDash(p.PolicyNames()))
				t.Note("-o yaml prints the Helm values and the policy documents.")
			})
		},
	}
	cmd.AddCommand(install, uninstall, preview)
	return cmd
}

func newSupplyChainRuntimeImagesCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "runtime-images [REGION]",
		Short: "Runtime images built in the region with their supply-chain reports",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			list, err := c.RegionRuntimeImages(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("RUNTIME", "VERSION", "BUILD", "REPORT", "STATUS", "CRITICAL", "HIGH", "MEDIUM", "LOW", "SIGNATURE")
				for _, r := range list {
					report, sig := "-", "-"
					if r.Report != nil {
						report, sig = r.Report.ID, output.Dash(r.Report.SignatureStatus)
					}
					cr, hi, me, lo := securityCounts(r.Report)
					t.Row(r.Runtime, r.Version, r.Status, report, securityReportStatus(r.Report), cr, hi, me, lo, sig)
				}
				if len(list) == 0 {
					t.Note("No runtime images were built in %s.", reg.Name)
				} else {
					t.Note("The SBOM of one: runr regions supply-chain runtime-images sbom %s REPORT", reg.Name)
				}
			})
		},
	}
	var file, format string
	sbom := &cobra.Command{
		Use:   "sbom REGION REPORT",
		Short: "Download the SBOM of a runtime image report",
		Args:  cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			if format != runr.SBOMSPDX && format != runr.SBOMCycloneDX {
				return usageErrorf("--format must be %s or %s", runr.SBOMSPDX, runr.SBOMCycloneDX)
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			var w io.Writer = app.Out
			closeOut := func() error { return nil }
			if file != "" && file != "-" {
				f, err := os.Create(file)
				if err != nil {
					return err
				}
				w, closeOut = f, f.Close
			}
			d := &runr.Download{W: w}
			err = c.DownloadRuntimeImageSBOM(ctx, reg.ID, args[1], format, d)
			if cerr := closeOut(); err == nil {
				err = cerr
			}
			if err != nil {
				return err
			}
			if file != "" && file != "-" {
				app.Progress("SBOM (%s, %d bytes) written to %s.", format, d.Bytes, file)
			}
			return nil
		},
	}
	sbom.Flags().StringVar(&file, "file", "", "write the SBOM to this file (default: stdout)")
	sbom.Flags().StringVar(&format, "format", runr.SBOMSPDX, "spdx-json or cyclonedx-json")
	cmd.AddCommand(sbom)
	return cmd
}
