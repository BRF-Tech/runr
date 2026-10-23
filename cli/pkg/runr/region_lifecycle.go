package runr

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"time"
)

// The lifecycle of a region (KnativeRegionController, KnativeInstallController,
// KnativeUninstallController, KnativeNodeController): create, settings, kubeconfig, delete, the
// cluster checks before a create, the cluster status, the worker nodes and the Knative components.

// Install statuses of a region's Knative components (operator, serving, the whole install).
const (
	KnativeNotInstalled = "not_installed"
	KnativeInstalling   = "installing"
	KnativeUninstalling = "uninstalling"
	KnativeReady        = "ready"
	KnativeDegraded     = "degraded"
	KnativeError        = "error"
)

// Knative components a region installs: the operator, Serving, or both in one run.
const (
	KnativeComponentAll      = "all"
	KnativeComponentOperator = "operator"
	KnativeComponentServing  = "serving"
)

// RegionDNSInstruction is the DNS record a region's function domains need.
type RegionDNSInstruction struct {
	Wildcard string `json:"wildcard"`
	Type     string `json:"type,omitempty"`
	Target   string `json:"target,omitempty"`
}

// RegionDetail is the full region resource (KnativeRegionResource) that GET
// /knative-regions/{region}, create, update and most region operations answer.
type RegionDetail struct {
	Region
	ServerURL              string                `json:"server_url,omitempty"`
	HasKubeconfig          bool                  `json:"has_kubeconfig"`
	IsSynced               bool                  `json:"is_synced,omitempty"`
	ClusterStatus          string                `json:"cluster_status,omitempty"`
	ClusterStatusMessage   string                `json:"cluster_status_message,omitempty"`
	KnativeStatusMessage   string                `json:"knative_status_message,omitempty"`
	KnativeStatusCheckedAt string                `json:"knative_status_checked_at,omitempty"`
	FaaSCPUOvercommit      float64               `json:"faas_cpu_overcommit,omitempty"`
	FaaSMemoryOvercommit   float64               `json:"faas_memory_overcommit,omitempty"`
	SelectedWorkers        []string              `json:"selected_workers"`
	WorkerCount            int                   `json:"worker_count"`
	KnativeSettings        map[string]any        `json:"knative_settings,omitempty"`
	RedisMode              string                `json:"redis_mode,omitempty"`
	RedisStatus            string                `json:"knative_redis_status,omitempty"`
	RedisAddress           string                `json:"redis_address,omitempty"`
	RedisStatusMessage     string                `json:"knative_redis_status_message,omitempty"`
	ExternalIP             string                `json:"external_ip,omitempty"`
	DNSInstruction         *RegionDNSInstruction `json:"dns_instruction,omitempty"`
	FunctionTLSMode        string                `json:"function_tls_mode,omitempty"`
	FunctionTLSExpiresAt   string                `json:"function_tls_expires_at,omitempty"`
	OperatorStatus         string                `json:"knative_operator_status,omitempty"`
	OperatorStatusMessage  string                `json:"knative_operator_status_message,omitempty"`
	OperatorNamespace      string                `json:"knative_operator_namespace,omitempty"`
	ServingStatus          string                `json:"knative_serving_status,omitempty"`
	ServingStatusMessage   string                `json:"knative_serving_status_message,omitempty"`
	ServingNamespace       string                `json:"knative_serving_namespace,omitempty"`
	HasServing             bool                  `json:"has_serving"`
	RegistryMode           string                `json:"registry_mode,omitempty"`
	RegistryAddress        string                `json:"registry_address,omitempty"`
	RegistryStorageType    string                `json:"registry_storage_type,omitempty"`
	RegistryStatus         string                `json:"registry_status,omitempty"`
	RegistryStatusMessage  string                `json:"registry_status_message,omitempty"`
	RegistryNamespace      string                `json:"registry_namespace,omitempty"`
	HasRegistry            bool                  `json:"has_registry"`
	DemoStatus             string                `json:"region_demo_status,omitempty"`
	WasmStatus             string                `json:"sandbox_wasm_status,omitempty"`
	MigrationStatus        string                `json:"migration_status,omitempty"`
	MigrationStartedAt     string                `json:"migration_started_at,omitempty"`
	MigrationOldIP         string                `json:"migration_old_ip,omitempty"`
	IsMigrating            bool                  `json:"is_migrating"`
}

