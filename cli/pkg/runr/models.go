package runr

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// Models and model services (docs/huggingface.md): the organization's model library (Hugging
// Face Hub models pinned to a revision, uploaded models, imports), offline copies in regions
// (snapshots), model services that serve a model with an engine on CPU or GPU, and the
// bindings that give a project's containers RUNR_MODEL_<ALIAS>_* variables.

// Model sources (AiModel.source).
const (
	ModelSourceHub    = "hub"
	ModelSourceUpload = "upload"
	ModelSourcePVC    = "pvc"
	ModelSourceOCI    = "oci"
)

// Model service statuses (AiModelService.status).
const (
	ModelServiceStopped         = "stopped"
	ModelServiceWaitingSnapshot = "waiting_snapshot"
	ModelServiceDeploying       = "deploying"
	ModelServiceReady           = "ready"
	ModelServiceFailed          = "failed"
)

// Snapshot statuses (AiModelSnapshot.status).
const (
	SnapshotPending  = "pending"
	SnapshotRunning  = "running"
	SnapshotReady    = "ready"
	SnapshotFailed   = "failed"
	SnapshotDeleting = "deleting"
)

// FlexInt64 is a number the API may send as a number, a numeric string or null.
type FlexInt64 int64

// UnmarshalJSON accepts 42, "42" and null.
func (f *FlexInt64) UnmarshalJSON(b []byte) error {
	b = bytes.TrimSpace(b)
	if len(b) == 0 || bytes.Equal(b, []byte("null")) {
		*f = 0
		return nil
	}
	s := strings.Trim(string(b), `"`)
	if s == "" {
		*f = 0
		return nil
	}
	n, err := strconv.ParseFloat(s, 64)
	if err != nil {
		return fmt.Errorf("%q is not a number", s)
	}
	*f = FlexInt64(n)
	return nil
}

// AIModel is AiModelResource: one model of the organization's library.
type AIModel struct {
	ID            string          `json:"id"`
	Name          string          `json:"name"`
	Source        string          `json:"source"`
	HubID         string          `json:"hub_id,omitempty"`
	Revision      string          `json:"revision,omitempty"`
	RevisionSHA   string          `json:"revision_sha,omitempty"`
	Task          string          `json:"task,omitempty"`
	Library       string          `json:"library,omitempty"`
	License       string          `json:"license,omitempty"`
	Gated         bool            `json:"gated"`
	Private       bool            `json:"private"`
	SizeBytes     FlexInt64       `json:"size_bytes,omitempty"`
	Parameters    FlexInt64       `json:"parameters,omitempty"`
	CardSummary   string          `json:"card_summary,omitempty"`
	Tags          []string        `json:"tags,omitempty"`
	SourceRef     string          `json:"source_ref,omitempty"`
	CreatedAt     string          `json:"created_at,omitempty"`
	Snapshots     []ModelSnapshot `json:"snapshots"`
	ServicesCount int             `json:"services_count"`
}

// SnapshotIn returns the model's snapshot in a region, if any.
func (m *AIModel) SnapshotIn(regionID string) *ModelSnapshot {
	for i := range m.Snapshots {
		if m.Snapshots[i].RegionID == regionID {
			return &m.Snapshots[i]
		}
	}
	return nil
}

// ModelSnapshot is AiModelSnapshotResource: the offline copy of a model in one region.
type ModelSnapshot struct {
	ID          string    `json:"id"`
	AIModelID   string    `json:"ai_model_id"`
	RegionID    string    `json:"knative_region_id"`
	RegionName  string    `json:"region_name,omitempty"`
	Source      string    `json:"source,omitempty"`
	Revision    string    `json:"revision,omitempty"`
	Status      string    `json:"status"`
	Message     string    `json:"message,omitempty"`
	SizeBytes   FlexInt64 `json:"size_bytes,omitempty"`
	FilesCount  int       `json:"files_count,omitempty"`
	StartedAt   string    `json:"started_at,omitempty"`
	CompletedAt string    `json:"completed_at,omitempty"`
}

