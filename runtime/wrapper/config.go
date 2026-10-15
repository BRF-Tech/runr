package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"strconv"
	"strings"
	"time"
)

const (
	modeExec       = "exec"
	modePersistent = "persistent"
	modeHTTP       = "http"

	triggerHTTP     = "http"
	triggerQueue    = "queue"
	triggerSchedule = "schedule"

	inputJSON = "json"
	inputRaw  = "raw"

	defaultTimeout = 30 * time.Second
	defaultMaxBody = 10 << 20
	defaultPort    = "8080"
	defaultCodeDir = "/app/code"
	maxWorkers     = 16
)

// config is the wrapper configuration, read from the environment (loadConfig).
type config struct {
	Mode    string        // RUNR_WRAPPER_MODE: exec | persistent | http
	Command []string      // RUNR_COMMAND: argv (a non-JSON-array string runs via /bin/sh -c)
	Trigger string        // RUNR_TRIGGER: http | queue | schedule
	Input   string        // RUNR_INPUT (exec mode): json | raw
	Workers int           // RUNR_WORKERS (persistent mode): 1..16
	Timeout time.Duration // RUNR_TIMEOUT: per invocation
	MaxBody int64         // RUNR_MAX_BODY: request body and handler output limit, bytes
	Port    string        // PORT: listen port (http / queue), the server's port (http mode)
	Dir     string        // working directory of the runtime command: RUNR_CODE_DIR when it exists

	Env    []string  // extra environment of the runtime processes (tests)
	Stdout io.Writer // schedule output (the response body)
	Stderr io.Writer // runtime stderr and the non-JSON stdout lines of persistent workers
}

// loadConfig reads the configuration through getenv (os.Getenv in production).
func loadConfig(getenv func(string) string) (config, error) {
	cfg := config{
		Mode:    strings.ToLower(strings.TrimSpace(getenv("RUNR_WRAPPER_MODE"))),
		Trigger: strings.ToLower(strings.TrimSpace(getenv("RUNR_TRIGGER"))),
		Input:   strings.ToLower(strings.TrimSpace(getenv("RUNR_INPUT"))),
		Port:    strings.TrimSpace(getenv("PORT")),
		Stdout:  os.Stdout,
		Stderr:  os.Stderr,
	}

	switch cfg.Mode {
	case modeExec, modePersistent, modeHTTP:
	case "":
		return cfg, errors.New("RUNR_WRAPPER_MODE is required (exec, persistent or http)")
	default:
		return cfg, fmt.Errorf("RUNR_WRAPPER_MODE=%q: expected exec, persistent or http", cfg.Mode)
	}

	switch cfg.Trigger {
	case "":
		cfg.Trigger = triggerHTTP
	case triggerHTTP, triggerQueue, triggerSchedule:
	default:
		return cfg, fmt.Errorf("RUNR_TRIGGER=%q: expected http, queue or schedule", cfg.Trigger)
	}

	switch cfg.Input {
	case "":
		cfg.Input = inputJSON
	case inputJSON, inputRaw:
	default:
		return cfg, fmt.Errorf("RUNR_INPUT=%q: expected json or raw", cfg.Input)
	}

	argv, err := parseCommand(getenv("RUNR_COMMAND"))
	if err != nil {
		return cfg, err
	}
	cfg.Command = argv

	cfg.Workers = 1
	if v := strings.TrimSpace(getenv("RUNR_WORKERS")); v != "" {
		n, err := strconv.Atoi(v)
		if err != nil {
			return cfg, fmt.Errorf("RUNR_WORKERS=%q is not an integer", v)
		}
		cfg.Workers = min(max(n, 1), maxWorkers)
	}

	cfg.Timeout = defaultTimeout
	if v := strings.TrimSpace(getenv("RUNR_TIMEOUT")); v != "" {
		d, err := parseSeconds(v)
		if err != nil {
			return cfg, fmt.Errorf("RUNR_TIMEOUT=%q: %v", v, err)
		}
		if d > 0 {
			cfg.Timeout = d
		}
	}

	cfg.MaxBody = defaultMaxBody
	if v := strings.TrimSpace(getenv("RUNR_MAX_BODY")); v != "" {
		n, err := strconv.ParseInt(v, 10, 64)
		if err != nil {
			return cfg, fmt.Errorf("RUNR_MAX_BODY=%q is not an integer", v)
		}
		if n > 0 {
			cfg.MaxBody = n
		}
	}

	if cfg.Port == "" {
		cfg.Port = defaultPort
	}

	codeDir := strings.TrimSpace(getenv("RUNR_CODE_DIR"))
	if codeDir == "" {
		codeDir = defaultCodeDir
	}
	if info, err := os.Stat(codeDir); err == nil && info.IsDir() {
		cfg.Dir = codeDir
	}

	return cfg, nil
}

// parseCommand turns RUNR_COMMAND into an argv: a JSON array of strings as it is, any other
// string through /bin/sh -c.
func parseCommand(raw string) ([]string, error) {
	s := strings.TrimSpace(raw)
	if s == "" {
		return nil, errors.New("RUNR_COMMAND is required")
	}

	if strings.HasPrefix(s, "[") {
		var argv []string
		if err := json.Unmarshal([]byte(s), &argv); err == nil {
			if len(argv) == 0 || argv[0] == "" {
				return nil, errors.New("RUNR_COMMAND is an empty array")
			}
			return argv, nil
		}
	}

	return []string{"/bin/sh", "-c", s}, nil
}

// parseSeconds accepts plain seconds ("30", "1.5") or Go durations ("500ms", "2m").
func parseSeconds(v string) (time.Duration, error) {
	if n, err := strconv.ParseFloat(v, 64); err == nil {
		if n < 0 {
			return 0, errors.New("negative duration")
		}
		return time.Duration(n * float64(time.Second)), nil
	}

	d, err := time.ParseDuration(v)
	if err != nil {
		return 0, err
	}
	if d < 0 {
		return 0, errors.New("negative duration")
	}
	return d, nil
}

// seconds formats a duration as plain seconds ("30s", "1.5s") for error messages.
func seconds(d time.Duration) string {
	return strconv.FormatFloat(d.Seconds(), 'f', -1, 64) + "s"
}