// UnmarshalJSON tolerates the loose parts of the resource: knative_settings as [] (an empty PHP
// array), dns_instruction as null.
func (d *RegionDetail) UnmarshalJSON(b []byte) error {
	type plain RegionDetail
	var raw struct {
		plain
		KnativeSettings json.RawMessage `json:"knative_settings"`
		SelectedWorkers json.RawMessage `json:"selected_workers"`
	}
	if err := json.Unmarshal(b, &raw); err != nil {
		return err
	}
	*d = RegionDetail(raw.plain)
	d.KnativeSettings = nil
	if s := raw.KnativeSettings; len(s) > 0 && s[0] == '{' {
		_ = json.Unmarshal(s, &d.KnativeSettings)
	}
	d.SelectedWorkers = []string{}
	if s := raw.SelectedWorkers; len(s) > 0 && s[0] == '[' {
		_ = json.Unmarshal(s, &d.SelectedWorkers)
	}
	return nil
}

// regionAnswer is {"message", "region": …}: what the region operations answer.
type regionAnswer struct {
	Message string        `json:"message"`
	Adopted bool          `json:"adopted,omitempty"`
	Warning string        `json:"warning,omitempty"`
	Region  *RegionDetail `json:"region"`
}

// RegionOperation is the answer of a queued region operation (an install, a removal, a change):
// a message and the region as it is now.
type RegionOperation struct {
	Message string `json:"message"`
	// Adopted is set when the API found the component already running and took it over.
	Adopted bool `json:"adopted,omitempty"`
	// Warning is shown before an install that restarts the container runtime of the nodes.
	Warning string        `json:"warning,omitempty"`
	Region  *RegionDetail `json:"region,omitempty"`
}

func regionPath(regionID, suffix string) string {
	return "/knative-regions/" + seg(regionID) + suffix
}

func (c *Client) regionOperation(ctx context.Context, method, path string, body any) (*RegionOperation, error) {
	var out regionAnswer
	if err := c.do(ctx, request{method: method, path: path, body: body}, &out); err != nil {
		return nil, err
	}
	return &RegionOperation{Message: out.Message, Adopted: out.Adopted, Warning: out.Warning, Region: out.Region}, nil
}

// GetRegionDetail returns the full resource of a region (regions.view).
func (c *Client) GetRegionDetail(ctx context.Context, regionID string) (*RegionDetail, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: regionPath(regionID, "")}, &raw); err != nil {
		return nil, err
	}
	var d RegionDetail
	if err := decodeData(raw, &d); err != nil {
		return nil, fmt.Errorf("decode region: %w", err)
	}
	return &d, nil
}

// RegionCreateInput is the body of POST /knative-regions. Kubeconfig is a credential: the API
// stores it encrypted and the client's trace never shows it.
type RegionCreateInput struct {
	// Name is also the namespace the region's projects run in.
	Name       string `json:"name"`
	Kubeconfig string `json:"kubeconfig"`
	// Nodes are the worker nodes projects run on (tainted for them); empty: every schedulable
	// node.
	Nodes    []string `json:"nodes"`
	IsActive *bool    `json:"is_active,omitempty"`
	// RedisMode is managed (default), in_cluster, external or none; in_cluster and external need
	// RedisAddress.
	RedisMode     string `json:"redis_mode,omitempty"`
	RedisAddress  string `json:"redis_address,omitempty"`
	RedisPassword string `json:"redis_password,omitempty"`
	// PrometheusURL, LokiURL reuse an in-cluster Prometheus / Loki instead of installing RunR's.
	PrometheusURL string `json:"prometheus_url,omitempty"`
	LokiURL       string `json:"loki_url,omitempty"`
	LokiOrgID     string `json:"loki_org_id,omitempty"`
}

// CreateRegion connects a cluster as a new region (regions.manage): the API checks the
// kubeconfig, creates the project namespace, selects the worker nodes and queues the Redis
// install.
func (c *Client) CreateRegion(ctx context.Context, in RegionCreateInput) (*RegionDetail, error) {
	if in.Nodes == nil {
		in.Nodes = []string{}
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPost, path: "/knative-regions", body: in}, &raw); err != nil {
		return nil, err
	}
	var d RegionDetail
	if err := decodeData(raw, &d); err != nil {
		return nil, fmt.Errorf("decode region: %w", err)
	}
	return &d, nil
}

// Region statuses a region can be set to.
var RegionStatuses = []string{"active", "inactive", "maintenance"}

