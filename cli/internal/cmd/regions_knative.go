package cmd

import (
	"context"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// `runr regions knative`: the Knative operator and Knative Serving of a region
// (KnativeInstallController, KnativeUninstallController).

func newRegionKnativeCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "knative [REGION]",
		Short: "Knative of a region: the operator and Serving, install, upgrade, uninstall, adopt",
		Long: "Projects run on Knative Serving, which the Knative operator installs. RunR installs both\n" +
			"with Helm (install REGION, or one COMPONENT: operator, then serving), adopts an operator or\n" +
			"Serving already running on the cluster (install and detect), upgrades Serving one minor\n" +
			"version at a time (upgrade) and removes them (uninstall). Installs, upgrades and removals run\n" +
			"in the background: --wait waits (exit code 6 when one fails). Reading needs regions.view,\n" +
			"changes regions.manage on a region your organization may change.",
		Example: "  runr regions knative eu-1\n" +
			"  runr regions knative install eu-1 --wait\n" +
			"  runr regions knative install eu-1 serving --version 1.18 --wait\n" +
			"  runr regions knative upgrade eu-1 --version 1.21 --wait\n" +
			"  runr regions knative detect eu-1\n" +
			"  runr regions knative uninstall eu-1 --yes --wait",
		Args: cobra.MaximumNArgs(1),
	}
	status := &cobra.Command{
		Use:     "status [REGION]",
		Aliases: []string{"get"},
		Short:   "Show the install state of a region's Knative operator and Serving",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			st, err := c.RegionComponents(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(st, func(t *output.TableWriter) {
				t.KV("Region", reg.Name)
				t.KV("Operator", rlcStatus(st.Operator.Status, st.Operator.Message)+" in "+output.Dash(st.Operator.Namespace))
				serving := rlcStatus(st.Serving.Status, st.Serving.Message) + " in " + output.Dash(st.Serving.Namespace)
				if st.Serving.Version != "" {
					serving += ", version " + st.Serving.Version
				}
				t.KV("Serving", serving)
				t.KV("Ingress", output.Dash(st.Serving.IngressClass))
			})
		},
	}
	cmd.AddCommand(status)
	cmd.RunE = status.RunE
	cmd.AddCommand(newRegionKnativeInstallCmd(app), newRegionKnativeUpgradeCmd(app), newRegionKnativeUninstallCmd(app), newRegionKnativeDetectCmd(app),
		newRegionKnativeVersionsCmd(app), newRegionKnativeNamespaceCmd(app))
	return cmd
}

// knativeComponentArg reads the optional COMPONENT argument (all, operator, serving).
func knativeComponentArg(args []string) (string, error) {
	if len(args) < 2 {
		return runr.KnativeComponentAll, nil
	}
	switch c := strings.ToLower(args[1]); c {
	case runr.KnativeComponentAll, runr.KnativeComponentOperator, runr.KnativeComponentServing:
		return c, nil
	}
	return "", usageErrorf("the Knative component is all, operator or serving, not %q", args[1])
}

func knativeLabel(component string) string {
	switch component {
	case runr.KnativeComponentOperator:
		return "the Knative operator"
	case runr.KnativeComponentServing:
		return "Knative Serving"
	}
	return "Knative"
}

// regionKnativeWait follows an install or removal and prints the region's components.
func regionKnativeWait(ctx context.Context, app *App, c *runr.Client, reg *runr.Region, component string, timeout time.Duration) error {
	d, err := c.WaitKnative(ctx, reg.ID, reg.Name, component, app.waitOptions(timeout, knativeLabel(component)+" on "+reg.Name))
	if d == nil {
		return err
	}
	if err == nil {
		st, _ := knativeStatus(d, component)
		app.Progress("%s on %s: %s.", upperFirst(knativeLabel(component)), reg.Name, st)
	}
	if perr := app.Printer().Print(d, func(t *output.TableWriter) { printRegionDetail(t, d) }); perr != nil && err == nil {
		err = perr
	}
	return err
}

// upperFirst capitalizes the first letter of a label that starts a sentence.
func upperFirst(s string) string {
	if s == "" {
		return s
	}
	return strings.ToUpper(s[:1]) + s[1:]
}