// GPURequest is the GPU share of a model service ({count, type, memory_min}).
type GPURequest struct {
	Count int `json:"count"`
	// Type is a GPU product name (node label), MemoryMin the least GPU memory in MiB.
	Type      string `json:"type,omitempty"`
	MemoryMin int    `json:"memory_min,omitempty"`
}

// ModelServiceModel is the model summary inside a model service.
type ModelServiceModel struct {
	ID        string    `json:"id"`
	Name      string    `json:"name"`
	HubID     string    `json:"hub_id,omitempty"`
	Task      string    `json:"task,omitempty"`
	Library   string    `json:"library,omitempty"`
	SizeBytes FlexInt64 `json:"size_bytes,omitempty"`
	Source    string    `json:"source,omitempty"`
}

// Playground is the request kind and path a service's playground uses.
type Playground struct {
	Kind string `json:"kind"`
	Path string `json:"path"`
}

// ModelService is AiModelServiceResource: a model served by an engine in one region.
type ModelService struct {
	ID             string             `json:"id"`
	Name           string             `json:"name"`
	ServiceName    string             `json:"service_name,omitempty"`
	AIModelID      string             `json:"ai_model_id"`
	Model          *ModelServiceModel `json:"model,omitempty"`
	RegionID       string             `json:"knative_region_id"`
	RegionName     string             `json:"region_name,omitempty"`
	Engine         string             `json:"engine"`
	Device         string             `json:"device"`
	Mode           string             `json:"mode"`
	API            string             `json:"api,omitempty"`
	GPU            *GPURequest        `json:"gpu,omitempty"`
	CPU            string             `json:"cpu,omitempty"`
	MemoryMB       int                `json:"memory_mb,omitempty"`
	MinScale       int                `json:"min_scale"`
	MaxScale       int                `json:"max_scale"`
	ScaleToZero    bool               `json:"scale_to_zero"`
	TimeoutSeconds int                `json:"timeout_seconds,omitempty"`
	Image          string             `json:"image,omitempty"`
	Args           []string           `json:"args,omitempty"`
	EnvVars        EnvMap             `json:"env_vars,omitempty"`
	Port           int                `json:"port,omitempty"`
	HealthPath     string             `json:"health_path,omitempty"`
	ServedName     string             `json:"served_name,omitempty"`
	Status         string             `json:"status"`
	StatusMessage  string             `json:"status_message,omitempty"`
	URL            string             `json:"url,omitempty"`
	OpenAIBase     string             `json:"openai_base,omitempty"`
	Playground     *Playground        `json:"playground,omitempty"`
	BindingsCount  int                `json:"bindings_count"`
	DeployedAt     string             `json:"deployed_at,omitempty"`
	CreatedAt      string             `json:"created_at,omitempty"`
}

// ModelName returns the name of the served model ("" when the answer has no model).
func (s *ModelService) ModelName() string {
	if s.Model == nil {
		return ""
	}
	if s.Model.HubID != "" {
		return s.Model.HubID
	}
	return s.Model.Name
}

// Running reports whether the service is deployed or on its way.
func (s *ModelService) Running() bool {
	switch s.Status {
	case ModelServiceDeploying, ModelServiceReady, ModelServiceWaitingSnapshot:
		return true
	}
	return false
}

// ModelBinding is AiModelBindingResource: a model service a project uses under an alias.
type ModelBinding struct {
	ID                  string        `json:"id"`
	ProjectID           string        `json:"project_id"`
	Project             *Ref          `json:"project,omitempty"`
	AIModelServiceID    string        `json:"ai_model_service_id"`
	Alias               string        `json:"alias"`
	MountWeights        bool          `json:"mount_weights"`
	Env                 EnvMap        `json:"env,omitempty"`
	Service             *ModelService `json:"service,omitempty"`
	AppliesOnNextDeploy bool          `json:"applies_on_next_deploy"`
}

// ModelServiceRef is an entry of the "available" list of GET /projects/{project}/models.
type ModelServiceRef struct {
	ID     string `json:"id"`
	Name   string `json:"name"`
	Engine string `json:"engine"`
	Status string `json:"status"`
}

