package cmd

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/runrsh/core/cli/internal/output"
	"github.com/runrsh/core/cli/pkg/runr"
)

// Region administration: the tracing backend (runr regions tracing), the data services
// bindings run on (runr regions data-services), the sandbox runtimes (runr regions sandbox,
// regions_sandbox.go), the supply chain (runr regions supply-chain, regions_supply_chain.go),
// GPU support and model storage (runr regions gpu, runr regions model-storage, regions_gpu.go).
// They hang under `runr regions`.

func addRegionAdminCommands(app *App, regions *cobra.Command) {
	regions.AddCommand(newRegionTracingCmd(app), newRegionDataServicesCmd(app), newRegionSandboxCmd(app), newRegionSupplyChainCmd(app),
		newRegionGPUCmd(app), newRegionModelStorageCmd(app))
}

// radmRegion resolves REGION (name or id; empty: the only active region).
func radmRegion(ctx context.Context, app *App, ref string) (*runr.Client, *runr.Region, error) {
	c, err := app.Client()
	if err != nil {
		return nil, nil, err
	}
	reg, err := c.FindRegion(ctx, ref)
	if err != nil {
		return nil, nil, err
	}
	return c, reg, nil
}

func radmArg(args []string) string {
	if len(args) > 0 {
		return args[0]
	}
	return ""
}

// ---------------------------------------------------------------------------------------
// Tracing backend

func newRegionTracingCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "tracing [REGION]",
		Short: "The tracing backend of a region: mode, Tempo, OTLP export, sampling, retention",
		Long: "A region's tracing backend receives the spans of its functions (docs/observability.md):\n" +
			"  managed   RunR installs Tempo and the collector\n" +
			"  reuse     the collector sends to a Tempo already running in the cluster\n" +
			"  external  the collector exports OTLP to an external endpoint\n" +
			"  off       no collector\n" +
			"Reading needs regions.view, changes regions.observability.manage.",
		Example: "  runr regions tracing eu-1\n" +
			"  runr regions tracing set eu-1 --mode managed --retention-hours 168 --install --wait\n" +
			"  runr regions tracing set eu-1 --mode external --export-endpoint https://otlp.example.com --export-headers headers.txt\n" +
			"  runr regions tracing rm eu-1 --yes",
		Args: cobra.MaximumNArgs(1),
	}
	get := &cobra.Command{
		Use:   "get [REGION]",
		Short: "Show the tracing backend of a region",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			t, err := c.RegionTracing(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(t, func(tw *output.TableWriter) { radmTracingDetail(tw, reg, t) })
		},
	}
	cmd.AddCommand(get)
	cmd.RunE = get.RunE
	cmd.AddCommand(newRegionTracingSetCmd(app), newRegionTracingInstallCmd(app), newRegionTracingRmCmd(app))
	return cmd
}

func radmTracingDetail(t *output.TableWriter, reg *runr.Region, tr *runr.RegionTracing) {
	t.KV("Region", reg.Name)
	t.KV("Mode", tr.Mode)
	status := output.Dash(tr.Status)
	if tr.StatusMessage != "" {
		status += " (" + tr.StatusMessage + ")"
	}
	t.KV("Status", status)
	switch tr.Mode {
	case runr.TracingReuse:
		t.KV("Tempo", output.Dash(tr.TempoURL))
		t.KV("Tempo OTLP endpoint", output.Dash(tr.TempoOTLPEndpoint))
		if tr.TempoOrgID != "" {
			t.KV("Tempo organization", tr.TempoOrgID)
		}
	case runr.TracingExternal:
		t.KV("Export endpoint", output.Dash(tr.ExportEndpoint))
		t.KV("Export headers", joinOrDash(tr.ExportHeaderNames)+" (values write-only)")
	}
	if tr.KnativeSampleRate != nil {
		t.KV("Knative sample rate", strconv.FormatFloat(*tr.KnativeSampleRate, 'f', -1, 64))
	}
	if tr.RetentionHours != nil {
		t.KV("Retention", strconv.Itoa(*tr.RetentionHours)+"h")
	}
	t.KV("Installed", output.Dash(tr.InstalledAt))
	t.KV("Queryable", yesNo(tr.Queryable))
	t.KV("Accepts spans", yesNo(tr.AcceptsSpans))
}

