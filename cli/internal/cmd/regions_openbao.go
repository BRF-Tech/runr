package cmd

import (
	"context"
	"strconv"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// `runr regions openbao`: the region's own OpenBao (OpenBaoController, docs/secret-stores.md
// "OpenBao in the region"), the third place a project secret value can live and the issuer of
// dynamic Postgres credentials of bindings.

func newRegionOpenBaoCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "openbao [REGION]",
		Aliases: []string{"bao"},
		Short:   "The region's own OpenBao: secret values and dynamic Postgres credentials",
		Long: "RunR installs and operates an OpenBao in the region (namespace runr-openbao): while it runs,\n" +
			"new secret values of the region's projects go into it, and Postgres bindings can be attached\n" +
			"with dynamic credentials from it (docs/secret-stores.md, docs/bindings.md). It needs External\n" +
			"Secrets Operator in the region. The install runs in the background (--wait waits, exit code 6\n" +
			"when it fails). Reading needs regions.view, changes regions.manage.",
		Example: "  runr regions openbao eu-1\n" +
			"  runr regions openbao install eu-1 --wait\n" +
			"  runr regions openbao default eu-1 off\n" +
			"  runr regions openbao uninstall eu-1 --yes",
		Args: cobra.MaximumNArgs(1),
	}
	status := &cobra.Command{
		Use:     "status [REGION]",
		Aliases: []string{"get"},
		Short:   "Show the region's OpenBao",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			b, err := c.RegionOpenBao(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return printRegionOpenBao(app, reg, b)
		},
	}
	cmd.AddCommand(status)
	cmd.RunE = status.RunE
	cmd.AddCommand(newRegionOpenBaoInstallCmd(app), newRegionOpenBaoUninstallCmd(app), newRegionOpenBaoDefaultCmd(app))
	return cmd
}

func printRegionOpenBao(app *App, reg *runr.Region, b *runr.RegionOpenBao) error {
	return app.Printer().Print(b, func(t *output.TableWriter) {
		t.KV("Region", reg.Name)
		t.KV("Status", rlcStatus(b.Status, b.Message))
		version := output.Dash(b.Version)
		if b.ReleaseVersion != "" && b.Version != "" && b.ReleaseVersion != b.Version {
			version += " (this release: " + b.ReleaseVersion + ")"
		}
		t.KV("Version", version)
		t.KV("Installed", output.Dash(b.InstalledAt))
		t.KV("New values go to", map[bool]string{true: "OpenBao", false: "the project's Kubernetes Secret"}[b.DefaultForValues && b.Status == runr.KnativeReady])
		t.KV("Values in it", strconv.Itoa(b.Values))
		t.KV("External Secrets Operator", output.Dash(b.ESOStatus))
		if b.ESOStatus != runr.KnativeReady && b.Status == runr.KnativeNotInstalled {
			t.Note("OpenBao needs External Secrets Operator in the region (docs/secret-stores.md).")
		}
	})
}

func regionOpenBaoWait(ctx context.Context, app *App, c *runr.Client, reg *runr.Region, timeout time.Duration) error {
	b, err := c.WaitRegionOpenBao(ctx, reg.ID, reg.Name, app.waitOptions(timeout, "OpenBao of "+reg.Name))
	if b == nil {
		return err
	}
	if err == nil {
		app.Progress("OpenBao of %s: %s.", reg.Name, b.Status)
	}
	if perr := printRegionOpenBao(app, reg, b); perr != nil && err == nil {
		err = perr
	}
	return err
}

func newRegionOpenBaoInstallCmd(app *App) *cobra.Command {
	var wait bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "install REGION",
		Short: "Install the region's OpenBao, or bring it to this release's version (regions.manage)",
		Long: "Installs OpenBao in the region's cluster: initialized with one key share whose unseal key\n" +
			"stays in the region's Secret runr-openbao-unseal, configured, its root token revoked. Needs\n" +
			"External Secrets Operator in the region. Installing again keeps the data and the unseal key.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			res, err := c.InstallRegionOpenBao(ctx, reg.ID)
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			if wait {
				return regionOpenBaoWait(ctx, app, c, reg, timeout)
			}
			return printRegionOpenBao(app, reg, &res.Data)
		},
	}
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the install ended (exit code 6 when it fails)")
	cmd.Flags().DurationVar(&timeout, "timeout", 15*time.Minute, "how long --wait waits")
	return cmd
}

func newRegionOpenBaoUninstallCmd(app *App) *cobra.Command {
	var wait, yes bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:     "uninstall REGION",
		Aliases: []string{"rm", "remove"},
		Short:   "Remove the region's OpenBao and its volume (regions.manage)",
		Long: "Removes OpenBao with its data; refused while secret values of the region's projects live in\n" +
			"it. Another region on the same cluster that still has it keeps it running.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Remove the OpenBao of "+reg.Name+" with its data", yes); err != nil {
				return err
			}
			res, err := c.UninstallRegionOpenBao(ctx, reg.ID)
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			if wait {
				return regionOpenBaoWait(ctx, app, c, reg, timeout)
			}
			return printRegionOpenBao(app, reg, &res.Data)
		},
	}
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the removal ended")
	cmd.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long --wait waits")
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}

func newRegionOpenBaoDefaultCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "default REGION on|off",
		Short: "Choose where new secret values of the region's projects go: OpenBao (on) or the Kubernetes Secret (off)",
		Long: "on: while OpenBao runs, new secret values of the region's projects go into it (the default).\n" +
			"off: they go into the project's Kubernetes Secret again. Values already written stay where\n" +
			"they are until they are set again.",
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			var on bool
			switch args[1] {
			case "on", "true", "yes":
				on = true
			case "off", "false", "no":
			default:
				return usageErrorf("%q: on or off", args[1])
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			b, err := c.SetRegionOpenBaoDefault(ctx, reg.ID, on)
			if err != nil {
				return err
			}
			if on {
				app.Progress("New secret values of %s's projects go to its OpenBao.", reg.Name)
			} else {
				app.Progress("New secret values of %s's projects go to their Kubernetes Secrets.", reg.Name)
			}
			return printRegionOpenBao(app, reg, b)
		},
	}
}
