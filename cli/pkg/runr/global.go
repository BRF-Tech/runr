package runr

import (
	"context"
	"fmt"
	"net/http"
	"time"
)

// Global projects (docs/global-projects.md): one project running in several regions behind one
// global host name <slug>.<zone>. The primary region (the project's knative_region_id) builds and
// deploys first; RunR rolls the version out to the other regions when it is ready there. The
// zone's DNS answers with the regions whose copy is healthy: the primary first (mode ha) or the
// closest one (mode proximity). Only platform regions with global DNS take part.

// Placements of a project (ProjectResource.placement).
const (
	PlacementRegional = "regional"
	PlacementGlobal   = "global"
)

// Modes of a global project.
const (
	GlobalModeHA        = "ha"
	GlobalModeProximity = "proximity"
)

// GlobalModes are the modes a global project can have, default first.
var GlobalModes = []string{GlobalModeHA, GlobalModeProximity}

// What happens when a global project's primary region fails: automatic — RunR moves the
// primary, its data and its triggers to the next healthy region; manual — the DNS answers the
// other regions and the primary stays until an operator moves it (FailoverProjectGlobal).
const (
	GlobalFailoverAutomatic = "automatic"
	GlobalFailoverManual    = "manual"
)

// GlobalFailovers are the failover settings, default first.
var GlobalFailovers = []string{GlobalFailoverAutomatic, GlobalFailoverManual}

// Roles of a region in a global project.
const (
	GlobalRolePrimary   = "primary"
	GlobalRoleSecondary = "secondary"
)

// Statuses of a global project's copy in one region.
const (
	GlobalRegionPending   = "pending"
	GlobalRegionBuilding  = "building"
	GlobalRegionDeploying = "deploying"
	GlobalRegionReady     = "ready"
	GlobalRegionFailed    = "failed"
	GlobalRegionRemoving  = "removing"
	// GlobalRegionFenced: the region lost the primary in a failover; it is out of the DNS answers
	// until it answers again, then its data is rebuilt from the new primary.
	GlobalRegionFenced = "fenced"
)

// Data status of a global project's copy in one region: whether it reaches the project's
// bindings (replica clusters streaming, data links up); "" when the project has no regional data.
const (
	GlobalDataReady   = "ready"
	GlobalDataSyncing = "syncing"
	GlobalDataFailed  = "failed"
)

// How a binding of a global project is reached from its regions (GlobalBindingData.Access).
const (
	GlobalAccessDirect   = "direct"
	GlobalAccessLink     = "link"
	GlobalAccessReplicas = "replicas"
)

// Statuses of a region's global DNS server.
const (
	GlobalDNSDisabled     = "disabled"
	GlobalDNSInstalling   = "installing"
	GlobalDNSReady        = "ready"
	GlobalDNSError        = "error"
	GlobalDNSUninstalling = "uninstalling"
)

// MaxGlobalRegions is the most regions a global project can run in (the API's
// global.max_regions default).
const MaxGlobalRegions = 8

// ProjectGlobal is ProjectResource.global and GET /projects/{project}/global.
type ProjectGlobal struct {
	Mode string `json:"mode"`
	// Failover is automatic or manual (empty on APIs before failover); FailoverAfterSeconds how long
	// the primary's health check fails before an automatic failover.
	Failover             string `json:"failover,omitempty"`
	FailoverAfterSeconds int    `json:"failover_after_seconds,omitempty"`
	// MovingTo is the region a running switchover or failover moves the primary to.
	MovingTo string `json:"moving_to,omitempty"`
	// Hostname is <slug>.<zone>; empty while the platform has no base domain.
	Hostname        string         `json:"hostname,omitempty"`
	PrimaryRegionID string         `json:"primary_region_id"`
	Regions         []GlobalRegion `json:"regions"`
	// Bindings are the project's bindings and how each region reaches them.
	Bindings []GlobalBindingData `json:"bindings,omitempty"`
	// Warnings are what the project uses that does not reach every region (bindings, …).
	Warnings []string `json:"warnings,omitempty"`
}

// GlobalBindingData is one binding of a global project across its regions: reached as it is
// (direct: an external binding), through its data link to the home region (link), or from a
// replica cluster in every region (replicas: a dedicated Postgres binding).
type GlobalBindingData struct {
	BindingID      string                `json:"binding_id"`
	Name           string                `json:"name"`
	Type           string                `json:"type"`
	Plan           string                `json:"plan,omitempty"`
	HomeRegionID   string                `json:"home_region_id,omitempty"`
	HomeRegionName string                `json:"home_region_name,omitempty"`
	Access         string                `json:"access"`
	Regions        []GlobalBindingRegion `json:"regions"`
}

