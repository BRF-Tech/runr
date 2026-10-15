package collector

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"math"
	"net/http"
	"net/url"
	"strconv"
	"time"

	"github.com/brf-tech/runr/runtime/agent/internal/podname"
)

// Collector queries Prometheus for per-project metrics.
type Collector struct {
	promURL   string
	namespace string
	client    *http.Client
}

func New(promURL, namespace string) *Collector {
	return &Collector{
		promURL:   promURL,
		namespace: namespace,
		client:    &http.Client{Timeout: 8 * time.Second},
	}
}

// Collect fetches metrics for all runr projects in the namespace.
func (c *Collector) Collect(ctx context.Context) []map[string]interface{} {
	cpuData := c.query(ctx,
		fmt.Sprintf(`sum by (pod) (rate(container_cpu_usage_seconds_total{namespace="%s",container!=""}[30s]))`, c.namespace),
	)
	memData := c.query(ctx,
		fmt.Sprintf(`sum by (pod) (container_memory_working_set_bytes{namespace="%s",container!=""}) / 1048576`, c.namespace),
	)

	// Group by project slug (pod name prefix before the last dash-suffix)
	projects := make(map[string]*metricsAgg)

	for pod, cpu := range cpuData {
		slug := extractSlug(pod)
		if slug == "" {
			continue
		}
		agg := projects[slug]
		if agg == nil {
			agg = &metricsAgg{}
			projects[slug] = agg
		}
		agg.cpu += cpu
		agg.podCount++
	}

	for pod, mem := range memData {
		slug := extractSlug(pod)
		if slug == "" {
			continue
		}
		agg := projects[slug]
		if agg == nil {
			agg = &metricsAgg{}
			projects[slug] = agg
		}
		agg.memory += mem
	}

	var results []map[string]interface{}
	for slug, agg := range projects {
		results = append(results, map[string]interface{}{
			"type":         "metrics",
			"project_slug": slug,
			"data": map[string]interface{}{
				"cpu":       agg.cpu,
				"memory":    agg.memory,
				"pod_count": agg.podCount,
			},
		})
	}

	return results
}

type metricsAgg struct {
	cpu      float64
	memory   float64
	podCount int
}

func (c *Collector) query(ctx context.Context, promQL string) map[string]float64 {
	u, err := url.Parse(c.promURL + "/api/v1/query")
	if err != nil {
		log.Printf("Prometheus URL %q invalid: %v", c.promURL, err)
		return nil
	}
	q := u.Query()
	q.Set("query", promQL)
	u.RawQuery = q.Encode()

	req, err := http.NewRequestWithContext(ctx, "GET", u.String(), nil)
	if err != nil {
		log.Printf("Prometheus request error: %v", err)
		return nil
	}
	resp, err := c.client.Do(req)
	if err != nil {
		log.Printf("Prometheus query error: %v", err)
		return nil
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != http.StatusOK {
		log.Printf("Prometheus query status %d", resp.StatusCode)
		return nil
	}

	var result promResponse
	if err := json.Unmarshal(body, &result); err != nil {
		return nil
	}

	data := make(map[string]float64)
	for _, r := range result.Data.Result {
		pod := r.Metric["pod"]
		if len(r.Value) < 2 {
			continue
		}
		val, ok := r.Value[1].(string)
		if !ok {
			continue
		}
		f, err := strconv.ParseFloat(val, 64)
		// NaN/±Inf (e.g. a rate over a reset counter) cannot be JSON-encoded and would drop
		// the whole message, so such samples are skipped
		if err != nil || math.IsNaN(f) || math.IsInf(f, 0) {
			continue
		}
		data[pod] = f
	}
	return data
}

type promResponse struct {
	Data struct {
		Result []struct {
			Metric map[string]string `json:"metric"`
			Value  []interface{}     `json:"value"`
		} `json:"result"`
	} `json:"data"`
}

// extractSlug extracts the project slug from a Knative pod name
// ({slug}-{revision}-deployment-{replicaset hash}-{pod hash}).
func extractSlug(pod string) string {
	return podname.Slug(pod)
}
