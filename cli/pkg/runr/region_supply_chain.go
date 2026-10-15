package runr

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/url"
	"time"
)

// The supply chain of a region (RegionSupplyChainController; docs/supply-chain.md): SBOM and
// vulnerability scan switches, the scanner DB, the region's policy gate, the signing key, the
// admission policy, the policy for CaaS images and the region's data location. Reading needs
// regions.view, changes regions.supply_chain.manage on a region the caller may change.

// RetiredSigningKey is a signing key that was rotated out: images it signed still verify while
// it is listed.
type RetiredSigningKey struct {
	Fingerprint string `json:"fingerprint"`
	PublicKey   string `json:"public_key,omitempty"`
	RetiredAt   string `json:"retired_at,omitempty"`
}

// RegionSupplyChain is GET /knative-regions/{region}/supply-chain (never the private key).
type RegionSupplyChain struct {
	RegionID string `json:"knative_region_id,omitempty"`

	SBOMEnabled         bool `json:"sbom_enabled"`
	ScanEnabled         bool `json:"scan_enabled"`
	ScanExternalImages  bool `json:"scan_external_images"`
	RescanIntervalHours int  `json:"rescan_interval_hours"`

	ScannerDBMode        string `json:"scanner_db_mode"`
	ScannerDBSource      string `json:"scanner_db_source,omitempty"`
	ScannerDBStatus      string `json:"scanner_db_status"`
	ScannerDBUpdatedAt   string `json:"scanner_db_updated_at,omitempty"`
	ScannerDBRefreshedAt string `json:"scanner_db_refreshed_at,omitempty"`
	ScannerDBMessage     string `json:"scanner_db_message,omitempty"`

	GateMode        string `json:"gate_mode"`
	GateMaxCritical *int   `json:"gate_max_critical"`
	GateMaxHigh     *int   `json:"gate_max_high"`
	GateOnlyFixable bool   `json:"gate_only_fixable"`
	GateRequireScan bool   `json:"gate_require_scan"`

	SigningEnabled        bool                `json:"signing_enabled"`
	VerifyOnDeploy        bool                `json:"verify_on_deploy"`
	AttestEnabled         bool                `json:"attest_enabled"`
	SigningStatus         string              `json:"signing_status"`
	SigningPublicKey      string              `json:"signing_public_key,omitempty"`
	SigningKeyFingerprint string              `json:"signing_key_fingerprint,omitempty"`
	SigningKeyCreatedAt   string              `json:"signing_key_created_at,omitempty"`
	SigningRetiredKeys    []RetiredSigningKey `json:"signing_retired_keys"`
	SigningMessage        string              `json:"signing_message,omitempty"`

	AdmissionMode         string   `json:"admission_mode"`
	AdmissionStatus       string   `json:"admission_status"`
	AdmissionMessage      string   `json:"admission_message,omitempty"`
	AdmissionRequireSBOM  bool     `json:"admission_require_sbom"`
	AdmissionExemptImages []string `json:"admission_exempt_images"`

	CaaSPolicy            string   `json:"caas_policy"`
	CaaSAllowedRegistries []string `json:"caas_allowed_registries"`
	CaaSTrustedKeys       []string `json:"caas_trusted_keys"`

	DataLocation string `json:"data_location,omitempty"`
	UpdatedBy    string `json:"updated_by,omitempty"`

	Region struct {
		ID               string `json:"id"`
		Name             string `json:"name"`
		HasRegistry      bool   `json:"has_registry"`
		RegistryMode     string `json:"registry_mode,omitempty"`
		ProjectNamespace string `json:"project_namespace,omitempty"`
	} `json:"region"`
	ScannerDBRepository    string `json:"scanner_db_repository,omitempty"`
	ScannerDBDefaultSource string `json:"scanner_db_default_source,omitempty"`
	SupplyChainNamespace   string `json:"supply_chain_namespace,omitempty"`
	CanManage              bool   `json:"can_manage"`
}

