package runrtest

import (
	"fmt"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Observability endpoints (routes/observability.php): project switches, traces, request
// replay, uptime checks and incidents, the status page and alerting. Traces, captures, uptime
// results, incidents, alert events and deliveries are seeded (SeedTrace, SeedReplay, …); the
// fake keeps alert channel secrets apart from their masked config like the real API.

type obsState struct {
	settings map[string]*runr.ProjectObservability
	// tracingMode / tracingStatus are the tracing backend of every region.
	tracingMode, tracingStatus string
	traces                     map[string][]runr.Trace // project id
	traceLogs                  map[string][]runr.TraceLogLine
	replays                    map[string][]*runr.ReplayCapture // project id
	checks                     map[string][]*runr.UptimeCheck   // project id
	results                    map[string][]runr.UptimeResult   // check id
	incidents                  map[string][]*runr.UptimeIncident
	page                       *runr.StatusPage
	channels                   []*runr.AlertChannel
	channelSecrets             map[string]map[string]any
	rules                      []*runr.AlertRule
	events                     []runr.AlertEvent
	deliveries                 []*runr.AlertDelivery
}

func (s *Server) obs() *obsState {
	return featureState(s, "observability", func() *obsState {
		return &obsState{settings: map[string]*runr.ProjectObservability{}, tracingMode: "managed", tracingStatus: "ready",
			traces: map[string][]runr.Trace{}, traceLogs: map[string][]runr.TraceLogLine{},
			replays: map[string][]*runr.ReplayCapture{}, checks: map[string][]*runr.UptimeCheck{},
			results: map[string][]runr.UptimeResult{}, incidents: map[string][]*runr.UptimeIncident{},
			channelSecrets: map[string]map[string]any{}}
	})
}

// SetTracingBackend sets the tracing backend of every region: mode managed, reuse, external or
// off, status installing, ready or error. Traces are queryable for managed and reuse when ready.
func (s *Server) SetTracingBackend(mode, status string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	o := s.obs()
	o.tracingMode, o.tracingStatus = mode, status
}

// ObservabilitySettings returns a project's tracing and replay settings.
func (s *Server) ObservabilitySettings(projectName string) runr.ProjectObservability {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	return *s.obsSettings(p)
}

// SeedTrace stores a trace of a project (and log lines carrying its id). Empty ids are filled.
func (s *Server) SeedTrace(projectName string, tr runr.Trace, logs ...runr.TraceLogLine) runr.Trace {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	o := s.obs()
	if tr.TraceID == "" {
		tr.TraceID = fmt.Sprintf("%032x", len(o.traceLogs)+len(o.traces[p.data.ID])+1)
	}
	tr.SpanCount = len(tr.Spans)
	tr.ErrorCount = 0
	for i := range tr.Spans {
		sp := &tr.Spans[i]
		sp.TraceID = tr.TraceID
		if sp.SpanID == "" {
			sp.SpanID = fmt.Sprintf("%016x", i+1)
		}
		if sp.Resource == nil {
			sp.Resource = runr.ObsAttrs{}
		}
		sp.Resource["runr.project_id"] = p.data.ID
		if sp.Status.Code == "error" {
			tr.ErrorCount++
		}
	}
	o.traces[p.data.ID] = append(o.traces[p.data.ID], tr)
	o.traceLogs[tr.TraceID] = append(o.traceLogs[tr.TraceID], logs...)
	return tr
}

// SeedReplay stores a captured failed request of a project and returns it.
func (s *Server) SeedReplay(projectName string, c runr.ReplayCapture) runr.ReplayCapture {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	if c.ID == "" {
		c.ID = s.newID()
	}
	if c.CapturedAt == "" {
		c.CapturedAt = nowRFC3339()
	}
	if c.Method == "" {
		c.Method = "POST"
	}
	if c.Status == 0 {
		c.Status = 500
	}
	o := s.obs()
	cp := c
	o.replays[p.data.ID] = append([]*runr.ReplayCapture{&cp}, o.replays[p.data.ID]...)
	return cp
}

// SeedUptimeResult records a probe result of a check (by id).
func (s *Server) SeedUptimeResult(checkID string, r runr.UptimeResult) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if r.CheckedAt == "" {
		r.CheckedAt = nowRFC3339()
	}
	if r.Location == "" {
		r.Location = "control-plane"
	}
	o := s.obs()
	o.results[checkID] = append([]runr.UptimeResult{r}, o.results[checkID]...)
}

// SeedUptimeIncident opens (or, with ResolvedAt set, records) an incident of a project's check.
func (s *Server) SeedUptimeIncident(projectName, checkID string, in runr.UptimeIncident) runr.UptimeIncident {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	if in.ID == "" {
		in.ID = s.newID()
	}
	in.ProjectID, in.UptimeCheckID = p.data.ID, checkID
	if in.Status == "" {
		in.Status = "open"
		if in.ResolvedAt != "" {
			in.Status = "resolved"
		}
	}
	if in.StartedAt == "" {
		in.StartedAt = nowRFC3339()
	}
	cp := in
	o := s.obs()
	o.incidents[p.data.ID] = append([]*runr.UptimeIncident{&cp}, o.incidents[p.data.ID]...)
	return cp
}

// SeedAlertEvent records a fired alert.
func (s *Server) SeedAlertEvent(e runr.AlertEvent) runr.AlertEvent {
	s.mu.Lock()
	defer s.mu.Unlock()
	if e.ID == "" {
		e.ID = s.newID()
	}
	if e.Status == "" {
		e.Status = "firing"
	}
	if e.FiredAt == "" {
		e.FiredAt = nowRFC3339()
	}
	if e.Data == nil {
		e.Data = runr.ObsAttrs{}
	}
	o := s.obs()
	o.events = append([]runr.AlertEvent{e}, o.events...)
	return e
}

// SeedAlertDelivery records a delivery of an alert to a channel (status pending, sent or failed).
func (s *Server) SeedAlertDelivery(d runr.AlertDelivery) runr.AlertDelivery {
	s.mu.Lock()
	defer s.mu.Unlock()
	if d.ID == "" {
		d.ID = s.newID()
	}
	if d.Kind == "" {
		d.Kind = "alert"
	}
	if d.CreatedAt == "" {
		d.CreatedAt = nowRFC3339()
	}
	o := s.obs()
	for _, ch := range o.channels {
		if ch.ID == d.AlertChannelID {
			d.ChannelName, d.ChannelType = ch.Name, ch.Type
		}
	}
	cp := d
	o.deliveries = append([]*runr.AlertDelivery{&cp}, o.deliveries...)
	return cp
}

