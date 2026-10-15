package dev

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// InvokeOptions describe one simulated trigger.
type InvokeOptions struct {
	// URL of the dev server (from the session state or --url).
	URL string
	// Trigger is http, schedule or queue; it decides the request shape.
	Trigger string
	// Event is the JSON event ("" = the trigger's default event).
	Event string
	// Method and Path of an http invocation.
	Method string
	Path   string
	// Queue is the stream name used in the CloudEvent source of a queue delivery.
	Queue  string
	Header map[string]string
	Client *http.Client
	// ScheduleCloudEvent sends a schedule invocation as the universal wrapper's schedule
	// CloudEvent (runtime plugins in http mode) instead of the built-in runtimes' request.
	ScheduleCloudEvent bool
}

// InvokeResult is the function's answer.
type InvokeResult struct {
	Status  int               `json:"status"`
	Headers map[string]string `json:"headers"`
	Body    string            `json:"body"`
	// Logs are what the wrapper and the handler wrote to stderr (schedule ticks run in the
	// container).
	Logs     string        `json:"logs,omitempty"`
	Duration time.Duration `json:"duration_ns"`
}

// BuildRequest returns the request a trigger sends to the function:
//
//   - http: the event as the request body (method and path as given);
//   - queue: what the queue consumer (runtime/queue-consumer) delivers: POST with the message
//     as JSON body and CloudEvent headers (ce-type dev.runr.queue.message);
//   - schedule: POST with the scheduler's event {"trigger":"schedule"} unless one is given.
func BuildRequest(ctx context.Context, o InvokeOptions) (*http.Request, error) {
	body, err := compactJSON(o.Event)
	if err != nil {
		return nil, err
	}
	method := strings.ToUpper(o.Method)
	path := o.Path
	if path == "" {
		path = "/"
	}
	if !strings.HasPrefix(path, "/") {
		path = "/" + path
	}
	headers := map[string]string{}
	switch o.Trigger {
	case runr.TriggerQueue:
		method = http.MethodPost
		if body == nil {
			body = []byte(`{}`)
		}
		stream := o.Queue
		if stream == "" {
			stream = "dev"
		}
		headers["ce-specversion"] = "1.0"
		headers["ce-type"] = "dev.runr.queue.message"
		headers["ce-source"] = "/redisstream/" + stream + "/runr-dev"
		headers["ce-id"] = fmt.Sprintf("%d-0", time.Now().UnixMilli())
		headers["ce-time"] = time.Now().UTC().Format(time.RFC3339)
		headers["X-RunR-Delivery-Attempt"] = "1"
	case runr.TriggerSchedule:
		method = http.MethodPost
		if body == nil {
			body = []byte(`{"trigger":"schedule"}`)
		}
		if o.ScheduleCloudEvent {
			// What the wrapper POSTs to a runtime's server for a tick (binary CloudEvent).
			headers["ce-specversion"] = "1.0"
			headers["ce-type"] = "dev.runr.schedule.tick"
			headers["ce-source"] = "/runr/schedule"
			headers["ce-id"] = fmt.Sprintf("runr-dev-%d", time.Now().UnixNano())
			headers["ce-time"] = time.Now().UTC().Format(time.RFC3339)
		} else {
			headers["X-RunR-Trigger"] = "schedule"
		}
	default:
		if method == "" {
			if body != nil {
				method = http.MethodPost
			} else {
				method = http.MethodGet
			}
		}
	}
	var rd io.Reader
	if body != nil {
		rd = bytes.NewReader(body)
	}
	req, err := http.NewRequestWithContext(ctx, method, strings.TrimRight(o.URL, "/")+path, rd)
	if err != nil {
		return nil, err
	}
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	for k, v := range o.Header {
		req.Header.Set(k, v)
	}
	return req, nil
}

// ScheduleExecArgs are the `docker exec` arguments of a schedule tick in a running plugin
// container: the wrapper runs once with RUNR_TRIGGER=schedule, like the CronJob runs it.
func ScheduleExecArgs(container string) []string {
	return []string{"exec", "-e", "RUNR_TRIGGER=schedule", container, WrapperPath}
}

// InvokeSchedule runs one schedule tick through the wrapper inside the session container. The
// wrapper prints the response body and exits 0 when the status is below 400; Status is then
// 200, else 500.
func InvokeSchedule(ctx context.Context, d Docker, container string) (*InvokeResult, error) {
	var out, errOut bytes.Buffer
	start := time.Now()
	err := d.Run(ctx, ScheduleExecArgs(container), &out, &errOut)
	res := &InvokeResult{Status: http.StatusOK, Headers: map[string]string{}, Body: strings.TrimRight(out.String(), "\n"),
		Logs: errOut.String(), Duration: time.Since(start)}
	if err != nil {
		if ctx.Err() != nil {
			return nil, ctx.Err()
		}
		res.Status = http.StatusInternalServerError
		if res.Body == "" && res.Logs == "" {
			res.Body = err.Error()
		}
	}
	return res, nil
}

// Invoke sends the trigger's request to the dev server.
func Invoke(ctx context.Context, o InvokeOptions) (*InvokeResult, error) {
	req, err := BuildRequest(ctx, o)
	if err != nil {
		return nil, err
	}
	c := o.Client
	if c == nil {
		c = &http.Client{Timeout: 5 * time.Minute}
	}
	start := time.Now()
	resp, err := c.Do(req)
	if err != nil {
		return nil, fmt.Errorf("invoke %s: %w (is `runr dev` running?)", req.URL, err)
	}
	defer resp.Body.Close()
	data, err := io.ReadAll(io.LimitReader(resp.Body, 16<<20))
	if err != nil {
		return nil, err
	}
	res := &InvokeResult{Status: resp.StatusCode, Headers: map[string]string{}, Body: string(data), Duration: time.Since(start)}
	for k := range resp.Header {
		res.Headers[k] = resp.Header.Get(k)
	}
	return res, nil
}