// HubModel is one result of the Hugging Face Hub search.
type HubModel struct {
	ID           string   `json:"id"`
	Author       string   `json:"author,omitempty"`
	Task         string   `json:"task,omitempty"`
	Library      string   `json:"library,omitempty"`
	Downloads    int64    `json:"downloads"`
	Likes        int64    `json:"likes"`
	Gated        bool     `json:"gated"`
	Private      bool     `json:"private"`
	LastModified string   `json:"last_modified,omitempty"`
	Tags         []string `json:"tags,omitempty"`
}

// HubFile is one file of a Hub model.
type HubFile struct {
	Name string    `json:"name"`
	Size FlexInt64 `json:"size,omitempty"`
}

// HubEngine is an engine that serves a Hub model.
type HubEngine struct {
	Engine      string   `json:"engine"`
	Devices     []string `json:"devices,omitempty"`
	Recommended bool     `json:"recommended"`
	API         string   `json:"api,omitempty"`
}

// HubModelInfo is GET /models/hub/info: a Hub model with the engines that serve it and RunR's
// suggestion for the deploy settings.
type HubModelInfo struct {
	ID           string         `json:"id"`
	SHA          string         `json:"sha,omitempty"`
	Task         string         `json:"task,omitempty"`
	Library      string         `json:"library,omitempty"`
	License      string         `json:"license,omitempty"`
	Gated        bool           `json:"gated"`
	Private      bool           `json:"private"`
	Downloads    int64          `json:"downloads"`
	Likes        int64          `json:"likes"`
	Tags         []string       `json:"tags,omitempty"`
	SizeBytes    FlexInt64      `json:"size_bytes,omitempty"`
	Parameters   FlexInt64      `json:"parameters,omitempty"`
	Files        []HubFile      `json:"files,omitempty"`
	CardSummary  string         `json:"card_summary,omitempty"`
	LastModified string         `json:"last_modified,omitempty"`
	Engines      []HubEngine    `json:"engines,omitempty"`
	Suggested    map[string]any `json:"suggested,omitempty"`
	Warnings     []string       `json:"warnings,omitempty"`
}

// Engine is one serving engine of GET /models/engines.
type Engine struct {
	Label   string   `json:"label"`
	Devices []string `json:"devices"`
	API     string   `json:"api"`
	Tasks   []string `json:"tasks"`
}

// EngineTask is the engines of one task and RunR's default per device.
type EngineTask struct {
	Task    string            `json:"task"`
	Engines []string          `json:"engines"`
	Default map[string]string `json:"default"`
}

// UnmarshalJSON tolerates a default device without an engine (null).
func (t *EngineTask) UnmarshalJSON(b []byte) error {
	var raw struct {
		Task    string             `json:"task"`
		Engines []string           `json:"engines"`
		Default map[string]*string `json:"default"`
	}
	if err := json.Unmarshal(b, &raw); err != nil {
		return err
	}
	*t = EngineTask{Task: raw.Task, Engines: raw.Engines, Default: map[string]string{}}
	for k, v := range raw.Default {
		if v != nil {
			t.Default[k] = *v
		}
	}
	return nil
}

// EngineCatalog is GET /models/engines.
type EngineCatalog struct {
	Engines map[string]Engine `json:"engines"`
	Tasks   []EngineTask      `json:"tasks"`
}

// HubTokenStatus is the organization's Hugging Face token (the value is never returned).
type HubTokenStatus struct {
	Configured bool   `json:"configured"`
	Hint       string `json:"hint,omitempty"`
	UpdatedAt  string `json:"updated_at,omitempty"`
}

// ModelServicePod is one pod of GET /model-services/{service}/logs.
type ModelServicePod struct {
	Name  string `json:"name"`
	Phase string `json:"phase"`
	Ready bool   `json:"ready"`
	Logs  string `json:"logs"`
}

// InvokeResult is the answer of POST /model-services/{service}/invoke.
type InvokeResult struct {
	Status      int             `json:"status"`
	DurationMS  int64           `json:"duration_ms"`
	ContentType string          `json:"content_type"`
	Body        json.RawMessage `json:"body"`
}