// knativeStatus is the status of a component on the region resource.
func knativeStatus(d *runr.RegionDetail, component string) (string, string) {
	switch component {
	case runr.KnativeComponentOperator:
		return output.Dash(d.OperatorStatus), d.OperatorStatusMessage
	case runr.KnativeComponentServing:
		return output.Dash(d.ServingStatus), d.ServingStatusMessage
	}
	return output.Dash(d.KnativeStatus), d.KnativeStatusMessage
}

func printRegionOperation(app *App, reg *runr.Region, res *runr.RegionOperation, hint string) error {
	return app.Printer().Print(res, func(t *output.TableWriter) {
		if res.Region != nil {
			printRegionDetail(t, res.Region)
		} else {
			t.KV("Region", reg.Name)
		}
		if hint != "" {
			t.Note("%s", hint)
		}
	})
}

func newRegionKnativeInstallCmd(app *App) *cobra.Command {
	var namespace, version string
	var wait bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "install REGION [all|operator|serving]",
		Short: "Install Knative on a region: the operator and Serving, or one of them (regions.manage)",
		Long: "Install the Knative operator and Knative Serving with Helm (all, the default), or one of them:\n" +
			"operator (--namespace, default knative-operator), then serving (--namespace, default\n" +
			"knative-serving; --version a Knative minor version from runr regions knative versions,\n" +
			"default the newest the cluster supports). What already runs on the cluster is adopted\n" +
			"instead, with all too: a running operator is never installed again (that would remove the\n" +
			"Knative CRDs and every Knative Service), a Ready Knative Serving is never applied again. The\n" +
			"install runs in the background: --wait waits (exit code 6 when it fails).",
		Example: "  runr regions knative install eu-1 --wait\n" +
			"  runr regions knative install eu-1 operator --wait\n" +
			"  runr regions knative install eu-1 serving --version 1.18 --wait",
		Args: cobra.RangeArgs(1, 2),
		RunE: func(cmd *cobra.Command, args []string) error {
			component, err := knativeComponentArg(args)
			if err != nil {
				return err
			}
			if component == runr.KnativeComponentAll && (namespace != "" || version != "") {
				return usageErrorf("--namespace and --version apply to the operator or serving component")
			}
			if component == runr.KnativeComponentOperator && version != "" {
				return usageErrorf("--version applies to serving")
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			res, err := c.InstallKnative(ctx, reg.ID, component, runr.KnativeInstallOptions{Namespace: namespace, Version: version})
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			if wait && !res.Adopted {
				return regionKnativeWait(ctx, app, c, reg, component, timeout)
			}
			return printRegionOperation(app, reg, res, "runr regions knative "+reg.Name+" shows the progress.")
		},
	}
	cmd.Flags().StringVar(&namespace, "namespace", "", "operator / serving: namespace of the component")
	cmd.Flags().StringVar(&version, "version", "", "serving: Knative minor version (1.18)")
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the install ended (exit code 6 when it fails)")
	cmd.Flags().DurationVar(&timeout, "timeout", 20*time.Minute, "how long --wait waits")
	return cmd
}

func newRegionKnativeUpgradeCmd(app *App) *cobra.Command {
	var version string
	var wait bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "upgrade REGION --version VERSION",
		Short: "Upgrade a region's Knative Serving to the next minor version (regions.manage)",
		Long: "Upgrade Knative Serving to --version, the minor version after the running one (Knative\n" +
			"upgrades one minor at a time) that the cluster supports (runr regions knative versions).\n" +
			"The Knative operator moves to that version first when it is older; the region's Knative\n" +
			"Services keep running while the operator rolls Serving's components. Regions on the same\n" +
			"cluster share Knative Serving. The upgrade runs in the background: --wait waits (exit code 6\n" +
			"when it fails).",
		Example: "  runr regions knative versions eu-1\n" +
			"  runr regions knative upgrade eu-1 --version 1.21 --wait",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if version == "" {
				return usageErrorf("--version is required (the next minor version, runr regions knative versions)")
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			res, err := c.UpgradeKnativeServing(ctx, reg.ID, version)
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			if wait {
				return regionKnativeWait(ctx, app, c, reg, runr.KnativeComponentServing, timeout)
			}
			return printRegionOperation(app, reg, res, "runr regions knative "+reg.Name+" shows the progress.")
		},
	}
	cmd.Flags().StringVar(&version, "version", "", "the next Knative minor version (1.21)")
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the upgrade ended (exit code 6 when it fails)")
	cmd.Flags().DurationVar(&timeout, "timeout", 30*time.Minute, "how long --wait waits")
	return cmd
}