// AlertChannelSecrets returns the full (unmasked) config the fake stored for a channel.
func (s *Server) AlertChannelSecrets(name string) map[string]any {
	s.mu.Lock()
	defer s.mu.Unlock()
	o := s.obs()
	for _, ch := range o.channels {
		if ch.Name == name {
			out := map[string]any{}
			for k, v := range o.channelSecrets[ch.ID] {
				out[k] = v
			}
			return out
		}
	}
	return nil
}

// UptimeChecks returns a project's checks.
func (s *Server) UptimeChecks(projectName string) []runr.UptimeCheck {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return nil
	}
	out := []runr.UptimeCheck{}
	for _, c := range s.obs().checks[p.data.ID] {
		out = append(out, *c)
	}
	return out
}

// AlertRules returns the alert rules.
func (s *Server) AlertRules() []runr.AlertRule {
	s.mu.Lock()
	defer s.mu.Unlock()
	out := []runr.AlertRule{}
	for _, r := range s.obs().rules {
		out = append(out, *r)
	}
	return out
}

// ---------------------------------------------------------------------------------------

func (s *Server) obsSettings(p *project) *runr.ProjectObservability {
	o := s.obs()
	st, ok := o.settings[p.data.ID]
	if !ok {
		st = &runr.ProjectObservability{ProjectID: p.data.ID, TracingSampleRatio: 1, ReplayMaxBodyBytes: 65536,
			ReplayRetentionDays: 7, ReplayRedactHeaders: []string{}, ReplayRedactQuery: []string{},
			PlatformRedactHeaders: []string{"authorization", "cookie"}, PlatformRedactQuery: []string{"token"}}
		o.settings[p.data.ID] = st
	}
	out := *st
	out.RegionTracing = &runr.RegionTracingState{Mode: o.tracingMode, Status: o.tracingStatus,
		Queryable: o.queryable(), AcceptsSpans: o.tracingMode != "off" && o.tracingStatus == "ready"}
	out.ReplayAvailable = obsCapturable(p)
	if s.projectTrigger(p) == runr.TriggerHTTP {
		out.ReplayTarget = "https://" + p.data.Slug + "." + s.regionName(p) + ".runr.test"
	}
	return &out
}

func (o *obsState) queryable() bool {
	return (o.tracingMode == "managed" || o.tracingMode == "reuse") && o.tracingStatus == "ready"
}

func (s *Server) projectTrigger(p *project) string {
	if t, _ := p.config["trigger_type"].(string); t != "" {
		return t
	}
	return runr.TriggerHTTP
}

// obsCapturable: code runtimes with http or queue triggers (ObservabilityPodSpec::capturable).
func obsCapturable(p *project) bool {
	t, _ := p.config["trigger_type"].(string)
	return p.data.ServiceType != runr.TypeCaaS && t != runr.TriggerSchedule
}

func obsLiveDeployment(p *project) bool {
	for _, d := range p.deployments {
		if d.data.Status == runr.DeploymentActive {
			return true
		}
	}
	return false
}

func obsBool(body map[string]any, key string) (bool, bool) {
	b, ok := body[key].(bool)
	return b, ok
}

func obsNum(body map[string]any, key string) (float64, bool) {
	f, ok := body[key].(float64)
	return f, ok
}

func obsStrings(v any) []string {
	raw, _ := v.([]any)
	out := []string{}
	for _, x := range raw {
		out = append(out, fmt.Sprint(x))
	}
	return out
}

func obsNotFound(what string) *apiErr { return &apiErr{status: 404, message: what + " not found."} }

var obsTraceID = regexp.MustCompile(`^[0-9a-fA-F]{32}$`)

