package cmd

import (
	"context"
	"fmt"
	"sort"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/runrsh/core/cli/internal/output"
	"github.com/runrsh/core/cli/pkg/runr"
)

// `runr regions sandbox`: the sandbox runtimes of a region (Kata Containers and gVisor on its
// nodes), the hardware virtualization Kata needs and which runtime each project type runs
// under (SandboxController).

func newRegionSandboxCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "sandbox [REGION]",
		Short: "Sandbox runtimes of a region: Kata Containers, gVisor and the project type mapping",
		Long: "The sandbox runtimes of a region run on its nodes: Kata Containers (a micro-VM per pod, with\n" +
			"the hypervisors clh, qemu, dragonball and fc; it needs hardware virtualization, /dev/kvm) and\n" +
			"gVisor (a user-space kernel). They isolate the Sandbox API's sandboxes and, through the\n" +
			"mapping, the projects of each type and image builds. Installs and removals run in the\n" +
			"background (--wait waits). Reading needs regions.view, changes sandbox.manage and a region\n" +
			"your organization may change.",
		Example: "  runr regions sandbox eu-1\n" +
			"  runr regions sandbox shims eu-1\n" +
			"  runr regions sandbox install eu-1 kata --hypervisor clh --hypervisor fc --wait --yes\n" +
			"  runr regions sandbox install eu-1 gvisor --wait\n" +
			"  runr regions sandbox map eu-1 --faas gvisor --build kata-clh\n" +
			"  runr regions sandbox uninstall eu-1 gvisor --yes",
		Args: cobra.MaximumNArgs(1),
	}
	status := &cobra.Command{
		Use:     "status [REGION]",
		Aliases: []string{"get"},
		Short:   "Show the sandbox runtimes of a region and the project type mapping",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			st, err := c.RegionSandboxStatus(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return printRegionSandbox(app, reg, st)
		},
	}
	cmd.AddCommand(status)
	cmd.RunE = status.RunE
	cmd.AddCommand(newRegionSandboxShimsCmd(app), newRegionSandboxInstallCmd(app), newRegionSandboxUninstallCmd(app),
		newRegionSandboxMapCmd(app))
	return cmd
}

func sandboxRuntimeLabel(st runr.SandboxRuntimeStatus) string {
	s := output.Dash(st.Status)
	if len(st.Hypervisors) > 0 {
		s += ": " + strings.Join(st.Hypervisors, ", ")
	}
	if st.Status != runr.SandboxRuntimeReady && st.Status != runr.SandboxRuntimeNotInstalled && st.LastLine() != "" {
		s += " (" + output.Truncate(st.LastLine(), 100) + ")"
	}
	return s
}

func printRegionSandbox(app *App, reg *runr.Region, st *runr.RegionSandboxStatus) error {
	return app.Printer().Print(st, func(t *output.TableWriter) {
		t.KV("Region", reg.Name)
		t.KV("Kata Containers", sandboxRuntimeLabel(st.Kata))
		t.KV("gVisor", sandboxRuntimeLabel(st.GVisor))
		t.KV("faas projects run under", output.Dash(st.Mapping.FaaS))
		t.KV("xaas projects run under", output.Dash(st.Mapping.XaaS))
		t.KV("caas projects run under", output.Dash(st.Mapping.CaaS))
		t.KV("image builds run under", output.Dash(st.Mapping.Build))
	})
}

// kataShimReasons are the texts of SandboxInstallService::shimReasonText.
var kataShimReasons = map[string]string{
	"arch_mismatch": "the node architecture is not supported",
	"no_kvm":        "no hardware virtualization (/dev/kvm) on the nodes",
	"no_devmapper":  "needs containerd's devmapper snapshotter (containerd, k3s or RKE2 nodes)",
	"no_gpu":        "no NVIDIA GPU was detected",
	"no_iommu":      "IOMMU is not available (bare metal required)",
	"no_snp":        "AMD SEV-SNP was not detected",
	"no_tdx":        "Intel TDX was not detected",
}