// InternalAuthModes are the values of the region setting internal_auth: the internal network
// refuses calls the callee's allow list does not admit (enforce, also when unset) or admits and
// logs them (audit) — docs/internal-network.md#enforce-and-audit.
var InternalAuthModes = []string{"enforce", "audit"}

// RegionUpdateInput is the body of PUT /knative-regions/{region}.
type RegionUpdateInput struct {
	// Kubeconfig replaces the region's kubeconfig.
	Kubeconfig *string `json:"kubeconfig,omitempty"`
	// RegionStatus is active, inactive or maintenance.
	RegionStatus *string `json:"region_status,omitempty"`
}

// UpdateRegion changes a region's status or kubeconfig (regions.manage).
func (c *Client) UpdateRegion(ctx context.Context, regionID string, in RegionUpdateInput) (*RegionDetail, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPut, path: regionPath(regionID, ""), body: in}, &raw); err != nil {
		return nil, err
	}
	var d RegionDetail
	if err := decodeData(raw, &d); err != nil {
		return nil, fmt.Errorf("decode region: %w", err)
	}
	return &d, nil
}

// RegionSettingsKeys are the settings PUT /knative-regions/{region}/settings takes
// (UpdateRegionSettingsRequest).
var RegionSettingsKeys = []string{"request_timeout", "agent_ws_url", "cert_manager_cluster_issuer", "cert_manager_issuer_mode",
	"cert_manager_acme_email", "ingress_trusted_ips"}

// RegionSettingsAnswer is the answer of PUT /knative-regions/{region}/settings.
type RegionSettingsAnswer struct {
	Message  string         `json:"message"`
	Settings map[string]any `json:"settings"`
	Region   *RegionDetail  `json:"region"`
}

// UpdateRegionSettings merges settings into the region's settings (regions.manage):
// request_timeout, agent_ws_url, cert_manager_cluster_issuer, cert_manager_issuer_mode,
// cert_manager_acme_email, ingress_trusted_ips.
func (c *Client) UpdateRegionSettings(ctx context.Context, regionID string, settings map[string]any) (*RegionSettingsAnswer, error) {
	var out RegionSettingsAnswer
	if err := c.do(ctx, request{method: http.MethodPut, path: regionPath(regionID, "/settings"), body: settings}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// DeleteRegion removes a region from RunR (regions.manage). The cluster keeps its workloads.
func (c *Client) DeleteRegion(ctx context.Context, regionID string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: regionPath(regionID, "")}, nil)
}

// RegionKubeconfig downloads the kubeconfig RunR uses for a region (regions.manage, on a region
// the caller may change). It is a credential: store it with mode 0600.
func (c *Client) RegionKubeconfig(ctx context.Context, regionID string) ([]byte, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: regionPath(regionID, "/kubeconfig"),
		accept: "application/x-yaml, application/json"}, &raw); err != nil {
		return nil, err
	}
	return raw, nil
}

// KubeconfigCheck is the answer of POST /knative-regions/validate-kubeconfig.
type KubeconfigCheck struct {
	Valid bool `json:"valid"`
	// Version is the cluster's Kubernetes version (1.31).
	Version string `json:"version"`
	// Kubeconfig is the kubeconfig as RunR adapted it (a local address rewritten), when it did.
	Kubeconfig string `json:"kubeconfig,omitempty"`
}

// ValidateKubeconfig checks that RunR reaches a cluster with a kubeconfig and that its
// Kubernetes version is supported (regions.manage); a 422 names what is wrong.
func (c *Client) ValidateKubeconfig(ctx context.Context, kubeconfig string) (*KubeconfigCheck, error) {
	var out KubeconfigCheck
	err := c.do(ctx, request{method: http.MethodPost, path: "/knative-regions/validate-kubeconfig",
		body: map[string]string{"kubeconfig": kubeconfig}}, &out)
	if err != nil {
		return nil, err
	}
	return &out, nil
}

// RegionNode is a worker node of a cluster (control-plane nodes are left out).
type RegionNode struct {
	Name              string            `json:"name"`
	Status            string            `json:"status"`
	CPUm              int               `json:"cpu_m"`
	MemoryMB          int               `json:"memory_mb"`
	AvailableCPUm     int               `json:"available_cpu_m"`
	AvailableMemoryMB int               `json:"available_memory_mb"`
	IsSelected        bool              `json:"is_selected"`
	Labels            map[string]string `json:"labels,omitempty"`
}