// GlobalBindingRegion is a binding in one region: home (its data), replica or link.
type GlobalBindingRegion struct {
	RegionID      string     `json:"region_id"`
	RegionName    string     `json:"region_name"`
	Kind          string     `json:"kind"`
	Status        string     `json:"status"`
	StatusMessage string     `json:"status_message,omitempty"`
	LagSeconds    *int       `json:"lag_seconds"`
	CheckedAt     *time.Time `json:"checked_at,omitempty"`
}

// GlobalRegion is the project's copy in one region.
type GlobalRegion struct {
	RegionID      string `json:"region_id"`
	RegionName    string `json:"region_name"`
	Role          string `json:"role"`
	Position      int    `json:"position"`
	Status        string `json:"status"`
	StatusMessage string `json:"status_message,omitempty"`
	// Version is the version number the region serves (nil: nothing yet); TargetVersion the one
	// being rolled out to it.
	Version       *int `json:"version"`
	TargetVersion *int `json:"target_version"`
	// Healthy is the last health check from the control plane (nil: never checked).
	Healthy         *bool      `json:"healthy"`
	HealthCheckedAt *time.Time `json:"health_checked_at,omitempty"`
	// Address is the region's external address (its Traefik).
	Address string `json:"address,omitempty"`
	// InDNS: the region is part of the DNS answer set now.
	InDNS bool `json:"in_dns"`
	// TriggersActive: the schedules, queues and event sources run here (the primary).
	TriggersActive bool `json:"triggers_active"`
	// DataStatus is ready, syncing or failed ("": no regional data); DataMessage why.
	DataStatus  string `json:"data_status,omitempty"`
	DataMessage string `json:"data_message,omitempty"`
	// UnhealthySince is the first failed health check of a run of failures; FencedAt when the
	// region lost the primary in a failover.
	UnhealthySince *time.Time `json:"unhealthy_since,omitempty"`
	FencedAt       *time.Time `json:"fenced_at,omitempty"`
}

// Primary returns the primary region's copy, nil when the list has none.
func (g *ProjectGlobal) Primary() *GlobalRegion {
	if g == nil {
		return nil
	}
	for i := range g.Regions {
		if g.Regions[i].RegionID == g.PrimaryRegionID || g.Regions[i].Role == GlobalRolePrimary {
			return &g.Regions[i]
		}
	}
	return nil
}

// IsGlobal reports whether the project runs in several regions behind a global host name.
func (p *Project) IsGlobal() bool {
	return p != nil && (p.Placement == PlacementGlobal || p.Global != nil)
}

// GlobalUpdateInput is the body of PUT /projects/{project}/global; nil / empty fields stay as
// they are.
type GlobalUpdateInput struct {
	Mode *string `json:"mode,omitempty"`
	// Failover is automatic or manual.
	Failover *string `json:"failover,omitempty"`
	// PrimaryRegionID moves the primary there (a switchover: the region's copy must be ready).
	PrimaryRegionID *string `json:"primary_region_id,omitempty"`
	// RegionIDs is the complete new region list (the primary included): regions left out are
	// torn down, new ones get the active version.
	RegionIDs []string `json:"region_ids,omitempty"`
}

// GlobalDNS is GET /global-dns: the platform's global zone and its servers.
type GlobalDNS struct {
	// Zone is g.<base domain> (or the operator's); empty without a base domain.
	Zone       string `json:"zone,omitempty"`
	BaseDomain string `json:"base_domain,omitempty"`
	// TLS is acme (cert-manager DNS-01 issues *.<zone> in every region) or none.
	TLS string `json:"tls,omitempty"`
	// UpdateRegionID is the server cert-manager sends its DNS-01 updates to.
	UpdateRegionID string              `json:"update_region_id,omitempty"`
	Delegation     GlobalDNSDelegation `json:"delegation"`
	Servers        []GlobalDNSServer   `json:"servers"`
	LastSyncAt     *time.Time          `json:"last_sync_at,omitempty"`
}

// GlobalDNSDelegation is what the operator creates in the parent zone (NS records of the zone and
// the glue records of the name servers) and whether DNS shows it.
type GlobalDNSDelegation struct {
	Records []DNSRecord `json:"records"`
	// Status is ok, partial, missing or unknown.
	Status  string `json:"status,omitempty"`
	Message string `json:"message,omitempty"`
}

