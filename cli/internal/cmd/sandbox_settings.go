package cmd

import (
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/runrsh/core/cli/internal/output"
	"github.com/runrsh/core/cli/pkg/runr"
)

// `runr sandbox network` (the network of a running sandbox) and `runr sandbox regions` (the
// sandbox settings of the regions).

func sandboxNetworkLabel(n runr.SandboxNetwork) string {
	if len(n.EgressAllow) == 0 {
		return "none"
	}
	return strings.Join(n.EgressAllow, ", ")
}

func newSandboxNetworkCmd(app *App) *cobra.Command {
	var allow []string
	var none bool
	c := &cobra.Command{
		Use:   "network ID",
		Short: "Show or replace the network of a sandbox (egress allowlist or none)",
		Long: "Without flags, show the network of a sandbox. --allow HOST[:PORT] (repeatable) replaces its\n" +
			"egress allowlist: addresses, CIDRs or host names, each with an optional port; host names are\n" +
			"resolved again. --none takes the network away. The change applies to the running sandbox at\n" +
			"once.",
		Example: "  runr sandbox network $SBX\n" +
			"  runr sandbox network $SBX --allow pypi.org:443 --allow files.pythonhosted.org:443\n" +
			"  runr sandbox network $SBX --none",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if none && len(allow) > 0 {
				return usageErrorf("give --allow or --none, not both")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			var s *runr.Sandbox
			if none || len(allow) > 0 {
				s, err = c.SetSandboxNetwork(cmd.Context(), args[0], allow)
				if err != nil {
					return err
				}
				app.Progress("Sandbox %s: network %s.", s.ID, sandboxNetworkLabel(s.Network))
			} else if s, err = c.GetSandbox(cmd.Context(), args[0]); err != nil {
				return err
			}
			return app.Printer().Print(s.Network, func(t *output.TableWriter) {
				t.Header("MODE", "EGRESS ALLOWED")
				mode := s.Network.Mode
				if mode == "" {
					mode = "none"
				}
				t.Row(mode, sandboxNetworkLabel(s.Network))
			})
		},
	}
	c.Flags().StringSliceVar(&allow, "allow", nil, "allow egress to an address, CIDR or host name with optional port (repeatable; replaces the list)")
	c.Flags().BoolVar(&none, "none", false, "no network")
	return c
}

func sandboxClassesLabel(list []string, none string) string {
	if len(list) == 0 {
		return none
	}
	return strings.Join(list, ",")
}

func sandboxRegionRows(t *output.TableWriter, list []runr.SandboxRegionSettings) {
	t.Header("REGION", "ENABLED", "CLASSES", "DEFAULT", "RUNC", "TTL", "MAX TTL", "MAX CPU", "MAX MEMORY", "MAX DISK")
	for _, r := range list {
		t.Row(output.Dash(r.RegionName()), yesNo(r.Enabled), sandboxClassesLabel(r.EffectiveClasses, "-"),
			output.Dash(r.EffectiveDefaultClass), yesNo(r.AllowRunc), sbxDuration(r.DefaultTTLSeconds),
			sbxDuration(r.MaxTTLSeconds), strconv.Itoa(r.MaxCPUm)+"m", strconv.Itoa(r.MaxMemoryMB)+" MiB",
			strconv.Itoa(r.MaxDiskMB)+" MiB")
	}
}

func sandboxRegionDetail(t *output.TableWriter, r *runr.SandboxRegionSettings) {
	t.Header("SETTING", "VALUE")
	allowed := "every installed class"
	if r.AllowedClasses != nil {
		allowed = sandboxClassesLabel(r.AllowedClasses, "none")
	}
	t.Row("region", output.Dash(r.RegionName()))
	t.Row("enabled", yesNoWord(r.Enabled))
	t.Row("installed classes", sandboxClassesLabel(r.InstalledClasses, "none"))
	t.Row("allowed classes", allowed)
	t.Row("classes you get", sandboxClassesLabel(r.EffectiveClasses, "none"))
	t.Row("default class", output.Dash(r.DefaultClass))
	t.Row("default class you get", output.Dash(r.EffectiveDefaultClass))
	t.Row("runc allowed", yesNoWord(r.AllowRunc))
	t.Row("default TTL", sbxDuration(r.DefaultTTLSeconds))
	t.Row("maximum TTL", sbxDuration(r.MaxTTLSeconds))
	t.Row("maximum CPU", strconv.Itoa(r.MaxCPUm)+"m")
	t.Row("maximum memory", strconv.Itoa(r.MaxMemoryMB)+" MiB")
	t.Row("maximum disk", strconv.Itoa(r.MaxDiskMB)+" MiB")
	t.Row("you can change it", yesNoWord(r.CanManage))
}