func newRegionSandboxShimsCmd(app *App) *cobra.Command {
	var recheck bool
	c := &cobra.Command{
		Use:     "shims [REGION]",
		Aliases: []string{"kvm"},
		Short:   "Which Kata hypervisors the region's nodes can run, and their /dev/kvm",
		Long: "Show the Kata hypervisors and whether the region's nodes can run them, and the hardware\n" +
			"virtualization (/dev/kvm) of every node Kata would run on. Nodes RunR has not checked yet\n" +
			"are checked in the background; --recheck checks every node again.",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			sh, err := c.RegionKataShims(cmd.Context(), reg.ID, recheck)
			if err != nil {
				return err
			}
			return app.Printer().Print(sh, func(t *output.TableWriter) {
				t.KV("Region", reg.Name)
				nodes := make([]string, 0, len(sh.KVM.Nodes))
				for n, v := range sh.KVM.Nodes {
					state := "not checked"
					if v != nil {
						state = yesNoWord(*v)
					}
					nodes = append(nodes, n+" "+state)
				}
				sort.Strings(nodes)
				t.KV("/dev/kvm", output.Dash(sh.KVM.State)+" ("+joinOrDash(nodes)+")")
				t.Header("HYPERVISOR", "RUNTIME CLASS", "AVAILABLE", "RECOMMENDED", "REASON")
				for _, hv := range runr.KataHypervisors {
					s, ok := sh.Shims[hv]
					if !ok {
						continue
					}
					reason := s.Reason
					if text, ok := kataShimReasons[reason]; ok {
						reason = text
					}
					t.Row(hv, s.Runtime, yesNoWord(s.Available), yesNo(s.Recommended), output.Dash(reason))
				}
				if sh.KVM.Probing {
					t.Note("RunR is checking /dev/kvm on the nodes in the background; run this again in a minute.")
				}
			})
		},
	}
	c.Flags().BoolVar(&recheck, "recheck", false, "check /dev/kvm on every node again (in the background)")
	return c
}

// sandboxRuntimeArg checks a RUNTIME argument (kata or gvisor).
func sandboxRuntimeArg(s string) (string, error) {
	switch r := strings.ToLower(s); r {
	case runr.SandboxRuntimeKata, runr.SandboxRuntimeGVisor:
		return r, nil
	}
	return "", usageErrorf("the sandbox runtime is kata or gvisor, not %q", s)
}

func sandboxRuntimeName(runtime string) string {
	if runtime == runr.SandboxRuntimeKata {
		return "Kata Containers"
	}
	return "gVisor"
}

// regionSandboxWait follows an install or removal and prints the region's sandbox status.
func regionSandboxWait(ctx context.Context, app *App, c *runr.Client, reg *runr.Region, runtime string, timeout time.Duration) error {
	st, err := c.WaitSandboxRuntime(ctx, reg.ID, reg.Name, runtime, app.waitOptions(timeout, sandboxRuntimeName(runtime)+" on "+reg.Name))
	if st == nil {
		return err
	}
	if err == nil {
		app.Progress("%s on %s: %s.", sandboxRuntimeName(runtime), reg.Name, st.Runtime(runtime).Status)
	}
	if perr := printRegionSandbox(app, reg, st); perr != nil && err == nil {
		err = perr
	}
	return err
}

func newRegionSandboxInstallCmd(app *App) *cobra.Command {
	var hypervisors []string
	var poolGB int
	var wait, yes bool
	var timeout time.Duration
	c := &cobra.Command{
		Use:   "install REGION RUNTIME",
		Short: "Install Kata Containers or gVisor on a region's nodes (sandbox.manage)",
		Long: "Install a sandbox runtime on the region's nodes: kata (with --hypervisor clh, qemu,\n" +
			"dragonball and fc; default clh) or gvisor. Installing Kata again with other hypervisors adds\n" +
			"them. Kata needs hardware virtualization on the nodes (runr regions sandbox shims shows it)\n" +
			"and restarts the container runtime on every target node, so it asks for confirmation\n" +
			"(--yes skips it); fc also sets up containerd's devmapper thin-pool (--devmapper-pool-gb,\n" +
			"sparse, default 20). The install runs in the background: --wait waits until it ends (exit\n" +
			"code 6 when it fails).",
		Example: "  runr regions sandbox install eu-1 gvisor --wait\n" +
			"  runr regions sandbox install eu-1 kata --hypervisor clh --hypervisor fc --devmapper-pool-gb 50 --wait --yes",
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			runtime, err := sandboxRuntimeArg(args[1])
			if err != nil {
				return err
			}
			in := runr.InstallSandboxRuntimeInput{Runtime: runtime}
			if runtime == runr.SandboxRuntimeGVisor && (len(hypervisors) > 0 || poolGB != 0) {
				return usageErrorf("--hypervisor and --devmapper-pool-gb are Kata settings")
			}
			for _, h := range hypervisors {
				h = strings.TrimPrefix(strings.ToLower(h), "kata-")
				known := false
				for _, k := range runr.KataHypervisors {
					known = known || k == h
				}
				if !known {
					return usageErrorf("unknown Kata hypervisor %q (%s)", h, strings.Join(runr.KataHypervisors, ", "))
				}
				in.Hypervisors = append(in.Hypervisors, h)
			}
			if poolGB != 0 {
				if poolGB < 10 || poolGB > 1024 {
					return usageErrorf("--devmapper-pool-gb must be between 10 and 1024")
				}
				in.DevmapperPoolGB = poolGB
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			if runtime == runr.SandboxRuntimeKata {
				hv := in.Hypervisors
				if len(hv) == 0 {
					hv = []string{"clh"}
				}
				question := fmt.Sprintf("Install Kata Containers (%s) on %s: this restarts the container runtime on every target node",
					strings.Join(hv, ", "), reg.Name)
				if err := app.confirm(question, yes); err != nil {
					return err
				}
			}
			res, err := c.InstallSandboxRuntime(ctx, reg.ID, in)
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			if res.Warning != "" {
				app.Warn("%s", res.Warning)
			}
			if wait {
				return regionSandboxWait(ctx, app, c, reg, runtime, timeout)
			}
			return app.Printer().Print(res, func(t *output.TableWriter) {
				t.Note("runr regions sandbox %s shows the progress.", reg.Name)
			})
		},
	}
	c.Flags().StringArrayVar(&hypervisors, "hypervisor", nil, "Kata hypervisor: clh, qemu, dragonball or fc (repeatable; default clh)")
	c.Flags().IntVar(&poolGB, "devmapper-pool-gb", 0, "fc: data size of the devmapper thin-pool in GiB (10-1024, sparse; default 20)")
	c.Flags().BoolVar(&wait, "wait", false, "wait until the install ended (exit code 6 when it fails)")
	c.Flags().DurationVar(&timeout, "timeout", 30*time.Minute, "how long --wait waits")
	c.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask before Kata restarts the container runtime")
	return c
}