// BodyText returns the body as text: a JSON string unquoted, anything else as JSON.
func (r *InvokeResult) BodyText() string {
	var s string
	if json.Unmarshal(r.Body, &s) == nil {
		return s
	}
	var buf bytes.Buffer
	if json.Indent(&buf, r.Body, "", "  ") == nil {
		return buf.String()
	}
	return string(r.Body)
}

// ---------------------------------------------------------------------------------------
// Hub, engines, token

// HubSearch filters the Hugging Face Hub search.
type HubSearch struct {
	Search  string
	Task    string
	Library string
	// Sort is downloads (default), likes, trending or updated.
	Sort   string
	Limit  int
	Cursor string
}

// SearchHub searches the Hugging Face Hub through the API and returns the models and the
// cursor of the next page ("" = last page).
func (c *Client) SearchHub(ctx context.Context, s HubSearch) ([]HubModel, string, error) {
	q := url.Values{}
	set := func(k, v string) {
		if v != "" {
			q.Set(k, v)
		}
	}
	set("search", s.Search)
	set("task", s.Task)
	set("library", s.Library)
	set("sort", s.Sort)
	set("cursor", s.Cursor)
	if s.Limit > 0 {
		q.Set("limit", strconv.Itoa(s.Limit))
	}
	var out struct {
		Data       []HubModel `json:"data"`
		NextCursor *string    `json:"next_cursor"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/models/hub/search", query: q}, &out); err != nil {
		return nil, "", err
	}
	next := ""
	if out.NextCursor != nil {
		next = *out.NextCursor
	}
	return out.Data, next, nil
}

// HubInfo returns a Hub model with the engines that serve it; regionID ("" = none) sizes the
// suggestion for that region's GPUs.
func (c *Client) HubInfo(ctx context.Context, hubID, revision, regionID string) (*HubModelInfo, error) {
	q := url.Values{"id": {hubID}}
	if revision != "" {
		q.Set("revision", revision)
	}
	if regionID != "" {
		q.Set("knative_region_id", regionID)
	}
	var out HubModelInfo
	if err := c.do(ctx, request{method: http.MethodGet, path: "/models/hub/info", query: q}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// ModelEngines returns the serving engines and, per task, the engines and RunR's defaults.
func (c *Client) ModelEngines(ctx context.Context) (*EngineCatalog, error) {
	var out EngineCatalog
	if err := c.do(ctx, request{method: http.MethodGet, path: "/models/engines"}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// HubToken reports whether the organization has a Hugging Face token (never its value).
func (c *Client) HubToken(ctx context.Context) (*HubTokenStatus, error) {
	var out HubTokenStatus
	if err := c.do(ctx, request{method: http.MethodGet, path: "/models/hub-token"}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// SetHubToken stores the organization's Hugging Face token (for gated and private models);
// validate checks it with the Hub first.
func (c *Client) SetHubToken(ctx context.Context, token string, validate bool) (*HubTokenStatus, error) {
	body := map[string]any{"token": token}
	if validate {
		body["validate"] = true
	}
	var out HubTokenStatus
	if err := c.do(ctx, request{method: http.MethodPut, path: "/models/hub-token", body: body}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// DeleteHubToken removes the organization's Hugging Face token.
func (c *Client) DeleteHubToken(ctx context.Context) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/models/hub-token"}, nil)
}

// ---------------------------------------------------------------------------------------
// Library

// AddModelInput is the body of POST /models.
type AddModelInput struct {
	// Source is hub (default), upload, pvc (a folder of the region's model volume) or oci.
	Source   string `json:"source"`
	HubID    string `json:"hub_id,omitempty"`
	Revision string `json:"revision,omitempty"`
	Name     string `json:"name,omitempty"`
	Task     string `json:"task,omitempty"`
	Library  string `json:"library,omitempty"`
	// Path (pvc) and Reference (oci) locate an import in RegionID.
	Path      string `json:"path,omitempty"`
	RegionID  string `json:"knative_region_id,omitempty"`
	Reference string `json:"reference,omitempty"`
}

// ListModels returns the organization's model library.
func (c *Client) ListModels(ctx context.Context) ([]AIModel, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/models"}, &raw); err != nil {
		return nil, err
	}
	var list []AIModel
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, fmt.Errorf("decode models: %w", err)
	}
	return list, nil
}

// GetModel returns one model of the library.
func (c *Client) GetModel(ctx context.Context, id string) (*AIModel, error) {
	var out AIModel
	if err := c.do(ctx, request{method: http.MethodGet, path: "/models/" + seg(id)}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// FindModel resolves a model of the library by id, name or Hub id.
func (c *Client) FindModel(ctx context.Context, ref string) (*AIModel, error) {
	if LooksLikeULID(ref) {
		m, err := c.GetModel(ctx, ref)
		if err == nil || !IsNotFound(err) {
			return m, err
		}
	}
	list, err := c.ListModels(ctx)
	if err != nil {
		return nil, err
	}
	var byHub []*AIModel
	for i := range list {
		if list[i].Name == ref || list[i].ID == ref {
			return &list[i], nil
		}
		if list[i].HubID == ref {
			byHub = append(byHub, &list[i])
		}
	}
	switch len(byHub) {
	case 0:
		return nil, &NotFoundError{Kind: "model", Ref: ref}
	case 1:
		return byHub[0], nil
	}
	names := make([]string, len(byHub))
	for i, m := range byHub {
		names[i] = m.Name
	}
	return nil, fmt.Errorf("%s is in the library more than once (%s): use the model's name", ref, strings.Join(names, ", "))
}

// AddModel adds a model to the library: a Hub model pinned to the commit its revision points
// to, an empty model to upload files into, or an import.
func (c *Client) AddModel(ctx context.Context, in AddModelInput) (*AIModel, error) {
	if in.Source == "" {
		in.Source = ModelSourceHub
	}
	var out AIModel
	if err := c.do(ctx, request{method: http.MethodPost, path: "/models", body: in}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// UpdateModelInput is the body of PATCH /models/{model}; nil fields stay.
type UpdateModelInput struct {
	Name    *string `json:"name,omitempty"`
	Task    *string `json:"task,omitempty"`
	Library *string `json:"library,omitempty"`
}

// UpdateModel renames a model or changes its task or library.
func (c *Client) UpdateModel(ctx context.Context, id string, in UpdateModelInput) (*AIModel, error) {
	var out AIModel
	if err := c.do(ctx, request{method: http.MethodPatch, path: "/models/" + seg(id), body: in}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// DeleteModel removes a model and its snapshots (409 while model services use it).
func (c *Client) DeleteModel(ctx context.Context, id string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/models/" + seg(id)}, nil)
}

// ---------------------------------------------------------------------------------------
// Snapshots (offline copies)

// ListSnapshots returns the offline copies of a model.
func (c *Client) ListSnapshots(ctx context.Context, modelID string) ([]ModelSnapshot, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/models/" + seg(modelID) + "/snapshots"}, &raw); err != nil {
		return nil, err
	}
	var list []ModelSnapshot
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, fmt.Errorf("decode snapshots: %w", err)
	}
	return list, nil
}

// CreateSnapshot mirrors a model into a region (again for a failed mirror); a ready snapshot
// is returned as it is.
func (c *Client) CreateSnapshot(ctx context.Context, modelID, regionID string) (*ModelSnapshot, error) {
	var out ModelSnapshot
	err := c.do(ctx, request{method: http.MethodPost, path: "/models/" + seg(modelID) + "/snapshots",
		body: map[string]any{"knative_region_id": regionID}}, &out)
	if err != nil {
		return nil, err
	}
	return &out, nil
}

// DeleteSnapshot removes an offline copy (409 while running services use it).
func (c *Client) DeleteSnapshot(ctx context.Context, modelID, snapshotID string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/models/" + seg(modelID) + "/snapshots/" + seg(snapshotID)}, nil)
}

// SnapshotLogs returns the logs of the mirror or import Job of a snapshot.
func (c *Client) SnapshotLogs(ctx context.Context, modelID, snapshotID string) (string, error) {
	var out struct {
		Logs string `json:"logs"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: "/models/" + seg(modelID) + "/snapshots/" + seg(snapshotID) + "/logs"}, &out)
	return out.Logs, err
}

