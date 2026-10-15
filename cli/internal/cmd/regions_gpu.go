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

// `runr regions gpu` (GPU support of a region: the NVIDIA device plugin or GPU operator) and
// `runr regions model-storage` (the volume model services keep their weights on).

func newRegionGPUCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "gpu [REGION]",
		Short: "GPU support of a region: device plugin or GPU operator, nodes, allocation, settings",
		Long: "GPU support of a region (docs/ai.md): RunR installs the NVIDIA device plugin (nodes with\n" +
			"a working driver and container toolkit) or the NVIDIA GPU operator (which installs the\n" +
			"driver and the toolkit itself) on the region's GPU nodes. Reading needs regions.view,\n" +
			"changes regions.gpu.manage on a region your organization may change.",
		Example: "  runr regions gpu eu-1 --refresh\n" +
			"  runr regions gpu install eu-1 --mode device-plugin --time-slicing 4 --wait\n" +
			"  runr regions gpu install eu-1 --mode operator --wait --yes\n" +
			"  runr regions gpu set eu-1 --gvisor-nvproxy\n" +
			"  runr regions gpu uninstall eu-1 --yes",
		Args: cobra.MaximumNArgs(1),
	}
	var refresh bool
	status := &cobra.Command{
		Use:     "status [REGION]",
		Aliases: []string{"get"},
		Short:   "Show the GPU support, GPU nodes, allocation and settings of a region",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			g, err := c.RegionGPU(cmd.Context(), reg.ID, refresh)
			if err != nil {
				return err
			}
			return printRegionGPU(app, reg, g)
		},
	}
	status.Flags().BoolVar(&refresh, "refresh", false, "read the nodes from the cluster now")
	cmd.Flags().AddFlagSet(status.Flags())
	cmd.RunE = status.RunE
	cmd.AddCommand(status, newRegionGPUInstallCmd(app), newRegionGPUUninstallCmd(app), newRegionGPUSetCmd(app))
	return cmd
}

func strOr(p *string, dash string) string {
	if p == nil || *p == "" {
		return dash
	}
	return *p
}

func printRegionGPU(app *App, reg *runr.Region, g *runr.RegionGPU) error {
	return app.Printer().Print(g, func(t *output.TableWriter) {
		t.KV("Region", reg.Name)
		status := g.Status
		if g.Status != "ready" && g.Status != "not_installed" && lastLineOf(g.StatusMessage) != "" {
			status += " (" + output.Truncate(lastLineOf(g.StatusMessage), 100) + ")"
		}
		t.KV("GPU support", g.Mode+", "+status)
		s := g.Summary
		t.KV("GPUs", fmt.Sprintf("%d in total, %d allocatable, %d allocated, %d available", s.Total, s.Allocatable, s.Allocated, s.Available))
		if len(s.HardwareWithoutPlugin) > 0 {
			t.KV("GPU hardware without the plugin", strings.Join(s.HardwareWithoutPlugin, ", "))
		}
		st := g.Settings
		slicing := "off"
		if st.TimeSlicingReplicas != nil {
			slicing = strconv.Itoa(*st.TimeSlicingReplicas) + " pods per GPU"
		}
		t.KV("Time-slicing", slicing)
		t.KV("RuntimeClass", fmt.Sprintf("%s (chosen: %s)", strOr(st.RuntimeClass, "none"), output.Dash(st.RuntimeClassChoice)))
		t.KV("gVisor nvproxy", onOff(st.GVisorNvproxy))
		nodes := "detected automatically"
		if len(st.GPUNodes) > 0 {
			nodes = strings.Join(st.GPUNodes, ", ")
		}
		t.KV("GPU nodes", fmt.Sprintf("%s (plugin only on them: %s)", nodes, yesNoWord(st.RestrictToGPUNodes)))
		if g.Mode == runr.GPUModeOperator {
			t.KV("Operator", fmt.Sprintf("driver %s, container toolkit %s", onOff(st.OperatorDriver), onOff(st.OperatorToolkit)))
		}
		for _, typ := range []string{"faas", "xaas", "caas"} {
			if sb, ok := g.Sandbox[typ]; ok && sb.GPU != "ok" {
				t.KV("GPUs of "+typ+" projects", sb.GPU+": "+strOr(sb.Message, "-"))
			}
		}
		t.Header("NODE", "READY", "PRODUCT", "GPUS", "ALLOCATABLE", "ALLOCATED", "DRIVER", "CUDA", "PLUGIN")
		for _, n := range g.Nodes {
			t.Row(n.Name, yesNoWord(n.Ready), strOr(n.Product, "-"), strconv.Itoa(n.Count), strconv.Itoa(n.Allocatable),
				strconv.Itoa(n.Allocated), strOr(n.DriverVersion, "-"), strOr(n.CUDAVersion, "-"), yesNoWord(n.HasDevicePlugin))
		}
		if len(g.Nodes) == 0 {
			t.Note("No GPU nodes found (--refresh reads the nodes now).")
		}
	})
}