func init() {
	handle("GET /projects/{project}/observability", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"data": s.obsSettings(p)}, nil
	})
	handle("PUT /projects/{project}/observability/tracing", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		enabled, ok := obsBool(q.Body, "enabled")
		if !ok {
			return 0, nil, errValidation("enabled", "The enabled field is required.")
		}
		ratio, hasRatio := obsNum(q.Body, "sample_ratio")
		if hasRatio && (ratio < 0 || ratio > 1) {
			return 0, nil, errValidation("sample_ratio", "The sample ratio field must be between 0 and 1.")
		}
		o := s.obs()
		if enabled && !(o.tracingMode != "off" && o.tracingStatus == "ready") {
			return 0, nil, &apiErr{status: 409, message: "The tracing backend of the project's region is not ready."}
		}
		s.obsSettings(p)
		st := o.settings[p.data.ID]
		st.TracingEnabled = enabled
		if hasRatio {
			st.TracingSampleRatio = ratio
		}
		return 200, map[string]any{"data": s.obsSettings(p), "requires_redeploy": obsLiveDeployment(p)}, nil
	})
	handle("PUT /projects/{project}/observability/replay", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		enabled, ok := obsBool(q.Body, "enabled")
		if !ok {
			return 0, nil, errValidation("enabled", "The enabled field is required.")
		}
		if enabled && !obsCapturable(p) {
			return 0, nil, &apiErr{status: 422, message: "Request replay works for code runtimes with an http or queue trigger only."}
		}
		if n, ok := obsNum(q.Body, "max_body_bytes"); ok && (n < 0 || n > 1048576) {
			return 0, nil, errValidation("max_body_bytes", "The max body bytes field must not be greater than 1048576.")
		}
		if n, ok := obsNum(q.Body, "retention_days"); ok && (n < 1 || n > 30) {
			return 0, nil, errValidation("retention_days", "The retention days field must be between 1 and 30.")
		}
		s.obsSettings(p)
		st := s.obs().settings[p.data.ID]
		st.ReplayEnabled = enabled
		if n, ok := obsNum(q.Body, "max_body_bytes"); ok {
			st.ReplayMaxBodyBytes = int(n)
		}
		if n, ok := obsNum(q.Body, "retention_days"); ok {
			st.ReplayRetentionDays = int(n)
		}
		if _, ok := q.Body["redact_headers"]; ok {
			st.ReplayRedactHeaders = obsLower(obsStrings(q.Body["redact_headers"]))
		}
		if _, ok := q.Body["redact_query"]; ok {
			st.ReplayRedactQuery = obsLower(obsStrings(q.Body["redact_query"]))
		}
		return 200, map[string]any{"data": s.obsSettings(p), "requires_redeploy": obsLiveDeployment(p)}, nil
	})

	// Traces
	handle("GET /projects/{project}/traces", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		o := s.obs()
		if !o.queryable() {
			return 409, map[string]any{"message": "The tracing backend of this region cannot be queried.", "reason": "not_queryable"}, nil
		}
		status := q.query("status")
		switch status {
		case "", runr.TraceStatusAll, runr.TraceStatusError, runr.TraceStatusOK:
		default:
			return 0, nil, errValidation("status", "The selected status is invalid.")
		}
		limit := 50
		if l := q.query("limit"); l != "" {
			n, cerr := strconv.Atoi(l)
			if cerr != nil || n < 1 || n > 200 {
				return 0, nil, errValidation("limit", "The limit field must be between 1 and 200.")
			}
			limit = n
		}
		minMs, _ := strconv.ParseFloat(q.query("min_duration_ms"), 64)
		from, _ := strconv.ParseInt(q.query("from"), 10, 64)
		to, _ := strconv.ParseInt(q.query("to"), 10, 64)
		if from > 0 && to > 0 && from >= to {
			return 0, nil, errValidation("from", "The start of the range must be before its end.")
		}
		list := []runr.TraceSummary{}
		pathFilter := q.query("path")
		for _, tr := range o.traces[p.data.ID] {
			if len(tr.Spans) == 0 {
				continue
			}
			root := tr.Spans[0]
			code := 200
			if c, ok := root.Attributes["http.response.status_code"].(float64); ok {
				code = int(c)
			}
			path, _ := root.Attributes["url.path"].(string)
			isErr := code >= 500
			if (status == runr.TraceStatusError && !isErr) || (status == runr.TraceStatusOK && isErr) {
				continue
			}
			if pathFilter != "" && !strings.Contains(path, pathFilter) {
				continue
			}
			if minMs > 0 && tr.DurationMs < minMs {
				continue
			}
			d := tr.DurationMs
			list = append(list, runr.TraceSummary{TraceID: tr.TraceID, RootService: root.Service, RootName: root.Name,
				Start: tr.Start, StartMs: tr.StartMs, DurationMs: &d, StatusCode: &code, Path: path,
				ColdStart: root.Attributes["runr.cold_start"], Error: isErr, Matched: 1,
				Spans: []runr.TraceSearchSpan{{SpanID: root.SpanID, DurationMs: root.DurationMs, Attributes: root.Attributes}}})
		}
		sort.SliceStable(list, func(i, j int) bool { return list[i].StartMs > list[j].StartMs })
		if len(list) > limit {
			list = list[:limit]
		}
		return 200, map[string]any{"data": map[string]any{
			"traceql": `{ resource.runr.project_id = "` + p.data.ID + `" && kind = server }`,
			"from":    from, "to": to, "traces": list,
		}}, nil
	})
	traceOf := func(s *Server, q *featureReq) (*runr.Trace, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return nil, err
		}
		id := q.Segs[3]
		if !obsTraceID.MatchString(id) {
			return nil, errNotFound()
		}
		o := s.obs()
		if !o.queryable() {
			return nil, &apiErr{status: 409, message: "The tracing backend of this region cannot be queried."}
		}
		for i := range o.traces[p.data.ID] {
			if strings.EqualFold(o.traces[p.data.ID][i].TraceID, id) {
				return &o.traces[p.data.ID][i], nil
			}
		}
		return nil, obsNotFound("Trace")
	}
	handle("GET /projects/{project}/traces/{traceId}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		tr, err := traceOf(s, q)
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"data": tr}, nil
	})
	handle("GET /projects/{project}/traces/{traceId}/logs", func(s *Server, q *featureReq) (int, any, *apiErr) {
		tr, err := traceOf(s, q)
		if err != nil {
			return 0, nil, err
		}
		lines := append([]runr.TraceLogLine{}, s.obs().traceLogs[tr.TraceID]...)
		return 200, map[string]any{"data": lines}, nil
	})

	// Request replay
	handle("GET /projects/{project}/replays", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		list := []runr.ReplayCapture{}
		for _, c := range s.obs().replays[p.data.ID] {
			if st := q.query("status"); st != "" && strconv.Itoa(c.Status) != st {
				continue
			}
			if path := q.query("path"); path != "" && !strings.Contains(c.Path, path) {
				continue
			}
			list = append(list, obsCaptureSummary(c))
		}
		return 200, map[string]any{"data": list, "meta": map[string]any{"current_page": 1, "last_page": 1, "total": len(list)}}, nil
	})
	captureOf := func(s *Server, q *featureReq) (*project, *runr.ReplayCapture, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return nil, nil, err
		}
		for _, c := range s.obs().replays[p.data.ID] {
			if c.ID == q.Segs[3] {
				return p, c, nil
			}
		}
		return nil, nil, errNotFound()
	}
	handle("GET /projects/{project}/replays/{capture}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, c, err := captureOf(s, q)
		if err != nil {
			return 0, nil, err
		}
		out := *c
		if out.Headers == nil {
			out.Headers = runr.ObsAttrs{}
		}
		return 200, map[string]any{"data": out}, nil
	})
	handle("POST /projects/{project}/replays/{capture}/replay", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, c, err := captureOf(s, q)
		if err != nil {
			return 0, nil, err
		}
		if s.projectTrigger(p) != runr.TriggerHTTP {
			return 0, nil, &apiErr{status: 409, message: "The project has no HTTP endpoint to replay to."}
		}
		if h, ok := q.Body["headers"].(map[string]any); ok {
			for name := range h {
				if !regexp.MustCompile(`^[A-Za-z0-9!#$%&'*+.^_` + "`" + `|~-]{1,100}$`).MatchString(name) {
					return 0, nil, errValidation("headers", "A header name is invalid.")
				}
			}
		}
		c.ReplayCount++
		c.LastReplayedAt = nowRFC3339()
		after := 200
		c.LastReplayStatus = &after
		url := "https://" + p.data.Slug + "." + s.regionName(p) + ".runr.test" + c.Path
		original := map[string]any{"status": c.Status, "headers": obsHeaders(c.ResponseHeaders), "body": c.ResponseBody,
			"body_base64": c.ResponseBodyBase64, "truncated": c.ResponseTruncated}
		replay := map[string]any{"status": after, "headers": map[string]any{"content-type": "application/json"},
			"body": `{"ok":true}`, "body_base64": false, "truncated": false, "error": nil, "duration_ms": 12.5,
			"replayed_at": nowRFC3339(), "url": url, "version": len(p.versions)}
		diff := map[string]any{
			"status":  map[string]any{"before": c.Status, "after": after, "changed": c.Status != after},
			"headers": map[string]any{"added": map[string]any{"content-type": "application/json"}, "removed": []any{}, "changed": []any{}},
			"body": map[string]any{"changed": true, "truncated": false, "lines": []map[string]any{
				{"op": "-", "text": c.ResponseBody}, {"op": "+", "text": `{"ok":true}`}}},
			"changed": true,
		}
		return 200, map[string]any{"data": map[string]any{"capture": obsCaptureSummary(c), "original": original,
			"replay": replay, "diff": diff}}, nil
	})
	handle("DELETE /projects/{project}/replays/{capture}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, c, err := captureOf(s, q)
		if err != nil {
			return 0, nil, err
		}
		o := s.obs()
		list := o.replays[p.data.ID]
		for i := range list {
			if list[i] == c {
				o.replays[p.data.ID] = append(list[:i], list[i+1:]...)
				break
			}
		}
		return 204, nil, nil
	})
	handle("DELETE /projects/{project}/replays", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		o := s.obs()
		n := len(o.replays[p.data.ID])
		delete(o.replays, p.data.ID)
		return 200, map[string]any{"deleted": n}, nil
	})

	// Uptime checks
	handle("GET /projects/{project}/uptime-checks", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		o := s.obs()
		list := []runr.UptimeCheck{}
		for _, c := range o.checks[p.data.ID] {
			out := *c
			out.Uptime24h, out.Uptime7d, out.Uptime30d = o.uptime(c.ID), o.uptime(c.ID), o.uptime(c.ID)
			out.AvgResponseMs24h = o.avgResponse(c.ID)
			list = append(list, out)
		}
		sort.SliceStable(list, func(i, j int) bool { return list[i].Name < list[j].Name })
		return 200, map[string]any{"data": list}, nil
	})
	handle("POST /projects/{project}/uptime-checks", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		o := s.obs()
		if len(o.checks[p.data.ID]) >= 20 {
			return 0, nil, &apiErr{status: 422, message: "A project has at most 20 uptime checks."}
		}
		if q.str("name") == "" {
			return 0, nil, errValidation("name", "The name field is required.")
		}
		c := &runr.UptimeCheck{ID: s.newID(), ProjectID: p.data.ID, Path: "/", Method: "GET", IntervalSeconds: 60,
			TimeoutSeconds: 10, ExpectedStatus: "200-399", Locations: runr.UptimeControlPlane, FailureThreshold: 3,
			RecoveryThreshold: 2, IsEnabled: true, Status: runr.UptimeUnknown, NextCheckAt: nowRFC3339(),
			RegionIDs: []string{}, HeaderNames: []string{}, LocationState: []byte(`{}`)}
		if err := s.obsApplyCheck(c, q.Body); err != nil {
			return 0, nil, err
		}
		if c.URL == "" && s.projectTrigger(p) != runr.TriggerHTTP {
			return 0, nil, errValidation("url", "The project has no HTTP endpoint; give an absolute url.")
		}
		o.checks[p.data.ID] = append(o.checks[p.data.ID], c)
		return 201, map[string]any{"data": c}, nil
	})
	checkOf := func(s *Server, q *featureReq) (*project, *runr.UptimeCheck, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return nil, nil, err
		}
		for _, c := range s.obs().checks[p.data.ID] {
			if c.ID == q.Segs[3] {
				return p, c, nil
			}
		}
		return nil, nil, errNotFound()
	}
	handle("PUT /projects/{project}/uptime-checks/{check}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, c, err := checkOf(s, q)
		if err != nil {
			return 0, nil, err
		}
		next := *c
		if err := s.obsApplyCheck(&next, q.Body); err != nil {
			return 0, nil, err
		}
		*c = next
		return 200, map[string]any{"data": c}, nil
	})
	handle("DELETE /projects/{project}/uptime-checks/{check}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, c, err := checkOf(s, q)
		if err != nil {
			return 0, nil, err
		}
		o := s.obs()
		list := o.checks[p.data.ID]
		for i := range list {
			if list[i] == c {
				o.checks[p.data.ID] = append(list[:i], list[i+1:]...)
				break
			}
		}
		delete(o.results, c.ID)
		return 204, nil, nil
	})
	handle("GET /projects/{project}/uptime-checks/{check}/results", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, c, err := checkOf(s, q)
		if err != nil {
			return 0, nil, err
		}
		if h := q.query("hours"); h != "" {
			if n, cerr := strconv.Atoi(h); cerr != nil || n < 1 || n > 720 {
				return 0, nil, errValidation("hours", "The hours field must be between 1 and 720.")
			}
		}
		o := s.obs()
		results := []runr.UptimeResult{}
		locations := []string{}
		seen := map[string]bool{}
		for _, r := range o.results[c.ID] {
			if loc := q.query("location"); loc != "" && r.Location != loc {
				continue
			}
			results = append(results, r)
			if !seen[r.Location] {
				seen[r.Location] = true
				locations = append(locations, r.Location)
			}
		}
		incidents := []runr.UptimeIncident{}
		for _, in := range o.incidents[p.data.ID] {
			if in.UptimeCheckID == c.ID {
				incidents = append(incidents, *in)
			}
		}
		up := o.uptime(c.ID)
		return 200, map[string]any{"data": map[string]any{
			"check": c, "results": results, "locations": locations,
			"uptime":              map[string]any{"24h": up, "7d": up, "30d": up},
			"avg_response_ms_24h": o.avgResponse(c.ID),
			"days":                []map[string]any{{"date": nowRFC3339()[:10], "total": len(results), "up": o.upCount(c.ID), "uptime": up}},
			"incidents":           incidents,
		}}, nil
	})
	handle("POST /projects/{project}/uptime-checks/{check}/run", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, c, err := checkOf(s, q)
		if err != nil {
			return 0, nil, err
		}
		code, ms := 200, 42
		o := s.obs()
		o.results[c.ID] = append([]runr.UptimeResult{{Location: "control-plane", OK: true, StatusCode: &code,
			ResponseMs: &ms, CheckedAt: nowRFC3339()}}, o.results[c.ID]...)
		c.Status, c.LastCheckedAt, c.LastResponseMs, c.LastStatusCode, c.LastError = runr.UptimeUp, nowRFC3339(), &ms, &code, ""
		return 200, map[string]any{"data": map[string]any{"ok": true, "status_code": code, "response_ms": ms, "error": nil, "check": c}}, nil
	})
	handle("GET /projects/{project}/uptime-incidents", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		list := []runr.UptimeIncident{}
		for _, in := range s.obs().incidents[p.data.ID] {
			list = append(list, *in)
		}
		return 200, map[string]any{"data": list}, nil
	})
	handle("PATCH /projects/{project}/uptime-incidents/{incident}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		for _, in := range s.obs().incidents[p.data.ID] {
			if in.ID != q.Segs[3] {
				continue
			}
			if t, ok := q.Body["title"].(string); ok {
				if len(t) > 200 {
					return 0, nil, errValidation("title", "The title field must not be greater than 200 characters.")
				}
				in.Title = t
			}
			if _, ok := q.Body["message"]; ok {
				in.Message, _ = q.Body["message"].(string)
			}
			return 200, map[string]any{"data": in}, nil
		}
		return 0, nil, errNotFound()
	})

	// Status page
	handle("GET /observability/status-page", func(s *Server, q *featureReq) (int, any, *apiErr) {
		return 200, map[string]any{"data": s.obsStatusPage()}, nil
	})
	handle("PUT /observability/status-page", func(s *Server, q *featureReq) (int, any, *apiErr) {
		slug, title := q.str("slug"), q.str("title")
		if !regexp.MustCompile(`^[a-z0-9]([-a-z0-9]*[a-z0-9])?$`).MatchString(slug) || len(slug) < 3 || len(slug) > 63 {
			return 0, nil, errValidation("slug", "The slug field format is invalid.")
		}
		if title == "" {
			return 0, nil, errValidation("title", "The title field is required.")
		}
		public, ok := obsBool(q.Body, "is_public")
		if !ok {
			return 0, nil, errValidation("is_public", "The is public field is required.")
		}
		days := 90
		if n, ok := obsNum(q.Body, "history_days"); ok {
			if n < 7 || n > 90 {
				return 0, nil, errValidation("history_days", "The history days field must be between 7 and 90.")
			}
			days = int(n)
		}
		history := true
		if b, ok := obsBool(q.Body, "show_incident_history"); ok {
			history = b
		}
		o := s.obs()
		id := s.newID()
		if o.page != nil {
			id = o.page.ID
		}
		desc, _ := q.Body["description"].(string)
		logo, _ := q.Body["logo_url"].(string)
		domain, _ := q.Body["custom_domain"].(string)
		o.page = &runr.StatusPage{ID: id, Slug: slug, Title: title, Description: desc, LogoURL: logo,
			CustomDomain: strings.ToLower(strings.TrimSuffix(strings.TrimSpace(domain), ".")), IsPublic: public,
			ShowIncidentHistory: history, HistoryDays: days, Exists: true}
		comps, _ := q.Body["components"].([]any)
		for _, raw := range comps {
			m, _ := raw.(map[string]any)
			id, _ := m["id"].(string)
			for _, checks := range o.checks {
				for _, c := range checks {
					if c.ID != id {
						continue
					}
					c.ShowOnStatusPage, _ = m["show_on_status_page"].(bool)
					c.StatusPageName, _ = m["status_page_name"].(string)
					if n, ok := m["status_page_order"].(float64); ok {
						c.StatusPageOrder = int(n)
					}
				}
			}
		}
		return 200, map[string]any{"data": s.obsStatusPage()}, nil
	})

	// Alert channels
	handle("GET /observability/alert-channels", func(s *Server, q *featureReq) (int, any, *apiErr) {
		list := []runr.AlertChannel{}
		for _, ch := range s.obs().channels {
			list = append(list, *ch)
		}
		sort.SliceStable(list, func(i, j int) bool { return list[i].Name < list[j].Name })
		return 200, map[string]any{"data": list}, nil
	})
	handle("POST /observability/alert-channels", func(s *Server, q *featureReq) (int, any, *apiErr) {
		name, typ := q.str("name"), q.str("type")
		if name == "" {
			return 0, nil, errValidation("name", "The name field is required.")
		}
		if !obsIn(typ, runr.AlertChannelTypes) {
			return 0, nil, errValidation("type", "The selected type is invalid.")
		}
		cfg, ok := q.Body["config"].(map[string]any)
		if !ok {
			return 0, nil, errValidation("config", "The config field is required.")
		}
		clean, err := obsChannelConfig(typ, cfg)
		if err != nil {
			return 0, nil, err
		}
		enabled := true
		if b, ok := obsBool(q.Body, "is_enabled"); ok {
			enabled = b
		}
		o := s.obs()
		ch := &runr.AlertChannel{ID: s.newID(), Name: name, Type: typ, IsEnabled: enabled, CreatedAt: nowRFC3339()}
		o.channelSecrets[ch.ID] = clean
		ch.Config = obsMask(typ, clean)
		o.channels = append(o.channels, ch)
		return 201, map[string]any{"data": ch}, nil
	})
	channelOf := func(s *Server, q *featureReq) (*runr.AlertChannel, *apiErr) {
		for _, ch := range s.obs().channels {
			if ch.ID == q.Segs[2] {
				return ch, nil
			}
		}
		return nil, errNotFound()
	}
	handle("PUT /observability/alert-channels/{channel}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		ch, err := channelOf(s, q)
		if err != nil {
			return 0, nil, err
		}
		o := s.obs()
		if cfg, ok := q.Body["config"].(map[string]any); ok {
			merged := map[string]any{}
			for k, v := range o.channelSecrets[ch.ID] {
				merged[k] = v
			}
			for k, v := range cfg {
				if v != nil && v != "" {
					merged[k] = v
				}
			}
			clean, err := obsChannelConfig(ch.Type, merged)
			if err != nil {
				return 0, nil, err
			}
			o.channelSecrets[ch.ID] = clean
			ch.Config = obsMask(ch.Type, clean)
		}
		if n := q.str("name"); n != "" {
			ch.Name = n
		}
		if b, ok := obsBool(q.Body, "is_enabled"); ok {
			ch.IsEnabled = b
		}
		return 200, map[string]any{"data": ch}, nil
	})
	handle("DELETE /observability/alert-channels/{channel}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		ch, err := channelOf(s, q)
		if err != nil {
			return 0, nil, err
		}
		o := s.obs()
		for i := range o.channels {
			if o.channels[i] == ch {
				o.channels = append(o.channels[:i], o.channels[i+1:]...)
				break
			}
		}
		for _, r := range o.rules {
			kept := []string{}
			for _, id := range r.ChannelIDs {
				if id != ch.ID {
					kept = append(kept, id)
				}
			}
			r.ChannelIDs = kept
		}
		delete(o.channelSecrets, ch.ID)
		return 204, nil, nil
	})
	handle("POST /observability/alert-channels/{channel}/test", func(s *Server, q *featureReq) (int, any, *apiErr) {
		ch, err := channelOf(s, q)
		if err != nil {
			return 0, nil, err
		}
		code := 200
		ok := true
		ch.LastTestAt, ch.LastTestOK, ch.LastTestError = nowRFC3339(), &ok, ""
		o := s.obs()
		o.deliveries = append([]*runr.AlertDelivery{{ID: s.newID(), AlertChannelID: ch.ID, ChannelName: ch.Name,
			ChannelType: ch.Type, Kind: "test", Status: "sent", Attempts: 1, ResponseCode: &code, SentAt: nowRFC3339(),
			CreatedAt: nowRFC3339()}}, o.deliveries...)
		return 200, map[string]any{"data": map[string]any{"ok": true, "response_code": code, "error": nil, "channel": ch}}, nil
	})

	// Alert rules
	handle("GET /observability/alert-rules", func(s *Server, q *featureReq) (int, any, *apiErr) {
		list := []runr.AlertRule{}
		for _, r := range s.obs().rules {
			list = append(list, *r)
		}
		sort.SliceStable(list, func(i, j int) bool { return list[i].Name < list[j].Name })
		return 200, map[string]any{"data": list}, nil
	})
	handle("POST /observability/alert-rules", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if q.str("name") == "" {
			return 0, nil, errValidation("name", "The name field is required.")
		}
		typ := q.str("type")
		if !obsIn(typ, runr.AlertRuleTypes) {
			return 0, nil, errValidation("type", "The selected type is invalid.")
		}
		r := &runr.AlertRule{ID: s.newID(), Type: typ, Severity: "warning", CooldownMinutes: 15, IsEnabled: true,
			State: "ok", Params: runr.ObsAttrs{}, ChannelIDs: []string{}}
		if err := s.obsApplyRule(r, q.Body, true); err != nil {
			return 0, nil, err
		}
		o := s.obs()
		o.rules = append(o.rules, r)
		return 201, map[string]any{"data": r}, nil
	})
	ruleOf := func(s *Server, q *featureReq) (*runr.AlertRule, *apiErr) {
		for _, r := range s.obs().rules {
			if r.ID == q.Segs[2] {
				return r, nil
			}
		}
		return nil, errNotFound()
	}
	handle("PUT /observability/alert-rules/{rule}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		r, err := ruleOf(s, q)
		if err != nil {
			return 0, nil, err
		}
		if _, has := q.Body["type"]; has {
			return 0, nil, errValidation("type", "The type field is prohibited.")
		}
		next := *r
		if err := s.obsApplyRule(&next, q.Body, false); err != nil {
			return 0, nil, err
		}
		*r = next
		return 200, map[string]any{"data": r}, nil
	})
	handle("DELETE /observability/alert-rules/{rule}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		r, err := ruleOf(s, q)
		if err != nil {
			return 0, nil, err
		}
		o := s.obs()
		for i := range o.rules {
			if o.rules[i] == r {
				o.rules = append(o.rules[:i], o.rules[i+1:]...)
				break
			}
		}
		for i := range o.events {
			if o.events[i].AlertRuleID == r.ID && o.events[i].Status == "firing" {
				o.events[i].Status, o.events[i].ResolvedAt = "resolved", nowRFC3339()
			}
		}
		return 204, nil, nil
	})
	handle("GET /observability/alert-events", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := q.query("status")
		if st != "" && st != "firing" && st != "resolved" {
			return 0, nil, errValidation("status", "The selected status is invalid.")
		}
		list := []runr.AlertEvent{}
		for _, e := range s.obs().events {
			if st == "" || e.Status == st {
				list = append(list, e)
			}
		}
		return 200, map[string]any{"data": list}, nil
	})
	handle("GET /observability/alert-deliveries", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := q.query("status")
		if st != "" && !obsIn(st, []string{"pending", "sent", "failed"}) {
			return 0, nil, errValidation("status", "The selected status is invalid.")
		}
		list := []runr.AlertDelivery{}
		for _, d := range s.obs().deliveries {
			if (st == "" || d.Status == st) && (q.query("channel_id") == "" || d.AlertChannelID == q.query("channel_id")) {
				list = append(list, *d)
			}
		}
		total := len(list)
		if len(list) > 50 {
			list = list[:50]
		}
		last := (total + 49) / 50
		if last == 0 {
			last = 1
		}
		return 200, map[string]any{"data": list, "meta": map[string]any{"current_page": 1, "last_page": last, "total": total}}, nil
	})
	handle("POST /observability/alert-deliveries/{delivery}/retry", func(s *Server, q *featureReq) (int, any, *apiErr) {
		for _, d := range s.obs().deliveries {
			if d.ID != q.Segs[2] {
				continue
			}
			if d.Status != "failed" || d.Kind == "test" {
				return 0, nil, &apiErr{status: 409, message: "Only a failed alert delivery can be retried."}
			}
			d.Status, d.NextAttemptAt = "pending", ""
			return 202, map[string]any{"data": d}, nil
		}
		return 0, nil, errNotFound()
	})
}

