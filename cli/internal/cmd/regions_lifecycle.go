package cmd

import (
	"bufio"
	"bytes"
	"context"
	"errors"
	"fmt"
	"os"
	"slices"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// The lifecycle of a region: `runr regions get | create | check | set | rm | status |
// kubeconfig | nodes` (KnativeRegionController, KnativeNodeController). The Knative components,
// the services of a region and WebAssembly support are in regions_knative.go,
// regions_services.go and regions_webassembly.go.

func newRegionLifecycleCommands(app *App) []*cobra.Command {
	return []*cobra.Command{
		newRegionGetCmd(app), newRegionCreateCmd(app), newRegionCheckCmd(app), newRegionSetCmd(app), newRegionRmCmd(app),
		newRegionStatusCmd(app), newRegionKubeconfigCmd(app), newRegionNodesCmd(app),
	}
}

// rlcStatus renders a status with its message's last line.
func rlcStatus(status, message string) string {
	s := output.Dash(status)
	if line := lastLogLine(message); line != "" && status != runr.KnativeReady && status != runr.KnativeNotInstalled {
		s += " (" + output.Truncate(line, 100) + ")"
	}
	return s
}

// lastLogLine returns the last non-empty line of an install log.
func lastLogLine(s string) string {
	lines := strings.Split(strings.TrimSpace(s), "\n")
	return strings.TrimSpace(lines[len(lines)-1])
}

func rlcMiB(mb int) string {
	if mb >= 1024 && mb%1024 == 0 {
		return strconv.Itoa(mb/1024) + " GiB"
	}
	return strconv.Itoa(mb) + " MiB"
}

func rlcFactor(f float64) string {
	return "×" + strconv.FormatFloat(f, 'f', -1, 64)
}

func rlcSettingsLabel(m map[string]any) string {
	if len(m) == 0 {
		return "-"
	}
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	parts := make([]string, 0, len(keys))
	for _, k := range keys {
		v := m[k]
		if v == nil {
			continue
		}
		if list, ok := v.([]any); ok {
			items := make([]string, 0, len(list))
			for _, x := range list {
				items = append(items, fmt.Sprint(x))
			}
			v = strings.Join(items, ",")
		}
		parts = append(parts, fmt.Sprintf("%s=%v", k, v))
	}
	return strings.Join(parts, " ")
}

func printRegionDetail(t *output.TableWriter, d *runr.RegionDetail) {
	t.KV("Name", d.Name)
	t.KV("ID", d.ID)
	status := d.Status()
	if d.IsDemo {
		status += " (demo)"
	}
	t.KV("Status", status)
	t.KV("Mode", output.Dash(d.RegionMode))
	t.KV("Namespace", output.Dash(d.ProjectNamespace))
	if d.IsSynced {
		return
	}
	cluster := rlcStatus(d.ClusterStatus, d.ClusterStatusMessage)
	if d.ServerURL != "" {
		cluster += ", " + d.ServerURL
	}
	if !d.HasKubeconfig {
		cluster += ", no kubeconfig"
	}
	t.KV("Cluster", cluster)
	t.KV("Knative", rlcStatus(d.KnativeStatus, d.KnativeStatusMessage))
	t.KV("  operator", rlcStatus(d.OperatorStatus, d.OperatorStatusMessage)+" in "+output.Dash(d.OperatorNamespace))
	t.KV("  serving", rlcStatus(d.ServingStatus, d.ServingStatusMessage)+" in "+output.Dash(d.ServingNamespace))
	redis := "-"
	if d.RedisMode != "" {
		redis = d.RedisMode + ", " + rlcStatus(d.RedisStatus, d.RedisStatusMessage)
		if d.RedisAddress != "" {
			redis += ", " + d.RedisAddress
		}
	}
	t.KV("Redis (queues)", redis)
	registry := "-"
	if d.RegistryMode != "" {
		registry = d.RegistryMode + ", " + rlcStatus(d.RegistryStatus, d.RegistryStatusMessage)
		if d.RegistryAddress != "" {
			registry += ", " + d.RegistryAddress
		}
	}
	t.KV("Registry", registry)
	workers := "every schedulable node"
	if len(d.SelectedWorkers) > 0 {
		workers = strings.Join(d.SelectedWorkers, ", ")
	}
	t.KV("Worker nodes", workers)
	t.KV("CPU", fmt.Sprintf("%dm reserved of %dm (FaaS overcommit %s)", d.UsedCPUm, d.TotalCPUm, rlcFactor(d.FaaSCPUOvercommit)))
	t.KV("Memory", fmt.Sprintf("%s reserved of %s (FaaS overcommit %s)", rlcMiB(d.UsedMemoryMB), rlcMiB(d.TotalMemoryMB),
		rlcFactor(d.FaaSMemoryOvercommit)))
	t.KV("Projects", strconv.Itoa(d.ProjectCount))
	t.KV("External IP", output.Dash(d.ExternalIP))
	if d.DNSInstruction != nil {
		dns := d.DNSInstruction.Wildcard
		if d.DNSInstruction.Type != "" {
			dns += " " + d.DNSInstruction.Type + " " + d.DNSInstruction.Target
		}
		t.KV("DNS", dns)
	}
	tls := "-"
	if d.FunctionTLSMode != "" {
		tls = d.FunctionTLSMode
		if d.FunctionTLSExpiresAt != "" {
			tls += ", expires " + d.FunctionTLSExpiresAt
		}
	}
	t.KV("Function TLS", tls)
	t.KV("WebAssembly", output.Dash(d.WasmStatus))
	if d.MigrationStatus != "" {
		t.KV("Migration", d.MigrationStatus)
	}
	if d.DemoStatus != "" {
		t.KV("Demo projects", d.DemoStatus)
	}
	t.KV("Settings", rlcSettingsLabel(d.KnativeSettings))
}

func newRegionGetCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:     "get [REGION]",
		Aliases: []string{"show"},
		Short:   "Show a region: cluster, Knative, Redis, registry, workers, capacity, settings",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			d, err := c.GetRegionDetail(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(d, func(t *output.TableWriter) { printRegionDetail(t, d) })
		},
	}
}

