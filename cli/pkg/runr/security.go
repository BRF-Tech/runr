package runr

import (
	"context"
	"encoding/json"
	"net/http"
	"net/url"
	"time"
)

// Software supply chain (docs/supply-chain.md): per version an SBOM, a vulnerability scan and a
// signature; the region's policy decides whether a version may be deployed. Data residency
// limits the regions an organization may use.

// SBOM formats of GET …/sbom?format=.
const (
	SBOMSPDX      = "spdx-json"
	SBOMCycloneDX = "cyclonedx-json"
)

// SeverityCounts are vulnerability counts by severity.
type SeverityCounts struct {
	Critical int `json:"critical"`
	High     int `json:"high"`
	Medium   int `json:"medium"`
	Low      int `json:"low"`
	Unknown  int `json:"unknown"`
}

// SecurityReport is ImageSecurityReport::summary (and ::detail with findings).
type SecurityReport struct {
	ID                string         `json:"id"`
	SubjectType       string         `json:"subject_type,omitempty"`
	VersionID         string         `json:"version_id,omitempty"`
	ImageKind         string         `json:"image_kind,omitempty"`
	ImageRef          string         `json:"image_ref,omitempty"`
	ImageDigest       string         `json:"image_digest,omitempty"`
	Status            string         `json:"status"`
	SBOMStatus        string         `json:"sbom_status,omitempty"`
	ScanStatus        string         `json:"scan_status,omitempty"`
	SignatureStatus   string         `json:"signature_status,omitempty"`
	AttestationStatus string         `json:"attestation_status,omitempty"`
	Counts            SeverityCounts `json:"counts"`
	Fixable           struct {
		Critical int `json:"critical"`
		High     int `json:"high"`
	} `json:"fixable"`
	FindingsTotal int    `json:"findings_total"`
	Packages      *int   `json:"packages,omitempty"`
	ScannedAt     string `json:"scanned_at,omitempty"`
	SignedAt      string `json:"signed_at,omitempty"`
	SignatureKey  string `json:"signature_key_fingerprint,omitempty"`
	FinishedAt    string `json:"finished_at,omitempty"`
	Error         string `json:"error,omitempty"`
	// Detail fields (version and deployment reports).
	Findings    []SecurityFinding `json:"findings,omitempty"`
	SBOMFormats []string          `json:"sbom_formats,omitempty"`
	StartedAt   string            `json:"started_at,omitempty"`
}

// SecurityFinding is one vulnerability of a report.
type SecurityFinding struct {
	ID        string `json:"id"`
	Severity  string `json:"severity"`
	Package   string `json:"package"`
	Installed string `json:"installed,omitempty"`
	Fixed     string `json:"fixed,omitempty"`
	Title     string `json:"title,omitempty"`
	URL       string `json:"url,omitempty"`
	Target    string `json:"target,omitempty"`
}

// SecurityVersion is one version row of the security tab.
type SecurityVersion struct {
	ID          string          `json:"id"`
	Number      int             `json:"number"`
	Label       string          `json:"label"`
	BuildStatus string          `json:"build_status,omitempty"`
	CreatedAt   string          `json:"created_at,omitempty"`
	ImageKind   string          `json:"image_kind,omitempty"`
	Image       string          `json:"image,omitempty"`
	Applicable  bool            `json:"applicable"`
	Report      *SecurityReport `json:"report,omitempty"`
}

// SecurityOverview is GET /projects/{project}/security.
type SecurityOverview struct {
	Region *struct {
		ID           string `json:"id"`
		Name         string `json:"name"`
		DataLocation string `json:"data_location,omitempty"`
	} `json:"region,omitempty"`
	Policy      map[string]any    `json:"policy,omitempty"`
	Pipeline    map[string]any    `json:"pipeline,omitempty"`
	Versions    []SecurityVersion `json:"versions"`
	Deployments []struct {
		ID           string          `json:"id"`
		Status       string          `json:"status"`
		VersionID    string          `json:"version_id"`
		VersionLabel string          `json:"version_label,omitempty"`
		CreatedAt    string          `json:"created_at,omitempty"`
		Report       *SecurityReport `json:"report,omitempty"`
	} `json:"deployments"`
	Overrides []map[string]any `json:"overrides,omitempty"`
}

