package cmd

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// `runr regions wasm`: WebAssembly support of a region (WasmController, docs/wasm.md): the
// runwasi shims on selected nodes the wasm-* runtimes deploy on.

// wasmReasons are the texts of WasmNodes' reasons (resources/wasm/lang/en/messages.php).
var wasmReasons = map[string]string{
	"not_linux":     "not a Linux node",
	"not_dedicated": "projects run on the dedicated workers only",
	"tainted":       "tainted or cordoned: projects cannot run there",
	"runtime":       "the container runtime is not containerd",
	"arch":          "the shims are published for amd64 and arm64",
}

func newRegionWasmCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "wasm [REGION]",
		Aliases: []string{"webassembly"},
		Short:   "WebAssembly support of a region: runwasi shims on its nodes",
		Long: "WebAssembly support installs runwasi shims (spin, the shim of the wasm-* runtimes, and\n" +
			"optionally wasmtime) on the region's eligible nodes, with their RuntimeClasses (docs/wasm.md).\n" +
			"Knative Serving must be installed. Installing restarts the container runtime once on every\n" +
			"selected node, so it asks for confirmation (--yes skips it); it runs in the background\n" +
			"(--wait waits, exit code 6 when it fails). Reading needs regions.view, changes sandbox.manage.",
		Example: "  runr regions wasm eu-1\n" +
			"  runr regions wasm install eu-1 --wait --yes\n" +
			"  runr regions wasm install eu-1 --shim spin --shim wasmtime --node worker-1 --wait --yes\n" +
			"  runr regions wasm uninstall eu-1 --yes",
		Args: cobra.MaximumNArgs(1),
	}
	var noCluster bool
	status := &cobra.Command{
		Use:     "status [REGION]",
		Aliases: []string{"get"},
		Short:   "Show WebAssembly support of a region, its nodes and shims",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			w, err := c.RegionWasm(cmd.Context(), reg.ID, !noCluster)
			if err != nil {
				return err
			}
			return printRegionWasm(app, reg, w)
		},
	}
	status.Flags().BoolVar(&noCluster, "no-cluster", false, "show the recorded state without reading the cluster")
	cmd.Flags().BoolVar(&noCluster, "no-cluster", false, "show the recorded state without reading the cluster")
	cmd.AddCommand(status)
	cmd.RunE = status.RunE
	cmd.AddCommand(newRegionWasmInstallCmd(app), newRegionWasmUninstallCmd(app))
	return cmd
}

func printRegionWasm(app *App, reg *runr.Region, w *runr.RegionWasm) error {
	return app.Printer().Print(w, func(t *output.TableWriter) {
		t.KV("Region", reg.Name)
		t.KV("Status", rlcStatus(w.Status, w.Message))
		t.KV("Shims", joinOrDash(w.Shims))
		nodes := "every eligible node"
		if w.Nodes != nil {
			nodes = joinOrDash(w.Nodes)
		}
		if w.Status != runr.KnativeReady && w.Status != runr.KnativeInstalling {
			nodes = "-"
		}
		t.KV("Nodes", nodes)
		available := make([]string, 0, len(w.AvailableShims))
		for _, s := range w.AvailableShims {
			available = append(available, s.ID+" "+s.Version+" ("+s.RuntimeClass+")")
		}
		t.KV("Available shims", joinOrDash(available))
		if w.ClusterError != "" {
			t.KV("Cluster", "unreachable: "+output.Truncate(w.ClusterError, 100))
		}
		if cl := w.Cluster; cl != nil {
			if len(cl.KnativeFeaturesToEnable) > 0 {
				t.KV("Knative features to enable", strings.Join(cl.KnativeFeaturesToEnable, ", "))
			}
			if cl.Installer != nil {
				t.KV("Installer", fmt.Sprintf("%d of %d nodes ready (%s)", cl.Installer.Ready, cl.Installer.Desired,
					joinOrDash(cl.Installer.Shims)))
			}
			t.Header("NODE", "ARCH", "RUNTIME", "ELIGIBLE", "TARGET", "SHIMS")
			for _, n := range cl.Nodes {
				eligible := "yes"
				if !n.Eligible {
					eligible = "no"
					if r := wasmReasons[n.Reason]; r != "" {
						eligible += ": " + r
					} else if n.Reason != "" {
						eligible += ": " + n.Reason
					}
				}
				rt := strings.TrimSpace(n.Flavor + " " + n.RuntimeVersion)
				t.Row(n.Name, output.Dash(n.Arch), output.Dash(rt), eligible, yesNo(n.Target), joinOrDash(n.Shims))
			}
		}
	})
}

