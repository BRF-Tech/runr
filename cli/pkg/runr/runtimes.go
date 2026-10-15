package runr

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/url"
	"sort"
	"strings"
	"time"
)

// CatalogRuntime is one runtime of the catalog (GET /runtimes): a built-in runtime or a
// runtime plugin registered from a runtime.yaml manifest.
type CatalogRuntime struct {
	ID          string   `json:"id"`
	Name        string   `json:"name"`
	Description string   `json:"description,omitempty"`
	Language    string   `json:"language,omitempty"`
	Extensions  []string `json:"extensions,omitempty"`
	Category    string   `json:"category,omitempty"`
	// Source is builtin, platform or custom.
	Source string `json:"source,omitempty"`
	// RunMode is http, exec or persistent (the execution contract of the runtime).
	RunMode  string   `json:"run_mode,omitempty"`
	Compiled bool     `json:"compiled"`
	Triggers []string `json:"triggers,omitempty"`
	Sandbox  []string `json:"sandbox,omitempty"`
	// RuntimeClass is the Kubernetes RuntimeClass the runtime's pods use instead of the
	// region's sandbox mapping (WebAssembly shims), "" for none.
	RuntimeClass string `json:"runtime_class,omitempty"`
	// Wasm is the WebAssembly shim the runtime runs on (spin), "" for container runtimes.
	Wasm           string           `json:"wasm,omitempty"`
	Handler        *RuntimeHandler  `json:"handler,omitempty"`
	DependencyFile string           `json:"dependency_file,omitempty"`
	Versions       []RuntimeVersion `json:"versions"`
	// Regions the runtime is offered in; empty means every region.
	Regions       []string `json:"regions,omitempty"`
	IsEnabled     bool     `json:"is_enabled"`
	TenantID      string   `json:"tenant_id,omitempty"`
	CreatedBy     FlexID   `json:"created_by,omitempty"`
	ProjectsCount int      `json:"projects_count"`
	UpdatedAt     string   `json:"updated_at,omitempty"`
}

// Kind classifies a runtime for listings: wasm, plugin or built-in.
func (r *CatalogRuntime) Kind() string {
	switch {
	case r.Wasm != "":
		return "wasm"
	case r.Source == "platform" || r.Source == "custom":
		return "plugin"
	}
	return "built-in"
}

// RuntimeHandler is the default entry point of a runtime.
type RuntimeHandler struct {
	File     string `json:"file"`
	Function string `json:"function"`
	Filename string `json:"filename"`
	Pattern  string `json:"pattern,omitempty"`
}

// RuntimeVersion is one version of a catalog runtime. Image is empty for versions the
// platform builds per region (Built).
type RuntimeVersion struct {
	Version     string `json:"version"`
	IsDefault   bool   `json:"is_default"`
	EOLAt       string `json:"eol_at,omitempty"`
	GPURequired bool   `json:"gpu_required,omitempty"`
	Image       string `json:"image,omitempty"`
	Built       bool   `json:"built"`
}

// DefaultVersion returns the default version, else the last one.
func (r *CatalogRuntime) DefaultVersion() *RuntimeVersion {
	for i := range r.Versions {
		if r.Versions[i].IsDefault {
			return &r.Versions[i]
		}
	}
	if n := len(r.Versions); n > 0 {
		return &r.Versions[n-1]
	}
	return nil
}

// FindVersion returns version v ("" = the default version), nil when the runtime has none.
func (r *CatalogRuntime) FindVersion(v string) *RuntimeVersion {
	if v == "" {
		return r.DefaultVersion()
	}
	for i := range r.Versions {
		if r.Versions[i].Version == v {
			return &r.Versions[i]
		}
	}
	return nil
}

// RuntimeDetail is GET /runtimes/{id}: the catalog entry plus the normalized manifest, the
// manifest as submitted and the files uploaded with it.
type RuntimeDetail struct {
	CatalogRuntime
	Manifest       *RuntimeManifest  `json:"manifest,omitempty"`
	ManifestSource string            `json:"manifest_source,omitempty"`
	Files          map[string]string `json:"files,omitempty"`
}

