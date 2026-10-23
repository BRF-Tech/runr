package runr

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"time"
)

// Organization export and import (docs/org-export.md): an export packages the organization —
// settings, roles, projects with every version's code, domains, gateways, workflows, bindings
// and optionally their data, prebuilt images and passphrase-encrypted secrets — into one
// versioned file (tar + zstd); an import uploads such a file in resumable chunks — or lets the
// API read it from an object binding or an S3 bucket, resumable too — shows a dry-run plan
// (region mapping, renames, what needs manual steps) and then creates everything. Both run as
// jobs on the API; the client polls them.

// Statuses of an organization transfer.
const (
	OrgTransferQueued    = "queued"
	OrgTransferRunning   = "running"
	OrgTransferUploading = "uploading"
	OrgTransferFetching  = "fetching"
	OrgTransferVerifying = "verifying"
	OrgTransferPlanned   = "planned"
	OrgTransferApplying  = "applying"
	OrgTransferCompleted = "completed"
	OrgTransferFailed    = "failed"
	OrgTransferCancelled = "cancelled"
	OrgTransferExpired   = "expired"
)

// OrgTransfer is one export or import of the organization.
type OrgTransfer struct {
	ID          string                  `json:"id"`
	Kind        string                  `json:"kind"`
	Status      string                  `json:"status"`
	Phase       string                  `json:"phase,omitempty"`
	Progress    int                     `json:"progress"`
	Message     string                  `json:"message,omitempty"`
	Options     map[string]any          `json:"options,omitempty"`
	Estimate    *OrgExportEstimate      `json:"estimate,omitempty"`
	Destination *OrgTransferDestination `json:"destination,omitempty"`
	Package     *OrgPackage             `json:"package,omitempty"`
	Upload      *OrgUpload              `json:"upload,omitempty"`
	Source      *OrgImportSource        `json:"source,omitempty"`
	Plan        *OrgImportPlan          `json:"plan,omitempty"`
	Report      *OrgTransferReport      `json:"report,omitempty"`
	CreatedBy   *OrgTransferUser        `json:"created_by,omitempty"`
	CreatedAt   string                  `json:"created_at,omitempty"`
	UpdatedAt   string                  `json:"updated_at,omitempty"`
	FinishedAt  string                  `json:"finished_at,omitempty"`
	ExpiresAt   string                  `json:"expires_at,omitempty"`
}

// Finished reports whether the transfer reached a final status.
func (t *OrgTransfer) Finished() bool {
	switch t.Status {
	case OrgTransferCompleted, OrgTransferFailed, OrgTransferCancelled, OrgTransferExpired:
		return true
	}
	return false
}

// OrgTransferUser is who started a transfer.
type OrgTransferUser struct {
	ID   FlexID `json:"id"`
	Name string `json:"name"`
}

// OrgExportEstimate is what an export would hold, sized before it runs.
type OrgExportEstimate struct {
	Projects         int                  `json:"projects"`
	Versions         int                  `json:"versions"`
	CodeBytes        int64                `json:"code_bytes"`
	Bindings         []OrgEstimateBinding `json:"bindings"`
	BindingDataBytes int64                `json:"binding_data_bytes"`
	Images           OrgEstimateImages    `json:"images"`
	TotalBytes       int64                `json:"total_bytes"`
	History          *OrgEstimateHistory  `json:"history,omitempty"`
	Warnings         []string             `json:"warnings,omitempty"`
}

// OrgEstimateHistory counts the run history an export with IncludeHistory carries.
type OrgEstimateHistory struct {
	Deployments   int `json:"deployments"`
	WorkflowRuns  int `json:"workflow_runs"`
	TriggerEvents int `json:"trigger_events"`
}

// OrgEstimateBinding is a binding of the organization and the size of its data.
type OrgEstimateBinding struct {
	ID            string `json:"id"`
	Name          string `json:"name"`
	Type          string `json:"type"`
	Plan          string `json:"plan,omitempty"`
	Region        string `json:"region,omitempty"`
	UsageBytes    int64  `json:"usage_bytes"`
	DataSupported bool   `json:"data_supported"`
}

// OrgEstimateImages counts the prebuilt images an export would pull; their size is known only
// once they are pulled (Bytes nil).
type OrgEstimateImages struct {
	Count int    `json:"count"`
	Bytes *int64 `json:"bytes"`
}

