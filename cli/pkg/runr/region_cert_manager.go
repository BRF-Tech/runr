package runr

import (
	"context"
	"net/http"
	"net/url"
	"time"
)

// cert-manager of a region and the ClusterIssuer of its custom domains (CertManagerController,
// docs/regions.md#cert-manager). An API without it answers 404 (the client then says so).

// Issuer modes of RunR's default issuer (region setting cert_manager_issuer_mode).
var CertManagerIssuerModes = []string{"auto", "acme", "ca"}

// CertManagerIssuer is the ClusterIssuer custom domains are issued by: kind acme (Let's
// Encrypt), ca (the region CA) or own (an operator's); source runr (RunR's default), region
// (the region setting) or platform (RUNR_CERT_MANAGER_CLUSTER_ISSUER).
type CertManagerIssuer struct {
	Name   string `json:"name,omitempty"`
	Kind   string `json:"kind,omitempty"`
	Source string `json:"source,omitempty"`
}

// CertManagerPlan is RunR's default issuer for the region as things are now, and why.
type CertManagerPlan struct {
	Kind   string `json:"kind"`
	Reason string `json:"reason"`
}

// CertManagerAcme is the ACME side: the account e-mail (region or platform), the server and
// whether the region is reachable from the internet.
type CertManagerAcme struct {
	Email        string `json:"email,omitempty"`
	EmailSource  string `json:"email_source,omitempty"`
	Server       string `json:"server,omitempty"`
	Public       bool   `json:"public"`
	PublicReason string `json:"public_reason,omitempty"`
}

// CertManagerDomains says whether custom domains of the region can use TLS mode cert-manager,
// and if not why and what to do.
type CertManagerDomains struct {
	Available bool   `json:"available"`
	Status    string `json:"status,omitempty"`
	Issuer    string `json:"issuer,omitempty"`
	Kind      string `json:"kind,omitempty"`
	Reason    string `json:"reason,omitempty"`
	// CA: the certificates come from the region CA (runr regions cert-manager ca downloads it).
	CA bool `json:"ca"`
	// DefaultMode is the TLS mode a new domain starts in (domains meta only).
	DefaultMode string `json:"default_mode,omitempty"`
}

// CertManagerCluster is what the cluster runs (cluster=true).
type CertManagerCluster struct {
	Present            bool     `json:"present"`
	Runr               bool     `json:"runr"`
	Namespace          string   `json:"namespace,omitempty"`
	Version            string   `json:"version,omitempty"`
	Available          bool     `json:"available"`
	Drift              []string `json:"drift,omitempty"`
	IssuerExists       bool     `json:"issuer_exists"`
	IssuerReady        bool     `json:"issuer_ready"`
	IssuerMessage      string   `json:"issuer_message,omitempty"`
	CAAvailable        bool     `json:"ca_available"`
	DomainCertificates int      `json:"domain_certificates"`
}

// RegionCertManager is GET /knative-regions/{region}/cert-manager.
type RegionCertManager struct {
	// Status is not_installed, installing, ready, uninstalling or error.
	Status  string `json:"status"`
	Message string `json:"message,omitempty"`
	// Managed: RunR installed it (true) or reuses the cluster's own (false); nil: neither yet.
	Managed        *bool              `json:"managed"`
	Version        string             `json:"version,omitempty"`
	Namespace      string             `json:"namespace,omitempty"`
	ChartVersion   string             `json:"chart_version,omitempty"`
	InstallEnabled bool               `json:"install_enabled"`
	Issuer         CertManagerIssuer  `json:"issuer"`
	IssuerMode     string             `json:"issuer_mode,omitempty"`
	Plan           CertManagerPlan    `json:"plan"`
	Acme           CertManagerAcme    `json:"acme"`
	Domains        CertManagerDomains `json:"domains"`
	CanManage      bool               `json:"can_manage"`
	// Cluster is nil without a cluster read (cluster=false, ClusterError).
	Cluster      *CertManagerCluster `json:"cluster,omitempty"`
	ClusterError string              `json:"cluster_error,omitempty"`
}

// RegionCertManager returns cert-manager of a region (regions.view); cluster adds what the
// cluster runs (the issuer's Ready condition, the region CA).
func (c *Client) RegionCertManager(ctx context.Context, regionID string, cluster bool) (*RegionCertManager, error) {
	q := url.Values{}
	if !cluster {
		q.Set("cluster", "0")
	}
	var out RegionCertManager
	if err := c.do(ctx, request{method: http.MethodGet, path: regionPath(regionID, "/cert-manager"), query: q}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// InstallRegionCertManager sets cert-manager up in the region (regions.manage): the cluster's
// own is reused, RunR installs one where there is none, then the default ClusterIssuer. Queued.
func (c *Client) InstallRegionCertManager(ctx context.Context, regionID string) (*RegionOperation, error) {
	return c.regionOperation(ctx, http.MethodPost, regionPath(regionID, "/cert-manager"), nil)
}

// RemoveRegionCertManager stops using cert-manager in the region (regions.manage): refused while
// custom domains have cert-manager certificates; RunR's parts go only where nothing else uses them.
func (c *Client) RemoveRegionCertManager(ctx context.Context, regionID string) (*RegionOperation, error) {
	return c.regionOperation(ctx, http.MethodDelete, regionPath(regionID, "/cert-manager"), nil)
}

// RegionCertManagerCA returns the region CA's certificate (PEM, regions.view).
func (c *Client) RegionCertManagerCA(ctx context.Context, regionID string) ([]byte, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: regionPath(regionID, "/cert-manager/ca-certificate"),
		accept: "application/x-pem-file, application/json"}, &raw); err != nil {
		return nil, err
	}
	return raw, nil
}

// DomainsCACertificate returns the CA of the project's region when its custom-domain certificates
// come from it (PEM, projects.view).
func (c *Client) DomainsCACertificate(ctx context.Context, projectID string) ([]byte, error) {
	return c.EnvironmentDomainsCACertificate(ctx, projectID, "")
}

// EnvironmentDomainsCACertificate is DomainsCACertificate for one environment of the project: the
// CA of the region that environment runs in ("" the project's own region).
func (c *Client) EnvironmentDomainsCACertificate(ctx context.Context, projectID, environment string) ([]byte, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + seg(projectID) + "/domains/ca-certificate",
		query: environmentQuery(environment), accept: "application/x-pem-file, application/json"}, &raw); err != nil {
		return nil, err
	}
	return raw, nil
}

// WaitRegionCertManager polls cert-manager of a region until its install or removal ends: ready
// or not_installed, a *FailedError for error.
func (c *Client) WaitRegionCertManager(ctx context.Context, regionID, regionName string, opts WaitOptions) (*RegionCertManager, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	what := "cert-manager of " + regionName
	last := ""
	for {
		cur, err := c.RegionCertManager(ctx, regionID, false)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		switch cur.Status {
		case KnativeInstalling, KnativeUninstalling:
		case KnativeError:
			return cur, &FailedError{What: "cert-manager of region", Ref: regionName, Status: cur.Status, Message: lastLine(cur.Message)}
		default:
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
