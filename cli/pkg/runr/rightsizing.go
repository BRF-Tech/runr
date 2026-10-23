package runr

import (
	"context"
	"errors"
	"net/http"
)

// Rightsizing recommendations (RightsizingController, docs/monitoring.md#rightsizing): every
// night RunR compares a running project's daily peaks of the last 14 days with its size and
// recommends a smaller size (or a larger one after OOM kills, CPU throttling or memory near its
// limit). Applying one saves the size into the config as a new version — the next deploy runs it.
// APIs before rightsizing answer 404: the methods return ErrUnsupported then.

// RightsizingSize is a pod's CPU (millicores) and memory (MiB).
type RightsizingSize struct {
	CPUM     int `json:"cpu_m"`
	MemoryMB int `json:"memory_mb"`
}

// RightsizingEvidence is what the recommendation is based on: the window, the highest and
// 95th percentile readings of one pod, OOM kills and the share of throttled CPU periods.
type RightsizingEvidence struct {
	WindowDays      int        `json:"window_days"`
	From            string     `json:"from,omitempty"`
	To              string     `json:"to,omitempty"`
	Days            int        `json:"days"`
	Sessions        int        `json:"sessions"`
	AlwaysOn        bool       `json:"always_on"`
	MemoryMaxMB     *FlexFloat `json:"memory_max_mb"`
	MemoryP95MB     *FlexFloat `json:"memory_p95_mb"`
	MemoryBasis     string     `json:"memory_basis,omitempty"`
	MemoryMargin    *FlexFloat `json:"memory_margin"`
	MemoryNearLimit bool       `json:"memory_near_limit"`
	CPUMaxM         *int       `json:"cpu_max_m"`
	CPUP95M         *int       `json:"cpu_p95_m"`
	CPUBasis        string     `json:"cpu_basis,omitempty"`
	CPUMargin       *FlexFloat `json:"cpu_margin"`
	OOMKills        int        `json:"oom_kills"`
	ThrottledRatio  FlexFloat  `json:"throttled_ratio"`
}

// RightsizingFreed is what the change frees (negative: takes): per pod × the pods an always-on
// workload keeps running, and the change of the worst case up to max_scale.
type RightsizingFreed struct {
	CPUM         int  `json:"cpu_m"`
	MemoryMB     int  `json:"memory_mb"`
	Pods         int  `json:"pods"`
	AlwaysOn     bool `json:"always_on"`
	PeakCPUM     int  `json:"peak_cpu_m"`
	PeakMemoryMB int  `json:"peak_memory_mb"`
}

// RightsizingSavings is the monthly difference the Billing module estimates (positive: saved):
// basis "reserved" (always-on, pay as you go), "usage" (scale-to-zero: billed by use, 0) or
// "fixed" (the allocation that could be lowered by FreedAllocation).
type RightsizingSavings struct {
	Basis           string           `json:"basis"`
	Monthly         *FlexFloat       `json:"monthly"`
	Currency        string           `json:"currency,omitempty"`
	Allocation      *RightsizingSize `json:"allocation,omitempty"`
	FreedAllocation *RightsizingSize `json:"freed_allocation,omitempty"`
}

// RightsizingBlock is why applying would be refused now (region capacity, fixed allocation).
type RightsizingBlock struct {
	Code    string `json:"code"`
	Message string `json:"message"`
}

// Recommendation is one project's rightsizing recommendation.
type Recommendation struct {
	ID        string `json:"id"`
	ProjectID string `json:"project_id"`
	Project   *struct {
		ID          string `json:"id"`
		Name        string `json:"name"`
		Slug        string `json:"slug"`
		ServiceType string `json:"service_type"`
	} `json:"project,omitempty"`
	RegionID string `json:"region_id,omitempty"`
	// Status is open, snoozed, dismissed or applied; Direction down, up or mixed.
	Status      string          `json:"status"`
	Direction   string          `json:"direction"`
	Current     RightsizingSize `json:"current"`
	Recommended RightsizingSize `json:"recommended"`
	Changes     struct {
		CPU    string `json:"cpu"`
		Memory string `json:"memory"`
	} `json:"changes"`
	Reasons          []string            `json:"reasons"`
	Evidence         RightsizingEvidence `json:"evidence"`
	Freed            RightsizingFreed    `json:"freed"`
	Savings          *RightsizingSavings `json:"savings"`
	Blocked          *RightsizingBlock   `json:"blocked"`
	ComputedAt       string              `json:"computed_at,omitempty"`
	SnoozedUntil     string              `json:"snoozed_until,omitempty"`
	DecidedAt        string              `json:"decided_at,omitempty"`
	AppliedVersionID string              `json:"applied_version_id,omitempty"`
}