// OrgTransferDestination is where an export goes: "download", an object binding of the
// organization ("binding") or an S3 bucket ("s3"). It never carries credentials.
type OrgTransferDestination struct {
	Type        string `json:"type"`
	BindingID   string `json:"binding_id,omitempty"`
	BindingName string `json:"binding_name,omitempty"`
	Endpoint    string `json:"endpoint,omitempty"`
	Bucket      string `json:"bucket,omitempty"`
	Key         string `json:"key,omitempty"`
}

// OrgImportSource is where an import reads its package from when it is not uploaded: an object
// binding of the organization ("binding") or an S3 bucket ("s3"). The credentials of an S3
// source are sent when the import starts or resumes and never come back.
type OrgImportSource struct {
	Type            string `json:"type"`
	BindingID       string `json:"binding_id,omitempty"`
	BindingName     string `json:"binding_name,omitempty"`
	Endpoint        string `json:"endpoint,omitempty"`
	Bucket          string `json:"bucket,omitempty"`
	Region          string `json:"region,omitempty"`
	PathStyle       *bool  `json:"path_style,omitempty"`
	Key             string `json:"key,omitempty"`
	AccessKeyID     string `json:"access_key_id,omitempty"`
	SecretAccessKey string `json:"secret_access_key,omitempty"`
	// Size, ETag and SHA256 are what the API found at the source (a resumed read checks them).
	Size   int64  `json:"size,omitempty"`
	ETag   string `json:"etag,omitempty"`
	SHA256 string `json:"sha256,omitempty"`
}

// OrgPackage describes the package file of a transfer.
type OrgPackage struct {
	Filename      string           `json:"filename"`
	SizeBytes     int64            `json:"size_bytes"`
	SHA256        string           `json:"sha256"`
	SchemaVersion int              `json:"schema_version"`
	RunrVersion   string           `json:"runr_version"`
	Source        OrgPackageSource `json:"source"`
	Downloadable  bool             `json:"downloadable"`
}

// OrgPackageSource is the organization a package was exported from.
type OrgPackageSource struct {
	Organization string `json:"organization"`
	Slug         string `json:"slug"`
	ExportedAt   string `json:"exported_at"`
}

// OrgUpload is the upload state of an import's package.
type OrgUpload struct {
	SizeBytes     int64  `json:"size_bytes"`
	UploadedBytes int64  `json:"uploaded_bytes"`
	ChunkSize     int64  `json:"chunk_size"`
	SHA256        string `json:"sha256,omitempty"`
}

// OrgImportPlan is the dry-run report of an import: what it creates where, collisions, checks
// and the steps it leaves to the user.
type OrgImportPlan struct {
	GeneratedAt  string           `json:"generated_at,omitempty"`
	Source       OrgPlanSource    `json:"source"`
	Contents     map[string]any   `json:"contents,omitempty"`
	Regions      []OrgPlanRegion  `json:"regions"`
	Projects     []OrgPlanProject `json:"projects"`
	Bindings     []OrgPlanBinding `json:"bindings"`
	Organization json.RawMessage  `json:"organization,omitempty"`
	ManualSteps  []OrgManualStep  `json:"manual_steps"`
	History      *OrgPlanHistory  `json:"history,omitempty"`
	Issues       []OrgPlanIssue   `json:"issues,omitempty"`
	Blocking     int              `json:"blocking"`
	Warnings     int              `json:"warnings"`
	CanApply     bool             `json:"can_apply"`
}

// OrgPlanHistory is the run history of a package: whether the import brings it, and per project
// what it holds and what the retention here leaves out.
type OrgPlanHistory struct {
	Import        bool                    `json:"import"`
	ExportedAt    string                  `json:"exported_at,omitempty"`
	Projects      []OrgPlanHistoryProject `json:"projects,omitempty"`
	UsageRecords  int                     `json:"usage_records"`
	Bills         int                     `json:"bills"`
	BillsImported bool                    `json:"bills_imported"`
}

// OrgPlanHistoryProject is the run history of one project of the package.
type OrgPlanHistoryProject struct {
	SourceID           string         `json:"source_id"`
	Name               string         `json:"name"`
	Deployments        int            `json:"deployments"`
	WorkflowRuns       int            `json:"workflow_runs"`
	TriggerEvents      int            `json:"trigger_events"`
	DroppedByRetention map[string]int `json:"dropped_by_retention,omitempty"`
}

