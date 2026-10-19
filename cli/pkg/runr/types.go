package runr

import (
	"bytes"
	"encoding/json"
	"fmt"
	"sort"
	"strconv"
	"strings"
	"time"
)

// Service types (Project.ServiceType).
const (
	TypeFaaS = "faas" // function: a flat set of files, one handler
	TypeXaaS = "xaas" // single-file script with a handler
	TypeCaaS = "caas" // container image
)

// Trigger types (ProjectConfig.TriggerType).
const (
	TriggerHTTP     = "http"
	TriggerSchedule = "schedule"
	TriggerQueue    = "queue"
)

// Deployment statuses.
const (
	DeploymentPending      = "pending"
	DeploymentRunning      = "running"
	DeploymentActive       = "active"
	DeploymentShuttingDown = "shutting_down"
	DeploymentShutDown     = "shut_down"
	DeploymentFailed       = "failed"
)

// Version build statuses.
const (
	BuildPending  = "pending"
	BuildBuilding = "building"
	BuildReady    = "ready"
	BuildFailed   = "failed"
	BuildPruned   = "pruned"
)

// Project is ProjectResource.
type Project struct {
	ID               string            `json:"id"`
	Name             string            `json:"name"`
	Slug             string            `json:"slug"`
	ServiceType      string            `json:"service_type"`
	Runtime          string            `json:"runtime,omitempty"`
	RuntimeVersion   string            `json:"runtime_version,omitempty"`
	Description      string            `json:"description,omitempty"`
	RegionID         string            `json:"knative_region_id,omitempty"`
	Region           *Ref              `json:"region,omitempty"`
	Config           ProjectConfig     `json:"config"`
	EndpointType     string            `json:"endpoint_type,omitempty"`
	EndpointURL      string            `json:"endpoint_url,omitempty"`
	LatestVersion    *Version          `json:"latest_version,omitempty"`
	LatestDeployment *DeploymentStatus `json:"latest_deployment,omitempty"`
	RuntimeEOL       string            `json:"runtime_eol,omitempty"`
	// Stopped is set while an administrator has stopped the project (APIs of 0.5.7 and later).
	Stopped   *ProjectStop `json:"stopped,omitempty"`
	CreatedAt *time.Time   `json:"created_at,omitempty"`
}

// Stop levels (ProjectStop.Level).
const (
	StopLevelPlatform     = "platform"     // a platform administrator stopped it; only one resumes it
	StopLevelOrganization = "organization" // an administrator of the organization stopped it
)

// ProjectStop is ProjectResource.stopped: the project serves nothing and cannot be deployed until
// it is resumed. By is nil for a platform stop seen by a member (the platform administrator is not
// named); Level is empty from APIs before 0.5.8, whose stops are the organization's.
type ProjectStop struct {
	At     *time.Time `json:"at,omitempty"`
	By     *Ref       `json:"by,omitempty"`
	Reason string     `json:"reason,omitempty"`
	Level  string     `json:"level,omitempty"`
}

// ByPlatform reports whether a platform administrator stopped the project.
func (s *ProjectStop) ByPlatform() bool { return s != nil && s.Level == StopLevelPlatform }

// RegionName returns the project's region name, or its id when the name is not loaded.
func (p *Project) RegionName() string {
	if p.Region != nil && p.Region.Name != "" {
		return p.Region.Name
	}
	return p.RegionID
}

// Ref is an {id, name} pair.
type Ref struct {
	ID   string `json:"id"`
	Name string `json:"name"`
}

// DeploymentStatus is ProjectResource.latest_deployment.
type DeploymentStatus struct {
	Status  string   `json:"status"`
	Version *Version `json:"version,omitempty"`
}

