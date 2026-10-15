package runr

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"strconv"
	"time"
)

// Recorded metrics of a project (HistoricalMetricsController): snapshots of CPU, memory,
// requests per second, concurrency, pods and cold starts at a granularity, and a summary of a
// period with the deployment sessions.

// FlexFloat is a number the API sends as a JSON number or as a decimal string ("0.250000").
type FlexFloat float64

// UnmarshalJSON accepts a number or a numeric string.
func (f *FlexFloat) UnmarshalJSON(b []byte) error {
	t := bytes.TrimSpace(b)
	if len(t) > 0 && t[0] == '"' {
		var s string
		if err := json.Unmarshal(t, &s); err != nil {
			return err
		}
		if s == "" {
			*f = 0
			return nil
		}
		v, err := strconv.ParseFloat(s, 64)
		if err != nil {
			return fmt.Errorf("not a number: %q", s)
		}
		*f = FlexFloat(v)
		return nil
	}
	var v float64
	if err := json.Unmarshal(t, &v); err != nil {
		return err
	}
	*f = FlexFloat(v)
	return nil
}

// MetricsSummary is GET /projects/{project}/metrics/summary.
type MetricsSummary struct {
	Metrics struct {
		PeriodDays      int        `json:"period_days"`
		AvgCPU          *FlexFloat `json:"avg_cpu"`
		MaxCPU          *FlexFloat `json:"max_cpu"`
		AvgMemoryMB     *FlexFloat `json:"avg_memory"`
		MaxMemoryMB     *FlexFloat `json:"max_memory"`
		AvgRPS          *FlexFloat `json:"avg_rps"`
		MaxRPS          *FlexFloat `json:"max_rps"`
		TotalColdStarts int        `json:"total_cold_starts"`
		SampleCount     int        `json:"sample_count"`
	} `json:"metrics"`
	Sessions struct {
		TotalSessions   int   `json:"total_sessions"`
		TotalDurationMS int64 `json:"total_duration_ms"`
		TotalCPUMS      int64 `json:"total_cpu_ms"`
		TotalMemoryMBMS int64 `json:"total_memory_mb_ms"`
		ColdStarts      int   `json:"cold_starts"`
		AvgDurationMS   int64 `json:"avg_duration_ms"`
	} `json:"sessions"`
}

// ProjectMetricsSummary summarizes a project's recorded metrics over the last days (7, 30 or
// 90) and its deployment sessions.
func (c *Client) ProjectMetricsSummary(ctx context.Context, projectID string, days int) (*MetricsSummary, error) {
	q := url.Values{}
	if days > 0 {
		q.Set("period", strconv.Itoa(days)+"d")
	}
	var out MetricsSummary
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + seg(projectID) + "/metrics/summary", query: q}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// MetricPoint is one recorded snapshot.
type MetricPoint struct {
	RecordedAt  string     `json:"recorded_at"`
	CPUCores    *FlexFloat `json:"cpu_cores"`
	MemoryMB    *FlexFloat `json:"memory_mb"`
	RPS         *FlexFloat `json:"rps"`
	Concurrency *FlexFloat `json:"concurrency"`
	PodCount    *int       `json:"pod_count"`
	ColdStarts  *int       `json:"cold_starts"`
}

// MetricsHistory is GET /projects/{project}/metrics/history.
type MetricsHistory struct {
	Data        []MetricPoint `json:"data"`
	Granularity string        `json:"granularity"`
	From        string        `json:"from"`
	To          string        `json:"to"`
}

// MetricGranularities are the granularities snapshots are recorded at.
var MetricGranularities = []string{"1m", "5m", "1h", "1d"}

// ProjectMetricsHistory returns a project's snapshots between from and to at a granularity
// (1m, 5m, 1h or 1d; "" = 5m).
func (c *Client) ProjectMetricsHistory(ctx context.Context, projectID string, from, to time.Time, granularity string) (*MetricsHistory, error) {
	q := url.Values{"from": {from.UTC().Format(time.RFC3339)}, "to": {to.UTC().Format(time.RFC3339)}}
	if granularity != "" {
		q.Set("granularity", granularity)
	}
	var out MetricsHistory
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + seg(projectID) + "/metrics/history", query: q}, &out); err != nil {
		return nil, err
	}
	if out.Data == nil {
		out.Data = []MetricPoint{}
	}
	return &out, nil
}
