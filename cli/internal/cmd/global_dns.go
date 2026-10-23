package cmd

import (
	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// `runr global-dns`: the platform's global zone (GlobalDnsController, docs/global-projects.md):
// the records the operator creates in the parent zone and the servers of the regions.

func init() { registerCommand(newGlobalDNSCmd) }

func newGlobalDNSCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "global-dns",
		Short: "The global zone of global projects: its delegation records and DNS servers",
		Long: "Global projects answer on <slug>.<zone>, the zone being g.<base domain>. RunR runs a\n" +
			"PowerDNS server in every region with global DNS (runr regions global-dns enable) and keeps\n" +
			"the zone identical on all of them. The operator delegates the zone once: the NS records of\n" +
			"the zone and the address (glue) records of its name servers go into the parent zone, as\n" +
			"listed here. Reading needs regions.view; sync needs regions.manage.",
		Example: "  runr global-dns\n" +
			"  runr global-dns -o json\n" +
			"  runr global-dns sync",
		Args: cobra.NoArgs,
	}
	status := &cobra.Command{
		Use:     "status",
		Aliases: []string{"get", "show"},
		Short:   "Show the zone, the delegation records and the servers",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			g, err := c.GlobalDNS(cmd.Context())
			if err != nil {
				return globalDNSUnsupported(err)
			}
			return printGlobalDNS(app, g)
		},
	}
	cmd.AddCommand(status)
	cmd.RunE = status.RunE
	cmd.AddCommand(&cobra.Command{
		Use:   "sync",
		Short: "Write the zone to every server now (regions.manage)",
		Long: "RunR writes the zone to every server every minute and after each change of a global\n" +
			"project; sync does it now, in the background.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			if err := c.SyncGlobalDNS(cmd.Context()); err != nil {
				return globalDNSUnsupported(err)
			}
			app.Progress("Zone sync queued.")
			return app.Printer().Print(map[string]any{"queued": true}, func(t *output.TableWriter) {
				t.Note("runr global-dns shows when the servers were synced.")
			})
		},
	})
	return cmd
}

var delegationTexts = map[string]string{
	"ok":      "the parent zone delegates the zone to the servers",
	"partial": "the parent zone names only some of the servers",
	"missing": "the parent zone does not delegate the zone yet",
	"unknown": "not checked",
}

func printGlobalDNS(app *App, g *runr.GlobalDNS) error {
	return app.Printer().Print(g, func(t *output.TableWriter) {
		if g.Zone == "" {
			t.KV("Zone", "- (set the platform's base domain first)")
		} else {
			t.KV("Zone", g.Zone)
		}
		t.KV("TLS", output.Dash(g.TLS))
		update := ""
		for _, s := range g.Servers {
			if s.RegionID == g.UpdateRegionID {
				update = s.RegionName
			}
		}
		if update != "" {
			t.KV("DNS-01 updates", update)
		}
		delegation := output.Dash(g.Delegation.Status)
		if text := delegationTexts[g.Delegation.Status]; text != "" {
			delegation += " (" + text + ")"
		}
		if g.Delegation.Message != "" {
			delegation += ": " + g.Delegation.Message
		}
		t.KV("Delegation", delegation)
		if g.LastSyncAt != nil {
			t.KV("Last sync", output.Age(g.LastSyncAt)+" ago")
		}
		// the records of the parent zone: NS records delegate the zone, the address records are their glue
		for _, r := range g.Delegation.Records {
			t.KV("Parent zone record", r.Name+" "+r.Type+" "+r.Value)
		}
		if len(g.Delegation.Records) > 0 {
			t.Note("Create the parent zone records once: the NS records delegate %s, the address records are their glue.", output.Dash(g.Zone))
		}
		t.Header("REGION", "STATUS", "ADDRESS", "NAME SERVER", "REACHABLE", "ZONE", "CERTIFICATE", "GEOIP")
		for _, s := range g.Servers {
			reachable := "-"
			if s.Reachable != nil {
				reachable = yesNoWord(*s.Reachable)
			}
			t.Row(s.RegionName, s.Status, output.Dash(s.Address), output.Dash(s.NSName), reachable,
				output.Dash(s.SyncStatus), output.Dash(s.TLSStatus), geoipShort(s.GeoIP))
		}
		if len(g.Servers) == 0 {
			t.Note("No region has global DNS yet: runr regions global-dns enable REGION installs a server.")
		}
	})
}