func lastLineOf(s string) string { return runr.SandboxRuntimeStatus{Message: s}.LastLine() }

// regionGPUWait follows a GPU install, removal or reconfiguration and prints the result.
func regionGPUWait(ctx context.Context, app *App, c *runr.Client, reg *runr.Region, res *runr.GPUChange, wait bool, timeout time.Duration) error {
	if res.Message != "" {
		app.Progress("%s", res.Message)
	}
	if !wait || res.GPU == nil || (res.GPU.Status != "installing" && res.GPU.Status != "uninstalling") {
		if res.GPU == nil {
			return app.Printer().Print(res, func(*output.TableWriter) {})
		}
		return printRegionGPU(app, reg, res.GPU)
	}
	g, err := c.WaitRegionGPU(ctx, reg.ID, reg.Name, app.waitOptions(timeout, "GPU support of "+reg.Name))
	if g == nil {
		return err
	}
	if perr := printRegionGPU(app, reg, g); perr != nil && err == nil {
		err = perr
	}
	return err
}

// gpuCommonFlags reads --time-slicing / --no-time-slicing, --runtime-class, --gpu-node and
// --restrict-to-gpu-nodes into body.
func gpuCommonFlags(cmd *cobra.Command, body map[string]any, slicing int, noSlicing bool, runtimeClass string, nodes []string) error {
	fl := cmd.Flags()
	if fl.Changed("time-slicing") && noSlicing {
		return usageErrorf("give --time-slicing or --no-time-slicing, not both")
	}
	if fl.Changed("time-slicing") {
		if slicing < 1 || slicing > 16 {
			return usageErrorf("--time-slicing must be between 1 and 16 pods per GPU (1: off)")
		}
		body["time_slicing_replicas"] = slicing
	}
	if noSlicing {
		body["time_slicing_replicas"] = nil
	}
	if fl.Changed("runtime-class") {
		switch runtimeClass {
		case "auto", "nvidia", "none":
			body["runtime_class"] = runtimeClass
		default:
			return usageErrorf("--runtime-class must be auto, nvidia or none")
		}
	}
	if len(nodes) > 0 {
		body["gpu_nodes"] = nodes
	}
	if fl.Changed("restrict-to-gpu-nodes") {
		v, _ := fl.GetBool("restrict-to-gpu-nodes")
		body["restrict_to_gpu_nodes"] = v
	}
	return nil
}

func addGPUCommonFlags(cmd *cobra.Command, slicing *int, noSlicing *bool, runtimeClass *string, nodes *[]string) {
	fl := cmd.Flags()
	fl.IntVar(slicing, "time-slicing", 0, "pods that share one GPU (1-16; 1: no sharing)")
	fl.BoolVar(noSlicing, "no-time-slicing", false, "no GPU sharing")
	fl.StringVar(runtimeClass, "runtime-class", "", "RuntimeClass of GPU pods: auto, nvidia or none")
	fl.StringArrayVar(nodes, "gpu-node", nil, "a GPU node (repeatable; default: detected automatically)")
	fl.Bool("restrict-to-gpu-nodes", true, "run the device plugin only on the GPU nodes (--restrict-to-gpu-nodes=false: on every node)")
}