type radmTracingFlags struct {
	mode, tempoURL, tempoOTLP, tempoOrg, exportEndpoint, exportHeaders string
	clearHeaders                                                       bool
	sampleRate                                                         float64
	retentionHours                                                     int
	install, wait                                                      bool
	timeout                                                            time.Duration
}

func newRegionTracingSetCmd(app *App) *cobra.Command {
	var f radmTracingFlags
	cmd := &cobra.Command{
		Use:   "set [REGION]",
		Short: "Change the tracing backend of a region (regions.observability.manage)",
		Long: "Change the tracing configuration of a region. Flags left out keep their values; --install\n" +
			"applies the configuration to the cluster at once (--wait waits for the install).\n" +
			"Export headers (for an external endpoint) are write-only: --export-headers reads them from a\n" +
			"file or stdin (-), one \"Name: value\" per line or a JSON object, never from an argument;\n" +
			"--clear-export-headers removes them.",
		Example: "  runr regions tracing set eu-1 --mode reuse --tempo-url http://tempo.monitoring.svc:3200 \\\n" +
			"      --tempo-otlp-endpoint tempo.monitoring.svc:4317 --install --wait\n" +
			"  printf 'Authorization: Bearer %s\\n' \"$OTLP_TOKEN\" | runr regions tracing set eu-1 \\\n" +
			"      --mode external --export-endpoint https://otlp.example.com/v1/traces --export-headers -",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			fl := cmd.Flags()
			if f.clearHeaders && f.exportHeaders != "" {
				return usageErrorf("--export-headers and --clear-export-headers exclude each other")
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, radmArg(args))
			if err != nil {
				return err
			}
			cur, err := c.RegionTracing(ctx, reg.ID)
			if err != nil {
				return err
			}
			in := runr.RegionTracingInput{Mode: cur.Mode, Install: f.install}
			if fl.Changed("mode") {
				switch f.mode {
				case runr.TracingManaged, runr.TracingReuse, runr.TracingExternal, runr.TracingOff:
				default:
					return usageErrorf("--mode must be managed, reuse, external or off")
				}
				in.Mode = f.mode
			}
			// The API resets the fields of the other modes: send the current ones again.
			pick := func(flag, flagValue, current string) *string {
				v := current
				if fl.Changed(flag) {
					v = flagValue
				}
				if v == "" {
					return nil
				}
				return &v
			}
			switch in.Mode {
			case runr.TracingReuse:
				in.TempoURL = pick("tempo-url", f.tempoURL, cur.TempoURL)
				in.TempoOTLPEndpoint = pick("tempo-otlp-endpoint", f.tempoOTLP, cur.TempoOTLPEndpoint)
				in.TempoOrgID = pick("tempo-org-id", f.tempoOrg, cur.TempoOrgID)
				if in.TempoURL == nil || in.TempoOTLPEndpoint == nil {
					return usageErrorf("--mode reuse needs --tempo-url and --tempo-otlp-endpoint")
				}
			case runr.TracingExternal:
				in.ExportEndpoint = pick("export-endpoint", f.exportEndpoint, cur.ExportEndpoint)
				if in.ExportEndpoint == nil {
					return usageErrorf("--mode external needs --export-endpoint")
				}
			default:
				for _, flag := range []string{"tempo-url", "tempo-otlp-endpoint", "tempo-org-id", "export-endpoint", "export-headers"} {
					if fl.Changed(flag) {
						return usageErrorf("--%s does not apply to mode %s", flag, in.Mode)
					}
				}
			}
			if f.exportHeaders != "" {
				if in.Mode != runr.TracingExternal {
					return usageErrorf("--export-headers applies to mode external")
				}
				data, err := readFileArg(app, f.exportHeaders)
				if err != nil {
					return err
				}
				h, err := radmParseHeaders(data)
				if err != nil {
					return usageErrorf("--export-headers: %v", err)
				}
				in.ExportHeaders = &h
			}
			if f.clearHeaders {
				empty := map[string]string{}
				in.ExportHeaders = &empty
			}
			if fl.Changed("sample-rate") {
				if f.sampleRate < 0 || f.sampleRate > 1 {
					return usageErrorf("--sample-rate must be between 0 and 1")
				}
				in.KnativeSampleRate = &f.sampleRate
			}
			if fl.Changed("retention-hours") {
				if f.retentionHours < 1 || f.retentionHours > 720 {
					return usageErrorf("--retention-hours must be between 1 and 720")
				}
				in.RetentionHours = &f.retentionHours
			}
			t, msg, err := c.SetRegionTracing(ctx, reg.ID, in)
			if err != nil {
				return err
			}
			if msg != "" {
				app.Progress("%s", msg)
			} else {
				app.Progress("Saved the tracing configuration of %s.", reg.Name)
			}
			if f.install && f.wait {
				t, err = radmWaitTracing(ctx, app, c, reg, f.timeout)
				if t == nil {
					return err
				}
				if perr := app.Printer().Print(t, func(tw *output.TableWriter) { radmTracingDetail(tw, reg, t) }); perr != nil && err == nil {
					err = perr
				}
				return err
			}
			return app.Printer().Print(t, func(tw *output.TableWriter) { radmTracingDetail(tw, reg, t) })
		},
	}
	fs := cmd.Flags()
	fs.StringVar(&f.mode, "mode", "", "managed, reuse, external or off (default: the current mode)")
	fs.StringVar(&f.tempoURL, "tempo-url", "", "reuse: query URL of the cluster's Tempo (http://tempo.<namespace>.svc:3200)")
	fs.StringVar(&f.tempoOTLP, "tempo-otlp-endpoint", "", "reuse: OTLP endpoint of that Tempo (tempo.<namespace>.svc:4317)")
	fs.StringVar(&f.tempoOrg, "tempo-org-id", "", "reuse: X-Scope-OrgID of a multi-tenant Tempo")
	fs.StringVar(&f.exportEndpoint, "export-endpoint", "", "external: https URL the collector exports OTLP to")
	fs.StringVar(&f.exportHeaders, "export-headers", "", "external: file (or - for stdin) with the export headers, \"Name: value\" lines or a JSON object")
	fs.BoolVar(&f.clearHeaders, "clear-export-headers", false, "external: remove the stored export headers")
	fs.Float64Var(&f.sampleRate, "sample-rate", 0, "share of requests Knative traces (0-1)")
	fs.IntVar(&f.retentionHours, "retention-hours", 0, "how long RunR's Tempo keeps traces (1-720)")
	fs.BoolVar(&f.install, "install", false, "apply the configuration to the cluster now")
	fs.BoolVar(&f.wait, "wait", false, "with --install: wait until the install finished")
	fs.DurationVar(&f.timeout, "timeout", 15*time.Minute, "how long --wait waits")
	return cmd
}