// sandboxRegionForbidden explains a 403 of PUT /sandbox-regions/{region}. A token that lacks a
// permission is explained by Execute (the answer names it); otherwise the region is one the
// caller may not change: it needs sandboxes.manage on a region of the caller's own (platform
// regions are changed by platform administrators).
func sandboxRegionForbidden(region *runr.SandboxRegionSettings, err error) error {
	if statusOf(err) != http.StatusForbidden || len(runr.MissingPermissions(err)) > 0 {
		return err
	}
	return fmt.Errorf("you may not change the sandbox settings of %s: that needs sandboxes.manage on a region "+
		"your organization owns, platform regions are changed by platform administrators "+
		"(`runr sandbox regions get %s` shows whether you can change it): %w", region.RegionName(), region.RegionName(), err)
}

// sbxDuration prints seconds as a duration (15m0s → 15m).
func sbxDuration(secs int) string {
	if secs <= 0 {
		return "-"
	}
	s := (time.Duration(secs) * time.Second).String()
	if strings.HasSuffix(s, "m0s") {
		s = strings.TrimSuffix(s, "0s")
	}
	if strings.HasSuffix(s, "h0m") {
		s = strings.TrimSuffix(s, "0m")
	}
	return s
}

func newSandboxRegionsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "regions",
		Aliases: []string{"region"},
		Short:   "Sandbox settings of the regions: classes, default class, limits",
		Long: "Every region that offers sandboxes has settings: whether sandboxes are enabled, the sandbox\n" +
			"classes it allows (of those its nodes have installed), the default class, whether runc may\n" +
			"be used, the default and maximum TTL and the maximum CPU, memory and disk of one sandbox.\n" +
			"The limits shown are those that apply (the region's own or the platform's, whichever is\n" +
			"lower). Changing them needs sandboxes.manage and the region's owner.",
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the sandbox settings of every region",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListSandboxRegions(cmd.Context())
			if err != nil {
				return err
			}
			return app.Printer().Print(list, func(t *output.TableWriter) { sandboxRegionRows(t, list) })
		},
	}
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE

	cmd.AddCommand(&cobra.Command{
		Use:   "get REGION",
		Short: "Show the sandbox settings of a region (name or id)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			r, err := c.FindSandboxRegion(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(r, func(t *output.TableWriter) { sandboxRegionDetail(t, r) })
		},
	})

	var (
		enable, disable, allowRunc bool
		classes, unset             []string
		allClasses                 bool
		defaultClass               string
		defaultTTL, maxTTL         time.Duration
		maxCPU, maxMemory, maxDisk string
	)
	set := &cobra.Command{
		Use:   "set REGION",
		Short: "Change the sandbox settings of a region (name or id)",
		Long: "Change settings; flags left out are kept. --unset NAME puts a setting back to the platform's\n" +
			"(names: classes, default-class, default-ttl, max-ttl, max-cpu, max-memory, max-disk).\n" +
			"--allowed-class kata allows every Kata hypervisor; runc can be the default class only with\n" +
			"--allow-runc.",
		Example: "  runr sandbox regions set eu-1 --allowed-class kata --allowed-class gvisor --default-class gvisor\n" +
			"  runr sandbox regions set eu-1 --default-ttl 30m --max-ttl 4h --max-cpu 2 --max-memory 4Gi\n" +
			"  runr sandbox regions set eu-1 --disable\n" +
			"  runr sandbox regions set eu-1 --unset max-ttl --all-classes",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			fl := cmd.Flags()
			change := runr.SandboxRegionChange{}
			if enable && disable {
				return usageErrorf("give --enable or --disable, not both")
			}
			if enable || disable {
				change["enabled"] = enable
			}
			if fl.Changed("allow-runc") {
				change["allow_runc"] = allowRunc
			}
			if len(classes) > 0 && allClasses {
				return usageErrorf("give --allowed-class or --all-classes, not both")
			}
			if len(classes) > 0 {
				change["allowed_classes"] = classes
			}
			if allClasses {
				change["allowed_classes"] = nil
			}
			if fl.Changed("default-class") {
				if defaultClass == "" {
					return usageErrorf("--default-class needs a class (--unset default-class puts the default back)")
				}
				change["default_class"] = defaultClass
			}
			for _, d := range []struct {
				flag, key string
				v         time.Duration
				min       int
			}{{"default-ttl", "default_ttl_seconds", defaultTTL, 10}, {"max-ttl", "max_ttl_seconds", maxTTL, 60}} {
				if !fl.Changed(d.flag) {
					continue
				}
				secs := sbxSeconds(d.v)
				if secs < d.min {
					return usageErrorf("--%s must be at least %ds", d.flag, d.min)
				}
				change[d.key] = secs
			}
			if fl.Changed("max-cpu") {
				m, err := sbxParseMillicores(maxCPU)
				if err != nil {
					return usageErrorf("--max-cpu: %v", err)
				}
				change["max_cpu_m"] = m
			}
			for _, q := range []struct{ flag, key, v string }{
				{"max-memory", "max_memory_mb", maxMemory}, {"max-disk", "max_disk_mb", maxDisk},
			} {
				if !fl.Changed(q.flag) {
					continue
				}
				m, err := sbxParseMiB(q.v)
				if err != nil {
					return usageErrorf("--%s: %v", q.flag, err)
				}
				change[q.key] = m
			}
			fields := map[string]string{"classes": "allowed_classes", "default-class": "default_class",
				"default-ttl": "default_ttl_seconds", "max-ttl": "max_ttl_seconds", "max-cpu": "max_cpu_m",
				"max-memory": "max_memory_mb", "max-disk": "max_disk_mb"}
			for _, u := range unset {
				f, ok := fields[strings.ToLower(u)]
				if !ok {
					return usageErrorf("--unset takes classes, default-class, default-ttl, max-ttl, max-cpu, max-memory or max-disk, not %q", u)
				}
				if _, set := change[f]; set {
					return usageErrorf("--unset %s and a new value for it cannot be combined", u)
				}
				change[f] = nil
			}
			if len(change) == 0 {
				return usageErrorf("give at least one setting to change")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			region, err := c.FindSandboxRegion(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			r, err := c.UpdateSandboxRegion(cmd.Context(), region.Region.ID, change)
			if err != nil {
				return sandboxRegionForbidden(region, err)
			}
			app.Progress("Updated the sandbox settings of %s.", r.RegionName())
			return app.Printer().Print(r, func(t *output.TableWriter) { sandboxRegionDetail(t, r) })
		},
	}
	sf := set.Flags()
	sf.BoolVar(&enable, "enable", false, "allow sandboxes in the region")
	sf.BoolVar(&disable, "disable", false, "allow no new sandboxes in the region")
	sf.StringArrayVar(&classes, "allowed-class", nil, "a sandbox class the region allows (repeatable): kata, gvisor, kata-fc, kata-clh, …")
	sf.BoolVar(&allClasses, "all-classes", false, "allow every installed class")
	sf.StringVar(&defaultClass, "default-class", "", "the class of a sandbox that names none")
	sf.BoolVar(&allowRunc, "allow-runc", false, "allow runc (no sandbox isolation; --allow-runc=false forbids it again)")
	sf.DurationVar(&defaultTTL, "default-ttl", 0, "TTL of a sandbox that names none, e.g. 15m")
	sf.DurationVar(&maxTTL, "max-ttl", 0, "longest TTL a sandbox may have, e.g. 4h")
	sf.StringVar(&maxCPU, "max-cpu", "", "most CPU of one sandbox: cores (2) or millicores (2000m)")
	sf.StringVar(&maxMemory, "max-memory", "", "most memory of one sandbox: MiB or a quantity such as 4Gi")
	sf.StringVar(&maxDisk, "max-disk", "", "most disk of one sandbox: MiB or a quantity such as 10Gi")
	sf.StringArrayVar(&unset, "unset", nil, "put a setting back to the platform's (repeatable)")
	cmd.AddCommand(set)
	return cmd
}