// rlcReadKubeconfig reads a kubeconfig from a file or stdin (-); it never comes from an argument.
func rlcReadKubeconfig(app *App, flag, path string) (string, error) {
	if path == "" {
		return "", usageErrorf("--%s FILE (or - for stdin) is required", flag)
	}
	data, err := readFileArg(app, path)
	if err != nil {
		return "", err
	}
	if len(bytes.TrimSpace(data)) == 0 {
		return "", usageErrorf("--%s %s is empty", flag, path)
	}
	return string(data), nil
}

// rlcReadSecret reads a password or key from a file or stdin (-), without a trailing newline.
func rlcReadSecret(app *App, flag, path string) (string, error) {
	data, err := readFileArg(app, path)
	if err != nil {
		return "", err
	}
	s := strings.TrimRight(string(data), "\r\n")
	if s == "" {
		return "", usageErrorf("--%s %s is empty", flag, path)
	}
	return s, nil
}

// rlcReadKeyValues reads KEY=VALUE lines (dotenv: # comments, export, quotes) of a credentials
// file; keys are lower-cased and aliases mapped through keys (unknown keys are refused).
func rlcReadKeyValues(app *App, flag, path string, keys map[string]string) (map[string]string, error) {
	data, err := readFileArg(app, path)
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
			return nil, usageErrorf("--%s line %d is not KEY=VALUE", flag, n)
		}
		key, known := keys[strings.ToLower(strings.TrimSpace(k))]
		if !known {
			names := map[string]bool{}
			for _, k := range keys {
				names[strings.ToUpper(k)] = true
			}
			list := make([]string, 0, len(names))
			for k := range names {
				list = append(list, k)
			}
			sort.Strings(list)
			return nil, usageErrorf("--%s: unknown key %s (%s)", flag, strings.TrimSpace(k), strings.Join(list, ", "))
		}
		v = strings.TrimSpace(v)
		if len(v) >= 2 && (v[0] == '"' && v[len(v)-1] == '"' || v[0] == '\'' && v[len(v)-1] == '\'') {
			v = v[1 : len(v)-1]
		}
		out[key] = v
	}
	return out, sc.Err()
}

// rlcOneStdin refuses several flags that read stdin (-) at once.
func rlcOneStdin(values map[string]string) error {
	var flags []string
	for flag, v := range values {
		if v == "-" {
			flags = append(flags, "--"+flag)
		}
	}
	if len(flags) > 1 {
		sort.Strings(flags)
		return usageErrorf("only one of %s can read stdin (-)", strings.Join(flags, ", "))
	}
	return nil
}

var registryS3Keys = map[string]string{
	"endpoint": "endpoint", "bucket": "bucket", "region": "region", "access_key": "access_key", "secret_key": "secret_key",
	"access_key_id": "access_key", "secret_access_key": "secret_key", "aws_access_key_id": "access_key",
	"aws_secret_access_key": "secret_key", "aws_region": "region",
}

var registryCredentialKeys = map[string]string{"username": "username", "password": "password", "user": "username"}

type regionCreateFlags struct {
	kubeconfig, redis, redisAddress, redisPassword, prometheus, loki, lokiOrg string
	registry, storage, storageClass, size, s3File, registryAddress, credFile  string
	retry                                                                     string
	nodes                                                                     []string
	inactive, wait                                                            bool
	timeout                                                                   time.Duration
}