func obsLower(in []string) []string {
	out := []string{}
	for _, s := range in {
		if s = strings.ToLower(strings.TrimSpace(s)); s != "" {
			out = append(out, s)
		}
	}
	return out
}

func obsIn(v string, list []string) bool {
	for _, x := range list {
		if x == v {
			return true
		}
	}
	return false
}

func obsHeaders(h runr.ObsAttrs) map[string]any {
	out := map[string]any{}
	for k, v := range h {
		out[k] = v
	}
	return out
}

func obsCaptureSummary(c *runr.ReplayCapture) runr.ReplayCapture {
	out := *c
	out.Headers, out.RedactedHeaders, out.Body, out.BodyBase64, out.BodyTruncated = nil, nil, "", false, false
	out.ResponseHeaders, out.ResponseBody, out.ResponseBodyBase64, out.ResponseTruncated = nil, "", false, false
	out.LastReplay = nil
	return out
}

func (o *obsState) uptime(checkID string) *float64 {
	rs := o.results[checkID]
	if len(rs) == 0 {
		return nil
	}
	v := float64(int(10000*float64(o.upCount(checkID))/float64(len(rs)))) / 100
	return &v
}

func (o *obsState) upCount(checkID string) int {
	n := 0
	for _, r := range o.results[checkID] {
		if r.OK {
			n++
		}
	}
	return n
}