// UnmarshalJSON tolerates labels encoded as [] (no labels).
func (n *RegionNode) UnmarshalJSON(b []byte) error {
	type plain RegionNode
	var raw struct {
		plain
		Labels json.RawMessage `json:"labels"`
	}
	if err := json.Unmarshal(b, &raw); err != nil {
		return err
	}
	*n = RegionNode(raw.plain)
	n.Labels = nil
	if l := raw.Labels; len(l) > 0 && l[0] == '{' {
		var m map[string]any
		if json.Unmarshal(l, &m) == nil {
			n.Labels = map[string]string{}
			for k, v := range m {
				n.Labels[k] = fmt.Sprint(v)
			}
		}
	}
	return nil
}

// PreviewNodes lists the worker nodes of a cluster before it becomes a region (regions.manage).
func (c *Client) PreviewNodes(ctx context.Context, kubeconfig string) ([]RegionNode, error) {
	var out struct {
		Nodes []RegionNode `json:"nodes"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: "/knative-regions/preview-nodes",
		body: map[string]string{"kubeconfig": kubeconfig}}, &out)
	if err != nil {
		return nil, err
	}
	if out.Nodes == nil {
		out.Nodes = []RegionNode{}
	}
	return out.Nodes, nil
}

// StorageClass is a StorageClass of a cluster.
type StorageClass struct {
	Name      string `json:"name"`
	IsDefault bool   `json:"is_default"`
}

// PreviewStorageClasses lists the StorageClasses of a cluster (regions.manage): the classes a
// registry volume can use.
func (c *Client) PreviewStorageClasses(ctx context.Context, kubeconfig string) ([]StorageClass, error) {
	var out struct {
		StorageClasses []StorageClass `json:"storageClasses"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: "/knative-regions/preview-storage-classes",
		body: map[string]string{"kubeconfig": kubeconfig}}, &out)
	if err != nil {
		return nil, err
	}
	if out.StorageClasses == nil {
		out.StorageClasses = []StorageClass{}
	}
	return out.StorageClasses, nil
}

