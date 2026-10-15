package runr

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/url"
	"sort"
	"strconv"
	"strings"
	"time"
)

// Bindings give projects managed data services (docs/bindings.md): a KV store on Redis, object
// storage, a Postgres database or external credentials. A binding belongs to the organization;
// an attachment injects its connection details into a project as environment variables.

// Binding types (Binding.Type).
const (
	BindingKV       = "kv"
	BindingObject   = "object"
	BindingPostgres = "postgres"
	BindingExternal = "external"
)

// BindingTypes are the binding types the API knows.
var BindingTypes = []string{BindingKV, BindingObject, BindingPostgres, BindingExternal}

// Binding plans (Binding.Plan).
const (
	BindingPlanShared    = "shared"
	BindingPlanDedicated = "dedicated"
)

// Binding statuses (Binding.Status).
const (
	BindingProvisioning = "provisioning"
	BindingReady        = "ready"
	BindingError        = "error"
	BindingRotating     = "rotating"
	// BindingDeleting means the deletion was requested: the binding is detached and its data
	// kept until PurgeAt; RestoreBinding brings it back.
	BindingDeleting = "deleting"
	BindingPurging  = "purging"
)

// Binding is BindingResource: a binding without its credentials.
type Binding struct {
	ID                string              `json:"id"`
	Name              string              `json:"name"`
	ResourceName      string              `json:"resource_name,omitempty"`
	Type              string              `json:"type"`
	Plan              string              `json:"plan,omitempty"`
	RegionID          string              `json:"knative_region_id,omitempty"`
	Region            *Ref                `json:"region,omitempty"`
	Status            string              `json:"status"`
	StatusMessage     string              `json:"status_message,omitempty"`
	Settings          BindingValues       `json:"settings,omitempty"`
	EnvKeys           []string            `json:"env_keys,omitempty"`
	Details           BindingValues       `json:"details,omitempty"`
	UsageBytes        int64               `json:"usage_bytes"`
	UsageMeasuredAt   string              `json:"usage_measured_at,omitempty"`
	RotatedAt         string              `json:"rotated_at,omitempty"`
	DeleteRequestedAt string              `json:"delete_requested_at,omitempty"`
	PurgeAt           string              `json:"purge_at,omitempty"`
	CreatedBy         FlexID              `json:"created_by,omitempty"`
	CreatedAt         string              `json:"created_at,omitempty"`
	UpdatedAt         string              `json:"updated_at,omitempty"`
	Attachments       []BindingAttachment `json:"attachments,omitempty"`
}

// RegionName returns the binding's region name, its id when the name is not loaded, "" for
// external bindings.
func (b *Binding) RegionName() string {
	if b.Region != nil && b.Region.Name != "" {
		return b.Region.Name
	}
	return b.RegionID
}

// Deleted reports whether the deletion of the binding was requested (restorable until purged).
func (b *Binding) Deleted() bool {
	return b.Status == BindingDeleting || b.Status == BindingPurging
}

// BindingAttachment is one binding attached to a project (BindingResource::attachment).
type BindingAttachment struct {
	ID        string `json:"id"`
	BindingID string `json:"binding_id"`
	ProjectID string `json:"project_id"`
	Project   *struct {
		ID   string `json:"id"`
		Name string `json:"name"`
		Slug string `json:"slug,omitempty"`
	} `json:"project,omitempty"`
	// EnvPrefix is put in front of every variable name (ORDERS_ → ORDERS_DATABASE_URL).
	EnvPrefix string `json:"env_prefix"`
	// EnvNames are the variables the project gets from this attachment.
	EnvNames  []string `json:"env_names,omitempty"`
	CreatedAt string   `json:"created_at,omitempty"`
	Binding   *Binding `json:"binding,omitempty"`
}

// ProjectName returns the attached project's name, else its id.
func (a *BindingAttachment) ProjectName() string {
	if a.Project != nil && a.Project.Name != "" {
		return a.Project.Name
	}
	return a.ProjectID
}

// BindingValues is a JSON object of settings or details. PHP encodes an empty one as [];
// BindingValues accepts that and null.
type BindingValues map[string]any