func (o *obsState) avgResponse(checkID string) *int {
	sum, n := 0, 0
	for _, r := range o.results[checkID] {
		if r.ResponseMs != nil {
			sum += *r.ResponseMs
			n++
		}
	}
	if n == 0 {
		return nil
	}
	avg := sum / n
	return &avg
}

var obsExpected = regexp.MustCompile(`^(\d{3}(-\d{3})?|[1-5]xx)(,(\d{3}(-\d{3})?|[1-5]xx))*$`)

// obsApplyCheck validates and applies the fields of an uptime check body (UptimeCheckController::validated).
func (s *Server) obsApplyCheck(c *runr.UptimeCheck, body map[string]any) *apiErr {
	if v, ok := body["name"].(string); ok {
		if v == "" || len(v) > 100 {
			return errValidation("name", "The name field must be 1 to 100 characters.")
		}
		c.Name = v
	}
	if v, ok := body["url"].(string); ok && v != "" {
		if !strings.HasPrefix(v, "http://") && !strings.HasPrefix(v, "https://") {
			return errValidation("url", "The url field must be a valid URL.")
		}
		c.URL = v
	}
	if v, ok := body["path"].(string); ok && v != "" {
		if !strings.HasPrefix(v, "/") {
			return errValidation("path", "The path field format is invalid.")
		}
		c.Path = v
	}
	if v, ok := body["method"].(string); ok && v != "" {
		if !obsIn(v, []string{"GET", "HEAD", "POST"}) {
			return errValidation("method", "The selected method is invalid.")
		}
		c.Method = v
	}
	if h, ok := body["headers"].(map[string]any); ok {
		names := []string{}
		for k := range h {
			names = append(names, k)
		}
		sort.Strings(names)
		c.HeaderNames = names
	}
	if n, ok := obsNum(body, "interval_seconds"); ok {
		if n < 30 || n > 3600 {
			return errValidation("interval_seconds", "The interval seconds field must be between 30 and 3600.")
		}
		c.IntervalSeconds = int(n)
	}
	if n, ok := obsNum(body, "timeout_seconds"); ok {
		if n < 1 || n > 60 {
			return errValidation("timeout_seconds", "The timeout seconds field must be between 1 and 60.")
		}
		c.TimeoutSeconds = int(n)
	}
	if v, ok := body["expected_status"].(string); ok && v != "" {
		if !obsExpected.MatchString(v) {
			return errValidation("expected_status", "The expected status is invalid: use 200, 200,204, 200-399 or 2xx.")
		}
		c.ExpectedStatus = v
	}
	if v, ok := body["body_regex"].(string); ok {
		if _, err := regexp.Compile(v); err != nil {
			return errValidation("body_regex", "The body pattern is not a valid regular expression.")
		}
		c.BodyRegex = v
	}
	if v, ok := body["locations"].(string); ok && v != "" {
		if v != runr.UptimeControlPlane && v != runr.UptimeRegions {
			return errValidation("locations", "The selected locations is invalid.")
		}
		c.Locations = v
	}
	if _, ok := body["region_ids"]; ok {
		ids := obsStrings(body["region_ids"])
		for _, id := range ids {
			if s.regionByID(id) == nil {
				return errValidation("region_ids", "A region of the check does not exist.")
			}
		}
		c.RegionIDs = ids
	}
	if n, ok := obsNum(body, "failure_threshold"); ok {
		if n < 1 || n > 20 {
			return errValidation("failure_threshold", "The failure threshold field must be between 1 and 20.")
		}
		c.FailureThreshold = int(n)
	}
	if n, ok := obsNum(body, "recovery_threshold"); ok {
		if n < 1 || n > 20 {
			return errValidation("recovery_threshold", "The recovery threshold field must be between 1 and 20.")
		}
		c.RecoveryThreshold = int(n)
	}
	if b, ok := obsBool(body, "is_enabled"); ok {
		c.IsEnabled = b
	}
	if b, ok := obsBool(body, "show_on_status_page"); ok {
		c.ShowOnStatusPage = b
	}
	if v, ok := body["status_page_name"].(string); ok {
		c.StatusPageName = v
	}
	if n, ok := obsNum(body, "status_page_order"); ok {
		c.StatusPageOrder = int(n)
	}
	return nil
}