// CheckNamespacePreview reports whether a namespace exists on a cluster before it becomes a
// region (regions.manage): a region's name is its project namespace, which must be free.
func (c *Client) CheckNamespacePreview(ctx context.Context, kubeconfig, name string) (bool, error) {
	var out struct {
		Exists bool `json:"exists"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: "/knative-regions/check-namespace-preview",
		body: map[string]string{"kubeconfig": kubeconfig, "name": name}}, &out)
	return out.Exists, err
}

// RegionClusterStatus is the answer of GET /knative-regions/{region}/knative-status: the live
// state of the cluster's Knative installation.
type RegionClusterStatus struct {
	// Status is ready, degraded, not_installed, installing, uninstalling or error.
	Status  string         `json:"status"`
	Message string         `json:"message"`
	Details map[string]any `json:"details,omitempty"`
	Region  *RegionDetail  `json:"region,omitempty"`
}

// UnmarshalJSON tolerates details encoded as [] (no details).
func (s *RegionClusterStatus) UnmarshalJSON(b []byte) error {
	type plain RegionClusterStatus
	var raw struct {
		plain
		Details json.RawMessage `json:"details"`
	}
	if err := json.Unmarshal(b, &raw); err != nil {
		return err
	}
	*s = RegionClusterStatus(raw.plain)
	s.Details = nil
	if d := raw.Details; len(d) > 0 && d[0] == '{' {
		_ = json.Unmarshal(d, &s.Details)
	}
	return nil
}

// RegionKnativeStatus checks the region's cluster (regions.view). An install in progress or a
// recorded error is answered from the record unless force is set.
func (c *Client) RegionKnativeStatus(ctx context.Context, regionID string, force bool) (*RegionClusterStatus, error) {
	q := url.Values{}
	if force {
		q.Set("force", "1")
	}
	var out RegionClusterStatus
	if err := c.do(ctx, request{method: http.MethodGet, path: regionPath(regionID, "/knative-status"), query: q}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// KnativeComponent is the install state of the Knative operator or of Knative Serving.
type KnativeComponent struct {
	Status       string `json:"status"`
	Message      string `json:"message,omitempty"`
	Namespace    string `json:"namespace,omitempty"`
	IngressClass string `json:"ingress_class,omitempty"`
	Version      string `json:"version,omitempty"`
}

// RegionComponents is GET /knative-regions/{region}/component-status.
type RegionComponents struct {
	Operator KnativeComponent `json:"operator"`
	Serving  KnativeComponent `json:"serving"`
	Region   *RegionDetail    `json:"region,omitempty"`
}

// RegionComponents returns the recorded install state of a region's Knative operator and
// Serving (regions.view).
func (c *Client) RegionComponents(ctx context.Context, regionID string) (*RegionComponents, error) {
	var out RegionComponents
	if err := c.do(ctx, request{method: http.MethodGet, path: regionPath(regionID, "/component-status")}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// KnativeInstallOptions are the settings of an operator or Serving install.
type KnativeInstallOptions struct {
	// Namespace of the component (default knative-operator / knative-serving).
	Namespace string `json:"namespace,omitempty"`
	// Version of Knative Serving (a minor version from KnativeVersions; default: the newest
	// the cluster supports).
	Version string `json:"version,omitempty"`
}

// InstallKnative queues the install of a Knative component (regions.manage): all (the operator,
// then Serving), operator or serving. An operator or Serving already running in the namespace is
// adopted instead (RegionOperation.Adopted).
func (c *Client) InstallKnative(ctx context.Context, regionID, component string, opts KnativeInstallOptions) (*RegionOperation, error) {
	switch component {
	case KnativeComponentAll, "":
		return c.regionOperation(ctx, http.MethodPost, regionPath(regionID, "/install-knative"), nil)
	case KnativeComponentOperator:
		var body any
		if opts.Namespace != "" {
			body = map[string]string{"namespace": opts.Namespace}
		}
		return c.regionOperation(ctx, http.MethodPost, regionPath(regionID, "/install-operator"), body)
	case KnativeComponentServing:
		var body any
		if opts.Namespace != "" || opts.Version != "" {
			body = opts
		}
		return c.regionOperation(ctx, http.MethodPost, regionPath(regionID, "/install-serving"), body)
	}
	return nil, fmt.Errorf("unknown Knative component %q", component)
}

// UpgradeKnativeServing queues the upgrade of a region's Knative Serving to the next minor
// version (regions.manage): version is one minor above the running one and supported by the
// cluster (KnativeVersions). The operator follows when it is older.
func (c *Client) UpgradeKnativeServing(ctx context.Context, regionID, version string) (*RegionOperation, error) {
	return c.regionOperation(ctx, http.MethodPost, regionPath(regionID, "/upgrade-serving"), map[string]string{"version": version})
}

// UninstallKnative queues the removal of a Knative component (regions.manage): all, operator
// (refused while Serving is installed) or serving. removeTraefik also removes the region's
// Traefik when it still serves other Ingresses (all and serving).
func (c *Client) UninstallKnative(ctx context.Context, regionID, component string, removeTraefik bool) (*RegionOperation, error) {
	var body any
	if removeTraefik {
		body = map[string]bool{"remove_traefik": true}
	}
	switch component {
	case KnativeComponentAll, "":
		return c.regionOperation(ctx, http.MethodPost, regionPath(regionID, "/uninstall-knative"), body)
	case KnativeComponentOperator:
		return c.regionOperation(ctx, http.MethodPost, regionPath(regionID, "/uninstall-operator"), nil)
	case KnativeComponentServing:
		return c.regionOperation(ctx, http.MethodPost, regionPath(regionID, "/uninstall-serving"), body)
	}
	return nil, fmt.Errorf("unknown Knative component %q", component)
}

// KnativeAdoption is one component of a detection: adopted with its namespace, or nil.
type KnativeAdoption struct {
	Adopted   bool   `json:"adopted"`
	Namespace string `json:"namespace,omitempty"`
}

// KnativeDetection is the answer of POST /knative-regions/{region}/detect-components.
type KnativeDetection struct {
	Operator *KnativeAdoption `json:"operator"`
	Serving  *KnativeAdoption `json:"serving"`
	Region   *RegionDetail    `json:"region,omitempty"`
}

// DetectKnative looks for a Knative operator and Serving already running on the cluster and
// adopts them (regions.manage).
func (c *Client) DetectKnative(ctx context.Context, regionID string) (*KnativeDetection, error) {
	var out KnativeDetection
	if err := c.do(ctx, request{method: http.MethodPost, path: regionPath(regionID, "/detect-components")}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// RegionNamespaceExists reports whether a namespace exists on the region's cluster
// (regions.view).
func (c *Client) RegionNamespaceExists(ctx context.Context, regionID, name string) (bool, error) {
	var out struct {
		Exists bool `json:"exists"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: regionPath(regionID, "/check-namespace"), query: url.Values{"name": {name}}}, &out)
	return out.Exists, err
}