// UnmarshalJSON accepts an object, an empty array or null.
func (v *BindingValues) UnmarshalJSON(b []byte) error {
	trimmed := bytes.TrimSpace(b)
	if bytes.Equal(trimmed, []byte("null")) || (len(trimmed) > 0 && trimmed[0] == '[') {
		*v = BindingValues{}
		return nil
	}
	var m map[string]any
	if err := json.Unmarshal(trimmed, &m); err != nil {
		return err
	}
	*v = m
	return nil
}

// String renders the values as "a=1, b=x" sorted by key ("" when empty).
func (v BindingValues) String() string {
	keys := make([]string, 0, len(v))
	for k, val := range v {
		if val == nil {
			continue
		}
		keys = append(keys, k)
	}
	sort.Strings(keys)
	parts := make([]string, 0, len(keys))
	for _, k := range keys {
		parts = append(parts, k+"="+BindingValueString(v[k]))
	}
	return strings.Join(parts, ", ")
}

// BindingValueString renders one setting value (numbers without exponent).
func BindingValueString(v any) string {
	switch t := v.(type) {
	case nil:
		return ""
	case string:
		return t
	case bool:
		return strconv.FormatBool(t)
	case float64:
		return strconv.FormatFloat(t, 'f', -1, 64)
	case int:
		return strconv.Itoa(t)
	case int64:
		return strconv.FormatInt(t, 10)
	}
	enc, _ := json.Marshal(v)
	return string(enc)
}

// BindingTypeInfo describes one binding type (GET /bindings/types).
type BindingTypeInfo struct {
	EnvKeys  []string      `json:"env_keys"`
	Plans    []string      `json:"plans"`
	Defaults BindingValues `json:"defaults"`
	Max      BindingValues `json:"max"`
}

// BindingTypesInfo is the answer of GET /bindings/types.
type BindingTypesInfo struct {
	Types map[string]BindingTypeInfo `json:"types"`
	// RetentionDays is how long the data of a deleted binding is kept (restore window).
	RetentionDays int `json:"retention_days"`
}

// BindingQuota is the organization's binding limits, claimed sizes and measured bytes.
type BindingQuota struct {
	Limits        BindingValues `json:"limits"`
	Used          BindingValues `json:"used"`
	MeasuredBytes BindingValues `json:"measured_bytes"`
}

// BindingHealth is the live check of a binding on its data service.
type BindingHealth struct {
	State   string          `json:"state"`
	Message string          `json:"message,omitempty"`
	Details json.RawMessage `json:"details,omitempty"`
}

// BindingUsageRecord is one day of a binding's usage.
type BindingUsageRecord struct {
	RecordDate     string  `json:"record_date"`
	StorageGBHours float64 `json:"storage_gb_hours"`
	InstanceHours  float64 `json:"instance_hours"`
	PeakBytes      int64   `json:"peak_bytes"`
	FinalCost      float64 `json:"final_cost"`
	Currency       string  `json:"currency,omitempty"`
}

// ProjectBindings is the answer of GET /projects/{project}/bindings.
type ProjectBindings struct {
	Attachments []BindingAttachment `json:"attachments"`
	// Available are the bindings that can still be attached to the project (empty without
	// bindings.view).
	Available []Binding `json:"available"`
	// Secret is the Kubernetes Secret of the project that holds the variables.
	Secret string `json:"secret,omitempty"`
}

// BindingFilter narrows ListBindings.
type BindingFilter struct {
	Type string
	// RegionID lists the bindings of a region (and the external ones).
	RegionID string
}

// ListBindings returns the organization's bindings with their attachments, sorted by name.
// Bindings whose deletion was requested are included (Status deleting).
func (c *Client) ListBindings(ctx context.Context, f BindingFilter) ([]Binding, error) {
	q := url.Values{}
	if f.Type != "" {
		q.Set("type", f.Type)
	}
	if f.RegionID != "" {
		q.Set("knative_region_id", f.RegionID)
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/bindings", query: q}, &raw); err != nil {
		return nil, err
	}
	var list []Binding
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, err
	}
	return list, nil
}

