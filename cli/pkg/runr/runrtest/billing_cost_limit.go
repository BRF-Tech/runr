package runrtest

import (
	"fmt"
	"math"
	"strconv"
	"strings"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// The monthly cost limit of a project (ProjectBillingController, CostLimits). Projects are
// pay-as-you-go unless SetFixedPrice marks them; this month's cost so far is what
// SetMonthToDate set (0 otherwise). Saving a limit checks it at once: a stop limit at or below
// the month so far stops the project, like the API. Without the Billing module the routes do not
// exist: Server.Without the three endpoints.

type costLimitState struct {
	currency string
	limits   map[string]*fakeCostLimit // project id →
	fixed    map[string]bool
	mtd      map[string]float64
	// stopped: projects the limit stopped
	stopped map[string]bool
}

type fakeCostLimit struct {
	amount    float64
	action    string
	updatedBy runr.User
	updatedAt string
	warnedAt  string
	reachedAt string
	checkedAt string
}

func (s *Server) costLimits() *costLimitState {
	return featureState(s, "cost-limits", func() *costLimitState {
		return &costLimitState{currency: "EUR", limits: map[string]*fakeCostLimit{}, fixed: map[string]bool{},
			mtd: map[string]float64{}, stopped: map[string]bool{}}
	})
}

// SetBillingCurrency sets the installation's billing currency (default EUR).
func (s *Server) SetBillingCurrency(currency string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.costLimits().currency = currency
}

// SetFixedPrice marks a project as fixed-price (a fixed region, or a region whose prices are 0):
// a cost limit does not apply to it.
func (s *Server) SetFixedPrice(projectName string, fixed bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.mustProject(projectName)
	s.costLimits().fixed[p.data.ID] = fixed
}

// SetMonthToDate sets what a project's bills of this month come to so far and checks its limit
// (CheckCostLimitsJob): a stop limit reached stops the project.
func (s *Server) SetMonthToDate(projectName string, amount float64) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.mustProject(projectName)
	st := s.costLimits()
	st.mtd[p.data.ID] = amount
	if l := st.limits[p.data.ID]; l != nil {
		s.evaluateCostLimit(p, l)
	}
}

// CostLimit returns a project's limit: amount and action (ok false without one).
func (s *Server) CostLimit(projectName string) (float64, string, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return 0, "", false
	}
	l := s.costLimits().limits[p.data.ID]
	if l == nil {
		return 0, "", false
	}
	return l.amount, l.action, true
}

// evaluateCostLimit is CostLimits::evaluate for the current month.
func (s *Server) evaluateCostLimit(p *project, l *fakeCostLimit) {
	st := s.costLimits()
	if st.fixed[p.data.ID] {
		return
	}
	cost := st.mtd[p.data.ID]
	l.checkedAt = nowRFC3339()
	switch {
	case cost >= l.amount:
		if l.reachedAt == "" {
			l.reachedAt = nowRFC3339()
			if l.warnedAt == "" {
				l.warnedAt = l.reachedAt
			}
			if l.action == runr.CostLimitStop && p.data.Stopped == nil {
				now := time.Now().UTC().Truncate(time.Second)
				p.data.Stopped = &runr.ProjectStop{At: &now, Level: runr.StopLevelOrganization,
					Reason: fmt.Sprintf("Monthly cost limit reached: %.2f of %.2f in %s.", cost, l.amount, now.Format("2006-01"))}
				st.stopped[p.data.ID] = true
			}
		}
	case cost*100 >= l.amount*80:
		if l.warnedAt == "" {
			l.warnedAt = nowRFC3339()
		}
	}
}