func newRegionCreateCmd(app *App) *cobra.Command {
	var f regionCreateFlags
	cmd := &cobra.Command{
		Use:   "create NAME --kubeconfig FILE",
		Short: "Connect a cluster as a new region (regions.manage)",
		Long: "Connect a Kubernetes cluster (1.30 or newer) as a region. NAME is also the namespace the\n" +
			"region's projects run in, which must not exist yet (runr regions check tests both). The\n" +
			"kubeconfig comes from a file or stdin (-), never from an argument; RunR stores it encrypted.\n" +
			"--node selects the worker nodes projects run on (repeatable; default: every schedulable node).\n" +
			"The region gets a Redis for queue triggers (--redis managed, the default; in_cluster or\n" +
			"external with --redis-address and --redis-password-file; none). --prometheus-url and\n" +
			"--loki-url reuse a Prometheus / Loki already running in the cluster (an in-cluster Service URL).\n\n" +
			"With --registry the region gets an image registry for builds, set up in the background:\n" +
			"  internal  RunR runs Zot on a volume (--registry-storage pvc, --storage-class, default the\n" +
			"            cluster's default class, --registry-size, default 10Gi) or on S3 (--registry-storage\n" +
			"            s3 --registry-s3 FILE with ENDPOINT, BUCKET, REGION, ACCESS_KEY, SECRET_KEY lines)\n" +
			"  external  --registry-address and --registry-credentials FILE (USERNAME, PASSWORD lines)\n" +
			"--wait waits until the region exists; a failed setup is started again with --retry TOKEN and\n" +
			"the same flags. Next: runr regions knative install NAME --wait.",
		Example: "  runr regions check eu-2 --kubeconfig eu-2.yaml\n" +
			"  runr regions create eu-2 --kubeconfig eu-2.yaml --node worker-1 --node worker-2\n" +
			"  runr regions create eu-2 --kubeconfig eu-2.yaml --registry internal --storage-class fast-ssd --wait\n" +
			"  runr regions create eu-2 --kubeconfig - --redis external --redis-address redis.example:6379 \\\n" +
			"      --redis-password-file redis.pass < eu-2.yaml",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			return runRegionCreate(cmd, app, args[0], &f)
		},
	}
	fs := cmd.Flags()
	fs.StringVar(&f.kubeconfig, "kubeconfig", "", "kubeconfig file of the cluster (- for stdin)")
	fs.StringArrayVar(&f.nodes, "node", nil, "worker node projects run on (repeatable; default: every schedulable node)")
	fs.BoolVar(&f.inactive, "inactive", false, "create the region inactive (projects cannot be deployed to it yet)")
	fs.StringVar(&f.redis, "redis", "", "Redis for queue triggers: managed (default), in_cluster, external or none")
	fs.StringVar(&f.redisAddress, "redis-address", "", "in_cluster / external: host:port of the Redis")
	fs.StringVar(&f.redisPassword, "redis-password-file", "", "file (or -) with the Redis password")
	fs.StringVar(&f.prometheus, "prometheus-url", "", "reuse this in-cluster Prometheus instead of installing one")
	fs.StringVar(&f.loki, "loki-url", "", "reuse this in-cluster Loki instead of installing one")
	fs.StringVar(&f.lokiOrg, "loki-org-id", "", "tenant (X-Scope-OrgID) of a multi-tenant Loki")
	fs.StringVar(&f.registry, "registry", "", "set up an image registry: internal or external")
	fs.StringVar(&f.storage, "registry-storage", "", "internal registry storage: pvc (default) or s3")
	fs.StringVar(&f.storageClass, "storage-class", "", "pvc: StorageClass of the registry volume (default: the cluster's default)")
	fs.StringVar(&f.size, "registry-size", "", "pvc: size of the registry volume (default 10Gi)")
	fs.StringVar(&f.s3File, "registry-s3", "", "s3: file (or -) with ENDPOINT, BUCKET, REGION, ACCESS_KEY, SECRET_KEY lines")
	fs.StringVar(&f.registryAddress, "registry-address", "", "external: address of the registry (registry.example.com/team)")
	fs.StringVar(&f.credFile, "registry-credentials", "", "external: file (or -) with USERNAME and PASSWORD lines")
	fs.StringVar(&f.retry, "retry", "", "start a failed registry setup (its token) again with these settings")
	fs.BoolVar(&f.wait, "wait", false, "wait until the region and its Redis are set up (exit code 6 when it fails)")
	fs.DurationVar(&f.timeout, "timeout", 20*time.Minute, "how long --wait waits")
	return cmd
}

