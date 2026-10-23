package runr

import (
	"context"
	"net/http"
)

// The monthly cost limit of a pay-as-you-go project (ProjectBillingController, CostLimits;
// docs/billing.md#monthly-cost-limit): one amount in the billing currency and what happens when
// the month reaches it, notify or stop. Billing is a module of the API: with it off the routes do
// not exist and the methods return ErrUnsupported (a 404 of a project that does not exist reads
// the same; check the project to tell them apart).

// Actions of a cost limit at 100 %.
const (
	CostLimitNotify = "notify"
	CostLimitStop   = "stop"
)

// CostLimitSetting is the limit itself: the amount and the action.
type CostLimitSetting struct {
	Amount    float64 `json:"amount"`
	Action    string  `json:"action"`
	UpdatedBy *Ref    `json:"updated_by,omitempty"`
	UpdatedAt string  `json:"updated_at,omitempty"`
}

// CostLimit is the answer of GET /projects/{project}/billing/cost-limit (CostLimits::status()):
// the limit (nil without one) and this month so far. Available is false for a project the limit
// does not apply to (a fixed-price project, a free region): the figures are nil then.
type CostLimit struct {
	Available bool              `json:"available"`
	Currency  string            `json:"currency"`
	Period    string            `json:"period"`
	Limit     *CostLimitSetting `json:"limit"`
	// MonthToDate is what the project's bills of the month come to so far; Forecast that ÷
	// elapsed days × days in the month.
	MonthToDate    *float64 `json:"month_to_date"`
	Forecast       *float64 `json:"forecast"`
	DaysElapsed    int      `json:"days_elapsed"`
	DaysInMonth    int      `json:"days_in_month"`
	Percent        *float64 `json:"percent"`
	WarningPercent int      `json:"warning_percent"`
	// State is none (no limit), ok, warning (at least WarningPercent) or reached.
	State     string `json:"state"`
	WarnedAt  string `json:"warned_at,omitempty"`
	ReachedAt string `json:"reached_at,omitempty"`
	// Stopped is true while the limit keeps the project stopped.
	Stopped   bool   `json:"stopped"`
	CanManage bool   `json:"can_manage"`
	CanResume bool   `json:"can_resume"`
	CheckedAt string `json:"checked_at,omitempty"`
}

// CostLimitInput sets a project's limit: an amount above 0 with at most three decimals and the
// action. Currency, when set, must be the billing currency.
type CostLimitInput struct {
	Amount   float64 `json:"amount"`
	Action   string  `json:"action"`
	Currency string  `json:"currency,omitempty"`
}

func costLimitPath(projectID string) string {
	return "/projects/" + seg(projectID) + "/billing/cost-limit"
}

func (c *Client) costLimit(ctx context.Context, method, projectID string, body any) (*CostLimit, error) {
	var out struct {
		Data CostLimit `json:"data"`
	}
	err := c.do(ctx, request{method: method, path: costLimitPath(projectID), body: body}, &out)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// GetCostLimit returns a project's cost limit and this month so far. It takes the project's
// owner, projects.billing on the project or billing.view.
func (c *Client) GetCostLimit(ctx context.Context, projectID string) (*CostLimit, error) {
	return c.costLimit(ctx, http.MethodGet, projectID, nil)
}

// SetCostLimit sets or changes a project's limit; it is checked right away, so a stop limit the
// month already passed stops the project at once. 422 cost_limit_not_available for a
// fixed-price project. It takes the owner, projects.billing or billing.manage.
func (c *Client) SetCostLimit(ctx context.Context, projectID string, in CostLimitInput) (*CostLimit, error) {
	return c.costLimit(ctx, http.MethodPut, projectID, in)
}

// DeleteCostLimit removes a project's limit. A project the limit stopped stays stopped until it is
// resumed.
func (c *Client) DeleteCostLimit(ctx context.Context, projectID string) (*CostLimit, error) {
	return c.costLimit(ctx, http.MethodDelete, projectID, nil)
}