// Parts of a region's supply chain whose changes run in the background (WaitRegionSupplyChain).
const (
	SupplyChainSigning   = "signing"
	SupplyChainScannerDB = "scanner_db"
	SupplyChainAdmission = "admission"
)

// Status returns the status of a part (SupplyChainSigning, …) and its message.
func (s *RegionSupplyChain) Status(part string) (string, string) {
	switch part {
	case SupplyChainSigning:
		return s.SigningStatus, s.SigningMessage
	case SupplyChainScannerDB:
		return s.ScannerDBStatus, s.ScannerDBMessage
	}
	return s.AdmissionStatus, s.AdmissionMessage
}

// RegionSupplyChainChange is the body of PUT …/supply-chain: only the given keys change; nil
// clears a nullable setting.
type RegionSupplyChainChange map[string]any

func supplyChainPath(regionID string) string {
	return "/knative-regions/" + seg(regionID) + "/supply-chain"
}

func (c *Client) supplyChainCall(ctx context.Context, method, path string, body any) (*RegionSupplyChain, error) {
	var out struct {
		Data RegionSupplyChain `json:"data"`
	}
	if err := c.do(ctx, request{method: method, path: path, body: body}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// RegionSupplyChain returns the supply-chain settings and state of a region.
func (c *Client) RegionSupplyChain(ctx context.Context, regionID string) (*RegionSupplyChain, error) {
	return c.supplyChainCall(ctx, http.MethodGet, supplyChainPath(regionID), nil)
}

// UpdateRegionSupplyChain changes settings of a region's supply chain.
func (c *Client) UpdateRegionSupplyChain(ctx context.Context, regionID string, change RegionSupplyChainChange) (*RegionSupplyChain, error) {
	return c.supplyChainCall(ctx, http.MethodPut, supplyChainPath(regionID), map[string]any(change))
}

// RotateRegionSigningKey generates the region's signing key, or a new one: the current key is
// retired (images it signed still verify while it is listed). It runs in the background.
func (c *Client) RotateRegionSigningKey(ctx context.Context, regionID string) (*RegionSupplyChain, error) {
	return c.supplyChainCall(ctx, http.MethodPost, supplyChainPath(regionID)+"/signing-key", nil)
}

// ForgetRetiredSigningKeys removes the retired keys: images only they signed no longer verify.
func (c *Client) ForgetRetiredSigningKeys(ctx context.Context, regionID string) (*RegionSupplyChain, error) {
	return c.supplyChainCall(ctx, http.MethodDelete, supplyChainPath(regionID)+"/signing-key/retired", nil)
}

// RefreshScannerDB copies the vulnerability database into the region's registry (mirror and
// offline modes) in the background.
func (c *Client) RefreshScannerDB(ctx context.Context, regionID string) (*RegionSupplyChain, error) {
	return c.supplyChainCall(ctx, http.MethodPost, supplyChainPath(regionID)+"/scanner-db/refresh", nil)
}

// InstallAdmission installs the admission policy (or applies an installed one again), with mode
// enforce or warn ("" keeps the region's mode). It runs in the background.
func (c *Client) InstallAdmission(ctx context.Context, regionID, mode string) (*RegionSupplyChain, error) {
	body := map[string]any{}
	if mode != "" {
		body["mode"] = mode
	}
	return c.supplyChainCall(ctx, http.MethodPost, supplyChainPath(regionID)+"/admission", body)
}

// UninstallAdmission removes the admission policy in the background.
func (c *Client) UninstallAdmission(ctx context.Context, regionID string) (*RegionSupplyChain, error) {
	return c.supplyChainCall(ctx, http.MethodDelete, supplyChainPath(regionID)+"/admission", nil)
}

// AdmissionPreview is what an admission install applies: the Helm release and values, the
// policy for images no policy matches, and the ClusterImagePolicy documents.
type AdmissionPreview struct {
	Release          string            `json:"release"`
	Chart            string            `json:"chart"`
	ChartVersion     string            `json:"chart_version"`
	Namespace        string            `json:"namespace"`
	ProjectNamespace string            `json:"project_namespace"`
	NamespaceLabel   string            `json:"namespace_label"`
	Values           json.RawMessage   `json:"values,omitempty"`
	NoMatchPolicy    string            `json:"no_match_policy"`
	Policies         []json.RawMessage `json:"policies"`
}

// PolicyNames returns the metadata.name of every policy document.
func (p *AdmissionPreview) PolicyNames() []string {
	names := []string{}
	for _, raw := range p.Policies {
		var doc struct {
			Metadata struct {
				Name string `json:"name"`
			} `json:"metadata"`
		}
		if json.Unmarshal(raw, &doc) == nil && doc.Metadata.Name != "" {
			names = append(names, doc.Metadata.Name)
		}
	}
	return names
}

// AdmissionPreview returns what an admission install would apply.
func (c *Client) AdmissionPreview(ctx context.Context, regionID string) (*AdmissionPreview, error) {
	var out struct {
		Data AdmissionPreview `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: supplyChainPath(regionID) + "/admission/preview"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// WriteRegionPublicKey writes the region's signing public key (PEM, for cosign verify --key)
// to w; 404 when the region has no key.
func (c *Client) WriteRegionPublicKey(ctx context.Context, regionID string, w io.Writer) (*Download, error) {
	d := &Download{W: w}
	err := c.do(ctx, request{method: http.MethodGet, path: supplyChainPath(regionID) + "/public-key",
		accept: "application/x-pem-file, text/plain"}, d)
	if err != nil {
		return nil, err
	}
	return d, nil
}

// RuntimeImageReport is a runtime image built in a region with its supply-chain report.
type RuntimeImageReport struct {
	BuildID string          `json:"build_id"`
	Runtime string          `json:"runtime"`
	Version string          `json:"version"`
	Status  string          `json:"status"`
	Image   string          `json:"image,omitempty"`
	Report  *SecurityReport `json:"report,omitempty"`
}

// RegionRuntimeImages returns the runtime images built in a region and their reports.
func (c *Client) RegionRuntimeImages(ctx context.Context, regionID string) ([]RuntimeImageReport, error) {
	var out struct {
		Data []RuntimeImageReport `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: supplyChainPath(regionID) + "/runtime-images"}, &out); err != nil {
		return nil, err
	}
	if out.Data == nil {
		out.Data = []RuntimeImageReport{}
	}
	return out.Data, nil
}

// DownloadRuntimeImageSBOM streams the SBOM of a runtime image report (format SBOMSPDX or
// SBOMCycloneDX) into d.
func (c *Client) DownloadRuntimeImageSBOM(ctx context.Context, regionID, reportID, format string, d *Download) error {
	q := url.Values{}
	if format != "" {
		q.Set("format", format)
	}
	return c.do(ctx, request{method: http.MethodGet, path: supplyChainPath(regionID) + "/runtime-images/" + seg(reportID) + "/sbom",
		query: q, accept: "application/spdx+json, application/vnd.cyclonedx+json, application/json"}, d)
}

// supplyChainBusy are the statuses of a part that is still changing.
var supplyChainBusy = map[string]bool{"generating": true, "refreshing": true, "installing": true, "removing": true}

// WaitRegionSupplyChain polls a region's supply chain until a part (SupplyChainSigning, …)
// finished changing; a *FailedError when it ended failed.
func (c *Client) WaitRegionSupplyChain(ctx context.Context, regionID, regionName, part string, opts WaitOptions) (*RegionSupplyChain, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	what := part + " of region " + regionName
	last := ""
	for {
		cur, err := c.RegionSupplyChain(ctx, regionID)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		status, msg := cur.Status(part)
		if status == "failed" {
			return cur, &FailedError{What: part + " of region", Ref: regionName, Status: status, Message: msg}
		}
		if !supplyChainBusy[status] {
			return cur, nil
		}
		if status != last {
			last = status
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
