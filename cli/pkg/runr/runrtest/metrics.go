package runrtest

import (
	"fmt"
	"strconv"
	"strings"
	"time"
)

// Recorded metrics of a project (HistoricalMetricsController) of the fake: snapshots seeded with
// SeedMetricSnapshot (decimals sent as strings, like the decimal casts of MetricSnapshot) and the
// session statistics of SetSessionStats.

// MetricSnapshot is one seeded snapshot.
type MetricSnapshot struct {
	At          time.Time
	Granularity string
	CPUCores    float64
	MemoryMB    float64
	RPS         float64
	Concurrency float64
	Pods        int
	ColdStarts  int
}

// SessionStats are the deployment sessions of a project (SessionTrackingService::getStats).
type SessionStats struct {
	Total, ColdStarts                          int
	DurationMS, CPUMS, MemoryMBMS, AvgDuration int64
}

type metricsState struct {
	snapshots map[string][]MetricSnapshot // project id → snapshots
	sessions  map[string]SessionStats
	live      map[string]LiveMetrics // project id → live reading of its deployments
}

func (s *Server) metricsSt() *metricsState {
	return featureState(s, "metrics", func() *metricsState {
		return &metricsState{snapshots: map[string][]MetricSnapshot{}, sessions: map[string]SessionStats{}}
	})
}

// SeedMetricSnapshot records a snapshot of a project ("" granularity = 5m).
func (s *Server) SeedMetricSnapshot(projectName string, m MetricSnapshot) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	if m.Granularity == "" {
		m.Granularity = "5m"
	}
	st := s.metricsSt()
	st.snapshots[p.data.ID] = append(st.snapshots[p.data.ID], m)
}

// SetSessionStats sets the session statistics of a project.
func (s *Server) SetSessionStats(projectName string, st SessionStats) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if p := s.projectByName(projectName); p != nil {
		s.metricsSt().sessions[p.data.ID] = st
	}
}

func metricDecimal(v float64, places int) string { return strconv.FormatFloat(v, 'f', places, 64) }

// LiveMetrics is the live reading of a deployment (KnativeMetricsService::getDeploymentMetrics);
// a nil value is a reading the region does not have.
type LiveMetrics struct {
	CPU, MemoryMB, RPS, CPUAvg1h, MemoryAvg1hMB *float64
}

// SetLiveMetrics sets what GET …/deployments/{deployment}/metrics answers for every deployment
// of a project (default: no readings, as for a region without Prometheus).
func (s *Server) SetLiveMetrics(projectName string, m LiveMetrics) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	st := s.metricsSt()
	if st.live == nil {
		st.live = map[string]LiveMetrics{}
	}
	st.live[p.data.ID] = m
}

func init() {
	handle("GET /projects/{project}/deployments/{deployment}/metrics", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		if len(q.Segs) < 4 || s.deploymentByID(p, q.Segs[3]) == nil {
			return 0, nil, errNotFound()
		}
		m := s.metricsSt().live[p.data.ID]
		out := map[string]any{"cpu": m.CPU, "memory": m.MemoryMB, "rps": m.RPS}
		if q.query("quick") != "1" {
			out["cpuAvg1h"], out["memAvg1h"] = m.CPUAvg1h, m.MemoryAvg1hMB
		}
		return 200, out, nil
	})

	handle("GET /projects/{project}/metrics/summary", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		days, _ := strconv.Atoi(strings.TrimSuffix(q.query("period"), "d"))
		if days == 0 {
			days = 7
		}
		from := time.Now().AddDate(0, 0, -days)
		var n, cold int
		var sumCPU, maxCPU, sumMem, maxMem, sumRPS, maxRPS float64
		for _, m := range s.metricsSt().snapshots[p.data.ID] {
			if m.At.Before(from) {
				continue
			}
			n++
			cold += m.ColdStarts
			sumCPU, sumMem, sumRPS = sumCPU+m.CPUCores, sumMem+m.MemoryMB, sumRPS+m.RPS
			if m.CPUCores > maxCPU {
				maxCPU = m.CPUCores
			}
			if m.MemoryMB > maxMem {
				maxMem = m.MemoryMB
			}
			if m.RPS > maxRPS {
				maxRPS = m.RPS
			}
		}
		avg := func(sum float64) any {
			if n == 0 {
				return nil
			}
			return sum / float64(n)
		}
		maxOf := func(v float64) any {
			if n == 0 {
				return nil
			}
			return v
		}
		ss := s.metricsSt().sessions[p.data.ID]
		return 200, map[string]any{
			"metrics": map[string]any{"period_days": days, "avg_cpu": avg(sumCPU), "max_cpu": maxOf(maxCPU),
				"avg_memory": avg(sumMem), "max_memory": maxOf(maxMem), "avg_rps": avg(sumRPS), "max_rps": maxOf(maxRPS),
				"total_cold_starts": cold, "sample_count": n},
			"sessions": map[string]any{"total_sessions": ss.Total, "total_duration_ms": ss.DurationMS, "total_cpu_ms": ss.CPUMS,
				"total_memory_mb_ms": ss.MemoryMBMS, "cold_starts": ss.ColdStarts, "avg_duration_ms": ss.AvgDuration},
		}, nil
	})

	handle("GET /projects/{project}/metrics/history", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		from, ferr := time.Parse(time.RFC3339, q.query("from"))
		to, terr := time.Parse(time.RFC3339, q.query("to"))
		if ferr != nil || terr != nil {
			return 0, nil, errValidation("from", "The from and to fields are required dates.")
		}
		if !to.After(from) {
			return 0, nil, errValidation("to", "The to field must be a date after from.")
		}
		gran := q.query("granularity")
		if gran == "" {
			gran = "5m"
		}
		switch gran {
		case "1m", "5m", "1h", "1d":
		default:
			return 0, nil, errValidation("granularity", fmt.Sprintf("The selected granularity %s is invalid.", gran))
		}
		data := []map[string]any{}
		for _, m := range s.metricsSt().snapshots[p.data.ID] {
			if m.Granularity != gran || m.At.Before(from) || m.At.After(to) {
				continue
			}
			data = append(data, map[string]any{"recorded_at": m.At.UTC().Format(time.RFC3339), "cpu_cores": metricDecimal(m.CPUCores, 6),
				"memory_mb": metricDecimal(m.MemoryMB, 2), "rps": metricDecimal(m.RPS, 3), "concurrency": metricDecimal(m.Concurrency, 3),
				"pod_count": m.Pods, "cold_starts": m.ColdStarts})
		}
		return 200, map[string]any{"data": data, "granularity": gran, "from": from.UTC().Format(time.RFC3339),
			"to": to.UTC().Format(time.RFC3339)}, nil
	})
}