func runRegionCreate(cmd *cobra.Command, app *App, name string, f *regionCreateFlags) error {
	if err := rlcOneStdin(map[string]string{"kubeconfig": f.kubeconfig, "redis-password-file": f.redisPassword,
		"registry-s3": f.s3File, "registry-credentials": f.credFile}); err != nil {
		return err
	}
	switch f.redis {
	case "", "managed", "none":
		if f.redisAddress != "" {
			return usageErrorf("--redis-address applies to --redis in_cluster or external")
		}
	case "in_cluster", "external":
		if f.redisAddress == "" {
			return usageErrorf("--redis %s needs --redis-address", f.redis)
		}
	default:
		return usageErrorf("--redis must be managed, in_cluster, external or none")
	}
	if f.registry == "" {
		for _, fl := range []string{"registry-storage", "storage-class", "registry-size", "registry-s3", "registry-address",
			"registry-credentials", "retry"} {
			if cmd.Flags().Changed(fl) {
				return usageErrorf("--%s needs --registry", fl)
			}
		}
	}
	kc, err := rlcReadKubeconfig(app, "kubeconfig", f.kubeconfig)
	if err != nil {
		return err
	}
	in := runr.RegionCreateInput{Name: name, Kubeconfig: kc, Nodes: f.nodes, RedisMode: f.redis, RedisAddress: f.redisAddress,
		PrometheusURL: f.prometheus, LokiURL: f.loki, LokiOrgID: f.lokiOrg}
	if f.redisPassword != "" {
		if f.redis != "in_cluster" && f.redis != "external" {
			return usageErrorf("--redis-password-file applies to --redis in_cluster or external")
		}
		if in.RedisPassword, err = rlcReadSecret(app, "redis-password-file", f.redisPassword); err != nil {
			return err
		}
	}
	ctx := cmd.Context()
	c, err := app.Client()
	if err != nil {
		return err
	}
	if f.registry != "" {
		if f.inactive {
			return usageErrorf("a region set up with --registry is created active")
		}
		return runRegistrySetup(ctx, cmd, app, c, in, f)
	}
	if f.inactive {
		active := false
		in.IsActive = &active
	}
	d, err := c.CreateRegion(ctx, in)
	if err != nil {
		return err
	}
	app.Progress("Created region %s (namespace %s).", d.Name, output.Dash(d.ProjectNamespace))
	if f.wait && d.RedisStatus == runr.KnativeInstalling {
		if _, err := c.WaitRegionRedis(ctx, d.ID, d.Name, app.waitOptions(f.timeout, "Redis of "+d.Name)); err != nil {
			return err
		}
		if d, err = c.GetRegionDetail(ctx, d.ID); err != nil {
			return err
		}
	}
	return app.Printer().Print(d, func(t *output.TableWriter) {
		printRegionDetail(t, d)
		if d.KnativeStatus != runr.KnativeReady {
			t.Note("Next: runr regions knative install %s --wait", d.Name)
		}
	})
}

// runRegistrySetup creates a region with an image registry (POST /registry-setup/start).
func runRegistrySetup(ctx context.Context, cmd *cobra.Command, app *App, c *runr.Client, base runr.RegionCreateInput, f *regionCreateFlags) error {
	cfg, err := readRegistryConfig(cmd, app, f.registryFlags(), func() (string, error) {
		return clusterDefaultStorageClass(ctx, c, base.Kubeconfig)
	})
	if err != nil {
		return err
	}
	in := runr.RegistrySetupInput{RegionCreateInput: base, RegistryConfig: cfg}
	var setup *runr.RegistrySetup
	if f.retry != "" {
		setup, err = c.RetryRegistrySetup(ctx, f.retry, in)
	} else {
		setup, err = c.StartRegistrySetup(ctx, in)
	}
	if err != nil {
		return err
	}
	app.Progress("Setting up region %s with an %s registry in the background (token %s).", base.Name, f.registry, setup.Token)
	if !f.wait {
		return app.Printer().Print(setup, func(t *output.TableWriter) {
			t.KV("Setup token", setup.Token)
			t.Note("runr regions registry setup-status %s --wait follows it.", setup.Token)
		})
	}
	return regionSetupWait(ctx, app, c, setup.Token, base.Name, f.timeout)
}

// regionSetupWait follows a registry setup and prints the region it created; name is the
// region's name when known ("" for a setup followed by its token).
func regionSetupWait(ctx context.Context, app *App, c *runr.Client, token, name string, timeout time.Duration) error {
	label, region := "of region "+name, name
	if name == "" {
		label, region = token, "NAME"
	}
	st, err := c.WaitRegistrySetup(ctx, token, label, app.waitOptions(timeout, "Registry setup "+label))
	if err != nil {
		var fe *runr.FailedError
		if errors.As(err, &fe) {
			app.Warn("start the setup again with: runr regions create %s --retry %s and the same flags", region, token)
		}
		if st != nil && app.Printer().Machine() {
			_ = app.Printer().Print(st, nil)
		}
		return err
	}
	app.Progress("%s", st.Message)
	d, err := c.GetRegionDetail(ctx, st.RegionID)
	if err != nil {
		return err
	}
	return app.Printer().Print(d, func(t *output.TableWriter) { printRegionDetail(t, d) })
}

// regionCheck is the result of `runr regions check`.
type regionCheck struct {
	Version         string              `json:"version"`
	Namespace       string              `json:"namespace,omitempty"`
	NamespaceExists *bool               `json:"namespace_exists,omitempty"`
	Nodes           []runr.RegionNode   `json:"nodes"`
	StorageClasses  []runr.StorageClass `json:"storage_classes"`
}

