package cmd

import (
	"context"
	"fmt"
	"strconv"
	"strings"

	"github.com/spf13/cobra"

	"github.com/runrsh/core/cli/internal/output"
	"github.com/runrsh/core/cli/pkg/runr"
)

// `runr residency policy|set`: an organization's security policy on an installation with
// several organizations — its data residency and its own policy gate.

// residencyTenant returns --tenant, else the caller's organization.
func residencyTenant(ctx context.Context, c *runr.Client, tenant string) (string, error) {
	if tenant != "" {
		return tenant, nil
	}
	res, err := c.GetResidency(ctx)
	if err != nil {
		return "", err
	}
	if res.TenantID == "" {
		return "", usageErrorf("give --tenant ID: you are not in an organization (a platform administrator), or this installation has a single organization")
	}
	return res.TenantID, nil
}

// residencyPolicyErr explains a 404 of /tenants/{tenant}/security-policy.
func residencyPolicyErr(err error) error {
	if runr.IsNotFound(err) {
		return fmt.Errorf("%w (no such organization, or this installation is single-tenant)", err)
	}
	return err
}

func residencyRegionName(p *runr.TenantSecurityPolicy, id string) string {
	for _, r := range p.Regions {
		if r.ID == id {
			return r.Name
		}
	}
	return id
}

func printTenantPolicy(app *App, p *runr.TenantSecurityPolicy) error {
	return app.Printer().Print(p, func(t *output.TableWriter) {
		t.KV("Organization", p.TenantID)
		t.KV("Residency enforced", yesNoWord(p.ResidencyEnforced))
		regions := make([]string, 0, len(p.AllowedRegionIDs))
		for _, id := range p.AllowedRegionIDs {
			regions = append(regions, residencyRegionName(p, id))
		}
		if len(regions) == 0 {
			t.KV("Allowed regions", "every region")
		} else {
			t.KV("Allowed regions", strings.Join(regions, ", "))
		}
		if len(p.AllowedLocations) == 0 {
			t.KV("Allowed data locations", "every location")
		} else {
			t.KV("Allowed data locations", strings.Join(p.AllowedLocations, ", "))
		}
		def := "-"
		if p.DefaultRegionID != "" {
			def = residencyRegionName(p, p.DefaultRegionID)
		}
		t.KV("Default region", def)
		t.KV("Restricted", yesNoWord(p.Restricted))
		gate := output.Dash(p.GateMode)
		if p.GateMode == "" {
			gate = "the regions' gate"
		}
		t.KV("Policy gate", gate)
		intLabel := func(v *int) string {
			if v == nil {
				return "-"
			}
			return strconv.Itoa(*v)
		}
		t.KV("Gate: max critical", intLabel(p.GateMaxCritical))
		t.KV("Gate: max high", intLabel(p.GateMaxHigh))
		scan := "-"
		if p.GateRequireScan != nil {
			scan = yesNoWord(*p.GateRequireScan)
		}
		t.KV("Gate: require a scan", scan)
		if p.UpdatedAt != "" {
			t.KV("Updated", strings.TrimSpace(p.UpdatedAt+" by "+p.UpdatedBy))
		}
		if len(p.Locations) > 0 {
			t.Note("Data locations of the regions: %s", strings.Join(p.Locations, ", "))
		}
	})
}

func newResidencyPolicyCmd(app *App) *cobra.Command {
	var tenant string
	c := &cobra.Command{
		Use:   "policy",
		Short: "An organization's security policy: residency and its own policy gate",
		Long: "Show an organization's security policy (default: yours): whether residency is enforced,\n" +
			"the allowed regions and data locations, the default region, and its own policy gate,\n" +
			"which is stricter than the regions' where it is set. Needs tenants.residency.manage or\n" +
			"regions.supply_chain.manage; another organization's (--tenant) needs a platform\n" +
			"administrator.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			id, err := residencyTenant(cmd.Context(), c, tenant)
			if err != nil {
				return err
			}
			p, err := c.TenantSecurityPolicy(cmd.Context(), id)
			if err != nil {
				return residencyPolicyErr(err)
			}
			return printTenantPolicy(app, p)
		},
	}
	c.Flags().StringVar(&tenant, "tenant", "", "organization (tenant id; default: yours)")
	return c
}