// UnmarshalJSON decodes the embedded catalog entry and the detail fields (files may be {} or []).
func (d *RuntimeDetail) UnmarshalJSON(b []byte) error {
	if err := json.Unmarshal(b, &d.CatalogRuntime); err != nil {
		return err
	}
	var extra struct {
		Manifest       json.RawMessage `json:"manifest"`
		ManifestSource string          `json:"manifest_source"`
		Files          EnvMap          `json:"files"`
	}
	if err := json.Unmarshal(b, &extra); err != nil {
		return err
	}
	d.ManifestSource = extra.ManifestSource
	if len(extra.Files) > 0 {
		d.Files = extra.Files
	}
	if m := bytes.TrimSpace(extra.Manifest); len(m) > 0 && m[0] == '{' {
		var man RuntimeManifest
		if err := json.Unmarshal(m, &man); err != nil {
			return err
		}
		d.Manifest = &man
	}
	return nil
}

// MarshalJSON writes the catalog fields and the detail fields side by side, the API's shape.
func (d RuntimeDetail) MarshalJSON() ([]byte, error) {
	base, err := json.Marshal(d.CatalogRuntime)
	if err != nil {
		return nil, err
	}
	var m map[string]json.RawMessage
	if err := json.Unmarshal(base, &m); err != nil {
		return nil, err
	}
	if d.Manifest != nil {
		if m["manifest"], err = json.Marshal(d.Manifest); err != nil {
			return nil, err
		}
	}
	if d.ManifestSource != "" {
		m["manifest_source"], _ = json.Marshal(d.ManifestSource)
	}
	files := d.Files
	if files == nil {
		files = map[string]string{}
	}
	m["files"], _ = json.Marshal(files)
	return json.Marshal(m)
}

// RuntimeManifest is a normalized runtime.yaml (api ManifestValidator::normalize): every
// default filled in.
type RuntimeManifest struct {
	APIVersion   string            `json:"apiVersion"`
	Kind         string            `json:"kind"`
	ID           string            `json:"id"`
	Name         string            `json:"name"`
	Description  string            `json:"description,omitempty"`
	Language     string            `json:"language,omitempty"`
	Extensions   []string          `json:"extensions"`
	Category     string            `json:"category,omitempty"`
	Versions     []ManifestVersion `json:"versions,omitempty"`
	Handler      ManifestHandler   `json:"handler"`
	Template     ManifestTemplate  `json:"template"`
	Dependencies *ManifestDeps     `json:"dependencies,omitempty"`
	Build        *ManifestBuild    `json:"build,omitempty"`
	Run          ManifestRun       `json:"run"`
	Triggers     []string          `json:"triggers,omitempty"`
	Sandbox      []string          `json:"sandbox,omitempty"`
	Resources    json.RawMessage   `json:"resources,omitempty"`
	RuntimeClass string            `json:"runtimeClass,omitempty"`
}

// Extension is the handler file's extension (the first of extensions).
func (m *RuntimeManifest) Extension() string {
	if len(m.Extensions) > 0 {
		return m.Extensions[0]
	}
	return ""
}

// DefaultFilename is <handler.file>.<extension>.
func (m *RuntimeManifest) DefaultFilename() string {
	file := m.Handler.File
	if file == "" {
		file = "main"
	}
	return file + "." + m.Extension()
}

// HandlerFunction is the default handler name.
func (m *RuntimeManifest) HandlerFunction() string {
	if m.Handler.Function == "" {
		return "handler"
	}
	return m.Handler.Function
}

// Version returns the manifest entry of version v ("" = the default one).
func (m *RuntimeManifest) Version(v string) *ManifestVersion {
	for i := range m.Versions {
		if (v == "" && m.Versions[i].Default) || (v != "" && m.Versions[i].Version == v) {
			return &m.Versions[i]
		}
	}
	if v == "" && len(m.Versions) > 0 {
		return &m.Versions[len(m.Versions)-1]
	}
	return nil
}

// NeedsWrapper reports whether the universal wrapper is the container's entrypoint for a
// trigger: exec and persistent runtimes always, http runtimes for an invoked schedule.
func (m *RuntimeManifest) NeedsWrapper(trigger string) bool {
	switch m.Run.Mode {
	case "exec", "persistent":
		return true
	}
	return trigger == TriggerSchedule && m.Run.Schedule == "invoke"
}

// UsesWrapper reports whether any trigger of the runtime runs through the wrapper.
func (m *RuntimeManifest) UsesWrapper() bool {
	triggers := m.Triggers
	if len(triggers) == 0 {
		triggers = []string{TriggerHTTP, TriggerSchedule, TriggerQueue}
	}
	for _, t := range triggers {
		if m.NeedsWrapper(t) {
			return true
		}
	}
	return false
}