// WaitSnapshot polls a snapshot until it is ready. A failed snapshot is a *FailedError.
func (c *Client) WaitSnapshot(ctx context.Context, modelID, snapshotID string, opts WaitOptions) (*ModelSnapshot, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	last := ""
	what := "snapshot " + snapshotID
	for {
		list, err := c.ListSnapshots(ctx, modelID)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		var s *ModelSnapshot
		for i := range list {
			if list[i].ID == snapshotID {
				s = &list[i]
			}
		}
		if s == nil {
			return nil, &NotFoundError{Kind: "snapshot", Ref: snapshotID}
		}
		status := s.Status
		if s.Message != "" && s.Status != SnapshotReady {
			status += ": " + s.Message
		}
		if status != last {
			last = status
			if opts.Progress != nil {
				opts.Progress(status)
			}
		}
		switch s.Status {
		case SnapshotReady:
			return s, nil
		case SnapshotFailed, SnapshotDeleting:
			return s, &FailedError{What: "snapshot", Ref: snapshotID, Status: s.Status, Message: s.Message}
		}
		select {
		case <-ctx.Done():
			return s, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
	}
}

// ---------------------------------------------------------------------------------------
// Model services

// ModelServiceSettings are the settings of a model service (create and update); nil fields
// are not sent (create: RunR's pick, update: unchanged).
type ModelServiceSettings struct {
	// Mode is online (the engine downloads from the Hub into the region's model volume) or
	// offline (it serves the region's snapshot and has no egress).
	Mode *string `json:"mode,omitempty"`
	// Device is cpu or gpu; Engine vllm, tgi, tei, transformers, diffusers, custom or auto.
	Device         *string            `json:"device,omitempty"`
	Engine         *string            `json:"engine,omitempty"`
	GPU            *GPURequest        `json:"gpu,omitempty"`
	CPU            *string            `json:"cpu,omitempty"`
	MemoryMB       *int               `json:"memory_mb,omitempty"`
	MinScale       *int               `json:"min_scale,omitempty"`
	MaxScale       *int               `json:"max_scale,omitempty"`
	ScaleToZero    *bool              `json:"scale_to_zero,omitempty"`
	TimeoutSeconds *int               `json:"timeout_seconds,omitempty"`
	Image          *string            `json:"image,omitempty"`
	Args           *[]string          `json:"args,omitempty"`
	EnvVars        *map[string]string `json:"env_vars,omitempty"`
	Port           *int               `json:"port,omitempty"`
	HealthPath     *string            `json:"health_path,omitempty"`
	ServedName     *string            `json:"served_name,omitempty"`
}

// Empty reports whether no setting is set.
func (s *ModelServiceSettings) Empty() bool {
	data, _ := json.Marshal(s)
	return string(data) == "{}"
}

// CreateModelServiceInput is the body of POST /model-services: a model of the library
// (AIModelID) or a Hub model (HubID, Revision), which is added to the library on first use.
type CreateModelServiceInput struct {
	AIModelID string `json:"ai_model_id,omitempty"`
	HubID     string `json:"hub_id,omitempty"`
	Revision  string `json:"revision,omitempty"`
	Name      string `json:"name,omitempty"`
	RegionID  string `json:"knative_region_id"`
	// Deploy false creates the service stopped (default: deploy it).
	Deploy *bool `json:"deploy,omitempty"`
	ModelServiceSettings
}

// ListModelServices returns the model services, of one region when regionID is set.
func (c *Client) ListModelServices(ctx context.Context, regionID string) ([]ModelService, error) {
	var q url.Values
	if regionID != "" {
		q = url.Values{"knative_region_id": {regionID}}
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/model-services", query: q}, &raw); err != nil {
		return nil, err
	}
	var list []ModelService
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, fmt.Errorf("decode model services: %w", err)
	}
	return list, nil
}