func newResidencySetCmd(app *App) *cobra.Command {
	var (
		tenant, defaultRegion, gateMode string
		regions, locations, unset       []string
		enforce, allRegions, allLocs    bool
		noDefault, requireScan          bool
		maxCritical, maxHigh            int
	)
	c := &cobra.Command{
		Use:   "set",
		Short: "Change an organization's residency or policy gate",
		Long: "Change an organization's security policy (default: yours); settings left out are kept.\n" +
			"Residency (tenants.residency.manage): --enforce[=false], --allow-region (repeatable, name\n" +
			"or id) or --all-regions, --allow-location (repeatable, e.g. TR or EU-DE) or --all-locations,\n" +
			"--default-region or --no-default-region. Empty lists restrict nothing; the default region\n" +
			"must be allowed. Policy gate (regions.supply_chain.manage), stricter than the regions'\n" +
			"where set: --gate-mode off|warn|enforce, --gate-max-critical, --gate-max-high,\n" +
			"--gate-require-scan[=false]; --unset gate-mode|gate-max-critical|gate-max-high|\n" +
			"gate-require-scan leaves one to the regions.",
		Example: "  runr residency set --allow-location TR --default-region ist-1\n" +
			"  runr residency set --tenant 01J… --allow-region eu-1 --allow-region eu-2 --enforce\n" +
			"  runr residency set --gate-mode enforce --gate-max-critical 0 --gate-require-scan\n" +
			"  runr residency set --unset gate-max-high --all-locations",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			fl := cmd.Flags()
			change := runr.TenantSecurityPolicyChange{}
			if len(regions) > 0 && allRegions {
				return usageErrorf("give --allow-region or --all-regions, not both")
			}
			if len(locations) > 0 && allLocs {
				return usageErrorf("give --allow-location or --all-locations, not both")
			}
			if fl.Changed("default-region") && noDefault {
				return usageErrorf("give --default-region or --no-default-region, not both")
			}
			if fl.Changed("enforce") {
				change["residency_enforced"] = enforce
			}
			if allRegions {
				change["allowed_region_ids"] = []string{}
			}
			if len(locations) > 0 {
				change["allowed_locations"] = locations
			}
			if allLocs {
				change["allowed_locations"] = []string{}
			}
			if noDefault {
				change["default_region_id"] = nil
			}
			if fl.Changed("gate-mode") {
				switch gateMode {
				case "off", "warn", "enforce":
					change["gate_mode"] = gateMode
				default:
					return usageErrorf("--gate-mode must be off, warn or enforce")
				}
			}
			for _, f := range []struct {
				flag, key string
				v         int
			}{{"gate-max-critical", "gate_max_critical", maxCritical}, {"gate-max-high", "gate_max_high", maxHigh}} {
				if fl.Changed(f.flag) {
					if f.v < 0 {
						return usageErrorf("--%s must be 0 or more", f.flag)
					}
					change[f.key] = f.v
				}
			}
			if fl.Changed("gate-require-scan") {
				change["gate_require_scan"] = requireScan
			}
			for _, u := range unset {
				key := strings.ReplaceAll(strings.ToLower(strings.TrimPrefix(u, "--")), "-", "_")
				switch key {
				case "gate_mode", "gate_max_critical", "gate_max_high", "gate_require_scan":
				default:
					return usageErrorf("--unset takes gate-mode, gate-max-critical, gate-max-high or gate-require-scan, not %q", u)
				}
				if _, set := change[key]; set {
					return usageErrorf("--unset %s and a new value for it cannot be combined", u)
				}
				change[key] = nil
			}
			if len(change) == 0 && len(regions) == 0 && !fl.Changed("default-region") {
				return usageErrorf("give at least one setting to change")
			}
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			id, err := residencyTenant(ctx, c, tenant)
			if err != nil {
				return err
			}
			if len(regions) > 0 || fl.Changed("default-region") {
				// Region names are resolved against the regions the policy lists.
				cur, err := c.TenantSecurityPolicy(ctx, id)
				if err != nil {
					return residencyPolicyErr(err)
				}
				resolve := func(ref string) (string, error) {
					for _, r := range cur.Regions {
						if r.ID == ref || strings.EqualFold(r.Name, ref) {
							return r.ID, nil
						}
					}
					return "", &runr.NotFoundError{Kind: "region", Ref: ref}
				}
				if len(regions) > 0 {
					ids := []string{}
					for _, r := range regions {
						rid, err := resolve(r)
						if err != nil {
							return err
						}
						ids = append(ids, rid)
					}
					change["allowed_region_ids"] = ids
				}
				if fl.Changed("default-region") {
					rid, err := resolve(defaultRegion)
					if err != nil {
						return err
					}
					change["default_region_id"] = rid
				}
			}
			p, err := c.UpdateTenantSecurityPolicy(ctx, id, change)
			if err != nil {
				return residencyPolicyErr(err)
			}
			app.Progress("Updated the security policy of %s.", p.TenantID)
			return printTenantPolicy(app, p)
		},
	}
	f := c.Flags()
	f.StringVar(&tenant, "tenant", "", "organization (tenant id; default: yours)")
	f.BoolVar(&enforce, "enforce", false, "enforce data residency (--enforce=false keeps the lists without applying them)")
	f.StringArrayVar(&regions, "allow-region", nil, "an allowed region, name or id (repeatable; replaces the list)")
	f.BoolVar(&allRegions, "all-regions", false, "no region list")
	f.StringArrayVar(&locations, "allow-location", nil, "an allowed data location such as TR or EU-DE (repeatable; replaces the list)")
	f.BoolVar(&allLocs, "all-locations", false, "no location list")
	f.StringVar(&defaultRegion, "default-region", "", "region preselected for new projects (name or id)")
	f.BoolVar(&noDefault, "no-default-region", false, "no default region")
	f.StringVar(&gateMode, "gate-mode", "", "the organization's policy gate: off, warn or enforce")
	f.IntVar(&maxCritical, "gate-max-critical", 0, "block a version with more CRITICAL findings")
	f.IntVar(&maxHigh, "gate-max-high", 0, "block a version with more HIGH findings")
	f.BoolVar(&requireScan, "gate-require-scan", false, "no deploy without a finished scan")
	f.StringArrayVar(&unset, "unset", nil, "leave a gate setting to the regions (repeatable)")
	return c
}