// OrgPlanSource is the exporting organization and RunR of a package.
type OrgPlanSource struct {
	Organization  string `json:"organization"`
	Slug          string `json:"slug"`
	RunrVersion   string `json:"runr_version"`
	SchemaVersion int    `json:"schema_version"`
	ExportedAt    string `json:"exported_at"`
}

// OrgPlanRegion maps a region of the package to one of this installation.
type OrgPlanRegion struct {
	SourceID   string `json:"source_id"`
	SourceName string `json:"source_name"`
	TargetID   string `json:"target_id"`
	TargetName string `json:"target_name"`
	Options    []Ref  `json:"options"`
}

// OrgPlanIssue is one finding of the plan: level error (blocks the import), warning or manual
// (a step left to the user).
type OrgPlanIssue struct {
	Level   string `json:"level"`
	Code    string `json:"code"`
	Message string `json:"message"`
}

// OrgPlanProject is one project of the package and what the import does with it.
type OrgPlanProject struct {
	SourceID       string         `json:"source_id"`
	Name           string         `json:"name"`
	TargetName     string         `json:"target_name"`
	Slug           string         `json:"slug"`
	ServiceType    string         `json:"service_type"`
	Runtime        string         `json:"runtime,omitempty"`
	RuntimeVersion string         `json:"runtime_version,omitempty"`
	SourceRegion   string         `json:"source_region,omitempty"`
	TargetRegionID string         `json:"target_region_id,omitempty"`
	TargetRegion   string         `json:"target_region,omitempty"`
	Versions       int            `json:"versions"`
	Domains        []string       `json:"domains,omitempty"`
	Bindings       []string       `json:"bindings,omitempty"`
	Collision      bool           `json:"collision"`
	SuggestedName  string         `json:"suggested_name,omitempty"`
	Status         string         `json:"status"`
	Issues         []OrgPlanIssue `json:"issues"`
}

// OrgPlanBinding is one binding of the package and what the import does with it.
type OrgPlanBinding struct {
	SourceID       string `json:"source_id"`
	Name           string `json:"name"`
	Type           string `json:"type"`
	Plan           string `json:"plan,omitempty"`
	TargetName     string `json:"target_name"`
	TargetRegionID string `json:"target_region_id,omitempty"`
	Data           bool   `json:"data"`
	DataBytes      int64  `json:"data_bytes"`
	// DataRestore says whether the import restores the data (restore_data, skip_binding_data);
	// a KV dump records the Redis version it was made with, the plan the target's.
	DataRestore        *bool          `json:"data_restore,omitempty"`
	DataRedisVersion   string         `json:"data_redis_version,omitempty"`
	TargetRedisVersion string         `json:"target_redis_version,omitempty"`
	Status             string         `json:"status"`
	Issues             []OrgPlanIssue `json:"issues"`
}

// OrgManualStep is a step the import leaves to the user (DNS of domains, secrets to enter
// again).
type OrgManualStep struct {
	Code    string   `json:"code"`
	Message string   `json:"message"`
	Items   []string `json:"items,omitempty"`
}

// OrgTransferReport is the final report of a transfer. An import's counts and items say what
// was created, found already there, skipped or failed; an export's what the package holds.
type OrgTransferReport struct {
	StartedAt   string             `json:"started_at,omitempty"`
	FinishedAt  string             `json:"finished_at,omitempty"`
	Counts      map[string]int     `json:"counts,omitempty"`
	Items       []OrgReportItem    `json:"items,omitempty"`
	ManualSteps []OrgManualStep    `json:"manual_steps,omitempty"`
	Warnings    []string           `json:"warnings,omitempty"`
	Entries     int                `json:"entries,omitempty"`
	Bytes       int64              `json:"bytes,omitempty"`
	Skipped     []OrgReportSkipped `json:"skipped,omitempty"`
}

// OrgReportItem is one entry of an import report.
type OrgReportItem struct {
	Section  string `json:"section"`
	Source   string `json:"source"`
	Name     string `json:"name"`
	TargetID string `json:"target_id,omitempty"`
	Status   string `json:"status"`
	Message  string `json:"message,omitempty"`
}

// OrgReportSkipped is a part an export left out, and why.
type OrgReportSkipped struct {
	Section string `json:"section"`
	Name    string `json:"name"`
	Message string `json:"message"`
}

