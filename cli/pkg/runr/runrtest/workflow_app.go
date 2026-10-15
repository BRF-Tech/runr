package runrtest

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net/url"
	"sort"
	"strings"
	"time"
)

// The workflow app of a project (WorkflowController::app / updateApp / rotateKey / signingKey)
// and workflow metering (WorkflowUsageController) of the fake.

type wfApp struct {
	override        string
	key             string
	previousExpires string
}

type wfUsageRow struct {
	date, projectID, workflowID, workflow string
	steps, runs                           int
}

type wfAppState struct {
	apps  map[string]*wfApp
	usage []wfUsageRow
	seq   int
}

func (s *Server) wfApps() *wfAppState {
	return featureState(s, "workflow-apps", func() *wfAppState { return &wfAppState{apps: map[string]*wfApp{}} })
}

func (s *Server) wfAppOf(projectID string) *wfApp {
	st := s.wfApps()
	if a, ok := st.apps[projectID]; ok {
		return a
	}
	st.seq++
	a := &wfApp{key: fmt.Sprintf("rwk_fake_signing_key_%d_%s", st.seq, strings.ToLower(projectID))}
	st.apps[projectID] = a
	return a
}

// fingerprint is the short hash the API shows instead of the key.
func (a *wfApp) fingerprint() string {
	sum := sha256.Sum256([]byte(a.key))
	return "sha256:" + hex.EncodeToString(sum[:])[:16]
}

// WorkflowSigningKeyOf returns the current signing key of a project's workflow app.
func (s *Server) WorkflowSigningKeyOf(projectName string) string {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return ""
	}
	return s.wfAppOf(p.data.ID).key
}

// WorkflowEndpointOverride returns the endpoint override of a project's workflow app.
func (s *Server) WorkflowEndpointOverride(projectName string) string {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return ""
	}
	return s.wfAppOf(p.data.ID).override
}

// SeedWorkflowUsage records step executions and started runs of a workflow on a day
// (YYYY-MM-DD).
func (s *Server) SeedWorkflowUsage(projectName, workflow, date string, steps, runs int) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	st := s.wfApps()
	st.usage = append(st.usage, wfUsageRow{date: date, projectID: p.data.ID, workflowID: "wf_" + workflow,
		workflow: workflow, steps: steps, runs: runs})
}

func (s *Server) wfAppDetail(p *project) map[string]any {
	out := s.wfAppJSON(p)
	a := s.wfAppOf(p.data.ID)
	out["key_fingerprint"] = a.fingerprint()
	if a.override != "" {
		out["endpoint_url"], out["endpoint_override"] = a.override, a.override
	}
	if a.previousExpires != "" {
		out["previous_key_expires_at"] = a.previousExpires
	}
	return out
}

func (s *Server) wfUsageReport(q *featureReq, projectID string) (int, any, *apiErr) {
	from, to := q.query("from"), q.query("to")
	for _, d := range []struct{ field, v string }{{"from", from}, {"to", to}} {
		if d.v == "" {
			continue
		}
		if _, err := time.Parse("2006-01-02", d.v); err != nil {
			return 0, nil, errValidation(d.field, "The "+d.field+" field must be a valid date.")
		}
	}
	if from == "" {
		from = time.Now().UTC().AddDate(0, 0, -29).Format("2006-01-02")
	}
	if to == "" {
		to = time.Now().UTC().Format("2006-01-02")
	}
	totalSteps, totalRuns := 0, 0
	daily := map[string][2]int{}
	type agg struct {
		workflow, projectID string
		steps, runs         int
	}
	byWf := map[string]*agg{}
	for _, r := range s.wfApps().usage {
		if r.date < from || r.date > to || (projectID != "" && r.projectID != projectID) {
			continue
		}
		totalSteps += r.steps
		totalRuns += r.runs
		d := daily[r.date]
		daily[r.date] = [2]int{d[0] + r.steps, d[1] + r.runs}
		a, ok := byWf[r.workflowID]
		if !ok {
			a = &agg{workflow: r.workflow, projectID: r.projectID}
			byWf[r.workflowID] = a
		}
		a.steps += r.steps
		a.runs += r.runs
	}
	days := make([]string, 0, len(daily))
	for d := range daily {
		days = append(days, d)
	}
	sort.Strings(days)
	dailyOut := []map[string]any{}
	for _, d := range days {
		dailyOut = append(dailyOut, map[string]any{"date": d, "step_executions": daily[d][0], "runs_started": daily[d][1]})
	}
	ids := make([]string, 0, len(byWf))
	for id := range byWf {
		ids = append(ids, id)
	}
	sort.Strings(ids)
	byOut := []map[string]any{}
	for _, id := range ids {
		a := byWf[id]
		byOut = append(byOut, map[string]any{"workflow_id": id, "workflow": a.workflow, "project_id": a.projectID,
			"step_executions": a.steps, "runs_started": a.runs})
	}
	return 200, map[string]any{
		"from": from, "to": to,
		"totals":      map[string]any{"step_executions": totalSteps, "runs_started": totalRuns},
		"daily":       dailyOut,
		"by_workflow": byOut,
	}, nil
}

func init() {
	handle("GET /projects/{project}/workflows/app", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"data": s.wfAppDetail(p)}, nil
	})
	handle("PATCH /projects/{project}/workflows/app", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		raw, present := q.Body["endpoint_url"]
		if !present {
			return 0, nil, errValidation("endpoint_url", "The endpoint url field must be present.")
		}
		v, _ := raw.(string)
		v = strings.TrimSpace(v)
		if v != "" {
			u, perr := url.Parse(v)
			if perr != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
				return 0, nil, errValidation("endpoint_url", "The endpoint must be an http or https URL.")
			}
			if strings.HasPrefix(u.Hostname(), "127.") || u.Hostname() == "localhost" || strings.HasPrefix(u.Hostname(), "169.254.") {
				return 0, nil, errValidation("endpoint_url", "The endpoint may not point at a private or loopback address.")
			}
		}
		s.wfAppOf(p.data.ID).override = v
		return 200, map[string]any{"data": s.wfAppDetail(p)}, nil
	})
	handle("POST /projects/{project}/workflows/app/rotate-key", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		a := s.wfAppOf(p.data.ID)
		st := s.wfApps()
		st.seq++
		a.key = fmt.Sprintf("rwk_fake_signing_key_%d_%s", st.seq, strings.ToLower(p.data.ID))
		until := time.Now().UTC().Add(24 * time.Hour)
		a.previousExpires = until.Format(time.RFC3339)
		return 200, map[string]any{
			"message": "New signing key created. The previous key keeps signing until " + until.Format("Mon, Jan 2, 2006 3:04 PM") + ".",
			"data":    s.wfAppDetail(p),
		}, nil
	})
	handle("GET /projects/{project}/workflows/app/signing-key", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		a := s.wfAppOf(p.data.ID)
		return 200, map[string]any{"signing_key": a.key, "env": "RUNR_WORKFLOW_SIGNING_KEY", "fingerprint": a.fingerprint()}, nil
	})
	handle("GET /workflow-usage", func(s *Server, q *featureReq) (int, any, *apiErr) {
		return s.wfUsageReport(q, "")
	})
	handle("GET /projects/{project}/workflow-usage", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		return s.wfUsageReport(q, p.data.ID)
	})
}