// ProjectConfig is the union of XaasConfigResource, FaasConfigResource and
// ContainerConfigResource. Raw keeps the config object as the API sent it.
type ProjectConfig struct {
	Filename        string   `json:"filename,omitempty"`
	Handler         string   `json:"handler,omitempty"`
	Image           string   `json:"image,omitempty"`
	Command         []string `json:"command,omitempty"`
	Args            []string `json:"args,omitempty"`
	Ports           FlexInts `json:"ports,omitempty"`
	EnvVars         EnvMap   `json:"env_vars,omitempty"`
	MemoryMB        *int     `json:"memory_mb,omitempty"`
	CPU             string   `json:"cpu,omitempty"`
	TimeoutSeconds  *int     `json:"timeout_seconds,omitempty"`
	ImagePullPolicy string   `json:"image_pull_policy,omitempty"`
	TriggerType     string   `json:"trigger_type,omitempty"`
	Schedule        string   `json:"schedule,omitempty"`
	QueueStream     string   `json:"queue_stream,omitempty"`
	ScaleToZero     *bool    `json:"scale_to_zero,omitempty"`
	MinScale        *int     `json:"min_scale,omitempty"`
	MaxScale        *int     `json:"max_scale,omitempty"`
	SecretEnvKeys   []string `json:"secret_env_keys,omitempty"`
	// EnvVarsHidden is set when the caller may not read the variables (no env.view):
	// EnvVars is then empty and must not be written back.
	EnvVarsHidden bool      `json:"env_vars_hidden,omitempty"`
	Raw           RawObject `json:"-"`
}

// UnmarshalJSON decodes the config and keeps the raw object.
func (c *ProjectConfig) UnmarshalJSON(b []byte) error {
	type plain ProjectConfig
	var p plain
	trimmed := bytes.TrimSpace(b)
	if bytes.Equal(trimmed, []byte("null")) || bytes.Equal(trimmed, []byte("[]")) {
		*c = ProjectConfig{}
		return nil
	}
	if err := json.Unmarshal(b, &p); err != nil {
		return err
	}
	*c = ProjectConfig(p)
	var raw map[string]json.RawMessage
	if json.Unmarshal(b, &raw) == nil {
		c.Raw = raw
	}
	return nil
}

// MarshalJSON writes the config as the API sent it when it was decoded from an answer, and
// the typed fields otherwise.
func (c ProjectConfig) MarshalJSON() ([]byte, error) {
	if len(c.Raw) > 0 {
		return json.Marshal(map[string]json.RawMessage(c.Raw))
	}
	type plain ProjectConfig
	return json.Marshal(plain(c))
}

// Trigger returns the trigger type, "http" when unset.
func (c ProjectConfig) Trigger() string {
	if c.TriggerType == "" {
		return TriggerHTTP
	}
	return c.TriggerType
}

// RawObject is a JSON object kept undecoded.
type RawObject map[string]json.RawMessage

// Has reports whether the object has key.
func (r RawObject) Has(key string) bool {
	_, ok := r[key]
	return ok
}

// EnvMap is an environment variable map. PHP encodes an empty map as [], and values may
// be numbers or booleans; EnvMap accepts all of them and holds strings.
type EnvMap map[string]string

// UnmarshalJSON accepts an object, an empty array or null.
func (m *EnvMap) UnmarshalJSON(b []byte) error {
	trimmed := bytes.TrimSpace(b)
	if bytes.Equal(trimmed, []byte("null")) || (len(trimmed) > 0 && trimmed[0] == '[') {
		*m = EnvMap{}
		return nil
	}
	var raw map[string]any
	if err := json.Unmarshal(b, &raw); err != nil {
		return fmt.Errorf("env_vars: %w", err)
	}
	out := make(EnvMap, len(raw))
	for k, v := range raw {
		switch t := v.(type) {
		case nil:
			out[k] = ""
		case string:
			out[k] = t
		case bool:
			out[k] = strconv.FormatBool(t)
		case float64:
			out[k] = strconv.FormatFloat(t, 'f', -1, 64)
		default:
			enc, _ := json.Marshal(t)
			out[k] = string(enc)
		}
	}
	*m = out
	return nil
}

// Keys returns the variable names sorted.
func (m EnvMap) Keys() []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}

// FlexInts is a list of integers the API may encode as numbers or numeric strings.
type FlexInts []int

// UnmarshalJSON accepts [8080, "9090"] and null.
func (f *FlexInts) UnmarshalJSON(b []byte) error {
	var raw []any
	if err := json.Unmarshal(b, &raw); err != nil {
		return err
	}
	out := make(FlexInts, 0, len(raw))
	for _, v := range raw {
		switch t := v.(type) {
		case float64:
			out = append(out, int(t))
		case string:
			n, err := strconv.Atoi(strings.TrimSpace(t))
			if err != nil {
				return fmt.Errorf("port %q is not a number", t)
			}
			out = append(out, n)
		}
	}
	*f = out
	return nil
}

