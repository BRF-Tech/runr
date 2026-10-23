package cmd

import (
	"context"
	"errors"
	"slices"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// `runr regions global-dns`: the region's PowerDNS server of the global zone
// (GlobalDnsController, docs/global-projects.md). Only regions with one can run global projects.

func newRegionGlobalDNSCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "global-dns [REGION]",
		Short: "The region's DNS server of the global zone, which global projects need",
		Long: "Global projects need a PowerDNS server in each of their regions: RunR installs it with a\n" +
			"LoadBalancer Service on UDP and TCP port 53 (the region must be reachable on a public :53)\n" +
			"and writes the global zone to every server. The operator delegates the zone to the\n" +
			"servers once (runr global-dns lists the records). Platform regions only; enabling runs in\n" +
			"the background (--wait waits, exit code 6 when it fails). Reading needs regions.view,\n" +
			"changes regions.manage.",
		Example: "  runr regions global-dns eu-1\n" +
			"  runr regions global-dns enable eu-1 --wait\n" +
			"  runr regions global-dns geoip eu-1 --source url --url https://mirror.internal/dbip-city-lite.mmdb.gz\n" +
			"  runr regions global-dns disable eu-1 --yes",
		Args: cobra.MaximumNArgs(1),
	}
	status := &cobra.Command{
		Use:     "status [REGION]",
		Aliases: []string{"get"},
		Short:   "Show the region's global DNS server",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			d, err := c.RegionGlobalDNS(cmd.Context(), reg.ID)
			if err != nil {
				return globalDNSUnsupported(err)
			}
			return printRegionGlobalDNS(app, reg, d)
		},
	}
	cmd.AddCommand(status)
	cmd.RunE = status.RunE
	cmd.AddCommand(newRegionGlobalDNSEnableCmd(app), newRegionGlobalDNSDisableCmd(app), newRegionGlobalDNSGeoIPCmd(app))
	return cmd
}

// geoipShort is the GeoIP state of a server in one word: its status and source.
func geoipShort(g *runr.GlobalDNSGeoIP) string {
	if g == nil {
		return "-"
	}
	return output.Dash(g.Status) + " (" + output.Dash(g.Source) + ")"
}

func newRegionGlobalDNSGeoIPCmd(app *App) *cobra.Command {
	var source, url string
	var reload bool
	cmd := &cobra.Command{
		Use:   "geoip REGION",
		Short: "The GeoIP database of the region's DNS server: show it, set its source, reload it",
		Long: "Proximity mode answers the healthy region closest to the client with a GeoIP database in\n" +
			"each DNS server. --source default downloads the platform's (globalDns.geoipUrl, DB-IP by\n" +
			"default); url downloads --url instead — an internal mirror or a file in the region's object\n" +
			"store, for a cluster without internet access; file uses the operator's own file on the\n" +
			"server's volume (kubectl -n runr-global-dns cp geoip.mmdb <pod>:/var/lib/powerdns/geoip.mmdb\n" +
			"-c pdns, then --reload); none answers without a preferred region. The server restarts\n" +
			"with a new source; --reload makes it read the source again. Without flags it shows the\n" +
			"state. Reading needs regions.view, changes regions.manage.",
		Example: "  runr regions global-dns geoip eu-1\n" +
			"  runr regions global-dns geoip eu-1 --source url --url http://runr-objects.runr-data.svc.cluster.local:3900/geoip/dbip.mmdb.gz\n" +
			"  runr regions global-dns geoip eu-1 --source file\n" +
			"  runr regions global-dns geoip eu-1 --reload",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			source = strings.ToLower(strings.TrimSpace(source))
			if source != "" && !slices.Contains(runr.GeoIPSources, source) {
				return usageErrorf("unknown GeoIP source %q (%s)", source, strings.Join(runr.GeoIPSources, ", "))
			}
			if url != "" && source == "" {
				source = runr.GeoIPSourceURL
			}
			if source == runr.GeoIPSourceURL && url == "" {
				return usageErrorf("--source url needs --url")
			}
			if url != "" && source != runr.GeoIPSourceURL {
				return usageErrorf("--url goes with --source url")
			}
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			var d *runr.RegionGlobalDNS
			switch {
			case source != "":
				if d, err = c.SetRegionGeoIP(ctx, reg.ID, source, url); err != nil {
					return globalDNSUnsupported(err)
				}
				app.Progress("GeoIP source of %s: %s; the DNS server restarts with it.", reg.Name, source)
				if reload {
					if d, err = c.ReloadRegionGeoIP(ctx, reg.ID); err != nil {
						return globalDNSUnsupported(err)
					}
				}
			case reload:
				if d, err = c.ReloadRegionGeoIP(ctx, reg.ID); err != nil {
					return globalDNSUnsupported(err)
				}
				app.Progress("The DNS server of %s reads its GeoIP source again.", reg.Name)
			default:
				if d, err = c.RegionGlobalDNS(ctx, reg.ID); err != nil {
					return globalDNSUnsupported(err)
				}
			}
			return printRegionGeoIP(app, reg, d)
		},
	}
	cmd.Flags().StringVar(&source, "source", "", "default, url, file or none")
	cmd.Flags().StringVar(&url, "url", "", "the URL the server downloads (source url; {yyyy} and {mm}: the current month)")
	cmd.Flags().BoolVar(&reload, "reload", false, "make the server read its source again (after copying a new file)")
	return cmd
}