func newRegionSandboxUninstallCmd(app *App) *cobra.Command {
	var wait, yes bool
	var timeout time.Duration
	c := &cobra.Command{
		Use:     "uninstall REGION RUNTIME",
		Aliases: []string{"rm", "remove"},
		Short:   "Remove Kata Containers or gVisor from a region's nodes (sandbox.manage)",
		Long: "Remove a sandbox runtime from the region's nodes. Map the project types that run under it\n" +
			"to another runtime first (runr regions sandbox map). The removal runs in the background:\n" +
			"--wait waits until it ends.",
		Example: "  runr regions sandbox uninstall eu-1 gvisor --yes --wait",
		Args:    cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			runtime, err := sandboxRuntimeArg(args[1])
			if err != nil {
				return err
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm(fmt.Sprintf("Remove %s from the nodes of %s", sandboxRuntimeName(runtime), reg.Name), yes); err != nil {
				return err
			}
			res, err := c.UninstallSandboxRuntime(ctx, reg.ID, runtime)
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			if wait {
				return regionSandboxWait(ctx, app, c, reg, runtime, timeout)
			}
			return app.Printer().Print(res, func(t *output.TableWriter) {
				t.Note("runr regions sandbox %s shows the progress.", reg.Name)
			})
		},
	}
	c.Flags().BoolVar(&wait, "wait", false, "wait until the removal ended")
	c.Flags().DurationVar(&timeout, "timeout", 30*time.Minute, "how long --wait waits")
	c.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return c
}

func newRegionSandboxMapCmd(app *App) *cobra.Command {
	var xaas, faas, caas, build string
	c := &cobra.Command{
		Use:     "map REGION",
		Aliases: []string{"mapping"},
		Short:   "Choose the sandbox runtime of each project type of a region (sandbox.manage)",
		Long: "Set the runtime class the region's projects of a type run under, and image builds: runc (no\n" +
			"sandbox), gvisor or kata-<hypervisor> (kata-clh, kata-fc, …), each installed and ready on\n" +
			"the region. Types left out keep their class. Running projects get the class when they are\n" +
			"deployed again.",
		Example: "  runr regions sandbox map eu-1 --faas gvisor --xaas gvisor\n" +
			"  runr regions sandbox map eu-1 --caas kata-clh --build kata-clh",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			fl := cmd.Flags()
			if !fl.Changed("xaas") && !fl.Changed("faas") && !fl.Changed("caas") && !fl.Changed("build") {
				return usageErrorf("give at least one of --faas, --xaas, --caas and --build")
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			st, err := c.RegionSandboxStatus(ctx, reg.ID)
			if err != nil {
				return err
			}
			m := st.Mapping
			for _, f := range []struct {
				flag string
				dst  *string
				v    string
			}{{"xaas", &m.XaaS, xaas}, {"faas", &m.FaaS, faas}, {"caas", &m.CaaS, caas}, {"build", &m.Build, build}} {
				if fl.Changed(f.flag) {
					if strings.TrimSpace(f.v) == "" {
						return usageErrorf("--%s needs a runtime class (runc, gvisor or kata-<hypervisor>)", f.flag)
					}
					*f.dst = strings.ToLower(strings.TrimSpace(f.v))
				}
			}
			if err := c.SetSandboxMapping(ctx, reg.ID, m); err != nil {
				return err
			}
			app.Progress("Updated the sandbox mapping of %s.", reg.Name)
			if st, err = c.RegionSandboxStatus(ctx, reg.ID); err != nil {
				return err
			}
			return printRegionSandbox(app, reg, st)
		},
	}
	c.Flags().StringVar(&faas, "faas", "", "runtime class of faas projects (functions)")
	c.Flags().StringVar(&xaas, "xaas", "", "runtime class of xaas projects (single-file scripts)")
	c.Flags().StringVar(&caas, "caas", "", "runtime class of caas projects (containers)")
	c.Flags().StringVar(&build, "build", "", "runtime class of image builds")
	return c
}