// ManifestVersion is one entry of versions: exactly one of Image, Dockerfile and Git is set.
type ManifestVersion struct {
	Version    string       `json:"version"`
	Image      string       `json:"image,omitempty"`
	Dockerfile string       `json:"dockerfile,omitempty"`
	Git        *ManifestGit `json:"git,omitempty"`
	BuildArgs  EnvMap       `json:"buildArgs,omitempty"`
	Default    bool         `json:"default"`
	EOL        string       `json:"eol,omitempty"`
	GPU        bool         `json:"gpu,omitempty"`
}

// ManifestGit is the Git source of a version built by RunR.
type ManifestGit struct {
	URL        string `json:"url"`
	Ref        string `json:"ref,omitempty"`
	Path       string `json:"path,omitempty"`
	Dockerfile string `json:"dockerfile,omitempty"`
}

// ManifestHandler is the manifest's handler section.
type ManifestHandler struct {
	File     string `json:"file"`
	Function string `json:"function"`
	Pattern  string `json:"pattern,omitempty"`
}

// ManifestTemplate holds the starter files ({file} is the entry file).
type ManifestTemplate struct {
	Files EnvMap `json:"files"`
}

// ManifestDeps is the dependencies section.
type ManifestDeps struct {
	File     string         `json:"file"`
	Template string         `json:"template,omitempty"`
	Image    string         `json:"image,omitempty"`
	Install  string         `json:"install"`
	Copy     []ManifestCopy `json:"copy,omitempty"`
	Env      EnvMap         `json:"env,omitempty"`
}

// ManifestCopy is one path copied from the build stage into the final image.
type ManifestCopy struct {
	From string `json:"from"`
	To   string `json:"to"`
}

// ManifestBuild is the build section of compiled runtimes.
type ManifestBuild struct {
	Image    string                       `json:"image,omitempty"`
	Command  string                       `json:"command"`
	RunImage string                       `json:"runImage,omitempty"`
	Source   *bool                        `json:"source,omitempty"`
	Files    map[string]ManifestBuildFile `json:"files,omitempty"`
}

// CopiesSource reports whether the code is copied to /app/code of the final image (default).
func (b *ManifestBuild) CopiesSource() bool { return b.Source == nil || *b.Source }

// UnmarshalJSON tolerates files encoded as [] (empty PHP array).
func (b *ManifestBuild) UnmarshalJSON(data []byte) error {
	type plain struct {
		Image    string          `json:"image"`
		Command  string          `json:"command"`
		RunImage string          `json:"runImage"`
		Source   *bool           `json:"source"`
		Files    json.RawMessage `json:"files"`
	}
	var p plain
	if err := json.Unmarshal(data, &p); err != nil {
		return err
	}
	*b = ManifestBuild{Image: p.Image, Command: p.Command, RunImage: p.RunImage, Source: p.Source}
	if f := bytes.TrimSpace(p.Files); len(f) > 0 && f[0] == '{' {
		if err := json.Unmarshal(f, &b.Files); err != nil {
			return err
		}
	}
	return nil
}

// ManifestBuildFile is a file placed next to the code before a build: one content, or a
// variant per mode (http, schedule).
type ManifestBuildFile struct {
	Content  string
	HTTP     string
	Schedule string
	PerMode  bool
}

// For returns the content for a mode (http or schedule).
func (f ManifestBuildFile) For(mode string) string {
	if !f.PerMode {
		return f.Content
	}
	if mode == "schedule" {
		return f.Schedule
	}
	return f.HTTP
}

// UnmarshalJSON accepts a string or {http, schedule}.
func (f *ManifestBuildFile) UnmarshalJSON(b []byte) error {
	b = bytes.TrimSpace(b)
	if len(b) > 0 && b[0] == '"' {
		return json.Unmarshal(b, &f.Content)
	}
	var v struct {
		HTTP     string `json:"http"`
		Schedule string `json:"schedule"`
	}
	if err := json.Unmarshal(b, &v); err != nil {
		return err
	}
	*f = ManifestBuildFile{HTTP: v.HTTP, Schedule: v.Schedule, PerMode: true}
	return nil
}