// GetBinding returns a binding by id.
func (c *Client) GetBinding(ctx context.Context, id string) (*Binding, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/bindings/" + seg(id)}, &raw); err != nil {
		return nil, err
	}
	return decodeBinding(raw)
}

// FindBinding resolves a binding by id or name.
func (c *Client) FindBinding(ctx context.Context, ref string) (*Binding, error) {
	ref = strings.TrimSpace(ref)
	list, err := c.ListBindings(ctx, BindingFilter{})
	if err != nil {
		return nil, err
	}
	if b := PickBinding(list, ref); b != nil {
		return b, nil
	}
	return nil, &NotFoundError{Kind: "binding", Ref: ref}
}

// PickBinding finds a binding by id or name in a list (nil when there is none).
func PickBinding(list []Binding, ref string) *Binding {
	for i := range list {
		if list[i].ID == ref || list[i].Name == ref {
			return &list[i]
		}
	}
	for i := range list {
		if strings.EqualFold(list[i].ID, ref) || strings.EqualFold(list[i].Name, ref) {
			return &list[i]
		}
	}
	return nil
}

// GetBindingTypes returns the binding types: variables, plans, defaults and maxima.
func (c *Client) GetBindingTypes(ctx context.Context) (*BindingTypesInfo, error) {
	var out struct {
		Data          map[string]BindingTypeInfo `json:"data"`
		RetentionDays int                        `json:"retention_days"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/bindings/types"}, &out); err != nil {
		return nil, err
	}
	return &BindingTypesInfo{Types: out.Data, RetentionDays: out.RetentionDays}, nil
}

// GetBindingQuota returns the organization's binding limits and what is claimed.
func (c *Client) GetBindingQuota(ctx context.Context) (*BindingQuota, error) {
	var out struct {
		Data BindingQuota `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/bindings/quota"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// CreateBindingInput is the body of POST /bindings (and the binding of a create + attach).
type CreateBindingInput struct {
	Name string `json:"name"`
	Type string `json:"type"`
	// Plan is shared (default) or dedicated (postgres).
	Plan string `json:"plan,omitempty"`
	// RegionID is required for kv, object and postgres bindings; external ones have none.
	// Ignored by a create + attach (the binding lives in the project's region).
	RegionID string `json:"knative_region_id,omitempty"`
	// Settings are the limits (memory_mb, quota_gb, storage_gb, connection_limit,
	// statement_timeout, instances, backups).
	Settings map[string]any `json:"settings,omitempty"`
	// Values are the variables of an external binding.
	Values map[string]string `json:"values,omitempty"`
}

// CreateBinding creates a binding; it is provisioned in the background (WaitBinding).
func (c *Client) CreateBinding(ctx context.Context, in CreateBindingInput) (*Binding, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPost, path: "/bindings", body: in}, &raw); err != nil {
		return nil, err
	}
	return decodeBinding(raw)
}

// UpdateBindingInput is the body of PATCH /bindings/{binding}: new limits, or the values of an
// external binding (they replace the old ones).
type UpdateBindingInput struct {
	Settings map[string]any    `json:"settings,omitempty"`
	Values   map[string]string `json:"values,omitempty"`
}

// UpdateBinding changes a binding's limits or an external binding's values.
func (c *Client) UpdateBinding(ctx context.Context, id string, in UpdateBindingInput) (*Binding, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPatch, path: "/bindings/" + seg(id), body: in}, &raw); err != nil {
		return nil, err
	}
	return decodeBinding(raw)
}

// DeleteBinding requests the deletion of a binding: it is detached from every project at once
// and its data kept until PurgeAt (RestoreBinding). The API wants the name as confirmation.
// It returns the binding and the API's message (with the purge date).
func (c *Client) DeleteBinding(ctx context.Context, b *Binding) (*Binding, string, error) {
	var raw []byte
	err := c.do(ctx, request{method: http.MethodDelete, path: "/bindings/" + seg(b.ID),
		body: map[string]string{"confirm": b.Name}}, &raw)
	if err != nil {
		return nil, "", err
	}
	var msg struct {
		Message string `json:"message"`
	}
	_ = json.Unmarshal(raw, &msg)
	out, err := decodeBinding(raw)
	return out, msg.Message, err
}