func newRegionKnativeUninstallCmd(app *App) *cobra.Command {
	var removeTraefik, wait, yes bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:     "uninstall REGION [all|operator|serving]",
		Aliases: []string{"rm", "remove"},
		Short:   "Remove Knative from a region, or one of its components (regions.manage)",
		Long: "Remove Knative Serving and the operator (all, the default), Serving only, or the operator\n" +
			"(after Serving). The region's projects stop serving. The region's Traefik stays while it\n" +
			"serves other Ingresses (such as the RunR console on the same cluster); --remove-traefik\n" +
			"removes it anyway. The removal runs in the background: --wait waits.",
		Example: "  runr regions knative uninstall eu-1 --yes --wait\n" +
			"  runr regions knative uninstall eu-1 serving --remove-traefik --yes",
		Args: cobra.RangeArgs(1, 2),
		RunE: func(cmd *cobra.Command, args []string) error {
			component, err := knativeComponentArg(args)
			if err != nil {
				return err
			}
			if removeTraefik && component == runr.KnativeComponentOperator {
				return usageErrorf("--remove-traefik applies to all or serving")
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Remove "+knativeLabel(component)+" from "+reg.Name+" (its projects stop serving)", yes); err != nil {
				return err
			}
			res, err := c.UninstallKnative(ctx, reg.ID, component, removeTraefik)
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			if wait {
				return regionKnativeWait(ctx, app, c, reg, component, timeout)
			}
			return printRegionOperation(app, reg, res, "runr regions knative "+reg.Name+" shows the progress.")
		},
	}
	cmd.Flags().BoolVar(&removeTraefik, "remove-traefik", false, "also remove the region's Traefik when it serves other Ingresses")
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the removal ended")
	cmd.Flags().DurationVar(&timeout, "timeout", 20*time.Minute, "how long --wait waits")
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}

func newRegionKnativeDetectCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:     "detect REGION",
		Aliases: []string{"adopt"},
		Short:   "Adopt a Knative operator and Serving already running on the cluster (regions.manage)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			res, err := c.DetectKnative(ctx, reg.ID)
			if err != nil {
				return err
			}
			adopted := func(a *runr.KnativeAdoption) string {
				if a == nil || !a.Adopted {
					return "not found"
				}
				return "adopted in " + output.Dash(a.Namespace)
			}
			return app.Printer().Print(res, func(t *output.TableWriter) {
				t.KV("Region", reg.Name)
				t.KV("Operator", adopted(res.Operator))
				t.KV("Serving", adopted(res.Serving))
				if res.Region != nil {
					t.KV("Knative", output.Dash(res.Region.KnativeStatus))
				}
			})
		},
	}
}

func newRegionKnativeVersionsCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "versions [REGION]",
		Short: "Knative versions the region's Kubernetes version supports, newest first",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			versions, err := c.KnativeVersions(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(versions, func(t *output.TableWriter) {
				t.Header("VERSION")
				for _, v := range versions {
					t.Row(v)
				}
				if len(versions) == 0 {
					t.Note("RunR could not read the cluster's Kubernetes version.")
				}
			})
		},
	}
}

func newRegionKnativeNamespaceCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "namespace REGION NAME",
		Short: "Whether a namespace exists on the region's cluster (before an install into it)",
		Args:  cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			exists, err := c.RegionNamespaceExists(cmd.Context(), reg.ID, args[1])
			if err != nil {
				return err
			}
			return app.Printer().Print(map[string]any{"region": reg.Name, "namespace": args[1], "exists": exists}, func(t *output.TableWriter) {
				t.KV("Region", reg.Name)
				t.KV("Namespace "+args[1], map[bool]string{true: "exists", false: "does not exist"}[exists])
			})
		},
	}
}
