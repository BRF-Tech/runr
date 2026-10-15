package runr

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"sort"
	"time"
)

// Administration of a region's tracing backend (docs/observability.md) and of the data
// services bindings run on (docs/bindings.md).

// Tracing modes of a region.
const (
	TracingManaged  = "managed"  // RunR installs Tempo and the collector
	TracingReuse    = "reuse"    // the collector sends to a Tempo already in the cluster
	TracingExternal = "external" // the collector exports OTLP to an external endpoint
	TracingOff      = "off"
)

// Install statuses of a region's tracing backend and of a data service.
const (
	RegionServiceInstalling   = "installing"
	RegionServiceReady        = "ready"
	RegionServiceError        = "error"
	RegionServiceUninstalling = "uninstalling"
	RegionServiceNotInstalled = "not_installed"
)

// RegionTracing is the tracing backend of a region (RegionTracingConfig::toApi).
type RegionTracing struct {
	RegionID          string   `json:"region_id"`
	Mode              string   `json:"mode"`
	Status            string   `json:"status,omitempty"`
	StatusMessage     string   `json:"status_message,omitempty"`
	TempoURL          string   `json:"tempo_url,omitempty"`
	TempoOTLPEndpoint string   `json:"tempo_otlp_endpoint,omitempty"`
	TempoOrgID        string   `json:"tempo_org_id,omitempty"`
	ExportEndpoint    string   `json:"export_endpoint,omitempty"`
	ExportHeaderNames []string `json:"export_header_names,omitempty"`
	KnativeSampleRate *float64 `json:"knative_sample_rate,omitempty"`
	RetentionHours    *int     `json:"retention_hours,omitempty"`
	InstalledAt       string   `json:"installed_at,omitempty"`
	Queryable         bool     `json:"queryable"`
	AcceptsSpans      bool     `json:"accepts_spans"`
}

// RegionTracingInput is the body of PUT /observability/regions/{region}/tracing. Mode is
// required; ExportHeaders is write-only: nil keeps the stored headers, an empty map clears
// them.
type RegionTracingInput struct {
	Mode              string             `json:"mode"`
	TempoURL          *string            `json:"tempo_url,omitempty"`
	TempoOTLPEndpoint *string            `json:"tempo_otlp_endpoint,omitempty"`
	TempoOrgID        *string            `json:"tempo_org_id,omitempty"`
	ExportEndpoint    *string            `json:"export_endpoint,omitempty"`
	ExportHeaders     *map[string]string `json:"export_headers,omitempty"`
	KnativeSampleRate *float64           `json:"knative_sample_rate,omitempty"`
	RetentionHours    *int               `json:"retention_hours,omitempty"`
	// Install applies the configuration to the cluster right away (the answer is 202).
	Install bool `json:"install,omitempty"`
}

// regionTracingAnswer is {"data": …, "message": …}.
type regionTracingAnswer struct {
	Data    RegionTracing `json:"data"`
	Message string        `json:"message,omitempty"`
}