// Version is VersionResource: one saved state of a project's code and config.
type Version struct {
	ID             string         `json:"id"`
	Number         int            `json:"number"`
	Label          string         `json:"label"`
	CommitID       string         `json:"commit_id,omitempty"`
	CommitMessage  string         `json:"commit_message,omitempty"`
	ChangedFiles   []string       `json:"changed_files,omitempty"`
	ConfigSnapshot map[string]any `json:"config_snapshot,omitempty"`
	BuildStatus    string         `json:"build_status"`
	BuildError     string         `json:"build_error,omitempty"`
	CreatedBy      *Ref           `json:"created_by,omitempty"`
	CreatedAt      *time.Time     `json:"created_at,omitempty"`
}

// UnmarshalJSON tolerates config_snapshot / changed_files encoded as [] by PHP and
// created_by as a bare id.
func (v *Version) UnmarshalJSON(b []byte) error {
	type plain struct {
		ID             string          `json:"id"`
		Number         int             `json:"number"`
		Label          string          `json:"label"`
		CommitID       string          `json:"commit_id"`
		CommitMessage  string          `json:"commit_message"`
		ChangedFiles   []string        `json:"changed_files"`
		ConfigSnapshot json.RawMessage `json:"config_snapshot"`
		BuildStatus    string          `json:"build_status"`
		BuildError     string          `json:"build_error"`
		CreatedBy      json.RawMessage `json:"created_by"`
		CreatedAt      *time.Time      `json:"created_at"`
	}
	var p plain
	if err := json.Unmarshal(b, &p); err != nil {
		return err
	}
	*v = Version{
		ID: p.ID, Number: p.Number, Label: p.Label, CommitID: p.CommitID,
		CommitMessage: p.CommitMessage, ChangedFiles: p.ChangedFiles,
		BuildStatus: p.BuildStatus, BuildError: p.BuildError, CreatedAt: p.CreatedAt,
	}
	if v.Label == "" && v.Number > 0 {
		v.Label = "v" + strconv.Itoa(v.Number)
	}
	if len(p.ConfigSnapshot) > 0 && p.ConfigSnapshot[0] == '{' {
		_ = json.Unmarshal(p.ConfigSnapshot, &v.ConfigSnapshot)
	}
	if len(p.CreatedBy) > 0 && p.CreatedBy[0] == '{' {
		var r struct {
			ID   FlexID `json:"id"`
			Name string `json:"name"`
		}
		if json.Unmarshal(p.CreatedBy, &r) == nil {
			v.CreatedBy = &Ref{ID: r.ID.String(), Name: r.Name}
		}
	}
	return nil
}

// Deployment is DeploymentResource.
type Deployment struct {
	ID             string         `json:"id"`
	Status         string         `json:"status"`
	K8sJobName     string         `json:"k8s_job_name,omitempty"`
	DeployedBy     string         `json:"deployed_by,omitempty"`
	DeployConfig   map[string]any `json:"deploy_config,omitempty"`
	KnativeReady   *bool          `json:"knative_ready,omitempty"`
	KnativeMessage string         `json:"knative_message,omitempty"`
	ReadyAt        *time.Time     `json:"ready_at,omitempty"`
	Version        *Ref           `json:"version,omitempty"`
	VersionLabel   string         `json:"-"`
	CreatedAt      *time.Time     `json:"created_at,omitempty"`
}

// UnmarshalJSON tolerates deploy_config encoded as [] and reads version.label.
func (d *Deployment) UnmarshalJSON(b []byte) error {
	type plain struct {
		ID             string          `json:"id"`
		Status         string          `json:"status"`
		K8sJobName     string          `json:"k8s_job_name"`
		DeployedBy     string          `json:"deployed_by"`
		DeployConfig   json.RawMessage `json:"deploy_config"`
		KnativeReady   *bool           `json:"knative_ready"`
		KnativeMessage string          `json:"knative_message"`
		ReadyAt        *time.Time      `json:"ready_at"`
		Version        *struct {
			ID    string `json:"id"`
			Label string `json:"label"`
		} `json:"version"`
		CreatedAt *time.Time `json:"created_at"`
	}
	var p plain
	if err := json.Unmarshal(b, &p); err != nil {
		return err
	}
	*d = Deployment{
		ID: p.ID, Status: p.Status, K8sJobName: p.K8sJobName, DeployedBy: p.DeployedBy,
		KnativeReady: p.KnativeReady, KnativeMessage: p.KnativeMessage, ReadyAt: p.ReadyAt,
		CreatedAt: p.CreatedAt,
	}
	if len(p.DeployConfig) > 0 && p.DeployConfig[0] == '{' {
		_ = json.Unmarshal(p.DeployConfig, &d.DeployConfig)
	}
	if p.Version != nil {
		d.Version = &Ref{ID: p.Version.ID, Name: p.Version.Label}
		d.VersionLabel = p.Version.Label
	}
	return nil
}