// SecurityDecision is whether the region's policy lets a version deploy.
type SecurityDecision struct {
	Applicable bool             `json:"applicable"`
	Blocked    bool             `json:"blocked"`
	Overridden bool             `json:"overridden"`
	Violations []map[string]any `json:"violations,omitempty"`
	Policy     map[string]any   `json:"policy,omitempty"`
}

// VersionSecurity is GET /projects/{project}/versions/{version}/security.
type VersionSecurity struct {
	Version  SecurityVersion   `json:"version"`
	Report   *SecurityReport   `json:"report,omitempty"`
	Decision *SecurityDecision `json:"decision,omitempty"`
}

// ProjectSecurity returns the security overview of a project.
func (c *Client) ProjectSecurity(ctx context.Context, projectID string) (*SecurityOverview, error) {
	var out struct {
		Data SecurityOverview `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/security"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// VersionSecurityReport returns the supply-chain report of a version and the policy decision.
func (c *Client) VersionSecurityReport(ctx context.Context, projectID, versionID string) (*VersionSecurity, error) {
	var out struct {
		Data VersionSecurity `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/versions/" + versionID + "/security"}, &out)
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// DeploymentSecurityReport returns the report of the version a deployment runs.
func (c *Client) DeploymentSecurityReport(ctx context.Context, projectID, deploymentID string) (*VersionSecurity, error) {
	var out struct {
		Data struct {
			DeploymentID string           `json:"deployment_id"`
			Version      *SecurityVersion `json:"version"`
			Report       *SecurityReport  `json:"report"`
		} `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/deployments/" + deploymentID + "/security"}, &out)
	if err != nil {
		return nil, err
	}
	res := &VersionSecurity{Report: out.Data.Report}
	if out.Data.Version != nil {
		res.Version = *out.Data.Version
	}
	return res, nil
}

// ScanVersion queues a new scan of a version (a full run when it has no SBOM yet). The API
// answers 409 while a run is in progress and 422 when the version has no image to scan.
func (c *Client) ScanVersion(ctx context.Context, projectID, versionID string) (*SecurityReport, error) {
	var out struct {
		Data SecurityReport `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/versions/" + versionID + "/security/scan"}, &out)
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// ReportRunning reports whether a supply-chain run of r is queued or in progress.
func ReportRunning(r *SecurityReport) bool {
	return r != nil && (r.Status == "pending" || r.Status == "running")
}

// WaitSecurityReport polls the report of a version until its run finished. A failed run is a
// FailedError, a run that does not finish in time a TimeoutError.
func (c *Client) WaitSecurityReport(ctx context.Context, projectID string, v *Version, opts WaitOptions) (*VersionSecurity, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	what := "the supply-chain report of " + v.Label
	last := ""
	for {
		cur, err := c.VersionSecurityReport(ctx, projectID, v.ID)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		if !ReportRunning(cur.Report) {
			if cur.Report != nil && cur.Report.Status == "failed" {
				return cur, &FailedError{What: "supply-chain run of", Ref: v.Label, Status: "failed", Message: cur.Report.Error}
			}
			return cur, nil
		}
		if cur.Report.Status != last {
			last = cur.Report.Status
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

// DownloadSBOM streams the SBOM of a version (format SBOMSPDX or SBOMCycloneDX) into d.
func (c *Client) DownloadSBOM(ctx context.Context, projectID, versionID, format string, d *Download) error {
	q := url.Values{}
	if format != "" {
		q.Set("format", format)
	}
	return c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/versions/" + versionID + "/sbom",
		query: q, accept: "application/spdx+json, application/vnd.cyclonedx+json, application/json"}, d)
}

// ModelServiceSecurity is GET /model-services/{service}/security: the supply-chain report of a
// model service's image (an engine image or the service's own). Scanned is whether the region
// scans external images; Current whether the report is of the image the service runs now.
type ModelServiceSecurity struct {
	Image   string          `json:"image,omitempty"`
	Scanned bool            `json:"scanned"`
	Current bool            `json:"current"`
	Report  *SecurityReport `json:"report,omitempty"`
}

// ModelServiceSecurity returns the supply-chain report of a model service's image.
func (c *Client) ModelServiceSecurity(ctx context.Context, serviceID string) (*ModelServiceSecurity, error) {
	var out struct {
		Data ModelServiceSecurity `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/model-services/" + seg(serviceID) + "/security"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// DownloadModelServiceSBOM streams the SBOM of a model service's image (format SBOMSPDX or
// SBOMCycloneDX) into d; 404 when the image has no report.
func (c *Client) DownloadModelServiceSBOM(ctx context.Context, serviceID, format string, d *Download) error {
	q := url.Values{}
	if format != "" {
		q.Set("format", format)
	}
	return c.do(ctx, request{method: http.MethodGet, path: "/model-services/" + seg(serviceID) + "/sbom", query: q,
		accept: "application/spdx+json, application/vnd.cyclonedx+json, application/json"}, d)
}

// Residency is GET /residency: the regions the organization may use.
type Residency struct {
	TenantID          string            `json:"tenant_id,omitempty"`
	Restricted        bool              `json:"restricted"`
	ResidencyEnforced bool              `json:"residency_enforced"`
	AllowedRegionIDs  []string          `json:"allowed_region_ids"`
	AllowedLocations  []string          `json:"allowed_locations"`
	DefaultRegionID   string            `json:"default_region_id,omitempty"`
	Regions           []ResidencyRegion `json:"regions"`
}

// ResidencyRegion is one region and whether the organization may create projects there.
type ResidencyRegion struct {
	ID           string `json:"id"`
	Name         string `json:"name"`
	DataLocation string `json:"data_location,omitempty"`
	Allowed      bool   `json:"allowed"`
}

// UnmarshalJSON tolerates allowed_region_ids as null (no restriction) and lists as {} / [].
func (r *Residency) UnmarshalJSON(b []byte) error {
	type plain struct {
		TenantID          *string           `json:"tenant_id"`
		Restricted        bool              `json:"restricted"`
		ResidencyEnforced bool              `json:"residency_enforced"`
		AllowedRegionIDs  json.RawMessage   `json:"allowed_region_ids"`
		AllowedLocations  json.RawMessage   `json:"allowed_locations"`
		DefaultRegionID   *string           `json:"default_region_id"`
		Regions           []ResidencyRegion `json:"regions"`
	}
	var p plain
	if err := json.Unmarshal(b, &p); err != nil {
		return err
	}
	*r = Residency{Restricted: p.Restricted, ResidencyEnforced: p.ResidencyEnforced, Regions: p.Regions}
	if p.TenantID != nil {
		r.TenantID = *p.TenantID
	}
	if p.DefaultRegionID != nil {
		r.DefaultRegionID = *p.DefaultRegionID
	}
	if len(p.AllowedRegionIDs) > 0 && p.AllowedRegionIDs[0] == '[' {
		_ = json.Unmarshal(p.AllowedRegionIDs, &r.AllowedRegionIDs)
	}
	if len(p.AllowedLocations) > 0 && p.AllowedLocations[0] == '[' {
		_ = json.Unmarshal(p.AllowedLocations, &r.AllowedLocations)
	}
	if r.Regions == nil {
		r.Regions = []ResidencyRegion{}
	}
	return nil
}

// GetResidency returns the organization's data residency policy.
func (c *Client) GetResidency(ctx context.Context) (*Residency, error) {
	var out struct {
		Data Residency `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/residency"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}