// KnativeVersions lists the Knative minor versions the region's Kubernetes version supports,
// newest first (regions.view).
func (c *Client) KnativeVersions(ctx context.Context, regionID string) ([]string, error) {
	var out struct {
		Versions []string `json:"versions"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: regionPath(regionID, "/compatible-versions")}, &out); err != nil {
		return nil, err
	}
	if out.Versions == nil {
		out.Versions = []string{}
	}
	return out.Versions, nil
}

// RegionNodes lists the worker nodes of a region's cluster with their capacity and whether
// projects run on them (regions.view).
func (c *Client) RegionNodes(ctx context.Context, regionID string) ([]RegionNode, error) {
	var out struct {
		Nodes []RegionNode `json:"nodes"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: regionPath(regionID, "/nodes")}, &out); err != nil {
		return nil, err
	}
	if out.Nodes == nil {
		out.Nodes = []RegionNode{}
	}
	return out.Nodes, nil
}

// RegionWorkers is the answer of POST /knative-regions/{region}/workers.
type RegionWorkers struct {
	Message       string        `json:"message"`
	TotalCPUm     int           `json:"total_cpu_m"`
	TotalMemoryMB int           `json:"total_memory_mb"`
	Region        *RegionDetail `json:"region,omitempty"`
}

// SetRegionWorkers selects the nodes projects run on (regions.manage): they are tainted for
// projects, nodes left out are released. An empty list lets projects run on every schedulable
// node. The region's capacity follows the selection.
func (c *Client) SetRegionWorkers(ctx context.Context, regionID string, nodes []string) (*RegionWorkers, error) {
	if nodes == nil {
		nodes = []string{}
	}
	var out RegionWorkers
	err := c.do(ctx, request{method: http.MethodPost, path: regionPath(regionID, "/workers"), body: map[string]any{"nodes": nodes}}, &out)
	if err != nil {
		return nil, err
	}
	return &out, nil
}

// knativeStatusOf returns the status field of a component on a region resource.
func knativeStatusOf(d *RegionDetail, component string) (string, string) {
	switch component {
	case KnativeComponentOperator:
		return orNotInstalled(d.OperatorStatus), d.OperatorStatusMessage
	case KnativeComponentServing:
		return orNotInstalled(d.ServingStatus), d.ServingStatusMessage
	}
	return orNotInstalled(d.KnativeStatus), d.KnativeStatusMessage
}

func orNotInstalled(s string) string {
	if s == "" {
		return KnativeNotInstalled
	}
	return s
}

// WaitKnative polls a region until the install or removal of a Knative component ends: any
// status but installing / uninstalling, a *FailedError for error.
func (c *Client) WaitKnative(ctx context.Context, regionID, regionName, component string, opts WaitOptions) (*RegionDetail, error) {
	if component == "" {
		component = KnativeComponentAll
	}
	feature := map[string]string{KnativeComponentAll: "Knative", KnativeComponentOperator: "the Knative operator",
		KnativeComponentServing: "Knative Serving"}[component]
	return c.waitRegion(ctx, regionID, regionName, feature, opts,
		func(d *RegionDetail) (string, string) { return knativeStatusOf(d, component) },
		[]string{KnativeInstalling, KnativeUninstalling})
}

// waitRegion polls GET /knative-regions/{region} until status(d) leaves the busy states; the
// status error ends it with a *FailedError naming feature ("Knative serving") and the region.
func (c *Client) waitRegion(ctx context.Context, regionID, regionName, feature string, opts WaitOptions,
	status func(*RegionDetail) (string, string), busy []string) (*RegionDetail, error) {
	what := feature + " of " + regionName
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	last := ""
	for {
		d, err := c.GetRegionDetail(ctx, regionID)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		st, msg := status(d)
		inProgress := false
		for _, b := range busy {
			inProgress = inProgress || st == b
		}
		if !inProgress {
			if st == KnativeError {
				return d, &FailedError{What: feature + " of region", Ref: regionName, Status: st, Message: lastLine(msg)}
			}
			return d, nil
		}
		if st != last {
			last = st
			if opts.Progress != nil {
				opts.Progress(last)
			}
		}
		select {
		case <-ctx.Done():
			return d, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
	}
}