// GetModelService returns one model service.
func (c *Client) GetModelService(ctx context.Context, id string) (*ModelService, error) {
	var out ModelService
	if err := c.do(ctx, request{method: http.MethodGet, path: "/model-services/" + seg(id)}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// FindModelService resolves a model service by id or name.
func (c *Client) FindModelService(ctx context.Context, ref string) (*ModelService, error) {
	if LooksLikeULID(ref) {
		s, err := c.GetModelService(ctx, ref)
		if err == nil || !IsNotFound(err) {
			return s, err
		}
	}
	list, err := c.ListModelServices(ctx, "")
	if err != nil {
		return nil, err
	}
	for i := range list {
		if list[i].Name == ref || list[i].ID == ref || list[i].ServiceName == ref {
			return &list[i], nil
		}
	}
	return nil, &NotFoundError{Kind: "model service", Ref: ref}
}

// CreateModelService creates a model service and, unless Deploy is false, deploys it.
func (c *Client) CreateModelService(ctx context.Context, in CreateModelServiceInput) (*ModelService, error) {
	var out ModelService
	if err := c.do(ctx, request{method: http.MethodPost, path: "/model-services", body: in}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// ClearableModelSettings are the settings UpdateModelService can reset to the engine's
// default (sent as null).
var ClearableModelSettings = []string{"image", "args", "env_vars", "port", "health_path"}

// UpdateModelService changes settings; a running service is redeployed with them. clear names
// settings of ClearableModelSettings to reset to the engine's default.
func (c *Client) UpdateModelService(ctx context.Context, id string, in ModelServiceSettings, clear ...string) (*ModelService, error) {
	var body any = in
	if len(clear) > 0 {
		data, err := json.Marshal(in)
		if err != nil {
			return nil, err
		}
		m := map[string]any{}
		if err := json.Unmarshal(data, &m); err != nil {
			return nil, err
		}
		for _, k := range clear {
			ok := false
			for _, c := range ClearableModelSettings {
				ok = ok || c == k
			}
			if !ok {
				return nil, fmt.Errorf("the setting %s cannot be cleared", k)
			}
			m[k] = nil
		}
		body = m
	}
	var out ModelService
	if err := c.do(ctx, request{method: http.MethodPatch, path: "/model-services/" + seg(id), body: body}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// DeleteModelService tears a model service down (409 while projects bind it).
func (c *Client) DeleteModelService(ctx context.Context, id string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/model-services/" + seg(id)}, nil)
}

// DeployModelService starts (deploys) a model service.
func (c *Client) DeployModelService(ctx context.Context, id string) (*ModelService, error) {
	var out ModelService
	if err := c.do(ctx, request{method: http.MethodPost, path: "/model-services/" + seg(id) + "/deploy"}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// StopModelService stops a model service; its settings stay.
func (c *Client) StopModelService(ctx context.Context, id string) (*ModelService, error) {
	var out ModelService
	if err := c.do(ctx, request{method: http.MethodPost, path: "/model-services/" + seg(id) + "/stop"}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// ModelServiceLogs returns the last lines of the engine container of up to five pods.
func (c *Client) ModelServiceLogs(ctx context.Context, id string, tail int) ([]ModelServicePod, error) {
	var q url.Values
	if tail > 0 {
		q = url.Values{"tail": {strconv.Itoa(tail)}}
	}
	var out struct {
		Pods []ModelServicePod `json:"pods"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/model-services/" + seg(id) + "/logs", query: q}, &out); err != nil {
		return nil, err
	}
	return out.Pods, nil
}

// InvokeInput is one request to a model service.
type InvokeInput struct {
	// Path is one of the engine's paths (/v1/chat/completions, /v1/embeddings, /predict, …).
	Path string `json:"path"`
	// Method is POST (default) or GET.
	Method string `json:"method,omitempty"`
	// Body is the JSON request body.
	Body json.RawMessage `json:"body,omitempty"`
}

// InvokeModelService sends one request to a model service through the API (it wakes a
// service scaled to zero).
func (c *Client) InvokeModelService(ctx context.Context, id string, in InvokeInput) (*InvokeResult, error) {
	var out InvokeResult
	if err := c.do(ctx, request{method: http.MethodPost, path: "/model-services/" + seg(id) + "/invoke", body: in}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// ModelServiceBindings returns the projects that use a model service.
func (c *Client) ModelServiceBindings(ctx context.Context, id string) ([]ModelBinding, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/model-services/" + seg(id) + "/bindings"}, &raw); err != nil {
		return nil, err
	}
	var list []ModelBinding
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, fmt.Errorf("decode bindings: %w", err)
	}
	return list, nil
}

// WaitModelService polls a model service until it is ready. A service that fails or stops
// is a *FailedError.
func (c *Client) WaitModelService(ctx context.Context, id string, opts WaitOptions) (*ModelService, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	last := ""
	what := "model service " + id
	for {
		s, err := c.GetModelService(ctx, id)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		status := s.Status
		if s.StatusMessage != "" && s.Status != ModelServiceReady {
			status += ": " + s.StatusMessage
		}
		if status != last {
			last = status
			if opts.Progress != nil {
				opts.Progress(status)
			}
		}
		switch s.Status {
		case ModelServiceReady:
			return s, nil
		case ModelServiceFailed, ModelServiceStopped:
			return s, &FailedError{What: "model service", Ref: s.Name, Status: s.Status, Message: s.StatusMessage}
		}
		select {
		case <-ctx.Done():
			return s, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
	}
}

// ---------------------------------------------------------------------------------------
// Models of a project

// BindModelInput is the body of POST /projects/{project}/models.
type BindModelInput struct {
	ServiceID string `json:"ai_model_service_id"`
	// Alias names the variables (RUNR_MODEL_<ALIAS>_URL, …); default: from the service name.
	Alias string `json:"alias,omitempty"`
	// MountWeights mounts the model's offline snapshot read-only (needs a ready snapshot in
	// the project's region).
	MountWeights *bool `json:"mount_weights,omitempty"`
}

// ProjectModels returns the model bindings of a project and the services of its region it
// can bind.
func (c *Client) ProjectModels(ctx context.Context, projectID string) ([]ModelBinding, []ModelServiceRef, error) {
	var out struct {
		Data      json.RawMessage `json:"data"`
		Available json.RawMessage `json:"available"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + seg(projectID) + "/models"}, &out); err != nil {
		return nil, nil, err
	}
	var list []ModelBinding
	if err := decodeList(out.Data, &list, nil); err != nil {
		return nil, nil, fmt.Errorf("decode model bindings: %w", err)
	}
	var avail []ModelServiceRef
	if err := decodeList(out.Available, &avail, nil); err != nil {
		return nil, nil, fmt.Errorf("decode available model services: %w", err)
	}
	return list, avail, nil
}

// BindModel binds a model service to a project; the variables apply on its next deploy.
func (c *Client) BindModel(ctx context.Context, projectID string, in BindModelInput) (*ModelBinding, error) {
	var out ModelBinding
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + seg(projectID) + "/models", body: in}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// UpdateModelBinding changes the alias or mount_weights of a binding (nil = unchanged).
func (c *Client) UpdateModelBinding(ctx context.Context, projectID, bindingID string, alias *string, mountWeights *bool) (*ModelBinding, error) {
	body := map[string]any{}
	if alias != nil {
		body["alias"] = *alias
	}
	if mountWeights != nil {
		body["mount_weights"] = *mountWeights
	}
	var out ModelBinding
	if err := c.do(ctx, request{method: http.MethodPatch, path: "/projects/" + seg(projectID) + "/models/" + seg(bindingID), body: body}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// UnbindModel removes a binding; the variables go with the project's next deploy.
func (c *Client) UnbindModel(ctx context.Context, projectID, bindingID string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/projects/" + seg(projectID) + "/models/" + seg(bindingID)}, nil)
}

// FindModelBinding resolves a binding of a project by alias, binding id, or the id or name of
// its model service.
func FindModelBinding(bindings []ModelBinding, ref string) (*ModelBinding, error) {
	for i := range bindings {
		b := &bindings[i]
		if b.ID == ref || strings.EqualFold(b.Alias, ref) || b.AIModelServiceID == ref ||
			(b.Service != nil && (b.Service.Name == ref || b.Service.ID == ref)) {
			return b, nil
		}
	}
	return nil, &NotFoundError{Kind: "model binding", Ref: ref}
}