// RestoreBinding brings a binding back whose deletion was requested (attach it again).
func (c *Client) RestoreBinding(ctx context.Context, id string) (*Binding, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPost, path: "/bindings/" + seg(id) + "/restore"}, &raw); err != nil {
		return nil, err
	}
	return decodeBinding(raw)
}

// RotateBinding issues new credentials; attached projects roll onto them (status rotating
// until it is done).
func (c *Client) RotateBinding(ctx context.Context, id string) (*Binding, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPost, path: "/bindings/" + seg(id) + "/rotate"}, &raw); err != nil {
		return nil, err
	}
	return decodeBinding(raw)
}

// BindingStatus returns the binding and its live health on the data service.
func (c *Client) BindingStatus(ctx context.Context, id string) (*Binding, *BindingHealth, error) {
	var out struct {
		Data   json.RawMessage `json:"data"`
		Health json.RawMessage `json:"health"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/bindings/" + seg(id) + "/status"}, &out); err != nil {
		return nil, nil, err
	}
	var b Binding
	if err := json.Unmarshal(out.Data, &b); err != nil {
		return nil, nil, err
	}
	h := &BindingHealth{}
	if len(out.Health) > 0 && out.Health[0] == '{' {
		if err := json.Unmarshal(out.Health, h); err != nil {
			return nil, nil, err
		}
	}
	return &b, h, nil
}

// BindingCredentials returns the binding's variables with their values (without an
// attachment's prefix). It needs bindings.view_credentials and is audited.
func (c *Client) BindingCredentials(ctx context.Context, id string) (map[string]string, error) {
	var out struct {
		Data struct {
			Env EnvMap `json:"env"`
		} `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/bindings/" + seg(id) + "/credentials"}, &out); err != nil {
		return nil, err
	}
	if out.Data.Env == nil {
		return map[string]string{}, nil
	}
	return out.Data.Env, nil
}

// BindingUsage returns the daily usage records of the last days (1-366, 0 = the API's 30).
func (c *Client) BindingUsage(ctx context.Context, id string, days int) ([]BindingUsageRecord, error) {
	q := url.Values{}
	if days > 0 {
		q.Set("days", strconv.Itoa(days))
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/bindings/" + seg(id) + "/usage", query: q}, &raw); err != nil {
		return nil, err
	}
	var list []BindingUsageRecord
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, err
	}
	return list, nil
}