// obsApplyRule validates and applies an alert rule body (AlertRuleController::validated).
func (s *Server) obsApplyRule(r *runr.AlertRule, body map[string]any, create bool) *apiErr {
	if v, ok := body["name"].(string); ok {
		if v == "" || len(v) > 100 {
			return errValidation("name", "The name field must be 1 to 100 characters.")
		}
		r.Name = v
	}
	if v, ok := body["project_id"].(string); ok {
		if v != "" && s.projectByID(v) == nil {
			return errValidation("project_id", "The project does not exist.")
		}
		r.ProjectID = v
	}
	if v, ok := body["region_id"].(string); ok {
		if v != "" && s.regionByID(v) == nil {
			return errValidation("region_id", "The region does not exist.")
		}
		r.RegionID = v
	}
	if v, ok := body["severity"].(string); ok && v != "" {
		if !obsIn(v, runr.AlertSeverities) {
			return errValidation("severity", "The selected severity is invalid.")
		}
		r.Severity = v
	}
	if n, ok := obsNum(body, "cooldown_minutes"); ok {
		if n < 0 || n > 10080 {
			return errValidation("cooldown_minutes", "The cooldown minutes field must be between 0 and 10080.")
		}
		r.CooldownMinutes = int(n)
	}
	if _, ok := body["channel_ids"]; ok {
		ids := obsStrings(body["channel_ids"])
		for _, id := range ids {
			found := false
			for _, ch := range s.obs().channels {
				found = found || ch.ID == id
			}
			if !found {
				return errValidation("channel_ids", "An alert channel of the rule does not exist.")
			}
		}
		r.ChannelIDs = ids
	}
	if b, ok := obsBool(body, "is_enabled"); ok {
		r.IsEnabled = b
	}
	params, hasParams := body["params"].(map[string]any)
	if !create && !hasParams {
		return nil
	}
	keys := map[string][]string{
		"resource_threshold": {"resource", "min_level"},
		"error_rate":         {"threshold", "window_minutes", "min_requests"},
		"cold_start_rate":    {"threshold", "window_minutes", "min_requests"},
		"p95_latency":        {"threshold_ms", "window_minutes", "min_requests"},
	}[r.Type]
	required := map[string]string{"error_rate": "threshold", "cold_start_rate": "threshold", "p95_latency": "threshold_ms"}[r.Type]
	if required != "" {
		if _, ok := params[required].(float64); !ok {
			return errValidation("params."+required, "The params."+required+" field is required.")
		}
	}
	clean := runr.ObsAttrs{}
	for _, k := range keys {
		if v, ok := params[k]; ok {
			clean[k] = v
		}
	}
	r.Params = clean
	return nil
}