// OrgExportOptions are the contents of an export (POST /org-transfers/export and its estimate).
type OrgExportOptions struct {
	// Projects are project ids; empty exports every project.
	Projects       []string `json:"projects,omitempty"`
	IncludeMembers bool     `json:"include_members,omitempty"`
	IncludeImages  bool     `json:"include_images,omitempty"`
	// BindingData lists the bindings whose data is exported: binding ids, or "all".
	BindingData    any  `json:"binding_data,omitempty"`
	IncludeSecrets bool `json:"include_secrets,omitempty"`
	// IncludeHistory adds the run history: deployments, workflow runs, trigger events, usage
	// and bills (docs/org-export.md#run-history).
	IncludeHistory bool `json:"include_history,omitempty"`
}

// OrgExportRequest is the body of POST /org-transfers/export.
type OrgExportRequest struct {
	OrgExportOptions
	// Passphrase encrypts the exported secrets (IncludeSecrets); it is never stored.
	Passphrase  string                `json:"passphrase,omitempty"`
	Destination *OrgExportDestination `json:"destination,omitempty"`
}

// OrgExportDestination is where an export goes. S3 credentials are used for the job only.
type OrgExportDestination struct {
	Type            string `json:"type"`
	BindingID       string `json:"binding_id,omitempty"`
	Prefix          string `json:"prefix,omitempty"`
	Endpoint        string `json:"endpoint,omitempty"`
	Bucket          string `json:"bucket,omitempty"`
	Region          string `json:"region,omitempty"`
	AccessKeyID     string `json:"access_key_id,omitempty"`
	SecretAccessKey string `json:"secret_access_key,omitempty"`
	PathStyle       *bool  `json:"path_style,omitempty"`
}

// OrgImportChoices map the package onto this installation (POST …/plan and …/apply).
type OrgImportChoices struct {
	// RegionMap maps source region ids to target region ids.
	RegionMap map[string]string `json:"region_map,omitempty"`
	// Renames give source project ids a new name; BindingRenames source binding ids.
	Renames        map[string]string `json:"renames,omitempty"`
	BindingRenames map[string]string `json:"binding_renames,omitempty"`
	SkipProjects   []string          `json:"skip_projects,omitempty"`
	// SkipBindingData leaves the data of these source bindings out (e.g. KV data an older
	// Redis here cannot take).
	SkipBindingData []string `json:"skip_binding_data,omitempty"`
	// History imports the package's run history (nil: the API's default, true).
	History *bool `json:"history,omitempty"`
	// Passphrase unlocks the package's secrets; it is never stored.
	Passphrase string `json:"passphrase,omitempty"`
}

// OrgImportApply is the body of POST …/apply.
type OrgImportApply struct {
	OrgImportChoices
	RestoreData   bool `json:"restore_data"`
	Images        bool `json:"images"`
	InviteMembers bool `json:"invite_members"`
	Deploy        bool `json:"deploy"`
}

// PlanBlockedError means the import plan has blocking issues: apply refuses it. The CLI exits
// with code 5.
type PlanBlockedError struct {
	// Blocking is the number of blocking issues (0 when the API did not say).
	Blocking int
}

func (e *PlanBlockedError) Error() string {
	n := "blocking issues"
	if e.Blocking > 0 {
		n = fmt.Sprintf("%d blocking issue(s)", e.Blocking)
	}
	return "the import plan has " + n + "; fix them (region mapping, renames, runtimes) and plan again"
}

// ListOrgTransfers returns the organization's latest exports and imports.
func (c *Client) ListOrgTransfers(ctx context.Context) ([]OrgTransfer, error) {
	var out struct {
		Data []OrgTransfer `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/org-transfers"}, &out); err != nil {
		return nil, err
	}
	return out.Data, nil
}

// GetOrgTransfer returns one export or import.
func (c *Client) GetOrgTransfer(ctx context.Context, id string) (*OrgTransfer, error) {
	var out struct {
		Data OrgTransfer `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: "/org-transfers/" + seg(id)}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "transfer", Ref: id}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// EstimateOrgExport sizes an export before it runs.