// ProjectName is the project's name when the API sent it, else its id.
func (r *Recommendation) ProjectName() string {
	if r.Project != nil && r.Project.Name != "" {
		return r.Project.Name
	}
	return r.ProjectID
}

// RightsizingList is GET /rightsizing: the open recommendations, largest monthly saving first.
type RightsizingList struct {
	Data    []Recommendation `json:"data"`
	Summary struct {
		Count          int        `json:"count"`
		MonthlySavings *FlexFloat `json:"monthly_savings"`
		Currency       string     `json:"currency,omitempty"`
		FreedMemoryMB  int        `json:"freed_memory_mb"`
		FreedCPUM      int        `json:"freed_cpu_m"`
	} `json:"summary"`
}

// RightsizingCollecting tells how much data a project has toward its first recommendation.
type RightsizingCollecting struct {
	Days             int `json:"days"`
	RequiredDays     int `json:"required_days"`
	Sessions         int `json:"sessions"`
	RequiredSessions int `json:"required_sessions"`
	WindowDays       int `json:"window_days"`
}

// ProjectRightsizing is GET /projects/{project}/rightsizing. Status is open, snoozed, dismissed
// or applied (Data set), or collecting, right_sized or not_eligible (Reason: gpu, stopped,
// not_running, unsupported, no_metrics, disabled).
type ProjectRightsizing struct {
	Status     string                 `json:"status"`
	Reason     string                 `json:"reason,omitempty"`
	Data       *Recommendation        `json:"data"`
	Collecting *RightsizingCollecting `json:"collecting"`
	// PendingDeploy: an applied size the running deployment does not have yet.
	PendingDeploy bool `json:"pending_deploy"`
}

// RightsizingApplied is the answer of POST /projects/{project}/rightsizing/apply.
type RightsizingApplied struct {
	Data    Recommendation `json:"data"`
	Version struct {
		ID     string `json:"id"`
		Number int    `json:"number"`
		Label  string `json:"label"`
	} `json:"version"`
	DeployRequired bool   `json:"deploy_required"`
	Message        string `json:"message"`
}

// ErrNoRecommendation means the project has no open recommendation to apply or dismiss.
var ErrNoRecommendation = errors.New("the project has no open rightsizing recommendation")

// Rightsizing lists the organization's open recommendations (metrics.view on the projects).
func (c *Client) Rightsizing(ctx context.Context) (*RightsizingList, error) {
	var out RightsizingList
	err := c.do(ctx, request{method: http.MethodGet, path: "/rightsizing"}, &out)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	if out.Data == nil {
		out.Data = []Recommendation{}
	}
	return &out, nil
}

// ProjectRightsizing returns a project's recommendation, or why it has none.
func (c *Client) ProjectRightsizing(ctx context.Context, projectID string) (*ProjectRightsizing, error) {
	var out ProjectRightsizing
	err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + seg(projectID) + "/rightsizing"}, &out)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	return &out, nil
}

// ApplyRightsizing saves the project's open recommendation into its config (projects.update):
// a new version that the next deploy runs. ErrNoRecommendation when there is none to apply; a
// refusal of the region capacity or a fixed allocation is an *APIError (422 with its code).
func (c *Client) ApplyRightsizing(ctx context.Context, projectID string) (*RightsizingApplied, error) {
	var out RightsizingApplied
	err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + seg(projectID) + "/rightsizing/apply"}, &out)
	if err != nil {
		return nil, rightsizingNotFound(err)
	}
	return &out, nil
}

// DismissRightsizing hides the project's open recommendation: for snoozeDays days when > 0,
// else while the recommended size stays the same.
func (c *Client) DismissRightsizing(ctx context.Context, projectID string, snoozeDays int) (*Recommendation, error) {
	body := map[string]any{}
	if snoozeDays > 0 {
		body["snooze_days"] = snoozeDays
	}
	var out struct {
		Data Recommendation `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + seg(projectID) + "/rightsizing/dismiss", body: body}, &out)
	if err != nil {
		return nil, rightsizingNotFound(err)
	}
	return &out.Data, nil
}

// rightsizingNotFound tells "no open recommendation" (404 rightsizing_none) from an API without
// rightsizing (404 without that code).
func rightsizingNotFound(err error) error {
	var apiErr *APIError
	if errors.As(err, &apiErr) && apiErr.StatusCode == http.StatusNotFound {
		if apiErr.Code == "rightsizing_none" {
			return ErrNoRecommendation
		}
		return ErrUnsupported
	}
	return err
}