// radmParseHeaders reads export headers: a JSON object or "Name: value" lines (# comments).
func radmParseHeaders(data []byte) (map[string]string, error) {
	trimmed := bytes.TrimSpace(data)
	out := map[string]string{}
	if len(trimmed) > 0 && trimmed[0] == '{' {
		var m map[string]any
		if err := json.Unmarshal(trimmed, &m); err != nil {
			return nil, err
		}
		for k, v := range m {
			out[k] = fmt.Sprint(v)
		}
	} else {
		sc := bufio.NewScanner(bytes.NewReader(trimmed))
		n := 0
		for sc.Scan() {
			n++
			line := strings.TrimSpace(sc.Text())
			if line == "" || strings.HasPrefix(line, "#") {
				continue
			}
			k, v, ok := strings.Cut(line, ":")
			if !ok {
				return nil, fmt.Errorf("line %d is not \"Name: value\"", n)
			}
			out[strings.TrimSpace(k)] = strings.TrimSpace(v)
		}
	}
	for k := range out {
		for _, r := range k {
			if !(r == '-' || r >= '0' && r <= '9' || r >= 'a' && r <= 'z' || r >= 'A' && r <= 'Z') {
				return nil, fmt.Errorf("header name %q: use letters, digits and -", k)
			}
		}
	}
	if len(out) > 10 {
		return nil, fmt.Errorf("at most 10 headers")
	}
	return out, nil
}