// MarshalJSON writes version as {id, label}, the API's shape.
func (d Deployment) MarshalJSON() ([]byte, error) {
	type out struct {
		ID             string         `json:"id"`
		Status         string         `json:"status"`
		K8sJobName     string         `json:"k8s_job_name,omitempty"`
		DeployedBy     string         `json:"deployed_by,omitempty"`
		DeployConfig   map[string]any `json:"deploy_config,omitempty"`
		KnativeReady   *bool          `json:"knative_ready,omitempty"`
		KnativeMessage string         `json:"knative_message,omitempty"`
		ReadyAt        *time.Time     `json:"ready_at,omitempty"`
		Version        map[string]any `json:"version,omitempty"`
		CreatedAt      *time.Time     `json:"created_at,omitempty"`
	}
	o := out{
		ID: d.ID, Status: d.Status, K8sJobName: d.K8sJobName, DeployedBy: d.DeployedBy,
		DeployConfig: d.DeployConfig, KnativeReady: d.KnativeReady, KnativeMessage: d.KnativeMessage,
		ReadyAt: d.ReadyAt, CreatedAt: d.CreatedAt,
	}
	if d.Version != nil {
		o.Version = map[string]any{"id": d.Version.ID, "label": d.Version.Name}
	}
	return json.Marshal(o)
}

// Live reports whether the deployment serves traffic or is about to.
func (d *Deployment) Live() bool {
	switch d.Status {
	case DeploymentPending, DeploymentRunning, DeploymentActive:
		return true
	}
	return false
}

// Domain is ProjectDomainResource.
type Domain struct {
	ID               string      `json:"id"`
	ProjectID        string      `json:"project_id"`
	Domain           string      `json:"domain"`
	Status           string      `json:"status"`
	StatusMessage    string      `json:"status_message,omitempty"`
	TLSMode          string      `json:"tls_mode"`
	TLSExpiresAt     string      `json:"tls_expires_at,omitempty"`
	HTTPSCertificate string      `json:"https_certificate,omitempty"`
	TLSNote          string      `json:"tls_note,omitempty"`
	URL              string      `json:"url"`
	Verification     DNSRecord   `json:"verification"`
	DNSTargets       []DNSRecord `json:"dns_targets,omitempty"`
	CreatedAt        string      `json:"created_at,omitempty"`
	UpdatedAt        string      `json:"updated_at,omitempty"`
}

// DNSRecord is one DNS record the customer creates.
type DNSRecord struct {
	Type  string `json:"type"`
	Name  string `json:"name,omitempty"`
	Value string `json:"value"`
}

// DomainsMeta is the meta object of GET /projects/{project}/domains.
type DomainsMeta struct {
	Eligible          bool        `json:"eligible"`
	Reason            string      `json:"reason,omitempty"`
	CertManagerIssuer string      `json:"cert_manager_issuer,omitempty"`
	DefaultHost       string      `json:"default_host,omitempty"`
	DNSTargets        []DNSRecord `json:"dns_targets,omitempty"`
}

// Region is KnativeRegionResource (the fields every caller sees, synced regions included).
type Region struct {
	ID               string `json:"id"`
	Name             string `json:"name"`
	IsActive         bool   `json:"is_active"`
	IsDemo           bool   `json:"is_demo"`
	RegionStatus     string `json:"region_status,omitempty"`
	RegionMode       string `json:"region_mode,omitempty"`
	KnativeStatus    string `json:"knative_status,omitempty"`
	HasRedis         bool   `json:"has_redis,omitempty"`
	ProjectCount     int    `json:"project_count,omitempty"`
	TotalCPUm        int    `json:"total_cpu_m,omitempty"`
	TotalMemoryMB    int    `json:"total_memory_mb,omitempty"`
	UsedCPUm         int    `json:"used_cpu_m,omitempty"`
	UsedMemoryMB     int    `json:"used_memory_mb,omitempty"`
	ProjectNamespace string `json:"project_namespace,omitempty"`
}