// GlobalDNSServer is the PowerDNS server of one region.
type GlobalDNSServer struct {
	RegionID      string `json:"region_id"`
	RegionName    string `json:"region_name"`
	Status        string `json:"status"`
	StatusMessage string `json:"status_message,omitempty"`
	// Address is the server's LoadBalancer address (public :53); NSName its name in the zone.
	Address string `json:"address,omitempty"`
	NSName  string `json:"ns_name,omitempty"`
	// Reachable: the server answers the zone's SOA authoritatively on :53 (nil: not checked).
	Reachable *bool `json:"reachable"`
	// SyncStatus is in_sync, drift or error.
	SyncStatus  string     `json:"sync_status,omitempty"`
	SyncMessage string     `json:"sync_message,omitempty"`
	SyncedAt    *time.Time `json:"synced_at,omitempty"`
	// TLSStatus is the region's *.<zone> certificate: none, pending, ready or error.
	TLSStatus    string     `json:"tls_status,omitempty"`
	TLSMessage   string     `json:"tls_message,omitempty"`
	TLSExpiresAt *time.Time `json:"tls_expires_at,omitempty"`
	// GeoIP is the server's GeoIP database of proximity mode (nil on older APIs).
	GeoIP *GlobalDNSGeoIP `json:"geoip,omitempty"`
}

// GeoIP sources of a region's DNS server: default (the platform's globalDns.geoipUrl), url (a URL
// the cluster reaches: an internal mirror, a file in the region's object store), file (the
// operator's own file on the server's volume, for a cluster without internet access), none.
const (
	GeoIPSourceDefault = "default"
	GeoIPSourceURL     = "url"
	GeoIPSourceFile    = "file"
	GeoIPSourceNone    = "none"
)

// GeoIPSources are the GeoIP sources, default first.
var GeoIPSources = []string{GeoIPSourceDefault, GeoIPSourceURL, GeoIPSourceFile, GeoIPSourceNone}

// GlobalDNSGeoIP is where a region's DNS server gets its GeoIP database and whether it has one.
type GlobalDNSGeoIP struct {
	Source string `json:"source"`
	// URL is the operator's URL (source url); EffectiveURL the one the server downloads.
	URL          string `json:"url,omitempty"`
	EffectiveURL string `json:"effective_url,omitempty"`
	// Status is loaded, missing, off or unknown; Message the source, size and date, or why not.
	Status    string     `json:"status"`
	Message   string     `json:"message,omitempty"`
	CheckedAt *time.Time `json:"checked_at,omitempty"`
}

// RegionGlobalDNS is GET /knative-regions/{region}/global-dns: the region's server and whether the
// region can have one.
type RegionGlobalDNS struct {
	GlobalDNSServer
	Eligible bool   `json:"eligible"`
	Reason   string `json:"reason,omitempty"`
}

func globalPath(projectID, suffix string) string {
	return "/projects/" + seg(projectID) + "/global" + suffix
}

// ProjectGlobal returns the regions of a global project with their status (projects.view). A
// regional project, or an API without global projects, answers 404 (IsNotFound).
func (c *Client) ProjectGlobal(ctx context.Context, projectID string) (*ProjectGlobal, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: globalPath(projectID, "")}, &raw); err != nil {
		return nil, err
	}
	var g ProjectGlobal
	if err := decodeData(raw, &g); err != nil {
		return nil, fmt.Errorf("decode global project: %w", err)
	}
	return &g, nil
}

// UpdateProjectGlobal changes a global project's mode, primary region or region list
// (projects.update): new regions get the active version, removed ones are torn down.
func (c *Client) UpdateProjectGlobal(ctx context.Context, projectID string, in GlobalUpdateInput) (*ProjectGlobal, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPut, path: globalPath(projectID, ""), body: in}, &raw); err != nil {
		return nil, err
	}
	var g ProjectGlobal
	if err := decodeData(raw, &g); err != nil {
		return nil, fmt.Errorf("decode global project: %w", err)
	}
	return &g, nil
}

// FailoverProjectGlobal moves a global project's primary to regionID ("": the first healthy
// region in HA order) when the primary does not answer (projects.update): the target's replica
// clusters are promoted at once — writes after their last received WAL are lost — and the old
// primary is fenced until it rejoins. A primary that answers moves with UpdateProjectGlobal
// (PrimaryRegionID, a switchover without loss).
func (c *Client) FailoverProjectGlobal(ctx context.Context, projectID, regionID string) (*ProjectGlobal, error) {
	body := map[string]any{}
	if regionID != "" {
		body["region_id"] = regionID
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPost, path: globalPath(projectID, "/failover"), body: body}, &raw); err != nil {
		return nil, err
	}
	var g ProjectGlobal
	if err := decodeData(raw, &g); err != nil {
		return nil, fmt.Errorf("decode global project: %w", err)
	}
	return &g, nil
}