// WaitBinding polls a binding until it is ready (provisioning and rotation run in the
// background). A binding that ends in error is a *FailedError.
func (c *Client) WaitBinding(ctx context.Context, b *Binding, opts WaitOptions) (*Binding, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	last := ""
	cur := b
	for {
		switch cur.Status {
		case BindingReady:
			return cur, nil
		case BindingError:
			return cur, &FailedError{What: "binding", Ref: cur.Name, Status: cur.Status, Message: cur.StatusMessage}
		case BindingDeleting, BindingPurging:
			return cur, &FailedError{What: "binding", Ref: cur.Name, Status: cur.Status, Message: "the binding is being deleted"}
		}
		if cur.Status != last {
			last = cur.Status
			if opts.Progress != nil {
				opts.Progress(last)
			}
		}
		select {
		case <-ctx.Done():
			return cur, &TimeoutError{What: "binding " + cur.Name, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
		next, err := c.GetBinding(ctx, cur.ID)
		if err != nil {
			if ctx.Err() != nil {
				return cur, &TimeoutError{What: "binding " + cur.Name, Timeout: opts.Timeout, Last: last}
			}
			return cur, err
		}
		cur = next
	}
}

// ProjectBindings returns the bindings attached to a project and those that can be attached.
func (c *Client) ProjectBindings(ctx context.Context, projectID string) (*ProjectBindings, error) {
	var out struct {
		Data      []BindingAttachment `json:"data"`
		Available json.RawMessage     `json:"available"`
		Secret    string              `json:"secret"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/bindings"}, &out); err != nil {
		return nil, err
	}
	res := &ProjectBindings{Attachments: out.Data, Secret: out.Secret}
	if len(out.Available) > 0 && out.Available[0] == '[' {
		if err := json.Unmarshal(out.Available, &res.Available); err != nil {
			return nil, err
		}
	}
	if res.Attachments == nil {
		res.Attachments = []BindingAttachment{}
	}
	if res.Available == nil {
		res.Available = []Binding{}
	}
	return res, nil
}

// FindAttachment returns the attachment of a binding (id or name) to a project, or the
// attachment with that id.
func (c *Client) FindAttachment(ctx context.Context, projectID, ref string) (*BindingAttachment, error) {
	pb, err := c.ProjectBindings(ctx, projectID)
	if err != nil {
		return nil, err
	}
	if a := PickAttachment(pb.Attachments, ref); a != nil {
		return a, nil
	}
	return nil, &NotFoundError{Kind: "attached binding", Ref: ref}
}

// PickAttachment finds an attachment by its id or its binding's id or name (nil when none).
func PickAttachment(list []BindingAttachment, ref string) *BindingAttachment {
	for i := range list {
		a := &list[i]
		if a.ID == ref || a.BindingID == ref || (a.Binding != nil && a.Binding.Name == ref) {
			return a
		}
	}
	for i := range list {
		a := &list[i]
		if strings.EqualFold(a.BindingID, ref) || (a.Binding != nil && strings.EqualFold(a.Binding.Name, ref)) {
			return a
		}
	}
	return nil
}

// AttachBindingInput is the body of POST /projects/{project}/bindings: attach the binding
// BindingID, or create Binding in the project's region and attach it.
type AttachBindingInput struct {
	BindingID string              `json:"binding_id,omitempty"`
	Binding   *CreateBindingInput `json:"binding,omitempty"`
	// EnvPrefix (upper case, ends with _) is put in front of every variable name.
	EnvPrefix string `json:"env_prefix,omitempty"`
}

// AttachBinding attaches a binding to a project (or creates and attaches one). The project's
// running service rolls onto the new variables; nothing needs to be redeployed.
func (c *Client) AttachBinding(ctx context.Context, projectID string, in AttachBindingInput) (*BindingAttachment, error) {
	if in.Binding != nil {
		b := *in.Binding
		b.RegionID = "" // the project's region
		in.Binding = &b
	}
	var out struct {
		Data BindingAttachment `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/bindings", body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// UpdateAttachment changes the env prefix of an attachment ("" = no prefix).
func (c *Client) UpdateAttachment(ctx context.Context, projectID, attachmentID, envPrefix string) (*BindingAttachment, error) {
	var out struct {
		Data BindingAttachment `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPatch, path: "/projects/" + projectID + "/bindings/" + seg(attachmentID),
		body: map[string]string{"env_prefix": envPrefix}}, &out)
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// DetachBinding removes an attachment: the variables leave the project (rolling restart), the
// binding and its data stay.
func (c *Client) DetachBinding(ctx context.Context, projectID, attachmentID string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/projects/" + projectID + "/bindings/" + seg(attachmentID)}, nil)
}

// ValidEnvPrefix reports whether s is an attachment env prefix the API accepts: "" or upper
// case letters, digits and _, starting with a letter and ending with _, at most 32 characters.
func ValidEnvPrefix(s string) bool {
	if s == "" {
		return true
	}
	if len(s) > 32 || s[0] < 'A' || s[0] > 'Z' || s[len(s)-1] != '_' {
		return false
	}
	for _, r := range s {
		if !(r == '_' || (r >= 'A' && r <= 'Z') || (r >= '0' && r <= '9')) {
			return false
		}
	}
	return true
}

func decodeBinding(raw []byte) (*Binding, error) {
	var b Binding
	if err := decodeData(raw, &b); err != nil {
		return nil, err
	}
	return &b, nil
}