func (c *Client) EstimateOrgExport(ctx context.Context, opts OrgExportOptions) (*OrgExportEstimate, error) {
	var out struct {
		Data OrgExportEstimate `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/org-transfers/export/estimate", body: opts}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// StartOrgExport starts an export job (202, status queued).
func (c *Client) StartOrgExport(ctx context.Context, in OrgExportRequest) (*OrgTransfer, error) {
	var out struct {
		Data OrgTransfer `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/org-transfers/export", body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// DownloadOrgExport streams the package of a completed export to w, from offset on (a resumed
// download: Range bytes=offset-). restart is called when the API answers with the whole file
// instead (200): the caller must then drop what it has and write from the start.
func (c *Client) DownloadOrgExport(ctx context.Context, id string, w io.Writer, offset int64, restart func() error) (*Download, error) {
	d := &Download{W: w}
	r := request{method: http.MethodGet, path: "/org-transfers/" + seg(id) + "/download", accept: "application/zstd, application/octet-stream, application/json"}
	if offset > 0 {
		r.headers = map[string]string{"Range": "bytes=" + strconv.FormatInt(offset, 10) + "-"}
		d.Begin = func(status int, _ http.Header) error {
			if status == http.StatusPartialContent || restart == nil {
				return nil
			}
			return restart()
		}
	}
	if err := c.long().do(ctx, r, d); err != nil {
		return nil, err
	}
	return d, nil
}

// CancelOrgTransfer cancels a running transfer, or removes a finished one and its files.
func (c *Client) CancelOrgTransfer(ctx context.Context, id string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/org-transfers/" + seg(id)}, nil)
}

// StartOrgImportInput is the body of POST /org-transfers/import.
type StartOrgImportInput struct {
	Filename string `json:"filename"`
	Size     int64  `json:"size"`
	SHA256   string `json:"sha256,omitempty"`
}

// StartOrgImportFrom opens an import whose package the API reads itself from an object binding
// or an S3 bucket (202, status fetching); sha256, when given, is checked once it is read.
func (c *Client) StartOrgImportFrom(ctx context.Context, source OrgImportSource, sha256 string) (*OrgTransfer, error) {
	var out struct {
		Data OrgTransfer `json:"data"`
	}
	body := map[string]any{"source": source}
	if sha256 != "" {
		body["sha256"] = sha256
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/org-transfers/import", body: body}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// ResumeOrgImportFetch continues reading the package of an import whose read failed, after the
// bytes the API has (an S3 source needs its credentials again).
func (c *Client) ResumeOrgImportFetch(ctx context.Context, id, accessKeyID, secretAccessKey string) (*OrgTransfer, error) {
	var out struct {
		Data OrgTransfer `json:"data"`
	}
	body := map[string]any{}
	if accessKeyID != "" || secretAccessKey != "" {
		body["access_key_id"], body["secret_access_key"] = accessKeyID, secretAccessKey
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/org-transfers/" + seg(id) + "/fetch", body: body}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// OrgHistoryRecord is a usage record or bill of the source organization an import kept,
// read-only.
type OrgHistoryRecord struct {
	ID              string         `json:"id"`
	Kind            string         `json:"kind"`
	SourceTable     string         `json:"source_table,omitempty"`
	ProjectID       string         `json:"project_id,omitempty"`
	SourceProjectID string         `json:"source_project_id,omitempty"`
	Subject         string         `json:"subject,omitempty"`
	PeriodDate      string         `json:"period_date,omitempty"`
	Data            map[string]any `json:"data"`
}

// ListOrgImportHistory returns a page of the usage records and bills an import kept (kind
// "usage", "bill" or empty for both) and how many there are.
func (c *Client) ListOrgImportHistory(ctx context.Context, id, kind string, page, perPage int) ([]OrgHistoryRecord, int, error) {
	var out struct {
		Data []OrgHistoryRecord `json:"data"`
		Meta struct {
			Total int `json:"total"`
		} `json:"meta"`
	}
	q := url.Values{}
	if kind != "" {
		q.Set("kind", kind)
	}
	if page > 0 {
		q.Set("page", strconv.Itoa(page))
	}
	if perPage > 0 {
		q.Set("per_page", strconv.Itoa(perPage))
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/org-transfers/" + seg(id) + "/history", query: q}, &out); err != nil {
		return nil, 0, err
	}
	return out.Data, out.Meta.Total, nil
}

// StartOrgImport opens an import whose package is then uploaded in chunks (status uploading).
func (c *Client) StartOrgImport(ctx context.Context, in StartOrgImportInput) (*OrgTransfer, error) {
	var out struct {
		Data OrgTransfer `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/org-transfers/import", body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// UploadOrgImportChunk sends one chunk of the package at offset and returns how many bytes the
// API has from the start without a gap — where to go on. Written at a fixed offset, the chunk
// is retried like a GET.
func (c *Client) UploadOrgImportChunk(ctx context.Context, id string, offset int64, chunk []byte) (int64, error) {
	var out struct {
		UploadedBytes int64 `json:"uploaded_bytes"`
	}
	err := c.do(ctx, request{
		method:      http.MethodPut,
		path:        "/org-transfers/" + seg(id) + "/chunk",
		query:       url.Values{"offset": {strconv.FormatInt(offset, 10)}},
		raw:         chunk,
		contentType: "application/octet-stream",
		retry:       true,
	}, &out)
	if err != nil {
		return 0, err
	}
	return out.UploadedBytes, nil
}

// CompleteOrgImport closes the upload: the API verifies the package and plans the import
// (status verifying, then planned or failed).
func (c *Client) CompleteOrgImport(ctx context.Context, id string) (*OrgTransfer, error) {
	var out struct {
		Data OrgTransfer `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/org-transfers/" + seg(id) + "/complete", body: map[string]any{}}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// PlanOrgImport plans the import again with the given choices (dry run, nothing is created).
func (c *Client) PlanOrgImport(ctx context.Context, id string, choices OrgImportChoices) (*OrgTransfer, error) {
	var out struct {
		Data OrgTransfer `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/org-transfers/" + seg(id) + "/plan", body: choices}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// ApplyOrgImport starts the import (202, status applying); on a failed import it resumes where
// it stopped. A plan with blocking issues is refused (422 plan_blocked → PlanBlockedError).
func (c *Client) ApplyOrgImport(ctx context.Context, id string, in OrgImportApply) (*OrgTransfer, error) {
	var out struct {
		Data OrgTransfer `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: "/org-transfers/" + seg(id) + "/apply", body: in}, &out)
	var ae *APIError
	if errors.As(err, &ae) && ae.StatusCode == http.StatusUnprocessableEntity && ae.Code == "plan_blocked" {
		return nil, &PlanBlockedError{}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// OrgWaitOptions control WaitOrgTransfer.
type OrgWaitOptions struct {
	// Timeout of the wait (0: until the transfer is done or ctx ends).
	Timeout  time.Duration
	Interval time.Duration
	// Progress is called with every poll's state.
	Progress func(t *OrgTransfer)
}

// WaitOrgTransfer polls a transfer until its status is one of until. failed, cancelled and
// expired end the wait with a FailedError (unless until names them).
func (c *Client) WaitOrgTransfer(ctx context.Context, id string, until []string, opts OrgWaitOptions) (*OrgTransfer, error) {
	interval := opts.Interval
	if interval <= 0 {
		interval = 2 * time.Second
	}
	var deadline time.Time
	if opts.Timeout > 0 {
		deadline = time.Now().Add(opts.Timeout)
	}
	for {
		t, err := c.GetOrgTransfer(ctx, id)
		if err != nil {
			return nil, err
		}
		if opts.Progress != nil {
			opts.Progress(t)
		}
		for _, s := range until {
			if t.Status == s {
				return t, nil
			}
		}
		if t.Finished() {
			return t, &FailedError{What: "organization " + t.Kind, Ref: t.ID, Status: t.Status, Message: t.Message}
		}
		if !deadline.IsZero() && time.Now().After(deadline) {
			return t, &TimeoutError{What: "organization " + t.Kind + " " + t.ID, Timeout: opts.Timeout, Last: t.Status}
		}
		if err := sleepCtx(ctx, interval); err != nil {
			return nil, err
		}
	}
}

// ImportFromSource lets the API read a package from an object binding or an S3 bucket and waits
// until it is verified and planned. Progress gets the phase ("fetching", "verifying") and the
// bytes read of the package's size (0 until the API knows it).
func (c *Client) ImportFromSource(ctx context.Context, source OrgImportSource, sha256 string, opts ImportPackageOptions) (*OrgTransfer, error) {
	tr, err := c.StartOrgImportFrom(ctx, source, sha256)
	if err != nil {
		return nil, err
	}
	return c.waitPlanned(ctx, tr.ID, opts)
}

// ResumeImportFromSource resumes the read of an import's package and waits until it is planned.
func (c *Client) ResumeImportFromSource(ctx context.Context, id, accessKeyID, secretAccessKey string, opts ImportPackageOptions) (*OrgTransfer, error) {
	tr, err := c.ResumeOrgImportFetch(ctx, id, accessKeyID, secretAccessKey)
	if err != nil {
		return nil, err
	}
	return c.waitPlanned(ctx, tr.ID, opts)
}

func (c *Client) waitPlanned(ctx context.Context, id string, opts ImportPackageOptions) (*OrgTransfer, error) {
	interval := opts.PollInterval
	if interval <= 0 {
		interval = 2 * time.Second
	}
	return c.WaitOrgTransfer(ctx, id, []string{OrgTransferPlanned}, OrgWaitOptions{
		Interval: interval,
		Progress: func(t *OrgTransfer) {
			if opts.Progress == nil {
				return
			}
			var done, total int64
			if t.Upload != nil {
				done, total = t.Upload.UploadedBytes, t.Upload.SizeBytes
			}
			opts.Progress(t.Status, done, total)
		},
	})
}

// ImportPackageOptions configure ImportPackage.
type ImportPackageOptions struct {
	PollInterval time.Duration
	// Progress is called after every chunk and on every phase change (resume, uploading,
	// verifying).
	Progress func(phase string, uploaded, total int64)
}

// ImportPackage uploads a package file and waits until the API verified it and planned the
// import: resumes an open import of the same file (same filename, size and SHA-256) from what
// the API has, sends the rest in chunks, completes the upload and returns the planned transfer.
func (c *Client) ImportPackage(ctx context.Context, file string, opts ImportPackageOptions) (*OrgTransfer, error) {
	f, err := os.Open(file)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	st, err := f.Stat()
	if err != nil {
		return nil, err
	}
	if st.Size() == 0 {
		return nil, fmt.Errorf("%s is empty", file)
	}
	sum, err := fileSHA256(file)
	if err != nil {
		return nil, err
	}
	interval := opts.PollInterval
	if interval <= 0 {
		interval = 2 * time.Second
	}
	progress := func(phase string, uploaded int64) {
		if opts.Progress != nil {
			opts.Progress(phase, uploaded, st.Size())
		}
	}
	name := filepath.Base(file)

	var tr *OrgTransfer
	if open, err := c.ListOrgTransfers(ctx); err == nil {
		for i := range open {
			o := open[i]
			if o.Kind != "import" || o.Status != OrgTransferUploading || o.Upload == nil || o.Upload.SizeBytes != st.Size() {
				continue
			}
			// the same file: its SHA-256, or (an upload started without one) its name
			if o.Upload.SHA256 == sum || (o.Upload.SHA256 == "" && o.Package != nil && o.Package.Filename == name) {
				tr = &o
				progress("resume", o.Upload.UploadedBytes)
				break
			}
		}
	}
	if tr == nil {
		if tr, err = c.StartOrgImport(ctx, StartOrgImportInput{Filename: name, Size: st.Size(), SHA256: sum}); err != nil {
			return nil, err
		}
	}
	if tr.Status != OrgTransferUploading || tr.Upload == nil {
		return nil, fmt.Errorf("import %s is %s and takes no upload", tr.ID, tr.Status)
	}

	chunk := tr.Upload.ChunkSize
	if chunk <= 0 {
		chunk = DefaultUploadChunkSize
	}
	buf := make([]byte, chunk)
	offset := tr.Upload.UploadedBytes
	progress("uploading", offset)
	for offset < st.Size() {
		n, err := f.ReadAt(buf, offset)
		if err != nil && !errors.Is(err, io.EOF) {
			return nil, err
		}
		if n == 0 {
			break
		}
		received, err := c.UploadOrgImportChunk(ctx, tr.ID, offset, buf[:n])
		if err != nil {
			return nil, err
		}
		if received > offset {
			offset = received
		} else {
			offset += int64(n)
		}
		progress("uploading", offset)
	}

	if tr, err = c.CompleteOrgImport(ctx, tr.ID); err != nil {
		return nil, err
	}
	progress(tr.Status, st.Size())
	if tr.Status == OrgTransferPlanned {
		return tr, nil
	}
	return c.WaitOrgTransfer(ctx, tr.ID, []string{OrgTransferPlanned}, OrgWaitOptions{
		Interval: interval,
		Progress: func(t *OrgTransfer) { progress(t.Status, st.Size()) },
	})
}