func newRegionGPUInstallCmd(app *App) *cobra.Command {
	var mode, runtimeClass string
	var slicing int
	var noSlicing, wait, yes bool
	var nodes []string
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "install REGION --mode device-plugin|operator",
		Short: "Install GPU support on a region's GPU nodes (regions.gpu.manage)",
		Long: "Install GPU support: --mode device-plugin (the nodes already have the NVIDIA driver and\n" +
			"container toolkit) or --mode operator (the NVIDIA GPU operator installs them: --driver=false\n" +
			"or --toolkit=false keep what the nodes have). The operator's driver build and toolkit\n" +
			"install restart the container runtime on the GPU nodes, so it asks first (--yes). The\n" +
			"install runs in the background: --wait waits (exit code 6 when it fails).",
		Example: "  runr regions gpu install eu-1 --mode device-plugin --time-slicing 4 --wait\n" +
			"  runr regions gpu install eu-1 --mode operator --gpu-node gpu-1 --wait --yes",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if mode != runr.GPUModeDevicePlugin && mode != runr.GPUModeOperator {
				return usageErrorf("--mode must be device-plugin or operator")
			}
			body := map[string]any{"mode": mode}
			if err := gpuCommonFlags(cmd, body, slicing, noSlicing, runtimeClass, nodes); err != nil {
				return err
			}
			fl := cmd.Flags()
			driver, toolkit := true, true
			for _, f := range []struct {
				flag, key string
				dst       *bool
			}{{"driver", "operator_driver", &driver}, {"toolkit", "operator_toolkit", &toolkit}} {
				if fl.Changed(f.flag) {
					if mode != runr.GPUModeOperator {
						return usageErrorf("--%s goes with --mode operator", f.flag)
					}
					*f.dst, _ = fl.GetBool(f.flag)
					body[f.key] = *f.dst
				}
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			if mode == runr.GPUModeOperator && (driver || toolkit) {
				question := "Install the NVIDIA GPU operator on " + reg.Name + ": it installs the driver and the container toolkit on the GPU nodes and restarts their container runtime"
				if err := app.confirm(question, yes); err != nil {
					return err
				}
			}
			res, err := c.InstallGPUSupport(ctx, reg.ID, body)
			if err != nil {
				return err
			}
			return regionGPUWait(ctx, app, c, reg, res, wait, timeout)
		},
	}
	cmd.Flags().StringVar(&mode, "mode", "", "device-plugin or operator")
	addGPUCommonFlags(cmd, &slicing, &noSlicing, &runtimeClass, &nodes)
	cmd.Flags().Bool("driver", true, "operator: install the NVIDIA driver")
	cmd.Flags().Bool("toolkit", true, "operator: install the NVIDIA container toolkit")
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the install ended (exit code 6 when it fails)")
	cmd.Flags().DurationVar(&timeout, "timeout", 30*time.Minute, "how long --wait waits")
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask before the operator changes the nodes")
	return cmd
}

func newRegionGPUUninstallCmd(app *App) *cobra.Command {
	var wait, yes bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:     "uninstall REGION",
		Aliases: []string{"rm", "remove"},
		Short:   "Remove the GPU support RunR installed (regions.gpu.manage)",
		Long: "Remove the device plugin or the GPU operator RunR installed. GPU workloads are no longer\n" +
			"scheduled; the operator's removal takes the driver and toolkit it installed off the nodes and\n" +
			"restarts their container runtime. Asks first (--yes); --wait waits.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Remove the GPU support of "+reg.Name+": GPU workloads lose their GPUs", yes); err != nil {
				return err
			}
			res, err := c.UninstallGPUSupport(ctx, reg.ID)
			if err != nil {
				return err
			}
			return regionGPUWait(ctx, app, c, reg, res, wait, timeout)
		},
	}
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the removal ended")
	cmd.Flags().DurationVar(&timeout, "timeout", 30*time.Minute, "how long --wait waits")
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}

func newRegionGPUSetCmd(app *App) *cobra.Command {
	var runtimeClass string
	var slicing int
	var noSlicing, autoNodes, wait bool
	var nodes []string
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "set REGION",
		Short: "Change GPU settings: time-slicing, RuntimeClass, gVisor nvproxy, GPU nodes",
		Long: "Change GPU settings; flags left out keep their value. Changes the installed chart needs\n" +
			"(time-slicing, RuntimeClass, GPU nodes) are applied in the background (--wait waits);\n" +
			"--gvisor-nvproxy lets gVisor-sandboxed projects use GPUs through nvproxy.",
		Example: "  runr regions gpu set eu-1 --time-slicing 2 --wait\n" +
			"  runr regions gpu set eu-1 --gvisor-nvproxy --auto-gpu-nodes",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			body := map[string]any{}
			if err := gpuCommonFlags(cmd, body, slicing, noSlicing, runtimeClass, nodes); err != nil {
				return err
			}
			if autoNodes {
				if len(nodes) > 0 {
					return usageErrorf("give --gpu-node or --auto-gpu-nodes, not both")
				}
				body["gpu_nodes"] = nil
			}
			if cmd.Flags().Changed("gvisor-nvproxy") {
				v, _ := cmd.Flags().GetBool("gvisor-nvproxy")
				body["gvisor_nvproxy"] = v
			}
			if len(body) == 0 {
				return usageErrorf("give at least one setting to change")
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			res, err := c.UpdateGPUSettings(ctx, reg.ID, body)
			if err != nil {
				return err
			}
			return regionGPUWait(ctx, app, c, reg, res, wait, timeout)
		},
	}
	addGPUCommonFlags(cmd, &slicing, &noSlicing, &runtimeClass, &nodes)
	cmd.Flags().BoolVar(&autoNodes, "auto-gpu-nodes", false, "find the GPU nodes automatically again")
	cmd.Flags().Bool("gvisor-nvproxy", false, "let gVisor-sandboxed projects use GPUs (nvproxy)")
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the settings are applied")
	cmd.Flags().DurationVar(&timeout, "timeout", 30*time.Minute, "how long --wait waits")
	return cmd
}