// RegionTracing returns the tracing backend of a region (regions.view).
func (c *Client) RegionTracing(ctx context.Context, regionID string) (*RegionTracing, error) {
	var out regionTracingAnswer
	if err := c.do(ctx, request{method: http.MethodGet, path: "/observability/regions/" + seg(regionID) + "/tracing"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// SetRegionTracing stores the tracing configuration of a region (regions.observability.manage);
// with in.Install it is applied to the cluster at once.
func (c *Client) SetRegionTracing(ctx context.Context, regionID string, in RegionTracingInput) (*RegionTracing, string, error) {
	var out regionTracingAnswer
	err := c.do(ctx, request{method: http.MethodPut, path: "/observability/regions/" + seg(regionID) + "/tracing", body: in}, &out)
	if err != nil {
		return nil, "", err
	}
	return &out.Data, out.Message, nil
}

// InstallRegionTracing installs (or applies again) the tracing backend of a region.
func (c *Client) InstallRegionTracing(ctx context.Context, regionID string) (*RegionTracing, string, error) {
	var out regionTracingAnswer
	err := c.do(ctx, request{method: http.MethodPost, path: "/observability/regions/" + seg(regionID) + "/tracing/install"}, &out)
	if err != nil {
		return nil, "", err
	}
	return &out.Data, out.Message, nil
}

// RemoveRegionTracing removes the collector (and RunR's Tempo) from a region; it runs in the
// background.
func (c *Client) RemoveRegionTracing(ctx context.Context, regionID string) (string, error) {
	var out struct {
		Message string `json:"message"`
	}
	err := c.do(ctx, request{method: http.MethodDelete, path: "/observability/regions/" + seg(regionID) + "/tracing"}, &out)
	return out.Message, err
}

// WaitRegionTracing polls a region's tracing backend until its install ends: ready, or a
// *FailedError for error.
func (c *Client) WaitRegionTracing(ctx context.Context, regionID, regionName string, opts WaitOptions) (*RegionTracing, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	what := "the tracing backend of " + regionName
	last := ""
	for {
		cur, err := c.RegionTracing(ctx, regionID)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		switch cur.Status {
		case RegionServiceError:
			return cur, &FailedError{What: "tracing install of region", Ref: regionName, Status: cur.Status, Message: cur.StatusMessage}
		case RegionServiceInstalling:
		default:
			// ready, or nothing to install (mode off)
			return cur, nil
		}
		if cur.Status != last {
			last = cur.Status
			if opts.Progress != nil {
				opts.Progress(last)
			}
		}
		select {
		case <-ctx.Done():
			return cur, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
	}
}

// Data services of a region.
const (
	DataServiceKV       = "kv"
	DataServiceObject   = "object"
	DataServicePostgres = "postgres"
)

// DataServiceNames are the data services a region can run, in display order.
var DataServiceNames = []string{DataServiceKV, DataServiceObject, DataServicePostgres}

// DataServiceHealth is the live health of a ready data service (?live=1).
type DataServiceHealth struct {
	State   string         `json:"state"`
	Message string         `json:"message,omitempty"`
	Details map[string]any `json:"details,omitempty"`
}

// DataService is one data service of a region.
type DataService struct {
	Service       string             `json:"service"`
	Installed     bool               `json:"installed"`
	Mode          string             `json:"mode,omitempty"`
	Status        string             `json:"status"`
	StatusMessage string             `json:"status_message,omitempty"`
	Config        map[string]any     `json:"config,omitempty"`
	BindingsCount int                `json:"bindings_count"`
	UpdatedAt     string             `json:"updated_at,omitempty"`
	Health        *DataServiceHealth `json:"health,omitempty"`
}

// UnmarshalJSON tolerates config encoded as [] (an empty PHP array).
func (d *DataService) UnmarshalJSON(b []byte) error {
	type plain DataService
	var raw struct {
		plain
		Config json.RawMessage `json:"config"`
	}
	if err := json.Unmarshal(b, &raw); err != nil {
		return err
	}
	*d = DataService(raw.plain)
	d.Config = nil
	if c := bytes.TrimSpace(raw.Config); len(c) > 0 && c[0] == '{' {
		_ = json.Unmarshal(c, &d.Config)
	}
	return nil
}

// RegionRedis is the region's own Redis (queues), which managed KV can reuse.
type RegionRedis struct {
	Mode        string `json:"mode,omitempty"`
	Status      string `json:"status,omitempty"`
	HasPassword bool   `json:"has_password"`
}

// RegionDataServices is the answer of GET /knative-regions/{region}/data-services.
type RegionDataServices struct {
	RegionID string `json:"region_id"`
	// Namespace holds the data services of every region of the installation.
	Namespace string `json:"namespace,omitempty"`
	// Services in DataServiceNames order.
	Services    []DataService `json:"services"`
	RegionRedis *RegionRedis  `json:"region_redis,omitempty"`
	// CanManage is the caller's regions.data_services.manage on the region.
	CanManage bool `json:"can_manage"`
	// Connected is false for a region without a cluster connection (nothing to install).
	Connected bool `json:"connected"`
}

// Service returns one data service of the answer.
func (r *RegionDataServices) Service(name string) *DataService {
	for i := range r.Services {
		if r.Services[i].Service == name {
			return &r.Services[i]
		}
	}
	return nil
}

// RegionDataServices returns the data services of a region (regions.view or bindings.view);
// live adds a health check of the ready ones.
func (c *Client) RegionDataServices(ctx context.Context, regionID string, live bool) (*RegionDataServices, error) {
	q := url.Values{}
	if live {
		q.Set("live", "1")
	}
	var out struct {
		Data *struct {
			RegionID    string                 `json:"region_id"`
			Namespace   string                 `json:"namespace"`
			Services    map[string]DataService `json:"services"`
			RegionRedis *RegionRedis           `json:"region_redis"`
		} `json:"data"`
		Abilities struct {
			Manage FlexBool `json:"manage"`
		} `json:"abilities"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: "/knative-regions/" + seg(regionID) + "/data-services", query: q}, &out)
	if err != nil {
		return nil, err
	}
	res := &RegionDataServices{RegionID: regionID, CanManage: bool(out.Abilities.Manage), Services: []DataService{}}
	if out.Data == nil {
		return res, nil
	}
	res.Connected = true
	res.RegionID, res.Namespace, res.RegionRedis = out.Data.RegionID, out.Data.Namespace, out.Data.RegionRedis
	seen := map[string]bool{}
	for _, name := range DataServiceNames {
		if s, ok := out.Data.Services[name]; ok {
			if s.Service == "" {
				s.Service = name
			}
			res.Services = append(res.Services, s)
			seen[name] = true
		}
	}
	var rest []string
	for name := range out.Data.Services {
		if !seen[name] {
			rest = append(rest, name)
		}
	}
	sort.Strings(rest)
	for _, name := range rest {
		s := out.Data.Services[name]
		if s.Service == "" {
			s.Service = name
		}
		res.Services = append(res.Services, s)
	}
	return res, nil
}

// DataServiceInstall is the answer of an install or uninstall request (queued).
type DataServiceInstall struct {
	Service string `json:"service"`
	Status  string `json:"status"`
	Mode    string `json:"mode,omitempty"`
	Message string `json:"message,omitempty"`
}

// InstallDataService installs or reconfigures a data service of a region
// (regions.data_services.manage). options are the service's settings (kv: mode, maxmemory_mb,
// storage; object: mode, storage, provider, endpoint, …; postgres: instances, storage,
// backups); credentials of an external object store go in options too and are stored
// encrypted by the API.
func (c *Client) InstallDataService(ctx context.Context, regionID, service string, options map[string]any) (*DataServiceInstall, error) {
	if options == nil {
		options = map[string]any{}
	}
	var out struct {
		Message string             `json:"message"`
		Data    DataServiceInstall `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: "/knative-regions/" + seg(regionID) + "/data-services/" + seg(service),
		body: options}, &out)
	if err != nil {
		return nil, err
	}
	out.Data.Message = out.Message
	return &out.Data, nil
}

// UninstallDataService removes a data service of a region; refused (422) while bindings use it.
func (c *Client) UninstallDataService(ctx context.Context, regionID, service string) (*DataServiceInstall, error) {
	var out struct {
		Message string             `json:"message"`
		Data    DataServiceInstall `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodDelete, path: "/knative-regions/" + seg(regionID) + "/data-services/" + seg(service)}, &out)
	if err != nil {
		return nil, err
	}
	out.Data.Message = out.Message
	return &out.Data, nil
}

// WaitDataService polls a data service until its install or uninstall ends. An install ends
// ready (a *FailedError for error); an uninstall ends not installed.
func (c *Client) WaitDataService(ctx context.Context, regionID, regionName, service string, opts WaitOptions) (*DataService, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	what := fmt.Sprintf("the %s data service of %s", service, regionName)
	last := ""
	for {
		all, err := c.RegionDataServices(ctx, regionID, false)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		cur := all.Service(service)
		if cur == nil {
			return nil, &NotFoundError{Kind: "data service", Ref: service}
		}
		switch cur.Status {
		case RegionServiceReady, RegionServiceNotInstalled:
			return cur, nil
		case RegionServiceError:
			return cur, &FailedError{What: "data service", Ref: service + " of " + regionName, Status: cur.Status, Message: cur.StatusMessage}
		}
		if cur.Status != last {
			last = cur.Status
			if opts.Progress != nil {
				opts.Progress(last)
			}
		}
		select {
		case <-ctx.Done():
			return cur, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
	}
}

// FlexBool is a boolean the API may encode as true/false, 1/0 or "1"/"0".
type FlexBool bool

// UnmarshalJSON accepts booleans, numbers and strings.
func (f *FlexBool) UnmarshalJSON(b []byte) error {
	b = bytes.TrimSpace(b)
	switch string(b) {
	case "true", "1", `"1"`, `"true"`:
		*f = true
	default:
		*f = false
	}
	return nil
}