func radmWaitTracing(ctx context.Context, app *App, c *runr.Client, reg *runr.Region, timeout time.Duration) (*runr.RegionTracing, error) {
	t, err := c.WaitRegionTracing(ctx, reg.ID, reg.Name, app.waitOptions(timeout, "Tracing of "+reg.Name))
	if err == nil {
		app.Progress("The tracing backend of %s is %s.", reg.Name, output.Dash(t.Status))
	}
	return t, err
}

func newRegionTracingInstallCmd(app *App) *cobra.Command {
	var wait bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "install [REGION]",
		Short: "Install (or apply again) the tracing backend of a region",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, radmArg(args))
			if err != nil {
				return err
			}
			t, msg, err := c.InstallRegionTracing(ctx, reg.ID)
			if err != nil {
				return err
			}
			if msg != "" {
				app.Progress("%s", msg)
			}
			if wait {
				t, err = radmWaitTracing(ctx, app, c, reg, timeout)
				if t == nil {
					return err
				}
			}
			if perr := app.Printer().Print(t, func(tw *output.TableWriter) { radmTracingDetail(tw, reg, t) }); perr != nil && err == nil {
				err = perr
			}
			return err
		},
	}
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the install finished (exit code 6 when it fails)")
	cmd.Flags().DurationVar(&timeout, "timeout", 15*time.Minute, "how long --wait waits")
	return cmd
}

func newRegionTracingRmCmd(app *App) *cobra.Command {
	var yes bool
	cmd := &cobra.Command{
		Use:     "rm REGION",
		Aliases: []string{"remove", "uninstall"},
		Short:   "Remove the collector (and RunR's Tempo) from a region",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Remove the tracing backend of "+reg.Name+" (stored traces are deleted with RunR's Tempo)", yes); err != nil {
				return err
			}
			msg, err := c.RemoveRegionTracing(ctx, reg.ID)
			if err != nil {
				return err
			}
			if msg == "" {
				msg = "Tracing removal started."
			}
			app.Progress("%s", msg)
			return app.Printer().Print(map[string]any{"region": reg.Name, "message": msg}, func(*output.TableWriter) {})
		},
	}
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}

// ---------------------------------------------------------------------------------------
// Data services

func newRegionDataServicesCmd(app *App) *cobra.Command {
	var live bool
	cmd := &cobra.Command{
		Use:     "data-services [REGION]",
		Aliases: []string{"data"},
		Short:   "The data services bindings run on: kv, object storage, Postgres",
		Long: "A region runs the data services its bindings use (docs/bindings.md): kv (managed KV on Redis,\n" +
			"or the region's own Redis), object (Garage, or an external S3 or Garage endpoint) and postgres\n" +
			"(CloudNativePG with a shared cluster). Reading needs regions.view or bindings.view, install and\n" +
			"uninstall regions.data_services.manage.",
		Example: "  runr regions data-services eu-1 --live\n" +
			"  runr regions data-services install eu-1 kv --maxmemory-mb 512 --wait\n" +
			"  runr regions data-services install eu-1 postgres --instances 3 --storage 50Gi --backups --wait\n" +
			"  runr regions data-services install eu-1 object --mode external --provider s3 \\\n" +
			"      --endpoint https://s3.example.com --credentials s3.env\n" +
			"  runr regions data-services uninstall eu-1 kv --yes",
		Args: cobra.MaximumNArgs(1),
	}
	ls := &cobra.Command{
		Use:     "ls [REGION]",
		Aliases: []string{"list", "status"},
		Short:   "Status of a region's data services",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			all, err := c.RegionDataServices(cmd.Context(), reg.ID, live)
			if err != nil {
				return err
			}
			return app.Printer().Print(all, func(t *output.TableWriter) { radmDataServicesTable(t, reg, all, live) })
		},
	}
	ls.Flags().BoolVar(&live, "live", false, "check the health of the ready services")
	cmd.Flags().BoolVar(&live, "live", false, "check the health of the ready services")
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE
	cmd.AddCommand(newRegionDataServiceInstallCmd(app), newRegionDataServiceUninstallCmd(app))
	return cmd
}