// MarshalJSON writes the manifest's shape back.
func (f ManifestBuildFile) MarshalJSON() ([]byte, error) {
	if f.PerMode {
		return json.Marshal(map[string]string{"http": f.HTTP, "schedule": f.Schedule})
	}
	return json.Marshal(f.Content)
}

// ManifestRun is the run section.
type ManifestRun struct {
	Mode     string   `json:"mode"`
	Command  []string `json:"command,omitempty"`
	Schedule string   `json:"schedule,omitempty"`
	Input    string   `json:"input,omitempty"`
	Workers  int      `json:"workers,omitempty"`
	Env      EnvMap   `json:"env,omitempty"`
}

// RuntimeCatalog is the answer of GET /runtimes.
type RuntimeCatalog struct {
	Data      []CatalogRuntime `json:"data"`
	Abilities struct {
		Manage    bool `json:"manage"`
		UseCustom bool `json:"use_custom"`
	} `json:"abilities"`
	CustomEnabled bool `json:"custom_enabled"`
}

// ListRuntimesOptions filter GET /runtimes.
type ListRuntimesOptions struct {
	// All adds disabled runtimes (runtimes.manage: every one; runtimes.use_custom: the
	// organization's custom runtimes).
	All bool
	// Region limits the list to the runtimes offered in a region (id).
	Region string
}

// RuntimeCatalogFiltered returns the catalog with options; ErrUnsupported on APIs without
// runtime plugins.
func (c *Client) RuntimeCatalogFiltered(ctx context.Context, o ListRuntimesOptions) (*RuntimeCatalog, error) {
	q := url.Values{}
	if o.All {
		q.Set("all", "1")
	}
	if o.Region != "" {
		q.Set("region", o.Region)
	}
	var raw []byte
	err := c.do(ctx, request{method: http.MethodGet, path: "/runtimes", query: q}, &raw)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	var out struct {
		Data      json.RawMessage `json:"data"`
		Abilities json.RawMessage `json:"abilities"`
		Custom    bool            `json:"custom_enabled"`
	}
	if err := json.Unmarshal(raw, &out); err != nil {
		return nil, err
	}
	cat := &RuntimeCatalog{CustomEnabled: out.Custom}
	if len(out.Data) > 0 && out.Data[0] == '[' {
		if err := json.Unmarshal(out.Data, &cat.Data); err != nil {
			return nil, err
		}
	}
	if len(out.Abilities) > 0 && out.Abilities[0] == '{' {
		_ = json.Unmarshal(out.Abilities, &cat.Abilities)
	}
	if cat.Data == nil {
		cat.Data = []CatalogRuntime{}
	}
	return cat, nil
}

// ListRuntimes returns the runtime catalog; ErrUnsupported on APIs without runtime plugins.
func (c *Client) ListRuntimes(ctx context.Context) ([]CatalogRuntime, error) {
	cat, err := c.RuntimeCatalogFiltered(ctx, ListRuntimesOptions{})
	if err != nil {
		return nil, err
	}
	return cat.Data, nil
}

// GetRuntime returns one runtime with its manifest and uploaded files.
func (c *Client) GetRuntime(ctx context.Context, id string) (*RuntimeDetail, error) {
	var raw []byte
	err := c.do(ctx, request{method: http.MethodGet, path: "/runtimes/" + seg(id)}, &raw)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "runtime", Ref: id}
	}
	if err != nil {
		return nil, err
	}
	var d RuntimeDetail
	if err := decodeData(raw, &d); err != nil {
		return nil, err
	}
	return &d, nil
}

