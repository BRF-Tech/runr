package main

import (
	"reflect"
	"strings"
	"testing"
	"time"
)

func envOf(m map[string]string) func(string) string {
	return func(k string) string { return m[k] }
}

func TestLoadConfigDefaults(t *testing.T) {
	cfg, err := loadConfig(envOf(map[string]string{
		"RUNR_WRAPPER_MODE": "exec",
		"RUNR_COMMAND":      `["/app/handler", "--flag"]`,
		"RUNR_CODE_DIR":     "/does/not/exist",
	}))
	if err != nil {
		t.Fatal(err)
	}

	if !reflect.DeepEqual(cfg.Command, []string{"/app/handler", "--flag"}) {
		t.Errorf("command = %q", cfg.Command)
	}
	if cfg.Trigger != triggerHTTP || cfg.Input != inputJSON || cfg.Workers != 1 {
		t.Errorf("trigger/input/workers = %s/%s/%d", cfg.Trigger, cfg.Input, cfg.Workers)
	}
	if cfg.Timeout != defaultTimeout || cfg.MaxBody != defaultMaxBody || cfg.Port != defaultPort {
		t.Errorf("timeout/maxbody/port = %s/%d/%s", cfg.Timeout, cfg.MaxBody, cfg.Port)
	}
	if cfg.Dir != "" {
		t.Errorf("a missing code dir must leave the cwd alone, got %q", cfg.Dir)
	}
}

func TestLoadConfigValues(t *testing.T) {
	dir := t.TempDir()
	cfg, err := loadConfig(envOf(map[string]string{
		"RUNR_WRAPPER_MODE": "Persistent",
		"RUNR_COMMAND":      `q /app/code/main.q`,
		"RUNR_TRIGGER":      "queue",
		"RUNR_INPUT":        "raw",
		"RUNR_WORKERS":      "40",
		"RUNR_TIMEOUT":      "1.5",
		"RUNR_MAX_BODY":     "1024",
		"PORT":              "9090",
		"RUNR_CODE_DIR":     dir,
	}))
	if err != nil {
		t.Fatal(err)
	}

	if cfg.Mode != modePersistent || cfg.Trigger != triggerQueue || cfg.Input != inputRaw {
		t.Errorf("mode/trigger/input = %s/%s/%s", cfg.Mode, cfg.Trigger, cfg.Input)
	}
	if !reflect.DeepEqual(cfg.Command, []string{"/bin/sh", "-c", "q /app/code/main.q"}) {
		t.Errorf("a non-array command runs via sh -c, got %q", cfg.Command)
	}
	if cfg.Workers != maxWorkers {
		t.Errorf("workers clamp to %d, got %d", maxWorkers, cfg.Workers)
	}
	if cfg.Timeout != 1500*time.Millisecond || cfg.MaxBody != 1024 || cfg.Port != "9090" || cfg.Dir != dir {
		t.Errorf("timeout/maxbody/port/dir = %s/%d/%s/%s", cfg.Timeout, cfg.MaxBody, cfg.Port, cfg.Dir)
	}
}

func TestLoadConfigErrors(t *testing.T) {
	cases := map[string]map[string]string{
		"RUNR_WRAPPER_MODE is required": {"RUNR_COMMAND": "x"},
		"expected exec, persistent":     {"RUNR_WRAPPER_MODE": "cgi", "RUNR_COMMAND": "x"},
		"RUNR_COMMAND is required":      {"RUNR_WRAPPER_MODE": "exec"},
		"empty array":                   {"RUNR_WRAPPER_MODE": "exec", "RUNR_COMMAND": "[]"},
		"RUNR_TRIGGER":                  {"RUNR_WRAPPER_MODE": "exec", "RUNR_COMMAND": "x", "RUNR_TRIGGER": "cron"},
		"RUNR_INPUT":                    {"RUNR_WRAPPER_MODE": "exec", "RUNR_COMMAND": "x", "RUNR_INPUT": "xml"},
		"RUNR_WORKERS":                  {"RUNR_WRAPPER_MODE": "persistent", "RUNR_COMMAND": "x", "RUNR_WORKERS": "two"},
		"RUNR_TIMEOUT":                  {"RUNR_WRAPPER_MODE": "exec", "RUNR_COMMAND": "x", "RUNR_TIMEOUT": "soon"},
	}
	for want, env := range cases {
		if _, err := loadConfig(envOf(env)); err == nil || !strings.Contains(err.Error(), want) {
			t.Errorf("%v: error %v, want it to mention %q", env, err, want)
		}
	}
}

func TestParseCommandShellFallback(t *testing.T) {
	// Looks like an array but is a shell test: runs through sh
	argv, err := parseCommand(`[ -f main.sh ] && sh main.sh`)
	if err != nil {
		t.Fatal(err)
	}
	if argv[0] != "/bin/sh" || argv[2] != `[ -f main.sh ] && sh main.sh` {
		t.Errorf("argv = %q", argv)
	}
}

func TestParseSeconds(t *testing.T) {
	for in, want := range map[string]time.Duration{"30": 30 * time.Second, "0.25": 250 * time.Millisecond, "2m": 2 * time.Minute} {
		got, err := parseSeconds(in)
		if err != nil || got != want {
			t.Errorf("parseSeconds(%q) = %s, %v", in, got, err)
		}
	}
	if _, err := parseSeconds("-1"); err == nil {
		t.Error("negative durations are rejected")
	}
	if seconds(1500*time.Millisecond) != "1.5s" || seconds(30*time.Second) != "30s" {
		t.Error("seconds() formats plain seconds")
	}
}
