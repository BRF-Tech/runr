package main

import (
	"bytes"
	"io"
	"net"
	"net/http"
	"os"
	"strings"
	"time"
)

// tickConfig configures `runr-wrapper tick`: one schedule CloudEvent to a server that already
// runs next to the wrapper — the schedule trigger of WebAssembly functions, whose Spin app runs
// as a sidecar of the CronJob pod (it serves HTTP and never exits).
type tickConfig struct {
	URL     string        // RUNR_TICK_URL, default http://127.0.0.1:$PORT/
	Addr    string        // host:port waited for (from the URL)
	Timeout time.Duration // RUNR_TIMEOUT: the tick request
	MaxBody int64
	Stdout  io.Writer
}

func loadTickConfig(getenv func(string) string) (tickConfig, error) {
	port := strings.TrimSpace(getenv("PORT"))
	if port == "" {
		port = defaultPort
	}

	cfg := tickConfig{
		URL:     strings.TrimSpace(getenv("RUNR_TICK_URL")),
		Timeout: defaultTimeout,
		MaxBody: defaultMaxBody,
		Stdout:  os.Stdout,
	}
	if cfg.URL == "" {
		cfg.URL = "http://" + net.JoinHostPort("127.0.0.1", port) + "/"
	}

	req, err := http.NewRequest(http.MethodPost, cfg.URL, nil)
	if err != nil {
		return cfg, err
	}
	cfg.Addr = req.URL.Host
	if req.URL.Port() == "" {
		cfg.Addr = net.JoinHostPort(req.URL.Hostname(), "80")
	}

	if v := strings.TrimSpace(getenv("RUNR_TIMEOUT")); v != "" {
		d, err := parseSeconds(v)
		if err != nil {
			return cfg, err
		}
		if d > 0 {
			cfg.Timeout = d
		}
	}

	return cfg, nil
}

// runTick waits until the server accepts connections (up to startupTimeout), POSTs the schedule
// CloudEvent (binary mode, the same request runProxySchedule sends), prints the response body and
// returns the exit code: 0 when the status is < 400, else 1.
func runTick(cfg tickConfig) int {
	if err := waitForPort(cfg.Addr, startupTimeout, nil); err != nil {
		logger.Printf("tick: %v", err)
		return 1
	}

	id, headers, data := scheduleEvent(time.Now())
	req, err := http.NewRequest(http.MethodPost, cfg.URL, bytes.NewReader(data))
	if err != nil {
		logger.Printf("tick: %v", err)
		return 1
	}
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	req.Header.Set("X-Request-Id", id)

	resp, err := (&http.Client{Timeout: cfg.Timeout}).Do(req)
	if err != nil {
		logger.Printf("schedule tick %s: %v", id, err)
		return 1
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(io.LimitReader(resp.Body, cfg.MaxBody))
	writeScheduleOutput(cfg.Stdout, body)

	logger.Printf("schedule tick %s: status %d", id, resp.StatusCode)
	return statusExitCode(resp.StatusCode)
}
