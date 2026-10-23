package cmd

import (
	"context"
	"errors"
	"strconv"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// `runr regions cert-manager`: cert-manager of a region and the ClusterIssuer of its custom
// domains (CertManagerController, docs/regions.md#cert-manager).

// errNoCertManagerAPI: the API answered 404 for the cert-manager status of a region it knows.
var errNoCertManagerAPI = errors.New("this RunR has no cert-manager management (it came with a later release): " +
	"set cert_manager_cluster_issuer with runr regions set instead")

func newRegionCertManagerCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "cert-manager [REGION]",
		Aliases: []string{"certmanager"},
		Short:   "cert-manager of a region: the certificates of its custom domains",
		Long: "cert-manager issues the certificates of project custom domains in TLS mode cert-manager. A\n" +
			"cert-manager the cluster already runs is reused (RunR never removes it); RunR installs one\n" +
			"where there is none, then a default ClusterIssuer: Let's Encrypt (ACME) where the region is\n" +
			"reachable from the internet and an ACME e-mail is set, else a region CA (download it with\n" +
			"`ca`). An issuer named in the region setting cert_manager_cluster_issuer takes precedence;\n" +
			"cert_manager_issuer_mode (auto, acme, ca) and cert_manager_acme_email choose RunR's\n" +
			"(runr regions set). Reading needs regions.view, changes regions.manage.",
		Example: "  runr regions cert-manager eu-1\n" +
			"  runr regions cert-manager install eu-1 --wait\n" +
			"  runr regions cert-manager ca eu-1 -f eu-1-ca.crt\n" +
			"  runr regions set eu-1 --set cert_manager_acme_email=ops@example.com --set cert_manager_issuer_mode=auto",
		Args: cobra.MaximumNArgs(1),
	}
	var noCluster bool
	status := &cobra.Command{
		Use:     "status [REGION]",
		Aliases: []string{"get"},
		Short:   "Show cert-manager of a region, its issuer and whether custom domains can use it",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			cm, err := c.RegionCertManager(cmd.Context(), reg.ID, !noCluster)
			if runr.IsNotFound(err) {
				return errNoCertManagerAPI
			}
			if err != nil {
				return err
			}
			return printRegionCertManager(app, reg, cm)
		},
	}
	status.Flags().BoolVar(&noCluster, "no-cluster", false, "show the recorded state without reading the cluster")
	cmd.Flags().BoolVar(&noCluster, "no-cluster", false, "show the recorded state without reading the cluster")
	cmd.AddCommand(status)
	cmd.RunE = status.RunE
	cmd.AddCommand(newRegionCertManagerInstallCmd(app), newRegionCertManagerRemoveCmd(app), newRegionCertManagerCACmd(app))
	return cmd
}

var certManagerKinds = map[string]string{
	"acme": "Let's Encrypt (ACME), publicly trusted",
	"ca":   "the region CA (clients trust it once it is installed: runr regions cert-manager ca)",
	"own":  "an issuer of yours",
}

func printRegionCertManager(app *App, reg *runr.Region, cm *runr.RegionCertManager) error {
	return app.Printer().Print(cm, func(t *output.TableWriter) {
		t.KV("Region", reg.Name)
		t.KV("Status", rlcStatus(cm.Status, cm.Message))
		if cm.Managed != nil {
			owner := "installed by RunR"
			if !*cm.Managed {
				owner = "the cluster's own (reused, never removed by RunR)"
			}
			t.KV("cert-manager", owner+" "+output.Dash(cm.Version)+" in "+output.Dash(cm.Namespace))
		}
		if cm.Issuer.Name != "" {
			kind := certManagerKinds[cm.Issuer.Kind]
			if kind == "" {
				kind = cm.Issuer.Kind
			}
			t.KV("ClusterIssuer", cm.Issuer.Name+" — "+kind)
			if cm.Issuer.Source == "runr" && cm.Plan.Reason != "" {
				t.KV("Why", cm.Plan.Reason)
			}
		}
		t.KV("Issuer mode", output.Dash(cm.IssuerMode))
		email := "-"
		if cm.Acme.Email != "" {
			email = cm.Acme.Email + " (" + cm.Acme.EmailSource + ")"
		}
		t.KV("ACME e-mail", email)
		t.KV("Reachable from the internet", yesNoWord(cm.Acme.Public))
		if cl := cm.Cluster; cl != nil {
			ready := yesNoWord(cl.IssuerReady)
			if cl.IssuerMessage != "" {
				ready += ": " + output.Truncate(cl.IssuerMessage, 120)
			}
			t.KV("Issuer Ready", ready)
			if len(cl.Drift) > 0 {
				t.KV("Differs from this release", joinOrDash(cl.Drift))
			}
			t.KV("Custom-domain certificates", strconv.Itoa(cl.DomainCertificates))
		}
		if cm.ClusterError != "" {
			t.KV("Cluster", "unreachable: "+output.Truncate(cm.ClusterError, 100))
		}
		if !cm.Domains.Available && cm.Domains.Reason != "" {
			t.Note("TLS mode cert-manager is not available: %s", cm.Domains.Reason)
		}
		if cm.Status == runr.KnativeNotInstalled && !cm.InstallEnabled {
			t.Note("This platform does not install cert-manager (Helm knative.certManager.install: false): install it in the cluster, then runr regions cert-manager install %s.", reg.Name)
		}
	})
}

