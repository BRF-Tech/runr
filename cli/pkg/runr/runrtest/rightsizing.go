package runrtest

import (
	"fmt"
	"sort"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Rightsizing recommendations of the fake (RightsizingController): recommendations seeded with
// SeedRecommendation, the state of a project without one with SetRightsizingState. Applying
// writes the recommended size into the project's config as a new version, like the API.

type rightsizingState struct {
	recommendations map[string]*runr.Recommendation // project id → recommendation
	states          map[string]runr.ProjectRightsizing
}

func (s *Server) rightsizingSt() *rightsizingState {
	return featureState(s, "rightsizing", func() *rightsizingState {
		return &rightsizingState{recommendations: map[string]*runr.Recommendation{}, states: map[string]runr.ProjectRightsizing{}}
	})
}

// SeedRecommendation stores a recommendation of a project (status "" = open; the project
// fields are filled in).
func (s *Server) SeedRecommendation(projectName string, r runr.Recommendation) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	if r.Status == "" {
		r.Status = "open"
	}
	if r.ID == "" {
		r.ID = s.newID()
	}
	r.ProjectID = p.data.ID
	r.Project = &struct {
		ID          string `json:"id"`
		Name        string `json:"name"`
		Slug        string `json:"slug"`
		ServiceType string `json:"service_type"`
	}{ID: p.data.ID, Name: p.data.Name, Slug: p.data.Slug, ServiceType: p.data.ServiceType}
	if r.Reasons == nil {
		r.Reasons = []string{}
	}
	s.rightsizingSt().recommendations[p.data.ID] = &r
}

// SetRightsizingState sets what GET /projects/{project}/rightsizing answers for a project
// without a recommendation (default: status "collecting").
func (s *Server) SetRightsizingState(projectName string, st runr.ProjectRightsizing) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if p := s.projectByName(projectName); p != nil {
		s.rightsizingSt().states[p.data.ID] = st
	}
}

// Recommendation returns the stored recommendation of a project.
func (s *Server) Recommendation(projectName string) (runr.Recommendation, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return runr.Recommendation{}, false
	}
	r, ok := s.rightsizingSt().recommendations[p.data.ID]
	if !ok {
		return runr.Recommendation{}, false
	}
	return *r, true
}

func monthly(r *runr.Recommendation) float64 {
	if r.Savings == nil || r.Savings.Monthly == nil {
		return -1e18
	}
	return float64(*r.Savings.Monthly)
}

func noRecommendation() *apiErr {
	return &apiErr{status: 404, message: "The project has no open rightsizing recommendation.", code: "rightsizing_none"}
}

func init() {
	handle("GET /rightsizing", func(s *Server, q *featureReq) (int, any, *apiErr) {
		list := []runr.Recommendation{}
		var total float64
		currency := ""
		for _, r := range s.rightsizingSt().recommendations {
			if r.Status != "open" {
				continue
			}
			list = append(list, *r)
			if r.Savings != nil && r.Savings.Monthly != nil && *r.Savings.Monthly > 0 {
				total += float64(*r.Savings.Monthly)
				currency = r.Savings.Currency
			}
		}
		sort.SliceStable(list, func(i, j int) bool {
			if a, b := monthly(&list[i]), monthly(&list[j]); a != b {
				return a > b
			}
			return list[i].Freed.MemoryMB > list[j].Freed.MemoryMB
		})
		summary := map[string]any{"count": len(list), "monthly_savings": nil, "currency": nil, "freed_memory_mb": 0, "freed_cpu_m": 0}
		if currency != "" {
			summary["monthly_savings"], summary["currency"] = total, currency
		}
		mem, cpu := 0, 0
		for _, r := range list {
			mem += max(0, r.Freed.MemoryMB)
			cpu += max(0, r.Freed.CPUM)
		}
		summary["freed_memory_mb"], summary["freed_cpu_m"] = mem, cpu
		return 200, map[string]any{"data": list, "summary": summary}, nil
	})

	handle("GET /projects/{project}/rightsizing", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		st := s.rightsizingSt()
		if r, ok := st.recommendations[p.data.ID]; ok {
			return 200, map[string]any{"status": r.Status, "reason": nil, "data": r, "collecting": nil,
				"pending_deploy": r.Status == "applied"}, nil
		}
		state, ok := st.states[p.data.ID]
		if !ok {
			state = runr.ProjectRightsizing{Status: "collecting", Collecting: &runr.RightsizingCollecting{RequiredDays: 3, RequiredSessions: 20, WindowDays: 14}}
		}
		out := map[string]any{"status": state.Status, "reason": nil, "data": nil, "collecting": state.Collecting, "pending_deploy": false}
		if state.Reason != "" {
			out["reason"] = state.Reason
		}
		return 200, out, nil
	})

	handle("POST /projects/{project}/rightsizing/apply", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		r, ok := s.rightsizingSt().recommendations[p.data.ID]
		if !ok || r.Status != "open" {
			return 0, nil, noRecommendation()
		}
		if r.Blocked != nil {
			e := errValidation("config.memory_mb", r.Blocked.Message)
			e.code = r.Blocked.Code
			return 0, nil, e
		}
		if p.config == nil {
			p.config = map[string]any{}
		}
		p.config["cpu"] = fmt.Sprintf("%dm", r.Recommended.CPUM)
		p.config["memory_mb"] = r.Recommended.MemoryMB
		v := s.saveVersion(p, nil, fmt.Sprintf("Rightsizing: memory %d MB → %d MB, CPU %dm → %dm",
			r.Current.MemoryMB, r.Recommended.MemoryMB, r.Current.CPUM, r.Recommended.CPUM), true)
		r.Status = "applied"
		r.DecidedAt = nowRFC3339()
		r.AppliedVersionID = v.data.ID
		return 200, map[string]any{
			"data":            r,
			"version":         map[string]any{"id": v.data.ID, "number": v.data.Number, "label": v.data.Label},
			"deploy_required": true,
			"message":         "The recommended size was saved as " + v.data.Label + ". Deploy the project to run it.",
		}, nil
	})

	handle("POST /projects/{project}/rightsizing/dismiss", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		r, ok := s.rightsizingSt().recommendations[p.data.ID]
		if !ok || (r.Status != "open" && r.Status != "snoozed") {
			return 0, nil, noRecommendation()
		}
		message := "The recommendation is dismissed. It comes back only when the recommended size changes."
		if days, given := q.Body["snooze_days"]; given && days != nil {
			n, isNum := days.(float64)
			if !isNum || n < 1 || n > 365 || n != float64(int(n)) {
				return 0, nil, errValidation("snooze_days", "The snooze days field must be between 1 and 365.")
			}
			r.Status = "snoozed"
			r.SnoozedUntil = time.Now().UTC().AddDate(0, 0, int(n)).Format(time.RFC3339)
			message = fmt.Sprintf("The recommendation is hidden for %d days.", int(n))
		} else {
			r.Status = "dismissed"
			r.SnoozedUntil = ""
		}
		r.DecidedAt = nowRFC3339()
		return 200, map[string]any{"data": r, "message": message}, nil
	})
}