func printRegionGeoIP(app *App, reg *runr.Region, d *runr.RegionGlobalDNS) error {
	return app.Printer().Print(d.GeoIP, func(t *output.TableWriter) {
		t.KV("Region", reg.Name)
		g := d.GeoIP
		if g == nil {
			t.KV("GeoIP", "- (the region runs no global DNS server, or this RunR API has no GeoIP source)")
			return
		}
		t.KV("Source", output.Dash(g.Source))
		if g.URL != "" {
			t.KV("URL", g.URL)
		}
		if g.EffectiveURL != "" && g.EffectiveURL != g.URL {
			t.KV("Downloads", g.EffectiveURL)
		}
		t.KV("Status", output.Dash(g.Status))
		if g.Message != "" {
			t.KV("Message", output.Truncate(g.Message, 200))
		}
		if g.CheckedAt != nil {
			t.KV("Checked", output.Age(g.CheckedAt)+" ago")
		}
		if g.Source == runr.GeoIPSourceFile && g.Status != "loaded" {
			t.Note("Copy the file: kubectl -n runr-global-dns cp geoip.mmdb <pod>:/var/lib/powerdns/geoip.mmdb -c pdns, then runr regions global-dns geoip %s --reload.", reg.Name)
		}
	})
}

// globalDNSUnsupported says that the API has no global DNS instead of a bare ErrUnsupported.
func globalDNSUnsupported(err error) error {
	if errors.Is(err, runr.ErrUnsupported) {
		return errors.New("this RunR API has no global projects (global DNS)")
	}
	return err
}

func reachableLabel(b *bool) string {
	if b == nil {
		return "-"
	}
	if *b {
		return "yes (answers the zone on :53)"
	}
	return "no (no authoritative answer on :53)"
}

func printGlobalDNSServer(t *output.TableWriter, s *runr.GlobalDNSServer) {
	st := output.Dash(s.Status)
	if s.StatusMessage != "" && s.Status != runr.GlobalDNSReady && s.Status != runr.GlobalDNSDisabled {
		st += " (" + output.Truncate(lastLogLine(s.StatusMessage), 100) + ")"
	}
	t.KV("Status", st)
	t.KV("Address", output.Dash(s.Address))
	t.KV("Name server", output.Dash(s.NSName))
	t.KV("Reachable", reachableLabel(s.Reachable))
	if s.SyncStatus != "" {
		sync := s.SyncStatus
		if s.SyncedAt != nil {
			sync += ", " + output.Age(s.SyncedAt) + " ago"
		}
		if s.SyncMessage != "" {
			sync += ": " + output.Truncate(s.SyncMessage, 100)
		}
		t.KV("Zone", sync)
	}
	if s.TLSStatus != "" {
		tls := s.TLSStatus
		if s.TLSExpiresAt != nil {
			tls += ", expires " + s.TLSExpiresAt.Local().Format(time.DateOnly)
		}
		if s.TLSMessage != "" {
			tls += ": " + output.Truncate(s.TLSMessage, 100)
		}
		t.KV("Certificate", tls)
	}
	if s.GeoIP != nil {
		geoip := geoipShort(s.GeoIP)
		if s.GeoIP.Message != "" {
			geoip += ": " + output.Truncate(s.GeoIP.Message, 100)
		}
		t.KV("GeoIP", geoip)
	}
}