func radmDataServicesTable(t *output.TableWriter, reg *runr.Region, all *runr.RegionDataServices, live bool) {
	if !all.Connected {
		t.Note("Region %s has no cluster connection: it runs no data services.", reg.Name)
		return
	}
	if live {
		t.Header("SERVICE", "STATUS", "MODE", "BINDINGS", "HEALTH", "SETTINGS", "UPDATED")
	} else {
		t.Header("SERVICE", "STATUS", "MODE", "BINDINGS", "SETTINGS", "UPDATED")
	}
	for _, s := range all.Services {
		status := s.Status
		if s.StatusMessage != "" {
			status += " (" + output.Truncate(s.StatusMessage, 60) + ")"
		}
		row := []string{s.Service, status, output.Dash(s.Mode), strconv.Itoa(s.BindingsCount)}
		if live {
			health := "-"
			if s.Health != nil {
				health = s.Health.State
				if s.Health.Message != "" {
					health += ": " + output.Truncate(s.Health.Message, 40)
				}
			}
			row = append(row, health)
		}
		row = append(row, radmSettingsLabel(s.Config), output.Dash(s.UpdatedAt))
		t.Row(row...)
	}
	if all.RegionRedis != nil && all.RegionRedis.Mode != "" {
		t.Note("Region Redis (queues): %s, %s.", all.RegionRedis.Mode, output.Dash(all.RegionRedis.Status))
	}
	if !all.CanManage {
		t.Note("Installing and uninstalling needs regions.data_services.manage.")
	}
}

