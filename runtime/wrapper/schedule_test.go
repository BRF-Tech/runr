package main

import (
	"encoding/json"
	"os"
	"strings"
	"testing"
	"time"
)

func TestScheduleExecSuccess(t *testing.T) {
	cfg, stdout, _ := testConfig(t, modeExec, "cat")
	cfg.Trigger = triggerSchedule

	if code := runSchedule(cfg, &execInvoker{cfg: cfg}); code != 0 {
		t.Fatalf("exit code %d", code)
	}

	var env Envelope
	if err := json.Unmarshal([]byte(stdout.String()), &env); err != nil {
		t.Fatalf("stdout is the response body (the envelope cat echoed): %v\n%s", err, stdout.String())
	}
	if env.Trigger != "schedule" || env.Event == nil || env.Event.Type != scheduleEventType {
		t.Errorf("envelope = %+v", env)
	}
}

func TestScheduleExecFailureStatus(t *testing.T) {
	cfg, stdout, _ := testConfig(t, modeExec, sh(`echo '{"statusCode":500,"body":"boom"}'`)...)
	cfg.Trigger = triggerSchedule

	if code := runSchedule(cfg, &execInvoker{cfg: cfg}); code != 1 {
		t.Errorf("status 500 -> exit 1, got %d", code)
	}
	if stdout.String() != "boom\n" {
		t.Errorf("stdout = %q", stdout.String())
	}
}

func TestScheduleExecNonZeroExit(t *testing.T) {
	cfg, _, _ := testConfig(t, modeExec, sh(`exit 3`)...)
	cfg.Trigger = triggerSchedule

	if code := runSchedule(cfg, &execInvoker{cfg: cfg}); code != 1 {
		t.Errorf("exit 3 -> exit 1, got %d", code)
	}
}

func TestScheduleExecRawInput(t *testing.T) {
	cfg, stdout, _ := testConfig(t, modeExec, sh(`printf '%s ' "$RUNR_TRIGGER"; cat`)...)
	cfg.Trigger = triggerSchedule
	cfg.Input = inputRaw

	if code := runSchedule(cfg, &execInvoker{cfg: cfg}); code != 0 || stdout.String() != "schedule {\"trigger\":\"schedule\"}\n" {
		t.Errorf("code %d stdout %q", code, stdout.String())
	}
}

func TestSchedulePersistent(t *testing.T) {
	cfg, stdout, _ := testConfig(t, modePersistent, os.Args[0])
	cfg.Env = []string{helperEnv + "=worker"}
	cfg.Trigger = triggerSchedule
	p := newPool(cfg)
	defer p.Close()

	if code := runSchedule(cfg, p); code != 0 || stdout.String() != "/?\n" {
		t.Errorf("code %d stdout %q", code, stdout.String())
	}
}

func TestScheduleHTTPModeInvokesTheServer(t *testing.T) {
	cfg, stdout, _ := testConfig(t, modeHTTP, os.Args[0])
	cfg.Env = []string{helperEnv + "=http-server", "HELPER_DELAY=300ms"}
	cfg.Trigger = triggerSchedule
	cfg.Port = freePort(t)

	start := time.Now()
	if code := runProxySchedule(cfg); code != 0 {
		t.Fatalf("exit code %d", code)
	}
	if got := stdout.String(); got != "type=dev.runr.schedule.tick source=/runr/schedule data={\"trigger\":\"schedule\"}\n" {
		t.Errorf("stdout = %q", got)
	}
	if time.Since(start) > 10*time.Second {
		t.Errorf("took %s", time.Since(start))
	}
}

func TestScheduleHTTPModeFailureStatus(t *testing.T) {
	cfg, _, _ := testConfig(t, modeHTTP, os.Args[0])
	cfg.Env = []string{helperEnv + "=http-server", "HELPER_STATUS=503"}
	cfg.Trigger = triggerSchedule
	cfg.Port = freePort(t)

	if code := runProxySchedule(cfg); code != 1 {
		t.Errorf("status 503 -> exit 1, got %d", code)
	}
}

func TestScheduleHTTPModeServerNeverListens(t *testing.T) {
	old := startupTimeout
	startupTimeout = 500 * time.Millisecond
	defer func() { startupTimeout = old }()

	cfg, _, _ := testConfig(t, modeHTTP, sh(`sleep 30`)...)
	cfg.Trigger = triggerSchedule
	cfg.Port = freePort(t)

	start := time.Now()
	if code := runProxySchedule(cfg); code != 1 {
		t.Errorf("exit code %d", code)
	}
	// startup timeout + SIGTERM of the sleeping server
	if time.Since(start) > 4*time.Second {
		t.Errorf("took %s", time.Since(start))
	}
}

func TestScheduleHTTPModeServerExitsEarly(t *testing.T) {
	cfg, _, stderr := testConfig(t, modeHTTP, sh(`echo "bad config" >&2; exit 2`)...)
	cfg.Trigger = triggerSchedule
	cfg.Port = freePort(t)

	start := time.Now()
	if code := runProxySchedule(cfg); code != 1 {
		t.Errorf("exit code %d", code)
	}
	if time.Since(start) > 3*time.Second {
		t.Errorf("an exited server must not wait for the startup timeout (%s)", time.Since(start))
	}
	if !strings.Contains(stderr.String(), "bad config") {
		t.Errorf("server output goes to the logs: %q", stderr.String())
	}
}