func regionCertManagerWait(ctx context.Context, app *App, c *runr.Client, reg *runr.Region, timeout time.Duration) error {
	cm, err := c.WaitRegionCertManager(ctx, reg.ID, reg.Name, app.waitOptions(timeout, "cert-manager of "+reg.Name))
	if cm == nil {
		return err
	}
	if err == nil {
		app.Progress("cert-manager of %s: %s.", reg.Name, cm.Status)
	}
	if perr := printRegionCertManager(app, reg, cm); perr != nil && err == nil {
		err = perr
	}
	return err
}

func newRegionCertManagerInstallCmd(app *App) *cobra.Command {
	var wait bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "install REGION",
		Short: "Set cert-manager up (or reuse the cluster's own) and create the default issuer (regions.manage)",
		Long: "Install cert-manager in the region (a cert-manager the cluster already runs is reused) and\n" +
			"create the default ClusterIssuer. Again on a working install it brings RunR's cert-manager to\n" +
			"this release and creates a missing issuer. Knative Serving must be installed. It runs in the\n" +
			"background: --wait waits (exit code 6 when it fails).",
		Example: "  runr regions cert-manager install eu-1 --wait",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			res, err := c.InstallRegionCertManager(ctx, reg.ID)
			if runr.IsNotFound(err) {
				return errNoCertManagerAPI
			}
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			if wait {
				return regionCertManagerWait(ctx, app, c, reg, timeout)
			}
			return printRegionOperation(app, reg, res, "runr regions cert-manager "+reg.Name+" shows the progress.")
		},
	}
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the install ended (exit code 6 when it fails)")
	cmd.Flags().DurationVar(&timeout, "timeout", 15*time.Minute, "how long --wait waits")
	return cmd
}

func newRegionCertManagerRemoveCmd(app *App) *cobra.Command {
	var wait, yes bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:     "rm REGION",
		Aliases: []string{"remove", "uninstall"},
		Short:   "Stop using cert-manager in a region (regions.manage)",
		Long: "Refused while custom domains of the region use TLS mode cert-manager. RunR's issuers, the\n" +
			"region CA and RunR's cert-manager go only when nothing else on the cluster uses them; a\n" +
			"cert-manager RunR did not install always stays. Its CRDs stay too. --wait waits.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Stop using cert-manager in "+reg.Name, yes); err != nil {
				return err
			}
			res, err := c.RemoveRegionCertManager(ctx, reg.ID)
			if runr.IsNotFound(err) {
				return errNoCertManagerAPI
			}
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			if wait {
				return regionCertManagerWait(ctx, app, c, reg, timeout)
			}
			return printRegionOperation(app, reg, res, "runr regions cert-manager "+reg.Name+" shows the progress.")
		},
	}
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the removal ended")
	cmd.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long --wait waits")
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}

func newRegionCertManagerCACmd(app *App) *cobra.Command {
	var file string
	cmd := &cobra.Command{
		Use:   "ca REGION",
		Short: "The region CA's certificate (PEM): install it where clients call the custom domains",
		Long: "Print the certificate of the region CA (runr-ca) — never its key — or write it to a file\n" +
			"(-f). Clients that trust it accept the custom-domain certificates of the region.",
		Example: "  runr regions cert-manager ca eu-1 > eu-1-ca.crt\n" +
			"  runr regions cert-manager ca eu-1 -f eu-1-ca.crt",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if app.Printer().Machine() && (file == "" || file == "-") {
				return usageErrorf("ca prints the certificate itself: leave out -o, or write it with -f FILE")
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			pem, err := c.RegionCertManagerCA(ctx, reg.ID)
			if err != nil {
				return err
			}
			return writeOut(app, file, pem)
		},
	}
	cmd.Flags().StringVarP(&file, "file", "f", "", "file to write (default: stdout)")
	return cmd
}