func newRegionCheckCmd(app *App) *cobra.Command {
	var kubeconfig string
	cmd := &cobra.Command{
		Use:     "check [NAME] --kubeconfig FILE",
		Aliases: []string{"preview"},
		Short:   "Check a cluster before it becomes a region: version, nodes, StorageClasses, namespace",
		Long: "Check what runr regions create needs: RunR reaches the cluster with the kubeconfig and its\n" +
			"Kubernetes version is supported; it lists the worker nodes (for --node) and the StorageClasses\n" +
			"(for a registry volume) and, with NAME, checks that the namespace NAME is free. Exit code 5\n" +
			"when the kubeconfig does not work or the namespace exists. Needs regions.manage.",
		Example: "  runr regions check eu-2 --kubeconfig eu-2.yaml",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			kc, err := rlcReadKubeconfig(app, "kubeconfig", kubeconfig)
			if err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			ctx := cmd.Context()
			v, err := c.ValidateKubeconfig(ctx, kc)
			if err != nil {
				return err
			}
			res := regionCheck{Version: v.Version}
			if res.Nodes, err = c.PreviewNodes(ctx, kc); err != nil {
				return err
			}
			if res.StorageClasses, err = c.PreviewStorageClasses(ctx, kc); err != nil {
				return err
			}
			if len(args) == 1 {
				exists, err := c.CheckNamespacePreview(ctx, kc, args[0])
				if err != nil {
					return err
				}
				res.Namespace, res.NamespaceExists = args[0], &exists
			}
			if err := app.Printer().Print(res, func(t *output.TableWriter) {
				t.KV("Kubernetes", output.Dash(res.Version))
				if res.NamespaceExists != nil {
					ns := "free"
					if *res.NamespaceExists {
						ns = "exists: a region named " + res.Namespace + " cannot be created on this cluster"
					}
					t.KV("Namespace "+res.Namespace, ns)
				}
				classes := make([]string, 0, len(res.StorageClasses))
				for _, sc := range res.StorageClasses {
					if sc.IsDefault {
						classes = append(classes, sc.Name+" (default)")
					} else {
						classes = append(classes, sc.Name)
					}
				}
				t.KV("StorageClasses", joinOrDash(classes))
				regionNodeRows(t, res.Nodes)
			}); err != nil {
				return err
			}
			if res.NamespaceExists != nil && *res.NamespaceExists {
				return &runr.APIError{StatusCode: 422, Method: "POST", Path: "/knative-regions/check-namespace-preview",
					Message: "namespace " + res.Namespace + " already exists on the cluster"}
			}
			return nil
		},
	}
	cmd.Flags().StringVar(&kubeconfig, "kubeconfig", "", "kubeconfig file of the cluster (- for stdin)")
	return cmd
}

func regionNodeRows(t *output.TableWriter, nodes []runr.RegionNode) {
	t.Header("NODE", "STATUS", "CPU", "MEMORY", "AVAILABLE CPU", "AVAILABLE MEMORY", "PROJECTS")
	for _, n := range nodes {
		t.Row(n.Name, output.Dash(n.Status), strconv.Itoa(n.CPUm)+"m", rlcMiB(n.MemoryMB), strconv.Itoa(n.AvailableCPUm)+"m",
			rlcMiB(n.AvailableMemoryMB), yesNo(n.IsSelected))
	}
	if len(nodes) == 0 {
		t.Note("The cluster has no worker nodes.")
	}
}

// regionSetKeys are the keys of `runr regions set`: status goes to the region, the rest to its
// settings.
var regionSetKeys = slices.Concat([]string{"status"}, regionSettingKeys, regionMonitoringKeys)

// regionSettingKeys go to PUT /knative-regions/{id}/settings (UpdateRegionSettingsRequest),
// regionMonitoringKeys to PUT /knative-regions/{id}/monitoring (UpdateRegionMonitoringRequest).
// TestRegionSetKeysMatchTheAPI keeps them equal to the fields of those requests.
var (
	regionSettingKeys = []string{"request_timeout", "agent_ws_url", "cert_manager_cluster_issuer", "cert_manager_issuer_mode",
		"cert_manager_acme_email", "ingress_trusted_ips", "internal_auth"}
	regionMonitoringKeys = []string{"prometheus_url", "loki_url", "loki_org_id"}
)