func printRegionGlobalDNS(app *App, reg *runr.Region, d *runr.RegionGlobalDNS) error {
	return app.Printer().Print(d, func(t *output.TableWriter) {
		t.KV("Region", reg.Name)
		printGlobalDNSServer(t, &d.GlobalDNSServer)
		if !d.Eligible {
			t.KV("Eligible", "no: "+output.Dash(d.Reason))
		}
		if d.Status == runr.GlobalDNSDisabled && d.Eligible {
			t.Note("runr regions global-dns enable %s installs the server.", reg.Name)
		}
	})
}

func regionGlobalDNSWait(ctx context.Context, app *App, c *runr.Client, reg *runr.Region, timeout time.Duration) error {
	d, err := c.WaitRegionGlobalDNS(ctx, reg.ID, reg.Name, app.waitOptions(timeout, "global DNS of "+reg.Name))
	if d == nil {
		return globalDNSUnsupported(err)
	}
	if err == nil {
		app.Progress("Global DNS of %s: %s.", reg.Name, d.Status)
	}
	if perr := printRegionGlobalDNS(app, reg, d); perr != nil && err == nil {
		err = perr
	}
	return err
}

func newRegionGlobalDNSEnableCmd(app *App) *cobra.Command {
	var wait bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:     "enable REGION",
		Aliases: []string{"install"},
		Short:   "Install the region's DNS server of the global zone (regions.manage)",
		Long: "Installs PowerDNS in the region with a LoadBalancer Service on UDP and TCP port 53 and\n" +
			"writes the global zone to it. The region needs Knative Serving and an address on a public\n" +
			"port 53; the zone has to be delegated to the servers once (runr global-dns).",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			d, err := c.EnableRegionGlobalDNS(ctx, reg.ID)
			if err != nil {
				return globalDNSUnsupported(err)
			}
			app.Progress("Global DNS of %s: installation queued.", reg.Name)
			if wait {
				return regionGlobalDNSWait(ctx, app, c, reg, timeout)
			}
			return printRegionGlobalDNS(app, reg, d)
		},
	}
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the install ended (exit code 6 when it fails)")
	cmd.Flags().DurationVar(&timeout, "timeout", 15*time.Minute, "how long --wait waits")
	return cmd
}

func newRegionGlobalDNSDisableCmd(app *App) *cobra.Command {
	var wait, yes bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:     "disable REGION",
		Aliases: []string{"uninstall", "rm"},
		Short:   "Remove the region's DNS server of the global zone (regions.manage)",
		Long: "Removes the region's PowerDNS server; refused while global projects run in the region.\n" +
			"Remove its NS and glue records from the parent zone as well (runr global-dns).",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Remove the global DNS server of "+reg.Name, yes); err != nil {
				return err
			}
			d, err := c.DisableRegionGlobalDNS(ctx, reg.ID)
			if err != nil {
				return globalDNSUnsupported(err)
			}
			app.Progress("Global DNS of %s: removal queued.", reg.Name)
			if wait {
				return regionGlobalDNSWait(ctx, app, c, reg, timeout)
			}
			return printRegionGlobalDNS(app, reg, d)
		},
	}
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the removal ended")
	cmd.Flags().DurationVar(&timeout, "timeout", 15*time.Minute, "how long --wait waits")
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}