// parseSizeGi reads a size in GiB: 200, 200Gi or 2Ti.
func parseSizeGi(s string) (int, error) {
	v := strings.TrimSpace(s)
	mul := 1
	switch {
	case strings.HasSuffix(v, "Ti"):
		v, mul = strings.TrimSuffix(v, "Ti"), 1024
	case strings.HasSuffix(v, "Gi"):
		v = strings.TrimSuffix(v, "Gi")
	}
	n, err := strconv.Atoi(v)
	if err != nil || n < 1 {
		return 0, fmt.Errorf("%q is not a size such as 200, 200Gi or 2Ti", s)
	}
	return n * mul, nil
}

func newRegionModelStorageCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "model-storage [REGION]",
		Short: "The volume model services keep their weights on: storage class, size, access mode",
		Long: "The volume claim of a region model services keep downloaded and uploaded weights on\n" +
			"(docs/ai.md). Once the claim exists only a larger size applies; its storage class and\n" +
			"access mode stay. Reading needs regions.view, changes regions.gpu.manage.",
		Example: "  runr regions model-storage eu-1\n" +
			"  runr regions model-storage set eu-1 --storage-class longhorn --size 500Gi --access-mode ReadWriteMany",
		Args: cobra.MaximumNArgs(1),
	}
	get := &cobra.Command{
		Use:   "get [REGION]",
		Short: "Show the model storage of a region",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			ms, err := c.RegionModelStorage(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return printModelStorage(app, reg, ms)
		},
	}
	cmd.RunE = get.RunE
	var storageClass, size, accessMode string
	set := &cobra.Command{
		Use:   "set REGION",
		Short: "Change the model storage of a region (regions.gpu.manage)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			fl := cmd.Flags()
			body := map[string]any{}
			if fl.Changed("storage-class") {
				body["storage_class"] = storageClass
			}
			if fl.Changed("size") {
				n, err := parseSizeGi(size)
				if err != nil {
					return usageErrorf("--size: %v", err)
				}
				body["size_gi"] = n
			}
			if fl.Changed("access-mode") {
				switch accessMode {
				case "ReadWriteMany", "ReadWriteOnce":
					body["access_mode"] = accessMode
				default:
					return usageErrorf("--access-mode must be ReadWriteMany or ReadWriteOnce")
				}
			}
			if len(body) == 0 {
				return usageErrorf("give --storage-class, --size or --access-mode")
			}
			c, reg, err := radmRegion(cmd.Context(), app, args[0])
			if err != nil {
				return err
			}
			ms, err := c.UpdateModelStorage(cmd.Context(), reg.ID, body)
			if err != nil {
				return err
			}
			app.Progress("Updated the model storage of %s.", reg.Name)
			return printModelStorage(app, reg, ms)
		},
	}
	set.Flags().StringVar(&storageClass, "storage-class", "", "StorageClass of the claim (empty: the cluster's default)")
	set.Flags().StringVar(&size, "size", "", "size in GiB: 200, 200Gi or 2Ti")
	set.Flags().StringVar(&accessMode, "access-mode", "", "ReadWriteMany or ReadWriteOnce")
	cmd.AddCommand(get, set)
	return cmd
}

func printModelStorage(app *App, reg *runr.Region, ms *runr.ModelStorage) error {
	return app.Printer().Print(ms, func(t *output.TableWriter) {
		t.KV("Region", reg.Name)
		t.KV("Claim", ms.PVCName+", "+scStatus(ms.Status, strOr(ms.Message, "")))
		t.KV("Storage class", output.Dash(ms.StorageClass))
		size := strconv.Itoa(ms.SizeGi) + "Gi"
		if ms.Capacity != nil {
			size += " (capacity " + *ms.Capacity + ")"
		}
		t.KV("Size", size)
		t.KV("Access mode", output.Dash(ms.AccessMode))
	})
}