// obsChannelConfig validates a channel configuration and keeps the known keys.
func obsChannelConfig(typ string, cfg map[string]any) (map[string]any, *apiErr) {
	str := func(k string) string { v, _ := cfg[k].(string); return v }
	switch typ {
	case "email":
		emails := obsStrings(cfg["emails"])
		if len(emails) == 0 || len(emails) > 20 {
			return nil, errValidation("config.emails", "The config.emails field must have 1 to 20 addresses.")
		}
		for _, e := range emails {
			if !strings.Contains(e, "@") {
				return nil, errValidation("config.emails", "The config.emails field must contain valid e-mail addresses.")
			}
		}
		return map[string]any{"emails": obsLower(emails)}, nil
	case "slack", "teams":
		if !strings.HasPrefix(str("webhook_url"), "https://") {
			return nil, errValidation("config.webhook_url", "The config.webhook url field must be an https URL.")
		}
		return map[string]any{"webhook_url": str("webhook_url")}, nil
	case "telegram":
		if !regexp.MustCompile(`^\d{3,20}:[A-Za-z0-9_-]{20,100}$`).MatchString(str("bot_token")) {
			return nil, errValidation("config.bot_token", "The config.bot token field format is invalid.")
		}
		if str("chat_id") == "" {
			return nil, errValidation("config.chat_id", "The config.chat id field is required.")
		}
		return map[string]any{"bot_token": str("bot_token"), "chat_id": str("chat_id")}, nil
	case "webhook":
		u := str("url")
		if !strings.HasPrefix(u, "http://") && !strings.HasPrefix(u, "https://") {
			return nil, errValidation("config.url", "The config.url field must be a valid URL.")
		}
		out := map[string]any{"url": u}
		if sec := str("secret"); sec != "" {
			if len(sec) < 16 {
				return nil, errValidation("config.secret", "The config.secret field must be at least 16 characters.")
			}
			out["secret"] = sec
		}
		return out, nil
	}
	return nil, errValidation("type", "The selected type is invalid.")
}