func newRegionSetCmd(app *App) *cobra.Command {
	var sets []string
	var kubeconfig string
	cmd := &cobra.Command{
		Use:   "set REGION --set KEY=VALUE... [--kubeconfig FILE]",
		Short: "Change a region's status, settings or kubeconfig (regions.manage)",
		Long: "Change a region with --set KEY=VALUE (repeatable); settings left out are kept:\n" +
			"  status                        active, inactive (no new deploys) or maintenance\n" +
			"  request_timeout               the longest request of a function, in seconds (1-3600); the\n" +
			"                                region's Traefik and Knative follow it\n" +
			"  agent_ws_url                  RunR's address as the region's agents reach it (wss://…;\n" +
			"                                empty: the console URL); the agent is deployed again\n" +
			"  cert_manager_cluster_issuer   a ClusterIssuer of yours for custom-domain certificates (empty:\n" +
			"                                RunR's default issuer, runr regions cert-manager)\n" +
			"  cert_manager_issuer_mode      RunR's default issuer: auto (Let's Encrypt where the region is\n" +
			"                                reachable from the internet, else the region CA), acme or ca\n" +
			"  cert_manager_acme_email       ACME account e-mail of that issuer (empty: the platform's)\n" +
			"  ingress_trusted_ips           comma-separated addresses / CIDRs of a load balancer in front\n" +
			"                                of the region's Traefik (empty: trust nobody)\n" +
			"  internal_auth                 enforce (the default, also for a region upgraded from an\n" +
			"                                earlier release): the internal network refuses calls the\n" +
			"                                callee's allow list does not admit; audit admits and logs them\n" +
			"  prometheus_url, loki_url      an existing in-cluster Prometheus / Loki\n" +
			"                                (http://<service>.<namespace>.svc:<port>); empty: RunR's own\n" +
			"                                again, installed when the region's Serving is ready\n" +
			"  loki_org_id                   tenant (X-Scope-OrgID) of an existing multi-tenant Loki\n" +
			"--kubeconfig FILE (or - for stdin) replaces the kubeconfig RunR uses for the cluster (after a\n" +
			"credential rotation; it has to reach the same cluster).",
		Example: "  runr regions set eu-1 --set status=maintenance\n" +
			"  runr regions set eu-1 --set request_timeout=900 --set ingress_trusted_ips=10.0.0.0/8,192.0.2.10\n" +
			"  runr regions set eu-1 --set internal_auth=audit\n" +
			"  runr regions set eu-1 --kubeconfig rotated.yaml",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			pairs, err := parseKeyValues("--set", sets)
			if err != nil {
				return err
			}
			var update runr.RegionUpdateInput
			settings := map[string]any{}
			monitoring := runr.RegionMonitoringInput{}
			for k, v := range pairs {
				key := strings.ReplaceAll(strings.ToLower(k), "-", "_")
				v = strings.TrimSpace(v)
				switch key {
				case "status", "region_status":
					ok := false
					for _, s := range runr.RegionStatuses {
						ok = ok || s == v
					}
					if !ok {
						return usageErrorf("--set status must be active, inactive or maintenance")
					}
					update.RegionStatus = &v
				case "request_timeout":
					n, err := strconv.Atoi(v)
					if err != nil || n < 1 || n > 3600 {
						return usageErrorf("--set request_timeout must be a number of seconds between 1 and 3600")
					}
					settings[key] = n
				case "agent_ws_url", "cert_manager_cluster_issuer", "cert_manager_acme_email":
					if v == "" {
						settings[key] = nil
					} else {
						settings[key] = v
					}
				case "cert_manager_issuer_mode":
					ok := false
					for _, m := range runr.CertManagerIssuerModes {
						ok = ok || m == v
					}
					if !ok {
						return usageErrorf("--set cert_manager_issuer_mode must be auto, acme or ca")
					}
					settings[key] = v
				case "internal_auth":
					// the API takes enforce or audit, never empty: an unset region enforces already
					if !slices.Contains(runr.InternalAuthModes, v) {
						return usageErrorf("--set internal_auth must be enforce or audit")
					}
					settings[key] = v
				case "ingress_trusted_ips":
					list := []string{}
					for _, item := range strings.Split(v, ",") {
						if item = strings.TrimSpace(item); item != "" {
							list = append(list, item)
						}
					}
					settings[key] = list
				case "prometheus_url", "loki_url", "loki_org_id":
					if v == "" {
						monitoring[key] = nil
					} else {
						monitoring[key] = v
					}
				default:
					return usageErrorf("--set takes KEY=VALUE with one of: %s", strings.Join(regionSetKeys, ", "))
				}
			}
			if kubeconfig != "" {
				kc, err := rlcReadKubeconfig(app, "kubeconfig", kubeconfig)
				if err != nil {
					return err
				}
				update.Kubeconfig = &kc
			}
			if update.RegionStatus == nil && update.Kubeconfig == nil && len(settings) == 0 && len(monitoring) == 0 {
				return usageErrorf("give at least one --set KEY=VALUE or --kubeconfig FILE")
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			var d *runr.RegionDetail
			if update.RegionStatus != nil || update.Kubeconfig != nil {
				if d, err = c.UpdateRegion(ctx, reg.ID, update); err != nil {
					return err
				}
			}
			if len(settings) > 0 {
				res, err := c.UpdateRegionSettings(ctx, reg.ID, settings)
				if err != nil {
					return err
				}
				d = res.Region
			}
			if len(monitoring) > 0 {
				res, err := c.UpdateRegionMonitoring(ctx, reg.ID, monitoring)
				if err != nil {
					return err
				}
				d = res.Region
			}
			if d == nil {
				if d, err = c.GetRegionDetail(ctx, reg.ID); err != nil {
					return err
				}
			}
			app.Progress("Updated region %s.", reg.Name)
			return app.Printer().Print(d, func(t *output.TableWriter) { printRegionDetail(t, d) })
		},
	}
	cmd.Flags().StringArrayVar(&sets, "set", nil, "KEY=VALUE of a setting (repeatable)")
	cmd.Flags().StringVar(&kubeconfig, "kubeconfig", "", "replace the region's kubeconfig with this file (- for stdin)")
	return cmd
}