// costLimitJSON is CostLimits::status().
func (s *Server) costLimitJSON(p *project) map[string]any {
	st := s.costLimits()
	now := time.Now().UTC()
	daysInMonth := time.Date(now.Year(), now.Month()+1, 0, 0, 0, 0, 0, time.UTC).Day()
	stopped := st.stopped[p.data.ID] && p.data.Stopped != nil
	l := st.limits[p.data.ID]
	var limit any
	if l != nil {
		limit = map[string]any{"amount": l.amount, "action": l.action,
			"updated_by": map[string]any{"id": l.updatedBy.ID.String(), "name": l.updatedBy.Name}, "updated_at": l.updatedAt}
	}
	out := map[string]any{
		"available": false, "currency": st.currency, "period": now.Format("2006-01"), "limit": limit,
		"month_to_date": nil, "forecast": nil, "days_elapsed": now.Day(), "days_in_month": daysInMonth,
		"percent": nil, "warning_percent": 80, "state": "none", "warned_at": nil, "reached_at": nil,
		"stopped": stopped, "can_manage": true, "can_resume": stopped, "checked_at": nil,
	}
	if l != nil {
		out["checked_at"] = nilIfEmpty(l.checkedAt)
	}
	if st.fixed[p.data.ID] {
		return out
	}
	cost := st.mtd[p.data.ID]
	out["available"] = true
	out["month_to_date"] = cost
	out["forecast"] = math.Round(cost/float64(now.Day())*float64(daysInMonth)*100) / 100
	if l != nil {
		out["percent"] = math.Round(cost*100/l.amount*10) / 10
		switch {
		case cost >= l.amount:
			out["state"] = "reached"
		case cost*100 >= l.amount*80:
			out["state"] = "warning"
		default:
			out["state"] = "ok"
		}
		out["warned_at"], out["reached_at"] = nilIfEmpty(l.warnedAt), nilIfEmpty(l.reachedAt)
		out["can_resume"] = stopped && !(l.action == runr.CostLimitStop && cost >= l.amount)
	}
	return out
}

// costLimitAmount validates amount like 'required|numeric|decimal:0,3|gt:0|max:1000000000'.
func costLimitAmount(raw any) (float64, string) {
	var text string
	switch v := raw.(type) {
	case nil:
		return 0, "The amount field is required."
	case float64:
		text = strconv.FormatFloat(v, 'f', -1, 64)
	case string:
		text = strings.TrimSpace(v)
		if text == "" {
			return 0, "The amount field is required."
		}
	default:
		return 0, "The amount field must be a number."
	}
	f, err := strconv.ParseFloat(text, 64)
	if err != nil {
		return 0, "The amount field must be a number."
	}
	if i := strings.IndexByte(text, '.'); i >= 0 && len(text)-i-1 > 3 {
		return 0, "The amount field must have 0-3 decimal places."
	}
	if f <= 0 {
		return 0, "The amount field must be greater than 0."
	}
	if f > 1e9 {
		return 0, "The amount field must not be greater than 1000000000."
	}
	return f, ""
}

func init() {
	handle("GET /projects/{project}/billing/cost-limit", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"data": s.costLimitJSON(p)}, nil
	})

	handle("PUT /projects/{project}/billing/cost-limit", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		st := s.costLimits()
		errs := map[string][]string{}
		amount, msg := costLimitAmount(q.Body["amount"])
		if msg != "" {
			errs["amount"] = []string{msg}
		}
		action := q.str("action")
		if action == "" {
			errs["action"] = []string{"The action field is required."}
		} else if action != runr.CostLimitNotify && action != runr.CostLimitStop {
			errs["action"] = []string{"The selected action is invalid."}
		}
		if raw, ok := q.Body["currency"]; ok && raw != nil {
			c, isStr := raw.(string)
			if !isStr || strings.ToUpper(strings.TrimSpace(c)) != st.currency {
				errs["currency"] = []string{"The limit is in the billing currency, " + st.currency + "."}
			}
		}
		if len(errs) > 0 {
			for _, f := range []string{"amount", "action", "currency"} {
				if m, ok := errs[f]; ok {
					return 0, nil, &apiErr{status: 422, message: m[0], errors: errs}
				}
			}
		}
		if st.fixed[p.data.ID] {
			return 0, nil, &apiErr{status: 422, code: "cost_limit_not_available",
				message: "A monthly cost limit is for projects in pay-as-you-go regions: a fixed-price project pays for its approved resources."}
		}
		amount = math.Round(amount*1000) / 1000
		l := st.limits[p.data.ID]
		newAmount := l == nil || l.amount != amount
		toStop := l != nil && l.action != runr.CostLimitStop && action == runr.CostLimitStop
		if l == nil {
			l = &fakeCostLimit{}
			st.limits[p.data.ID] = l
		}
		l.amount, l.action, l.updatedBy, l.updatedAt = amount, action, s.User, nowRFC3339()
		if newAmount {
			l.warnedAt, l.reachedAt = "", ""
		} else if toStop {
			l.reachedAt = ""
		}
		s.evaluateCostLimit(p, l)
		return 200, map[string]any{"data": s.costLimitJSON(p)}, nil
	})

	handle("DELETE /projects/{project}/billing/cost-limit", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		delete(s.costLimits().limits, p.data.ID)
		return 200, map[string]any{"data": s.costLimitJSON(p)}, nil
	})
}