// RuntimeTemplate returns the starter files of a runtime for a project type (faas or xaas)
// and handler ("" = the runtime's default).
func (c *Client) RuntimeTemplate(ctx context.Context, runtimeID, serviceType, handler string) (map[string]string, error) {
	q := url.Values{}
	if serviceType != "" {
		q.Set("service_type", serviceType)
	}
	if handler != "" {
		q.Set("handler", handler)
	}
	var out struct {
		Files json.RawMessage `json:"files"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: "/runtimes/" + seg(runtimeID) + "/template", query: q}, &out)
	if err != nil {
		return nil, err
	}
	return decodeFileMap(out.Files)
}

// ManifestProblem is one problem of an invalid manifest: a JSON pointer and a message.
type ManifestProblem struct {
	Path    string `json:"path"`
	Message string `json:"message"`
}

// ManifestProblems returns the problems of a 422 answer to a manifest (validate, register,
// update), nil for other errors.
func ManifestProblems(err error) []ManifestProblem {
	var ae *APIError
	if !errors.As(err, &ae) || ae.StatusCode != http.StatusUnprocessableEntity {
		return nil
	}
	var out []ManifestProblem
	keys := make([]string, 0, len(ae.Errors))
	for k := range ae.Errors {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		for _, msg := range ae.Errors[k] {
			if k == "manifest" {
				if p, m, ok := strings.Cut(msg, ": "); ok && strings.HasPrefix(p, "/") {
					out = append(out, ManifestProblem{Path: p, Message: m})
					continue
				}
			}
			out = append(out, ManifestProblem{Path: k, Message: msg})
		}
	}
	if len(out) == 0 && ae.Message != "" {
		out = append(out, ManifestProblem{Path: "/", Message: ae.Message})
	}
	return out
}

// RuntimeValidation is the answer of POST /runtimes/validate.
type RuntimeValidation struct {
	Valid    bool             `json:"valid"`
	Manifest *RuntimeManifest `json:"manifest,omitempty"`
}

// ValidateRuntime checks a manifest (YAML or JSON text) and the files uploaded with it the way
// registration does. An invalid manifest is a 422 APIError; ManifestProblems lists its problems.
func (c *Client) ValidateRuntime(ctx context.Context, manifest string, files map[string]string) (*RuntimeValidation, error) {
	body := map[string]any{"manifest": manifest}
	if files != nil {
		body["files"] = files
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPost, path: "/runtimes/validate", body: body}, &raw); err != nil {
		return nil, err
	}
	var out struct {
		Valid    bool            `json:"valid"`
		Manifest json.RawMessage `json:"manifest"`
	}
	if err := json.Unmarshal(raw, &out); err != nil {
		return nil, err
	}
	v := &RuntimeValidation{Valid: out.Valid}
	if m := bytes.TrimSpace(out.Manifest); len(m) > 0 && m[0] == '{' {
		var man RuntimeManifest
		if err := json.Unmarshal(m, &man); err == nil {
			v.Manifest = &man
		}
	}
	return v, nil
}

// RuntimeInput is the body of POST /runtimes and PUT /runtimes/{id}. Nil fields are not sent;
// AllRegions sends regions: null (offered in every region).
type RuntimeInput struct {
	Manifest   *string
	Files      map[string]string
	Regions    []string
	AllRegions bool
	IsEnabled  *bool
}

func (in RuntimeInput) body() map[string]any {
	b := map[string]any{}
	if in.Manifest != nil {
		b["manifest"] = *in.Manifest
	}
	if in.Files != nil {
		b["files"] = in.Files
	}
	switch {
	case in.AllRegions:
		b["regions"] = nil
	case in.Regions != nil:
		b["regions"] = in.Regions
	}
	if in.IsEnabled != nil {
		b["is_enabled"] = *in.IsEnabled
	}
	return b
}

// RegisterRuntime registers a runtime from a manifest: a platform runtime for holders of
// runtimes.manage, a custom runtime of the organization for runtimes.use_custom.
func (c *Client) RegisterRuntime(ctx context.Context, in RuntimeInput) (*RuntimeDetail, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPost, path: "/runtimes", body: in.body()}, &raw); err != nil {
		return nil, err
	}
	var d RuntimeDetail
	if err := decodeData(raw, &d); err != nil {
		return nil, err
	}
	return &d, nil
}

// UpdateRuntime changes a runtime: its manifest and files (not for built-in runtimes), its
// regions and whether it is enabled.
func (c *Client) UpdateRuntime(ctx context.Context, id string, in RuntimeInput) (*RuntimeDetail, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPut, path: "/runtimes/" + seg(id), body: in.body()}, &raw); err != nil {
		if IsNotFound(err) {
			return nil, &NotFoundError{Kind: "runtime", Ref: id}
		}
		return nil, err
	}
	var d RuntimeDetail
	if err := decodeData(raw, &d); err != nil {
		return nil, err
	}
	return &d, nil
}

// DeleteRuntime removes a registered runtime; the API answers 409 while projects use it.
func (c *Client) DeleteRuntime(ctx context.Context, id string) error {
	err := c.do(ctx, request{method: http.MethodDelete, path: "/runtimes/" + seg(id)}, nil)
	if IsNotFound(err) {
		return &NotFoundError{Kind: "runtime", Ref: id}
	}
	return err
}

// RuntimeBuild is the build of a runtime version's image in one region (dockerfile and git
// versions).
type RuntimeBuild struct {
	Version    string `json:"version"`
	RegionID   string `json:"knative_region_id"`
	RegionName string `json:"region_name,omitempty"`
	// Status is pending, building, ready or failed.
	Status    string `json:"status"`
	Image     string `json:"image,omitempty"`
	Error     string `json:"error,omitempty"`
	UpdatedAt string `json:"updated_at,omitempty"`
}

// Runtime build statuses.
const (
	RuntimeBuildPending  = "pending"
	RuntimeBuildBuilding = "building"
	RuntimeBuildReady    = "ready"
	RuntimeBuildFailed   = "failed"
)

// RuntimeBuilds lists the image builds of a runtime per region and version.
func (c *Client) RuntimeBuilds(ctx context.Context, id string) ([]RuntimeBuild, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/runtimes/" + seg(id) + "/builds"}, &raw); err != nil {
		if IsNotFound(err) {
			return nil, &NotFoundError{Kind: "runtime", Ref: id}
		}
		return nil, err
	}
	var list []RuntimeBuild
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, err
	}
	return list, nil
}

// BuildRuntime (re)builds the image of every dockerfile / git version of a runtime, or of one
// version, in a region and returns the build list.
func (c *Client) BuildRuntime(ctx context.Context, id, regionID, version string) ([]RuntimeBuild, error) {
	body := map[string]any{"knative_region_id": regionID}
	if version != "" {
		body["version"] = version
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPost, path: "/runtimes/" + seg(id) + "/builds", body: body}, &raw); err != nil {
		if IsNotFound(err) {
			return nil, &NotFoundError{Kind: "runtime", Ref: id}
		}
		return nil, err
	}
	var list []RuntimeBuild
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, err
	}
	return list, nil
}

// WaitRuntimeBuilds polls the builds of a runtime in a region ("" = every region) of one
// version ("" = every version) until none is pending or building. A failed build is a
// FailedError; the wait is bounded by o.Timeout.
func (c *Client) WaitRuntimeBuilds(ctx context.Context, id, regionID, version string, o WaitOptions) ([]RuntimeBuild, error) {
	interval := o.Interval
	if interval <= 0 {
		interval = 2 * time.Second
	}
	timeout := o.Timeout
	if timeout <= 0 {
		timeout = 15 * time.Minute
	}
	deadline := time.Now().Add(timeout)
	last := ""
	for {
		all, err := c.RuntimeBuilds(ctx, id)
		if err != nil {
			return nil, err
		}
		var mine []RuntimeBuild
		busy := false
		for _, b := range all {
			if (regionID != "" && b.RegionID != regionID) || (version != "" && b.Version != version) {
				continue
			}
			mine = append(mine, b)
			if b.Status == RuntimeBuildPending || b.Status == RuntimeBuildBuilding {
				busy = true
				last = b.Version + " " + b.Status
			}
		}
		if !busy {
			for _, b := range mine {
				if b.Status == RuntimeBuildFailed {
					ref := id + " " + b.Version
					if b.RegionName != "" {
						ref += " in " + b.RegionName
					}
					return mine, &FailedError{What: "runtime image build", Ref: ref, Status: b.Status, Message: b.Error}
				}
			}
			return mine, nil
		}
		if o.Progress != nil {
			o.Progress(last)
		}
		if time.Now().After(deadline) {
			return mine, &TimeoutError{What: "the runtime image build of " + id, Timeout: timeout, Last: last}
		}
		select {
		case <-ctx.Done():
			return mine, ctx.Err()
		case <-time.After(interval):
		}
	}
}

// TestDeployRuntime creates an xaas project from the runtime's starter template in a region and
// deploys it once its version is ready (the console's Test deploy).
func (c *Client) TestDeployRuntime(ctx context.Context, id, regionID, version string) (*Project, error) {
	body := map[string]any{"knative_region_id": regionID}
	if version != "" {
		body["version"] = version
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPost, path: "/runtimes/" + seg(id) + "/test-deploy", body: body}, &raw); err != nil {
		if IsNotFound(err) {
			return nil, &NotFoundError{Kind: "runtime", Ref: id}
		}
		return nil, err
	}
	var p Project
	if err := decodeData(raw, &p); err != nil {
		return nil, err
	}
	return &p, nil
}