// obsMask is AlertChannel::maskedConfig.
func obsMask(typ string, c map[string]any) runr.ObsAttrs {
	host := func(u string) any {
		if u == "" {
			return nil
		}
		rest := u[strings.Index(u, "://")+3:]
		if i := strings.IndexAny(rest, "/?#"); i >= 0 {
			rest = rest[:i]
		}
		return rest
	}
	str := func(k string) string { v, _ := c[k].(string); return v }
	switch typ {
	case "email":
		return runr.ObsAttrs{"emails": c["emails"]}
	case "slack", "teams":
		return runr.ObsAttrs{"webhook_host": host(str("webhook_url")), "webhook_set": str("webhook_url") != ""}
	case "telegram":
		return runr.ObsAttrs{"chat_id": str("chat_id"), "bot_token_set": str("bot_token") != ""}
	case "webhook":
		return runr.ObsAttrs{"url_host": host(str("url")), "url_set": str("url") != "", "secret_set": str("secret") != ""}
	}
	return runr.ObsAttrs{}
}

func (s *Server) obsStatusPage() map[string]any {
	o := s.obs()
	page := runr.StatusPage{Slug: "status", Title: "Status", IsPublic: false, ShowIncidentHistory: true, HistoryDays: 90}
	if o.page != nil {
		page = *o.page
	}
	components := []runr.StatusPageComponent{}
	for _, p := range s.projects {
		for _, c := range o.checks[p.data.ID] {
			status := "unknown"
			switch c.Status {
			case runr.UptimeUp:
				status = "operational"
			case runr.UptimeDown:
				status = "outage"
			}
			components = append(components, runr.StatusPageComponent{ID: c.ID, Name: c.Name, ProjectID: p.data.ID,
				ProjectName: p.data.Name, ShowOnStatusPage: c.ShowOnStatusPage, StatusPageName: c.StatusPageName,
				StatusPageOrder: c.StatusPageOrder, Status: status})
		}
	}
	var preview any
	if page.Exists {
		preview = map[string]any{"title": page.Title, "status": "operational"}
	}
	return map[string]any{"page": page, "components": components, "preview": preview, "public_path": "/status/" + page.Slug}
}