func newRegionRmCmd(app *App) *cobra.Command {
	var yes bool
	cmd := &cobra.Command{
		Use:     "rm REGION",
		Aliases: []string{"delete", "remove"},
		Short:   "Remove a region from RunR (regions.manage)",
		Long: "Remove a region from RunR. RunR forgets the cluster and its kubeconfig; what runs in the\n" +
			"cluster stays, so uninstall Knative and the region's services first to clean the cluster up.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			question := "Remove region " + reg.Name + " from RunR"
			if reg.ProjectCount > 0 {
				question += fmt.Sprintf(" (it runs %s)", countLabel(reg.ProjectCount, "project"))
			}
			if err := app.confirm(question, yes); err != nil {
				return err
			}
			if err := c.DeleteRegion(ctx, reg.ID); err != nil {
				return err
			}
			app.Progress("Removed region %s.", reg.Name)
			return app.Printer().Print(map[string]any{"region": reg.Name, "id": reg.ID, "deleted": true}, func(*output.TableWriter) {})
		},
	}
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}

func newRegionStatusCmd(app *App) *cobra.Command {
	var refresh bool
	cmd := &cobra.Command{
		Use:   "status [REGION]",
		Short: "Check a region's cluster and its Knative installation now",
		Long: "Check the region's cluster: whether RunR reaches it and Knative Serving and the region's\n" +
			"Traefik are ready (ready, degraded with the reasons, not_installed). While an install runs or\n" +
			"after an error the recorded state is shown; --refresh checks the cluster anyway.",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			st, err := c.RegionKnativeStatus(cmd.Context(), reg.ID, refresh)
			if err != nil {
				return err
			}
			return app.Printer().Print(st, func(t *output.TableWriter) {
				t.KV("Region", reg.Name)
				t.KV("Status", output.Dash(st.Status))
				if msg := lastLogLine(st.Message); msg != "" {
					t.KV("Message", output.Truncate(msg, 160))
				}
				keys := make([]string, 0, len(st.Details))
				for k := range st.Details {
					keys = append(keys, k)
				}
				sort.Strings(keys)
				for _, k := range keys {
					t.KV(strings.ReplaceAll(k, "_", " "), fmt.Sprint(st.Details[k]))
				}
				if d := st.Region; d != nil {
					t.KV("Knative operator", rlcStatus(d.OperatorStatus, d.OperatorStatusMessage))
					t.KV("Knative Serving", rlcStatus(d.ServingStatus, d.ServingStatusMessage))
				}
			})
		},
	}
	cmd.Flags().BoolVar(&refresh, "refresh", false, "check the cluster even while an install runs or after an error")
	return cmd
}

// writeSecretFile writes data to path with mode 0600: a new file, or an existing one with force.
func writeSecretFile(path string, data []byte, force bool) error {
	flags := os.O_WRONLY | os.O_CREATE | os.O_EXCL
	if force {
		flags = os.O_WRONLY | os.O_CREATE | os.O_TRUNC
	}
	f, err := os.OpenFile(path, flags, 0o600)
	if err != nil {
		if errors.Is(err, os.ErrExist) {
			return usageErrorf("%s exists: --force overwrites it", path)
		}
		return err
	}
	// An existing file keeps its mode on open: restrict it as well.
	if err := f.Chmod(0o600); err != nil && force {
		_ = f.Close()
		return err
	}
	if _, err := f.Write(data); err != nil {
		_ = f.Close()
		return err
	}
	return f.Close()
}

func newRegionKubeconfigCmd(app *App) *cobra.Command {
	var file string
	var stdout, force bool
	cmd := &cobra.Command{
		Use:   "kubeconfig REGION",
		Short: "Save the kubeconfig RunR uses for a region (mode 0600) (regions.manage)",
		Long: "Download the kubeconfig RunR uses for the region's cluster. It is a credential: it is\n" +
			"written to a file only you can read (mode 0600; default <region>-kubeconfig.yaml, -f FILE\n" +
			"another one; an existing file is kept unless --force) and never shown in runr's output or\n" +
			"trace. --stdout prints it instead, for a pipe.",
		Example: "  runr regions kubeconfig eu-1\n" +
			"  runr regions kubeconfig eu-1 -f ~/.kube/eu-1.yaml --force\n" +
			"  runr regions kubeconfig eu-1 --stdout | kubectl --kubeconfig /dev/stdin get nodes",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if stdout && file != "" {
				return usageErrorf("give -f FILE or --stdout, not both")
			}
			if stdout && app.Printer().Machine() {
				return usageErrorf("--stdout prints the kubeconfig itself: leave out -o")
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			if !stdout && file == "" {
				file = reg.Name + "-kubeconfig.yaml"
			}
			if !stdout {
				// Refuse an existing file before the download.
				if _, err := os.Stat(file); err == nil && !force {
					return usageErrorf("%s exists: --force overwrites it", file)
				}
			}
			data, err := c.RegionKubeconfig(ctx, reg.ID)
			if err != nil {
				return err
			}
			if stdout {
				_, err := app.Out.Write(data)
				return err
			}
			if err := writeSecretFile(file, data, force); err != nil {
				return err
			}
			app.Progress("Wrote the kubeconfig of %s to %s (mode 0600).", reg.Name, file)
			return app.Printer().Print(map[string]any{"region": reg.Name, "file": file, "bytes": len(data)}, func(*output.TableWriter) {})
		},
	}
	cmd.Flags().StringVarP(&file, "file", "f", "", "file to write (default <region>-kubeconfig.yaml)")
	cmd.Flags().BoolVar(&stdout, "stdout", false, "print the kubeconfig to stdout instead of writing a file")
	cmd.Flags().BoolVar(&force, "force", false, "overwrite an existing file")
	return cmd
}

func newRegionNodesCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "nodes [REGION]",
		Aliases: []string{"workers"},
		Short:   "The worker nodes of a region and the ones projects run on",
		Long: "List the worker nodes of the region's cluster (control-plane nodes are left out) with their\n" +
			"capacity and whether projects run on them. `runr regions nodes set` chooses those nodes.",
		Example: "  runr regions nodes eu-1\n" +
			"  runr regions nodes set eu-1 worker-1 worker-2 --yes",
		Args: cobra.MaximumNArgs(1),
	}
	ls := &cobra.Command{
		Use:     "ls [REGION]",
		Aliases: []string{"list"},
		Short:   "List the worker nodes of a region",
		Args:    cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, reg, err := radmRegion(cmd.Context(), app, radmArg(args))
			if err != nil {
				return err
			}
			nodes, err := c.RegionNodes(cmd.Context(), reg.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(nodes, func(t *output.TableWriter) {
				regionNodeRows(t, nodes)
				selected := 0
				for _, n := range nodes {
					if n.IsSelected {
						selected++
					}
				}
				if selected == 0 && len(nodes) > 0 {
					t.Note("No node is reserved for projects: they run on every schedulable node.")
				}
			})
		},
	}
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE
	cmd.AddCommand(newRegionNodesSetCmd(app))
	return cmd
}

func newRegionNodesSetCmd(app *App) *cobra.Command {
	var all, yes bool
	cmd := &cobra.Command{
		Use:   "set REGION [NODE...]",
		Short: "Choose the nodes a region's projects run on (regions.manage)",
		Long: "Reserve nodes for the region's projects: they get the worker role and a taint only projects\n" +
			"tolerate. Nodes left out are released; --all reserves none, so projects run on every\n" +
			"schedulable node. The region's capacity follows the selection. Releasing nodes moves the\n" +
			"projects running there, so it asks for confirmation (--yes skips it).",
		Example: "  runr regions nodes set eu-1 worker-1 worker-2\n" +
			"  runr regions nodes set eu-1 --all --yes",
		Args: cobra.MinimumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			nodes := args[1:]
			if all == (len(nodes) > 0) {
				return usageErrorf("give the nodes, or --all for every schedulable node")
			}
			ctx := cmd.Context()
			c, reg, err := radmRegion(ctx, app, args[0])
			if err != nil {
				return err
			}
			current, err := c.RegionNodes(ctx, reg.ID)
			if err != nil {
				return err
			}
			known := map[string]bool{}
			var released []string
			for _, n := range current {
				known[n.Name] = true
				keep := false
				for _, want := range nodes {
					keep = keep || want == n.Name
				}
				if n.IsSelected && (all || !keep) {
					released = append(released, n.Name)
				}
			}
			for _, n := range nodes {
				if !known[n] {
					return usageErrorf("region %s has no worker node %s (runr regions nodes %s lists them)", reg.Name, n, reg.Name)
				}
			}
			if len(released) > 0 {
				if err := app.confirm("Release "+strings.Join(released, ", ")+" from the projects of "+reg.Name, yes); err != nil {
					return err
				}
			}
			res, err := c.SetRegionWorkers(ctx, reg.ID, nodes)
			if err != nil {
				return err
			}
			app.Progress("%s", res.Message)
			return app.Printer().Print(res, func(t *output.TableWriter) {
				t.KV("Region", reg.Name)
				workers := "every schedulable node"
				if len(nodes) > 0 {
					workers = strings.Join(nodes, ", ")
				}
				t.KV("Worker nodes", workers)
				t.KV("Capacity", fmt.Sprintf("%dm CPU, %s memory", res.TotalCPUm, rlcMiB(res.TotalMemoryMB)))
			})
		},
	}
	cmd.Flags().BoolVar(&all, "all", false, "reserve no node: projects run on every schedulable node")
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask before nodes are released")
	return cmd
}