// SyncProjectGlobal rolls the active version out again to every region whose copy is not ready
// and applies the routes again (projects.deploy); queued.
func (c *Client) SyncProjectGlobal(ctx context.Context, projectID string) error {
	return c.do(ctx, request{method: http.MethodPost, path: globalPath(projectID, "/sync")}, nil)
}

// GlobalDNS returns the platform's global zone, the delegation records and the servers
// (regions.view); ErrUnsupported on APIs without global projects.
func (c *Client) GlobalDNS(ctx context.Context) (*GlobalDNS, error) {
	var raw []byte
	err := c.do(ctx, request{method: http.MethodGet, path: "/global-dns"}, &raw)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	var g GlobalDNS
	if err := decodeData(raw, &g); err != nil {
		return nil, fmt.Errorf("decode global DNS: %w", err)
	}
	return &g, nil
}

// SyncGlobalDNS writes the zone to every server now (regions.manage); queued.
func (c *Client) SyncGlobalDNS(ctx context.Context) error {
	err := c.do(ctx, request{method: http.MethodPost, path: "/global-dns/sync"}, nil)
	if IsNotFound(err) {
		return ErrUnsupported
	}
	return err
}

func (c *Client) regionGlobalDNS(ctx context.Context, method, regionID string) (*RegionGlobalDNS, error) {
	var raw []byte
	err := c.do(ctx, request{method: method, path: regionPath(regionID, "/global-dns")}, &raw)
	if IsNotFound(err) {
		// the region was resolved before: the API has no global DNS
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	var out RegionGlobalDNS
	if err := decodeData(raw, &out); err != nil {
		return nil, fmt.Errorf("decode global DNS of the region: %w", err)
	}
	return &out, nil
}

// RegionGlobalDNS returns the global DNS server of a region and whether the region can have one
// (regions.view).
func (c *Client) RegionGlobalDNS(ctx context.Context, regionID string) (*RegionGlobalDNS, error) {
	return c.regionGlobalDNS(ctx, http.MethodGet, regionID)
}

// EnableRegionGlobalDNS installs the region's PowerDNS server (regions.manage, platform regions
// only); it runs in the background.
func (c *Client) EnableRegionGlobalDNS(ctx context.Context, regionID string) (*RegionGlobalDNS, error) {
	return c.regionGlobalDNS(ctx, http.MethodPost, regionID)
}

// DisableRegionGlobalDNS removes the region's PowerDNS server (regions.manage); refused while
// global projects run in the region.
func (c *Client) DisableRegionGlobalDNS(ctx context.Context, regionID string) (*RegionGlobalDNS, error) {
	return c.regionGlobalDNS(ctx, http.MethodDelete, regionID)
}

// SetRegionGeoIP sets the GeoIP source of a region's DNS server (regions.manage): source is one of
// GeoIPSources, url the URL of source url. The server restarts with it.
func (c *Client) SetRegionGeoIP(ctx context.Context, regionID, source, url string) (*RegionGlobalDNS, error) {
	body := map[string]any{"source": source}
	if url != "" {
		body["url"] = url
	}
	return c.regionGeoIP(ctx, http.MethodPut, regionID, "", body)
}

// ReloadRegionGeoIP makes a region's DNS server read its GeoIP source again (regions.manage):
// after the operator copied a new file onto its volume.
func (c *Client) ReloadRegionGeoIP(ctx context.Context, regionID string) (*RegionGlobalDNS, error) {
	return c.regionGeoIP(ctx, http.MethodPost, regionID, "/reload", nil)
}

func (c *Client) regionGeoIP(ctx context.Context, method, regionID, suffix string, body any) (*RegionGlobalDNS, error) {
	var raw []byte
	err := c.do(ctx, request{method: method, path: regionPath(regionID, "/global-dns/geoip"+suffix), body: body}, &raw)
	if IsNotFound(err) {
		// the region was resolved before: no server there, or an API without the GeoIP source
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	var out RegionGlobalDNS
	if err := decodeData(raw, &out); err != nil {
		return nil, fmt.Errorf("decode global DNS of the region: %w", err)
	}
	return &out, nil
}

// WaitRegionGlobalDNS polls a region's global DNS server until its install or removal ends:
// ready or disabled, a *FailedError for error.
func (c *Client) WaitRegionGlobalDNS(ctx context.Context, regionID, regionName string, opts WaitOptions) (*RegionGlobalDNS, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	what := "global DNS of " + regionName
	last := ""
	for {
		cur, err := c.RegionGlobalDNS(ctx, regionID)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		switch cur.Status {
		case GlobalDNSInstalling, GlobalDNSUninstalling:
		case GlobalDNSError:
			return cur, &FailedError{What: "global DNS of region", Ref: regionName, Status: cur.Status, Message: lastLine(cur.StatusMessage)}
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