func regionWasmWait(ctx context.Context, app *App, c *runr.Client, reg *runr.Region, timeout time.Duration) error {
	w, err := c.WaitRegionWasm(ctx, reg.ID, reg.Name, app.waitOptions(timeout, "WebAssembly support of "+reg.Name))
	if w == nil {
		return err
	}
	if err == nil {
		app.Progress("WebAssembly support of %s: %s.", reg.Name, w.Status)
	}
	if perr := printRegionWasm(app, reg, w); perr != nil && err == nil {
		err = perr
	}
	return err
}

func newRegionWasmInstallCmd(app *App) *cobra.Command {
	var shims, nodes []string
	var wait, yes bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "install REGION",
		Short: "Install WebAssembly support, or change its shims or nodes (sandbox.manage)",
		Long: "Install runwasi shims on the region's nodes: --shim spin (the default; the shim of the wasm-*\n" +
			"runtimes) and/or wasmtime, on every eligible node or the --node ones (repeatable). Installing\n" +
			"again changes the shims or nodes; a failed change keeps the working install. The container\n" +
			"runtime restarts once on every selected node: confirm, or pass --yes.",
		Example: "  runr regions wasm install eu-1 --wait --yes\n" +
			"  runr regions wasm install eu-1 --shim spin --shim wasmtime --node worker-1 --node worker-2 --yes",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			in := runr.WasmInstallInput{Shims: []string{}}
			seen := map[string]bool{}
			for _, s := range shims {
				s = strings.ToLower(strings.TrimSpace(s))
				known := false
				for _, k := range runr.WasmShims {
					known = known || k == s
				}
				if !known {
					return usageErrorf("unknown WebAssembly shim %q (%s)", s, strings.Join(runr.WasmShims, ", "))
				}
				if !seen[s] {
					seen[s] = true
					in.Shims = append(in.Shims, s)
				}
			}
			if len(in.Shims) == 0 {
				in.Shims = []string{"spin"}
			}
			if len(nodes) > 0 {
				in.Nodes = nodes
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			target := "every eligible node"
			if len(nodes) > 0 {
				target = strings.Join(nodes, ", ")
			}
			question := fmt.Sprintf("Install WebAssembly support (%s) on %s of %s: this restarts the container runtime once on every selected node",
				strings.Join(in.Shims, ", "), target, reg.Name)
			if err := app.confirm(question, yes); err != nil {
				return err
			}
			res, err := c.InstallRegionWasm(ctx, reg.ID, in)
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			if wait {
				return regionWasmWait(ctx, app, c, reg, timeout)
			}
			return printRegionOperation(app, reg, res, "runr regions wasm "+reg.Name+" shows the progress.")
		},
	}
	cmd.Flags().StringArrayVar(&shims, "shim", nil, "shim to install: spin or wasmtime (repeatable; default spin)")
	cmd.Flags().StringArrayVar(&nodes, "node", nil, "node to install on (repeatable; default every eligible node)")
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the install ended (exit code 6 when it fails)")
	cmd.Flags().DurationVar(&timeout, "timeout", 30*time.Minute, "how long --wait waits")
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask before the container runtime restarts")
	return cmd
}

func newRegionWasmUninstallCmd(app *App) *cobra.Command {
	var wait, yes bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:     "uninstall REGION",
		Aliases: []string{"rm", "remove"},
		Short:   "Remove WebAssembly support from a region's nodes (sandbox.manage)",
		Long: "Remove the shims and their RuntimeClasses from the region's nodes; refused while Wasm\n" +
			"projects run. The removal runs in the background: --wait waits.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Remove WebAssembly support from the nodes of "+reg.Name, yes); err != nil {
				return err
			}
			res, err := c.UninstallRegionWasm(ctx, reg.ID)
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			if wait {
				return regionWasmWait(ctx, app, c, reg, timeout)
			}
			return printRegionOperation(app, reg, res, "runr regions wasm "+reg.Name+" shows the progress.")
		},
	}
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the removal ended")
	cmd.Flags().DurationVar(&timeout, "timeout", 30*time.Minute, "how long --wait waits")
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}
