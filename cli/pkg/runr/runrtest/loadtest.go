package runrtest

import (
	"strconv"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Load-test endpoints (routes/observability.php, docs/load-test.md). A started test is pending,
// running on the next read and completed (or failed with SetLoadTestOutcome) on the one after;
// the guard of the API answers like it: only the project's function host, the 10 / 50 / 100
// users and 30 / 60 / 300 s choices, one test per project (409), a daily limit (429).

type loadTestState struct {
	tests      map[string][]*runr.LoadTest // project id, newest first
	failWith   string                      // non-empty: tests end failed with this error
	dailyLimit int
	used       int
}

func (s *Server) loadTests() *loadTestState {
	return featureState(s, "load-tests", func() *loadTestState {
		return &loadTestState{tests: map[string][]*runr.LoadTest{}, dailyLimit: 20}
	})
}

// SetLoadTestOutcome makes the next tests fail with message ("" lets them complete), and sets the
// organization's daily limit (0 keeps it).
func (s *Server) SetLoadTestOutcome(failMessage string, dailyLimit int) {
	s.mu.Lock()
	defer s.mu.Unlock()
	lt := s.loadTests()
	lt.failWith = failMessage
	if dailyLimit > 0 {
		lt.dailyLimit = dailyLimit
	}
}

// SeedLoadTest stores a test of a project (newest first) and returns it.
func (s *Server) SeedLoadTest(projectName string, t runr.LoadTest) runr.LoadTest {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	if t.ID == "" {
		t.ID = s.newID()
	}
	t.ProjectID = p.data.ID
	if t.Status == "" {
		t.Status = runr.LoadTestCompleted
	}
	if t.Method == "" {
		t.Method = "GET"
	}
	if t.Host == "" {
		t.Host = loadTestHost(p)
	}
	if t.Path == "" {
		t.Path = "/"
	}
	t.URL = "https://" + t.Host + t.Path
	if t.CreatedAt == "" {
		t.CreatedAt = nowRFC3339()
	}
	cp := t
	lt := s.loadTests()
	lt.tests[p.data.ID] = append([]*runr.LoadTest{&cp}, lt.tests[p.data.ID]...)
	return cp
}

// LoadTests returns a project's tests, newest first.
func (s *Server) LoadTests(projectName string) []runr.LoadTest {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	out := []runr.LoadTest{}
	for _, t := range s.loadTests().tests[p.data.ID] {
		out = append(out, *t)
	}
	return out
}

// loadTestHost is the project's function host: <slug>.<region>.runr.test.
func loadTestHost(p *project) string {
	region := "region"
	if p.data.Region != nil && p.data.Region.Name != "" {
		region = p.data.Region.Name
	}
	return p.data.Slug + "." + region + ".runr.test"
}

func loadTestResults(users int) *runr.LoadTestResults {
	f := func(v float64) *float64 { return &v }
	pods, cold := users/10, 1
	return &runr.LoadTestResults{
		Requests: users * 300, FailedRequests: 3, RPS: float64(users) * 9.95, ErrorRate: 3 / float64(users*300),
		LatencyMs:    runr.LoadTestLatency{Avg: f(21.5), Min: f(2.1), P50: f(18.2), P95: f(48.7), P99: f(97.3), Max: f(412)},
		DataReceived: 1 << 20, DataSent: 1 << 18, PodsPeak: &pods, PodsSource: "prometheus", ColdStarts: &cold,
	}
}

func init() {
	ofProject := func(s *Server, q *featureReq) (*project, *runr.LoadTest, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return nil, nil, err
		}
		for _, t := range s.loadTests().tests[p.data.ID] {
			if t.ID == q.Segs[3] {
				return p, t, nil
			}
		}
		return nil, nil, errNotFound()
	}

	handle("GET /projects/{project}/load-tests", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		lt := s.loadTests()
		limit := 10
		if n, perr := strconv.Atoi(q.query("limit")); perr == nil && n > 0 {
			limit = n
		}
		list := []runr.LoadTest{}
		for i, t := range lt.tests[p.data.ID] {
			if i >= limit {
				break
			}
			list = append(list, *t)
		}
		meta := runr.LoadTestOptions{Enabled: true, Users: []int{10, 50, 100}, Durations: []int{30, 60, 300},
			Methods: []string{"GET", "POST"}, Targets: []string{loadTestHost(p)}, DailyLimit: lt.dailyLimit,
			UsedToday: lt.used, MaxBodyBytes: 4096, Metered: true}
		return 200, map[string]any{"data": list, "meta": meta}, nil
	})

	handle("POST /projects/{project}/load-tests", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		lt := s.loadTests()
		users, _ := q.Body["users"].(float64)
		duration, _ := q.Body["duration_seconds"].(float64)
		if users != 10 && users != 50 && users != 100 {
			return 0, nil, errValidation("users", "The selected users is invalid.")
		}
		if duration != 30 && duration != 60 && duration != 300 {
			return 0, nil, errValidation("duration_seconds", "The selected duration seconds is invalid.")
		}
		host := q.str("host")
		if host == "" {
			host = loadTestHost(p)
		}
		if host != loadTestHost(p) {
			e := errValidation("host", host+" is not this project's URL.")
			e.code = "host_not_allowed"
			return 0, nil, e
		}
		for _, t := range lt.tests[p.data.ID] {
			if !t.Done() {
				return 0, nil, &apiErr{status: 409, message: "A load test of this project is running. Wait for it or cancel it.",
					code: "load_test_running", extra: map[string]any{"load_test_id": t.ID}}
			}
		}
		if lt.used >= lt.dailyLimit {
			return 0, nil, &apiErr{status: 429, message: "Your organization reached its daily limit of load tests.",
				code: "daily_limit_reached", extra: map[string]any{"daily_limit": lt.dailyLimit}}
		}
		method := q.str("method")
		if method == "" {
			method = "GET"
		}
		path := q.str("path")
		if path == "" {
			path = "/"
		}
		t := &runr.LoadTest{ID: s.newID(), ProjectID: p.data.ID, Status: runr.LoadTestPending, Method: method, Host: host,
			Path: path, URL: "https://" + host + path, Body: q.str("body"), HasBody: q.str("body") != "",
			ContentType: q.str("content_type"), Users: int(users), DurationSeconds: int(duration), CreatedAt: nowRFC3339()}
		lt.tests[p.data.ID] = append([]*runr.LoadTest{t}, lt.tests[p.data.ID]...)
		lt.used++
		return 202, map[string]any{"data": t}, nil
	})

	handle("GET /projects/{project}/load-tests/{loadTest}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, t, err := ofProject(s, q)
		if err != nil {
			return 0, nil, err
		}
		lt := s.loadTests()
		switch {
		case t.Status == runr.LoadTestPending:
			t.Status, t.StartedAt = runr.LoadTestRunning, nowRFC3339()
		case t.Status == runr.LoadTestRunning && lt.failWith != "":
			t.Status, t.Error, t.FinishedAt = runr.LoadTestFailed, lt.failWith, nowRFC3339()
		case t.Status == runr.LoadTestRunning:
			t.Status, t.Results, t.FinishedAt = runr.LoadTestCompleted, loadTestResults(t.Users), nowRFC3339()
		}
		return 200, map[string]any{"data": t}, nil
	})

	handle("POST /projects/{project}/load-tests/{loadTest}/cancel", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, t, err := ofProject(s, q)
		if err != nil {
			return 0, nil, err
		}
		if !t.Done() {
			t.Status, t.FinishedAt = runr.LoadTestCancelled, nowRFC3339()
		}
		return 200, map[string]any{"data": t}, nil
	})
}
