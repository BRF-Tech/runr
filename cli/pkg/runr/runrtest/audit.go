package runrtest

import (
	"bytes"
	"encoding/csv"
	"encoding/json"
	"strconv"
	"strings"
	"time"

	"github.com/runrsh/core/cli/pkg/runr"
)

// Audit log (AuditEventController): events are seeded with SeedAuditEvent and answered newest
// first with the controller's filters.

type auditState struct {
	events []runr.AuditEvent // oldest first
}

func (s *Server) auditState() *auditState {
	return featureState(s, "audit", func() *auditState { return &auditState{} })
}

// SeedAuditEvent appends an event (ID and OccurredAt are filled in when empty).
func (s *Server) SeedAuditEvent(e runr.AuditEvent) runr.AuditEvent {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.auditState()
	if e.ID == "" {
		e.ID = s.newID()
	}
	if e.OccurredAt == "" {
		e.OccurredAt = time.Now().UTC().Add(time.Duration(len(st.events)) * time.Second).Format(time.RFC3339)
	}
	if e.Result == "" {
		e.Result = "success"
	}
	if e.Actor.Type == "" {
		e.Actor = runr.AuditActor{Type: "user", ID: s.User.ID, Name: s.User.Name, Email: s.User.Email, AuthMethod: "api_token:1"}
	}
	st.events = append(st.events, e)
	return e
}

func auditMatches(e runr.AuditEvent, q *featureReq) bool {
	if a := strings.TrimSpace(q.query("actor")); a != "" {
		la := strings.ToLower(a)
		if e.Actor.ID.String() != a && !strings.Contains(strings.ToLower(e.Actor.Name), la) &&
			!strings.Contains(strings.ToLower(e.Actor.Email), la) {
			return false
		}
	}
	if actions := strings.TrimSpace(q.query("action")); actions != "" {
		ok := false
		for _, a := range strings.Split(actions, ",") {
			a = strings.TrimSpace(a)
			if strings.HasSuffix(a, "*") {
				ok = ok || strings.HasPrefix(e.Action, strings.TrimSuffix(a, "*"))
			} else {
				ok = ok || e.Action == a
			}
		}
		if !ok {
			return false
		}
	}
	if v := q.query("result"); v != "" && e.Result != v {
		return false
	}
	if v := q.query("request_id"); v != "" && e.RequestID != v {
		return false
	}
	if v := q.query("target_type"); v != "" && (e.Target == nil || e.Target.Type != v) {
		return false
	}
	if v := q.query("target_id"); v != "" && (e.Target == nil || e.Target.ID.String() != v) {
		return false
	}
	if v := q.query("target"); v != "" && (e.Target == nil || !strings.Contains(strings.ToLower(e.Target.Name), strings.ToLower(v))) {
		return false
	}
	if v := q.query("from"); v != "" && e.OccurredAt < v {
		return false
	}
	if v := q.query("to"); v != "" && e.OccurredAt > v {
		return false
	}
	if v := strings.ToLower(q.query("q")); v != "" && !strings.Contains(strings.ToLower(e.Action+" "+e.Actor.Name+" "+e.Actor.Email), v) {
		return false
	}
	return true
}

func (s *Server) auditFiltered(q *featureReq) ([]runr.AuditEvent, *apiErr) {
	if r := q.query("result"); r != "" && r != "success" && r != "denied" && r != "failure" {
		return nil, errValidation("result", "The selected result is invalid.")
	}
	for _, k := range []string{"from", "to"} {
		if v := q.query(k); v != "" {
			if _, err := time.Parse(time.RFC3339, v); err != nil {
				return nil, errValidation(k, "The "+k+" field must be a valid date.")
			}
		}
	}
	if f := q.query("format"); f != "" && f != "csv" && f != "json" {
		return nil, errValidation("format", "The selected format is invalid.")
	}
	events := s.auditState().events
	out := []runr.AuditEvent{}
	for i := len(events) - 1; i >= 0; i-- {
		if auditMatches(events[i], q) {
			out = append(out, events[i])
		}
	}
	return out, nil
}

func init() {
	handle("GET /audit-events", func(s *Server, q *featureReq) (int, any, *apiErr) {
		list, err := s.auditFiltered(q)
		if err != nil {
			return 0, nil, err
		}
		perPage := 25
		if v := q.query("per_page"); v != "" {
			n, convErr := strconv.Atoi(v)
			if convErr != nil || n < 1 || n > 100 {
				return 0, nil, errValidation("per_page", "The per page field must be between 1 and 100.")
			}
			perPage = n
		}
		total := len(list)
		if len(list) > perPage {
			list = list[:perPage]
		}
		last := (total + perPage - 1) / perPage
		if last == 0 {
			last = 1
		}
		return 200, map[string]any{"data": list, "meta": map[string]any{
			"current_page": 1, "last_page": last, "per_page": perPage, "total": total,
			"actions": []string{"project.update", "project.secret.set"}, "can_export": s.holds("audit.export"), "scope": "platform",
		}}, nil
	})

	handle("GET /audit-events/{id}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		for _, e := range s.auditState().events {
			if e.ID == q.Segs[1] {
				return 200, map[string]any{"data": e}, nil
			}
		}
		return 0, nil, errNotFound()
	})

	handle("GET /audit-events/export", func(s *Server, q *featureReq) (int, any, *apiErr) {
		list, err := s.auditFiltered(q)
		if err != nil {
			return 0, nil, err
		}
		stamp := time.Now().UTC().Format("20060102-150405")
		if q.query("format") == "json" {
			data, _ := json.Marshal(list)
			return 200, rawAnswer{contentType: "application/json", filename: "runr-audit-" + stamp + ".json", data: data}, nil
		}
		var buf bytes.Buffer
		w := csv.NewWriter(&buf)
		_ = w.Write([]string{"occurred_at", "actor_name", "action", "result", "target_type", "target_name"})
		for _, e := range list {
			tt, tn := "", ""
			if e.Target != nil {
				tt, tn = e.Target.Type, e.Target.Name
			}
			_ = w.Write([]string{e.OccurredAt, e.Actor.Name, e.Action, e.Result, tt, tn})
		}
		w.Flush()
		return 200, rawAnswer{contentType: "text/csv; charset=UTF-8", filename: "runr-audit-" + stamp + ".csv", data: buf.Bytes()}, nil
	})
}
