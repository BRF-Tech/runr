package main

import (
	"net/http"
	"os"
	"strings"
	"sync"
	"testing"
	"time"
)

// workerPool starts a persistent pool whose workers are the helper worker (helperWorker).
func workerPool(t *testing.T, workers int, timeout time.Duration) (config, *pool, *syncBuffer) {
	t.Helper()
	cfg, _, stderr := testConfig(t, modePersistent, os.Args[0])
	cfg.Env = []string{helperEnv + "=worker"}
	cfg.Workers = workers
	cfg.Timeout = timeout

	p := newPool(cfg)
	t.Cleanup(p.Close)
	return cfg, p, stderr
}

func TestPersistentEnvelopeResponse(t *testing.T) {
	cfg, p, _ := workerPool(t, 1, 5*time.Second)

	status, body, h := call(t, cfg, p, "GET", "/a?x=1", "", nil)
	if status != 200 || body != "/a?x=1" || h.Get("X-Trigger") != "http" || h.Get("X-Worker") != "0" {
		t.Errorf("got %d %q %v", status, body, h)
	}

	// The same process answers the next request
	_, _, h2 := call(t, cfg, p, "GET", "/b", "", nil)
	if h2.Get("X-Pid") != h.Get("X-Pid") {
		t.Errorf("pid changed between requests: %s -> %s", h.Get("X-Pid"), h2.Get("X-Pid"))
	}
}

func TestPersistentNonJSONLinesAreLogged(t *testing.T) {
	cfg, p, stderr := workerPool(t, 1, 5*time.Second)

	status, body, _ := call(t, cfg, p, "GET", "/log", "", nil)
	if status != 200 || body != "/log?" {
		t.Errorf("got %d %q", status, body)
	}
	if !strings.Contains(stderr.String(), "this is a log line, not JSON\n") {
		t.Errorf("the non-JSON line was not logged: %q", stderr.String())
	}
}

func TestPersistentPlainJSONValues(t *testing.T) {
	cfg, p, _ := workerPool(t, 1, 5*time.Second)

	if status, body, h := call(t, cfg, p, "GET", "/string", "", nil); status != 200 || body != "plain string" || h.Get("Content-Type") != contentTypeText {
		t.Errorf("string: %d %q %v", status, body, h)
	}
	if status, body, h := call(t, cfg, p, "GET", "/object", "", nil); status != 200 || body != `{"hello":"world"}` || h.Get("Content-Type") != contentTypeJSON {
		t.Errorf("object: %d %q %v", status, body, h)
	}
}

func TestPersistentWorkerRestartsAfterCrash(t *testing.T) {
	cfg, p, _ := workerPool(t, 1, 5*time.Second)

	_, _, before := call(t, cfg, p, "GET", "/a", "", nil)

	if status, body, _ := call(t, cfg, p, "GET", "/crash", "", nil); status != http.StatusBadGateway || body != `{"error":"handler process exited"}` {
		t.Errorf("crash: %d %q", status, body)
	}

	status, body, after := call(t, cfg, p, "GET", "/again", "", nil)
	if status != 200 || body != "/again?" {
		t.Errorf("after restart: %d %q", status, body)
	}
	if after.Get("X-Pid") == before.Get("X-Pid") {
		t.Error("the worker was not restarted")
	}
}

func TestPersistentTimeoutRestartsWorker(t *testing.T) {
	cfg, p, _ := workerPool(t, 1, 1500*time.Millisecond)

	_, _, before := call(t, cfg, p, "GET", "/a", "", nil)

	start := time.Now()
	if status, body, _ := call(t, cfg, p, "GET", "/hang", "", nil); status != http.StatusGatewayTimeout || body != `{"error":"handler timed out after 1.5s"}` {
		t.Errorf("hang: %d %q", status, body)
	}
	if time.Since(start) > 3*time.Second {
		t.Errorf("timeout took %s", time.Since(start))
	}

	status, body, after := call(t, cfg, p, "GET", "/ok", "", nil)
	if status != 200 || body != "/ok?" || after.Get("X-Pid") == before.Get("X-Pid") {
		t.Errorf("after timeout: %d %q pid %s -> %s", status, body, before.Get("X-Pid"), after.Get("X-Pid"))
	}
}

func TestPersistentWorkersServeConcurrently(t *testing.T) {
	cfg, p, _ := workerPool(t, 2, 3*time.Second)

	// Wait until both workers are up
	call(t, cfg, p, "GET", "/warm", "", nil)
	time.Sleep(300 * time.Millisecond)

	// One worker hangs until its timeout; the other still answers meanwhile
	hung := make(chan int, 1)
	go func() {
		status, _, _ := call(t, cfg, p, "GET", "/hang", "", nil)
		hung <- status
	}()
	time.Sleep(200 * time.Millisecond)

	var wg sync.WaitGroup
	var mu sync.Mutex
	pids := map[string]bool{}
	start := time.Now()
	for i := 0; i < 3; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			status, _, h := call(t, cfg, p, "GET", "/x", "", nil)
			if status != 200 {
				t.Errorf("status %d", status)
			}
			mu.Lock()
			pids[h.Get("X-Pid")] = true
			mu.Unlock()
		}()
	}
	wg.Wait()

	if elapsed := time.Since(start); elapsed > 2*time.Second {
		t.Errorf("the free worker answered only after %s", elapsed)
	}
	if len(pids) != 1 {
		t.Errorf("the requests should all go to the free worker, got pids %v", pids)
	}
	if status := <-hung; status != http.StatusGatewayTimeout {
		t.Errorf("hang: status %d", status)
	}
}

func TestPersistentStartFailureAnswersTimeout(t *testing.T) {
	cfg, _, _ := testConfig(t, modePersistent, "/nonexistent/interpreter")
	cfg.Timeout = 400 * time.Millisecond
	p := newPool(cfg)
	defer p.Close()

	if status, _, _ := call(t, cfg, p, "GET", "/", "", nil); status != http.StatusGatewayTimeout {
		t.Errorf("no worker -> 504, got %d", status)
	}
}