func radmSettingsLabel(cfg map[string]any) string {
	if len(cfg) == 0 {
		return "-"
	}
	keys := make([]string, 0, len(cfg))
	for k := range cfg {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	parts := make([]string, 0, len(keys))
	for _, k := range keys {
		v := cfg[k]
		if v == nil {
			continue
		}
		if m, ok := v.(map[string]any); ok && len(m) == 0 {
			continue
		}
		parts = append(parts, fmt.Sprintf("%s=%v", k, v))
	}
	return output.Truncate(strings.Join(parts, " "), 70)
}

// radmService resolves a service argument (kv, object, postgres and their aliases).
func radmService(s string) (string, error) {
	t, err := bindingType(s)
	if err == nil {
		for _, name := range runr.DataServiceNames {
			if name == t {
				return t, nil
			}
		}
	}
	return "", usageErrorf("unknown data service %q (kv, object or postgres)", s)
}

// radmCredentialKeys maps the names a credentials file uses to the API's fields.
var radmCredentialKeys = map[string]string{
	"admin_token": "admin_token", "access_key_id": "access_key_id", "secret_access_key": "secret_access_key",
	"aws_access_key_id": "access_key_id", "aws_secret_access_key": "secret_access_key",
}

// radmReadCredentials reads KEY=VALUE lines (dotenv) of an external object store's credentials.
func radmReadCredentials(app *App, arg string) (map[string]string, error) {
	data, err := readFileArg(app, arg)
	if err != nil {
		return nil, err
	}
	out := map[string]string{}
	sc := bufio.NewScanner(bytes.NewReader(data))
	n := 0
	for sc.Scan() {
		n++
		line := strings.TrimSpace(sc.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		line = strings.TrimPrefix(line, "export ")
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			return nil, usageErrorf("--credentials line %d is not KEY=VALUE", n)
		}
		key, known := radmCredentialKeys[strings.ToLower(strings.TrimSpace(k))]
		if !known {
			return nil, usageErrorf("--credentials: unknown key %s (ADMIN_TOKEN, ACCESS_KEY_ID, SECRET_ACCESS_KEY)", strings.TrimSpace(k))
		}
		v = strings.TrimSpace(v)
		if len(v) >= 2 && (v[0] == '"' && v[len(v)-1] == '"' || v[0] == '\'' && v[len(v)-1] == '\'') {
			v = v[1 : len(v)-1]
		}
		out[key] = v
	}
	return out, sc.Err()
}

type radmInstallFlags struct {
	mode, storage, provider, endpoint, s3Region, vendor, bucketPrefix, adminEndpoint, credentials string
	maxMemoryMB, instances                                                                        int
	backups, pathStyle, wait                                                                      bool
	timeout                                                                                       time.Duration
}

// radmOptions builds the install settings of a service from the flags.
func radmOptions(cmd *cobra.Command, app *App, service string, f *radmInstallFlags) (map[string]any, error) {
	fl := cmd.Flags()
	opts := map[string]any{}
	allowed := map[string][]string{
		runr.DataServiceKV:       {"mode", "maxmemory-mb", "storage"},
		runr.DataServiceObject:   {"mode", "storage", "provider", "endpoint", "s3-region", "path-style", "vendor", "bucket-prefix", "admin-endpoint", "credentials"},
		runr.DataServicePostgres: {"instances", "storage", "backups"},
	}[service]
	for _, name := range []string{"mode", "maxmemory-mb", "storage", "provider", "endpoint", "s3-region", "path-style",
		"vendor", "bucket-prefix", "admin-endpoint", "credentials", "instances", "backups"} {
		if !fl.Changed(name) {
			continue
		}
		ok := false
		for _, a := range allowed {
			ok = ok || a == name
		}
		if !ok {
			return nil, usageErrorf("--%s does not apply to the %s data service", name, service)
		}
	}
	str := func(flag, key, v string) {
		if fl.Changed(flag) {
			opts[key] = v
		}
	}
	str("mode", "mode", f.mode)
	str("storage", "storage", f.storage)
	str("provider", "provider", f.provider)
	str("endpoint", "endpoint", f.endpoint)
	str("s3-region", "region", f.s3Region)
	str("vendor", "vendor", f.vendor)
	str("bucket-prefix", "bucket_prefix", f.bucketPrefix)
	str("admin-endpoint", "admin_endpoint", f.adminEndpoint)
	if fl.Changed("maxmemory-mb") {
		opts["maxmemory_mb"] = f.maxMemoryMB
	}
	if fl.Changed("instances") {
		opts["instances"] = f.instances
	}
	if fl.Changed("backups") {
		opts["backups"] = f.backups
	}
	if fl.Changed("path-style") {
		opts["path_style"] = f.pathStyle
	}
	if f.storage != "" && !radmSize(f.storage) {
		return nil, usageErrorf("--storage is a size such as 512Mi, 10Gi or 1Ti")
	}
	if f.credentials != "" {
		creds, err := radmReadCredentials(app, f.credentials)
		if err != nil {
			return nil, err
		}
		for k, v := range creds {
			opts[k] = v
		}
	}
	return opts, nil
}

func radmSize(s string) bool {
	for _, unit := range []string{"Mi", "Gi", "Ti"} {
		if n, ok := strings.CutSuffix(s, unit); ok {
			if _, err := strconv.Atoi(n); err == nil && len(n) >= 1 && len(n) <= 5 {
				return true
			}
		}
	}
	return false
}

func newRegionDataServiceInstallCmd(app *App) *cobra.Command {
	var f radmInstallFlags
	cmd := &cobra.Command{
		Use:   "install REGION SERVICE",
		Short: "Install or reconfigure a data service of a region (regions.data_services.manage)",
		Long: "Install a data service, or change the settings of an installed one; the install runs in the\n" +
			"background (--wait waits, exit code 6 when it fails). Settings:\n" +
			"  kv        --mode managed|region, --maxmemory-mb, --storage\n" +
			"  object    --mode managed|external, --storage; external: --provider garage|s3, --endpoint,\n" +
			"            --s3-region, --path-style, --vendor, --bucket-prefix, --admin-endpoint (garage)\n" +
			"  postgres  --instances, --storage, --backups (to the region's object storage)\n" +
			"An external store's credentials (ADMIN_TOKEN for Garage, ACCESS_KEY_ID and SECRET_ACCESS_KEY\n" +
			"for S3) come from --credentials FILE or - (stdin), KEY=VALUE lines; the API stores them\n" +
			"encrypted and never returns them.",
		Example: "  runr regions data-services install eu-1 kv --wait\n" +
			"  runr regions data-services install eu-1 object --mode external --provider garage \\\n" +
			"      --endpoint https://s3.garage.example.com --admin-endpoint https://admin.garage.example.com --credentials garage.env",
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			service, err := radmService(args[1])
			if err != nil {
				return err
			}
			opts, err := radmOptions(cmd, app, service, &f)
			if err != nil {
				return err
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			res, err := c.InstallDataService(ctx, reg.ID, service, opts)
			if err != nil {
				return err
			}
			if res.Message != "" {
				app.Progress("%s", res.Message)
			}
			if !f.wait {
				return app.Printer().Print(res, func(t *output.TableWriter) {
					t.KV("Region", reg.Name)
					t.KV("Service", res.Service)
					t.KV("Status", res.Status)
					t.KV("Mode", output.Dash(res.Mode))
				})
			}
			return radmWaitService(ctx, app, c, reg, service, f.timeout)
		},
	}
	fs := cmd.Flags()
	fs.StringVar(&f.mode, "mode", "", "kv: managed (default) or region (the region's Redis); object: managed (default) or external")
	fs.StringVar(&f.storage, "storage", "", "volume size (512Mi, 10Gi, 1Ti)")
	fs.IntVar(&f.maxMemoryMB, "maxmemory-mb", 0, "kv: Redis maxmemory (64-65536)")
	fs.IntVar(&f.instances, "instances", 0, "postgres: instances of the shared cluster (1-5)")
	fs.BoolVar(&f.backups, "backups", false, "postgres: back the shared cluster up to the region's object storage")
	fs.StringVar(&f.provider, "provider", "", "object, external: garage or s3")
	fs.StringVar(&f.endpoint, "endpoint", "", "object, external: S3 endpoint URL")
	fs.StringVar(&f.s3Region, "s3-region", "", "object, external: S3 region name")
	fs.BoolVar(&f.pathStyle, "path-style", false, "object, external: path-style bucket addressing")
	fs.StringVar(&f.vendor, "vendor", "", "object, external: S3 vendor label (minio, ceph, …)")
	fs.StringVar(&f.bucketPrefix, "bucket-prefix", "", "object: prefix of the bucket names")
	fs.StringVar(&f.adminEndpoint, "admin-endpoint", "", "object, external garage: admin API URL")
	fs.StringVar(&f.credentials, "credentials", "", "object, external: file (or - for stdin) with ADMIN_TOKEN / ACCESS_KEY_ID / SECRET_ACCESS_KEY")
	fs.BoolVar(&f.wait, "wait", false, "wait until the install finished (exit code 6 when it fails)")
	fs.DurationVar(&f.timeout, "timeout", 15*time.Minute, "how long --wait waits")
	return cmd
}

func radmWaitService(ctx context.Context, app *App, c *runr.Client, reg *runr.Region, service string, timeout time.Duration) error {
	svc, err := c.WaitDataService(ctx, reg.ID, reg.Name, service, app.waitOptions(timeout, service+" in "+reg.Name))
	if svc == nil {
		return err
	}
	if err == nil {
		app.Progress("The %s data service of %s is %s.", service, reg.Name, svc.Status)
	}
	if perr := app.Printer().Print(svc, func(t *output.TableWriter) {
		t.KV("Region", reg.Name)
		t.KV("Service", svc.Service)
		status := svc.Status
		if svc.StatusMessage != "" {
			status += " (" + svc.StatusMessage + ")"
		}
		t.KV("Status", status)
		t.KV("Mode", output.Dash(svc.Mode))
		t.KV("Settings", radmSettingsLabel(svc.Config))
	}); perr != nil && err == nil {
		err = perr
	}
	return err
}

func newRegionDataServiceUninstallCmd(app *App) *cobra.Command {
	var yes, wait bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:     "uninstall REGION SERVICE",
		Aliases: []string{"rm"},
		Short:   "Uninstall a data service of a region (refused while bindings use it)",
		Args:    cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			service, err := radmService(args[1])
			if err != nil {
				return err
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Uninstall the "+service+" data service of "+reg.Name+" (its data is deleted)", yes); err != nil {
				return err
			}
			res, err := c.UninstallDataService(ctx, reg.ID, service)
			if err != nil {
				return err
			}
			if res.Message != "" {
				app.Progress("%s", res.Message)
			}
			if wait {
				return radmWaitService(ctx, app, c, reg, service, timeout)
			}
			return app.Printer().Print(res, func(t *output.TableWriter) {
				t.KV("Region", reg.Name)
				t.KV("Service", res.Service)
				t.KV("Status", res.Status)
			})
		},
	}
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the service is removed")
	cmd.Flags().DurationVar(&timeout, "timeout", 15*time.Minute, "how long --wait waits")
	return cmd
}