// Status returns region_status, falling back to active/inactive.
func (r *Region) Status() string {
	if r.RegionStatus != "" {
		return r.RegionStatus
	}
	if r.IsActive {
		return "active"
	}
	return "inactive"
}

// User is the authenticated user (GET /auth/me).
type User struct {
	ID       FlexID `json:"id"`
	Name     string `json:"name"`
	Username string `json:"username"`
	Email    string `json:"email"`
}

// Token is an API token (GET /tokens).
type Token struct {
	ID         FlexID   `json:"id"`
	Name       string   `json:"name"`
	Abilities  []string `json:"abilities"`
	LastUsedAt string   `json:"last_used_at,omitempty"`
	ExpiresAt  string   `json:"expires_at,omitempty"`
	CreatedAt  string   `json:"created_at,omitempty"`
}

// FlexID is an identifier the API encodes as a number (users, tokens) or a string (ULIDs).
type FlexID string

// UnmarshalJSON accepts a JSON number or string.
func (f *FlexID) UnmarshalJSON(b []byte) error {
	b = bytes.TrimSpace(b)
	if bytes.Equal(b, []byte("null")) {
		*f = ""
		return nil
	}
	if len(b) > 0 && b[0] == '"' {
		var s string
		if err := json.Unmarshal(b, &s); err != nil {
			return err
		}
		*f = FlexID(s)
		return nil
	}
	var n json.Number
	if err := json.Unmarshal(b, &n); err != nil {
		return err
	}
	*f = FlexID(n.String())
	return nil
}

// String returns the id as text.
func (f FlexID) String() string { return string(f) }

// ExecutorImage is one runtime version the platform offers (GET /settings executor_images).
type ExecutorImage struct {
	ID        string `json:"id"`
	Runtime   string `json:"runtime"`
	Version   string `json:"version"`
	IsDefault bool   `json:"is_default"`
	SortOrder int    `json:"sort_order"`
	EOLAt     string `json:"eol_at,omitempty"`
}

// Logs is the answer of GET /projects/{project}/deployments/{deployment}/logs.
type Logs struct {
	Pods          []Pod  `json:"pods"`
	Logs          string `json:"logs"`
	LastTsMs      int64  `json:"last_ts_ms,omitempty"`
	Source        string `json:"source,omitempty"`
	Error         string `json:"error,omitempty"`
	SuggestStatus string `json:"suggest_status,omitempty"`
}

// Pod is one pod of a deployment.
type Pod struct {
	Name   string `json:"name"`
	Status string `json:"status"`
	Ready  bool   `json:"ready"`
}

// File is one code file of a faas project.
type File struct {
	Name    string `json:"name"`
	Content string `json:"content"`
}

// Page is the pagination of a list answer (Laravel paginators and resource collections).
type Page struct {
	CurrentPage int `json:"current_page"`
	LastPage    int `json:"last_page"`
	PerPage     int `json:"per_page"`
	Total       int `json:"total"`
}

// decodeList decodes a collection the API sends as {"data": […]} (resource collections,
// paginators) or as a bare array into out (a pointer to a slice). page, when not nil, is
// filled from meta (resource collections) or the paginator's own fields.
func decodeList(raw []byte, out any, page *Page) error {
	trimmed := bytes.TrimSpace(raw)
	if len(trimmed) == 0 {
		return nil
	}
	if trimmed[0] == '[' {
		return json.Unmarshal(trimmed, out)
	}
	var probe struct {
		Data json.RawMessage `json:"data"`
		Meta *Page           `json:"meta"`
	}
	if err := json.Unmarshal(trimmed, &probe); err != nil {
		return err
	}
	if page != nil {
		if probe.Meta != nil {
			*page = *probe.Meta
		} else {
			_ = json.Unmarshal(trimmed, page)
		}
	}
	if len(probe.Data) == 0 || probe.Data[0] != '[' {
		return nil
	}
	return json.Unmarshal(probe.Data, out)
}

// decodeData decodes a single resource that the API may or may not wrap in {"data": …}.
func decodeData(raw []byte, out any) error {
	var probe map[string]json.RawMessage
	if err := json.Unmarshal(raw, &probe); err == nil {
		if inner, ok := probe["data"]; ok && len(inner) > 0 && inner[0] == '{' {
			if _, hasID := probe["id"]; !hasID {
				return json.Unmarshal(inner, out)
			}
		}
	}
	return json.Unmarshal(raw, out)
}
