package runrtest

import (
	"encoding/json"
	"fmt"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/runrsh/core/cli/pkg/runr"
)

// Background processes, pause / resume, the terminal session, warm pools, quotas, usage and
// metrics of the fake's Sandbox API (SandboxProcessesController, SandboxesController,
// SandboxExecController::terminal, SandboxAdminController).

type sbxProcess struct {
	data   runr.SandboxProcess
	output []SandboxChunk
	// exit is the exit code the process ends with when its output is followed.
	exit int
	// signals are the signals the process received.
	signals []string
}

type sbxTerminalSession struct {
	sandboxID  string
	cols, rows int
}

// SandboxTerminalLog is what a terminal session of the fake received.
type SandboxTerminalLog struct {
	SandboxID string
	// Cols and Rows are the size POST …/terminal asked for.
	Cols, Rows int
	// Input is every byte the client sent.
	Input []byte
	// Resizes are the {"resize"} messages.
	Resizes [][2]int
	// Pongs counts the client's answers to the server's ping.
	Pongs int
}

type sbxExtraState struct {
	processes map[string][]*sbxProcess
	sessions  map[string]*sbxTerminalSession
	terminals []*SandboxTerminalLog
	pools     []*runr.SandboxPool
	defaults  runr.SandboxQuotaLimits
	custom    *runr.SandboxQuotaLimits
	usage     []runr.SandboxUsageRecord
	seq       int
}

func (s *Server) sbxExtra() *sbxExtraState {
	return featureState(s, "sandbox-extra", func() *sbxExtraState {
		return &sbxExtraState{
			processes: map[string][]*sbxProcess{},
			sessions:  map[string]*sbxTerminalSession{},
			defaults:  runr.SandboxQuotaLimits{MaxConcurrent: 10, MaxCPUm: 8000, MaxMemoryMB: 16384, MaxPoolSize: 10},
		}
	})
}

// SandboxProcesses returns the background processes of a sandbox, oldest first, with the
// signals each received.
func (s *Server) SandboxProcesses(sandboxID string) ([]runr.SandboxProcess, map[string][]string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	var out []runr.SandboxProcess
	sig := map[string][]string{}
	for _, p := range s.sbxExtra().processes[sandboxID] {
		out = append(out, p.data)
		sig[p.data.ID] = append([]string(nil), p.signals...)
	}
	return out, sig
}

// SandboxTerminals returns the terminal sessions the fake served.
func (s *Server) SandboxTerminals() []SandboxTerminalLog {
	s.mu.Lock()
	defer s.mu.Unlock()
	var out []SandboxTerminalLog
	for _, t := range s.sbxExtra().terminals {
		cp := *t
		cp.Input = append([]byte(nil), t.Input...)
		cp.Resizes = append([][2]int(nil), t.Resizes...)
		out = append(out, cp)
	}
	return out
}

// SandboxPools returns the fake's warm pools.
func (s *Server) SandboxPools() []runr.SandboxPool {
	s.mu.Lock()
	defer s.mu.Unlock()
	var out []runr.SandboxPool
	for _, p := range s.sbxExtra().pools {
		out = append(out, *p)
	}
	return out
}

// SandboxQuotaLimits returns the limits in effect (the custom row, else the defaults).
func (s *Server) SandboxQuotaLimits() runr.SandboxQuotaLimits {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.sbxQuotaLimits()
}

// SeedSandboxUsage adds daily usage records.
func (s *Server) SeedSandboxUsage(records ...runr.SandboxUsageRecord) {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.sbxExtra()
	st.usage = append(st.usage, records...)
}

func (s *Server) sbxQuotaLimits() runr.SandboxQuotaLimits {
	st := s.sbxExtra()
	if st.custom != nil {
		return *st.custom
	}
	return st.defaults
}

func (s *Server) sbxProcessFind(q *featureReq) (*sbxSandbox, *sbxProcess, *apiErr) {
	sb, err := s.sbxFind(q)
	if err != nil {
		return nil, nil, err
	}
	if len(q.Segs) < 4 {
		return nil, nil, errNotFound()
	}
	for _, p := range s.sbxExtra().processes[sb.data.ID] {
		if p.data.ID == q.Segs[3] {
			return sb, p, nil
		}
	}
	return nil, nil, &apiErr{status: 404, message: "Process not found."}
}

func sbxCommandLine(cmd any) (string, []string, bool) {
	switch c := cmd.(type) {
	case string:
		if strings.TrimSpace(c) == "" {
			return "", nil, false
		}
		return c, []string{"/bin/sh", "-c", c}, true
	case []any:
		if len(c) == 0 {
			return "", nil, false
		}
		argv := make([]string, len(c))
		for i, a := range c {
			argv[i] = fmt.Sprint(a)
		}
		return strings.Join(argv, " "), argv, true
	}
	return "", nil, false
}

var sbxQuotaFields = []string{"max_concurrent", "max_cpu_m", "max_memory_mb", "max_sandbox_seconds_month", "max_pool_size"}

var sbxKnownClasses = map[string]bool{"kata": true, "gvisor": true, "runc": true, "kata-fc": true, "kata-clh": true,
	"kata-qemu": true, "kata-dragonball": true}

func (s *Server) sbxQuotaJSON() map[string]any {
	usage := runr.SandboxQuotaUsage{}
	for _, sb := range s.sbx().list {
		switch sb.data.Status {
		case runr.SandboxPending, runr.SandboxRunning, runr.SandboxPaused:
			usage.Concurrent++
			usage.CPUm += sb.data.Resources.CPUm
			usage.MemoryMB += sb.data.Resources.MemoryMB
		}
	}
	for _, p := range s.sbxExtra().pools {
		if p.IsEnabled {
			usage.PoolSize += p.Size
		}
	}
	return map[string]any{"tenant_id": nil, "limits": s.sbxQuotaLimits(), "usage": usage}
}

func init() {
	// ── Background processes ──
	handle("POST /sandboxes/{sandbox}/processes", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxRunning(q)
		if err != nil {
			return 0, nil, err
		}
		line, argv, ok := sbxCommandLine(q.Body["cmd"])
		if !ok {
			return 0, nil, errValidation("cmd", "The command is required.")
		}
		call := SandboxExecCall{SandboxID: sb.data.ID, Cmd: argv, Cwd: q.str("cwd"), Env: map[string]string{}}
		if env, ok := q.Body["env"].(map[string]any); ok {
			for k, v := range env {
				call.Env[k] = fmt.Sprint(v)
			}
		}
		st := s.sbx()
		st.calls = append(st.calls, call)
		reply := sbxDefaultReply(call)
		if st.exec != nil {
			reply = st.exec(call)
		}
		ex := s.sbxExtra()
		ex.seq++
		cwd := call.Cwd
		if cwd == "" {
			cwd = "/workspace"
		}
		pid := 100 + ex.seq
		p := &sbxProcess{output: reply.Output, exit: reply.ExitCode, data: runr.SandboxProcess{
			ID: s.newID(), SandboxID: sb.data.ID, PID: &pid, Command: line, Cwd: cwd,
			Status: runr.ProcessRunning, StartedAt: nowRFC3339()}}
		ex.processes[sb.data.ID] = append(ex.processes[sb.data.ID], p)
		sb.log("process.start", line)
		return 201, map[string]any{"data": p.data}, nil
	})
	handle("GET /sandboxes/{sandbox}/processes", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxFind(q)
		if err != nil {
			return 0, nil, err
		}
		list := []runr.SandboxProcess{}
		procs := s.sbxExtra().processes[sb.data.ID]
		for i := len(procs) - 1; i >= 0; i-- {
			list = append(list, procs[i].data)
		}
		return 200, map[string]any{"data": list}, nil
	})
	handle("GET /sandboxes/{sandbox}/processes/{process}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, p, err := s.sbxProcessFind(q)
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"data": p.data}, nil
	})
	handle("GET /sandboxes/{sandbox}/processes/{process}/logs", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, p, err := s.sbxProcessFind(q)
		if err != nil {
			return 0, nil, err
		}
		if sb.data.Status != runr.SandboxRunning {
			return 0, nil, &apiErr{status: 409, message: "The sandbox is not running (status: " + sb.data.Status + ")."}
		}
		if q.query("follow") == "1" || q.query("follow") == "true" {
			var b strings.Builder
			frame := func(event string, data any) {
				enc, _ := json.Marshal(data)
				fmt.Fprintf(&b, "event: %s\ndata: %s\n\n", event, enc)
			}
			frame("start", map[string]any{"process_id": p.data.ID, "sandbox_id": sb.data.ID})
			for _, ch := range p.output {
				frame(ch.Stream, sbxChunk(ch.Data))
			}
			if p.data.Status == runr.ProcessRunning {
				code := p.exit
				p.data.Status = runr.ProcessExited
				p.data.ExitCode = &code
				p.data.EndedAt = nowRFC3339()
			}
			frame("exit", map[string]any{"exit_code": p.data.ExitCode, "status": p.data.Status})
			return 200, rawAnswer{contentType: "text/event-stream; charset=utf-8", data: []byte(b.String())}, nil
		}
		tail := 65536
		if v := q.query("tail_bytes"); v != "" {
			n, cerr := strconv.Atoi(v)
			if cerr != nil || n < 1 {
				return 0, nil, errValidation("tail_bytes", "The tail bytes must be a positive integer.")
			}
			tail = n
		}
		var stdout, stderr []byte
		for _, ch := range p.output {
			if ch.Stream == "stderr" {
				stderr = append(stderr, ch.Data...)
			} else {
				stdout = append(stdout, ch.Data...)
			}
		}
		truncated := false
		cut := func(b []byte) []byte {
			if len(b) > tail {
				truncated = true
				return b[len(b)-tail:]
			}
			return b
		}
		stdout, stderr = cut(stdout), cut(stderr)
		data := map[string]any{"truncated": truncated}
		for k, v := range sbxChunk(stdout) {
			data[strings.Replace(k, "data", "stdout", 1)] = v
		}
		for k, v := range sbxChunk(stderr) {
			data[strings.Replace(k, "data", "stderr", 1)] = v
		}
		return 200, map[string]any{"data": data}, nil
	})
	handle("DELETE /sandboxes/{sandbox}/processes/{process}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, p, err := s.sbxProcessFind(q)
		if err != nil {
			return 0, nil, err
		}
		sig := strings.ToUpper(q.query("signal"))
		if sig == "" {
			sig = "TERM"
		}
		valid := false
		for _, x := range runr.SandboxSignals {
			valid = valid || x == sig
		}
		if !valid {
			return 0, nil, errValidation("signal", "The signal must be one of "+strings.Join(runr.SandboxSignals, ", ")+".")
		}
		p.signals = append(p.signals, sig)
		if p.data.Status == runr.ProcessRunning {
			switch sig {
			case "KILL", "TERM", "INT", "HUP", "QUIT":
				p.data.Status = runr.ProcessKilled
				p.data.EndedAt = nowRFC3339()
			}
		}
		return 200, map[string]any{"data": p.data}, nil
	})

	// ── Lifecycle ──
	handle("POST /sandboxes/{sandbox}/pause", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxRunning(q)
		if err != nil {
			return 0, nil, err
		}
		sb.data.Status = runr.SandboxPaused
		sb.data.PausedAt = nowRFC3339()
		sb.log("paused", "")
		return 200, map[string]any{"data": sb.data}, nil
	})
	handle("POST /sandboxes/{sandbox}/resume", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxFind(q)
		if err != nil {
			return 0, nil, err
		}
		if sb.data.Status != runr.SandboxPaused {
			return 0, nil, &apiErr{status: 409, message: "The sandbox is not paused (status: " + sb.data.Status + ")."}
		}
		sb.data.Status = runr.SandboxRunning
		sb.data.PausedAt = ""
		sb.data.LastActivityAt = nowRFC3339()
		sb.log("resumed", "")
		return 200, map[string]any{"data": sb.data}, nil
	})
	handle("POST /sandboxes/{sandbox}/timeout", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxFind(q)
		if err != nil {
			return 0, nil, err
		}
		if sb.data.Ended() {
			return 0, nil, &apiErr{status: 409, message: "The sandbox has ended."}
		}
		ttl, ok := q.Body["ttl_seconds"].(float64)
		if !ok {
			return 0, nil, errValidation("ttl_seconds", "The ttl seconds field is required.")
		}
		if ttl < 10 || ttl > 86400 {
			return 0, nil, errValidation("ttl_seconds", "The TTL must be between 10 and 86400 seconds.")
		}
		sb.data.TTLSeconds = int(ttl)
		sb.data.ExpiresAt = time.Now().UTC().Add(time.Duration(ttl) * time.Second).Format(time.RFC3339)
		sb.log("timeout", strconv.Itoa(int(ttl)))
		return 200, map[string]any{"data": sb.data}, nil
	})
	handle("POST /sandboxes/{sandbox}/keepalive", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxRunning(q)
		if err != nil {
			return 0, nil, err
		}
		sb.data.LastActivityAt = nowRFC3339()
		return 200, map[string]any{"data": sb.data}, nil
	})

	// ── Terminal ──
	handle("POST /sandboxes/{sandbox}/terminal", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxRunning(q)
		if err != nil {
			return 0, nil, err
		}
		ex := s.sbxExtra()
		ex.seq++
		id := fmt.Sprintf("8f7c2a10-0000-4000-8000-%012d", ex.seq)
		cols, rows := 80, 24
		if v, ok := q.Body["cols"].(float64); ok && v >= 1 {
			cols = int(v)
		}
		if v, ok := q.Body["rows"].(float64); ok && v >= 1 {
			rows = int(v)
		}
		ex.sessions[id] = &sbxTerminalSession{sandboxID: sb.data.ID, cols: cols, rows: rows}
		sb.log("terminal", id[:8])
		return 200, map[string]any{"data": map[string]any{"session_id": id, "url": "/ws/shell?token=" + id}, "session_id": id}, nil
	})

	// ── Warm pools ──
	handle("GET /sandbox-pools", func(s *Server, q *featureReq) (int, any, *apiErr) {
		list := []runr.SandboxPool{}
		for _, p := range s.sbxExtra().pools {
			list = append(list, *p)
		}
		return 200, map[string]any{"data": list}, nil
	})
	handle("POST /sandbox-pools", func(s *Server, q *featureReq) (int, any, *apiErr) {
		regionRef := q.str("region")
		if regionRef == "" {
			return 0, nil, errValidation("region", "The region field is required.")
		}
		reg := s.regionByID(regionRef)
		if reg == nil {
			reg = s.regionByName(regionRef)
		}
		if reg == nil {
			return 0, nil, errValidation("region", "The region "+regionRef+" offers no sandboxes.")
		}
		rt := q.str("runtime")
		if rt == "" {
			return 0, nil, errValidation("runtime", "The runtime field is required.")
		}
		version := q.str("version")
		found := false
		for _, img := range s.images {
			if img.Runtime == rt && (version == "" && img.IsDefault || img.Version == version) {
				found = true
				version = img.Version
			}
		}
		if !found {
			return 0, nil, errValidation("version", "The runtime "+rt+" has no version "+version+".")
		}
		size, ok := q.Body["size"].(float64)
		if !ok || size < 0 || size > 50 {
			return 0, nil, errValidation("size", "The size must be between 0 and 50.")
		}
		ex := s.sbxExtra()
		total := int(size)
		for _, p := range ex.pools {
			if p.IsEnabled {
				total += p.Size
			}
		}
		if limit := s.sbxQuotaLimits().MaxPoolSize; limit > 0 && total > limit {
			return 0, nil, &apiErr{status: 422, message: fmt.Sprintf("The warm pools would hold %d sandboxes; the quota allows %d.", total, limit),
				errors: map[string][]string{"size": {"quota_pool_size"}}}
		}
		class := q.str("sandbox_class")
		if class != "" && class != "auto" && !sbxKnownClasses[class] {
			return 0, nil, errValidation("sandbox_class", "The sandbox class "+class+" is not available in this region.")
		}
		if class == "auto" {
			class = ""
		}
		cpu, mem, disk := 500, 512, 1024
		if v, ok := q.Body["cpu"]; ok {
			cpu = sbxMillicores(v)
		}
		if v, ok := q.Body["memory"]; ok {
			mem = sbxMiB(v)
		}
		if v, ok := q.Body["disk"]; ok {
			disk = sbxMiB(v)
		}
		p := &runr.SandboxPool{ID: s.newID(), Region: &runr.Ref{ID: reg.ID, Name: reg.Name}, Runtime: rt, RuntimeVersion: version,
			SandboxClass: class, CPUm: cpu, MemoryMB: mem, DiskMB: disk, Size: int(size), IsEnabled: true,
			Starting: int(size), CreatedAt: nowRFC3339()}
		ex.pools = append(ex.pools, p)
		return 201, map[string]any{"data": p}, nil
	})
	handle("PUT /sandbox-pools/{pool}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		ex := s.sbxExtra()
		for _, p := range ex.pools {
			if p.ID != q.Segs[1] {
				continue
			}
			if v, ok := q.Body["size"].(float64); ok {
				if v < 0 || v > 50 {
					return 0, nil, errValidation("size", "The size must be between 0 and 50.")
				}
				p.Size = int(v)
			}
			if v, ok := q.Body["is_enabled"].(bool); ok {
				p.IsEnabled = v
			}
			return 200, map[string]any{"data": p}, nil
		}
		return 0, nil, &apiErr{status: 404, message: "The pool does not exist."}
	})
	handle("DELETE /sandbox-pools/{pool}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		ex := s.sbxExtra()
		for i, p := range ex.pools {
			if p.ID == q.Segs[1] {
				ex.pools = append(ex.pools[:i], ex.pools[i+1:]...)
				return 204, nil, nil
			}
		}
		return 0, nil, &apiErr{status: 404, message: "The pool does not exist."}
	})

	// ── Quotas (a single-tenant installation: only the defaults row) ──
	handle("GET /sandbox-quotas", func(s *Server, q *featureReq) (int, any, *apiErr) {
		return 200, map[string]any{"data": s.sbxQuotaJSON()}, nil
	})
	handle("GET /sandbox-quotas/tenants", func(s *Server, q *featureReq) (int, any, *apiErr) {
		row := s.sbxQuotaJSON()
		return 200, map[string]any{"data": []any{map[string]any{"tenant": nil, "limits": row["limits"], "usage": row["usage"],
			"custom": s.sbxExtra().custom != nil}}}, nil
	})
	handle("PUT /sandbox-quotas/{tenant}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if q.Segs[1] != "default" {
			return 0, nil, &apiErr{status: 404, message: "The organization does not exist."}
		}
		ex := s.sbxExtra()
		limits := s.sbxQuotaLimits()
		set := map[string]*int{"max_concurrent": &limits.MaxConcurrent, "max_cpu_m": &limits.MaxCPUm,
			"max_memory_mb": &limits.MaxMemoryMB, "max_sandbox_seconds_month": &limits.MaxSandboxSecondsMonth,
			"max_pool_size": &limits.MaxPoolSize}
		defaults := map[string]int{"max_concurrent": ex.defaults.MaxConcurrent, "max_cpu_m": ex.defaults.MaxCPUm,
			"max_memory_mb": ex.defaults.MaxMemoryMB, "max_sandbox_seconds_month": ex.defaults.MaxSandboxSecondsMonth,
			"max_pool_size": ex.defaults.MaxPoolSize}
		for _, f := range sbxQuotaFields {
			v, present := q.Body[f]
			if !present {
				continue
			}
			if v == nil {
				*set[f] = defaults[f]
				continue
			}
			n, ok := v.(float64)
			if !ok || n < 0 || n != float64(int(n)) {
				return 0, nil, errValidation(f, "The "+strings.ReplaceAll(f, "_", " ")+" field must be an integer of at least 0.")
			}
			*set[f] = int(n)
		}
		if v, present := q.Body["allowed_classes"]; present {
			if v == nil {
				limits.AllowedClasses = nil
			} else {
				list, ok := v.([]any)
				if !ok {
					return 0, nil, errValidation("allowed_classes", "The allowed classes field must be an array.")
				}
				var classes []string
				for _, c := range list {
					name := fmt.Sprint(c)
					if !sbxKnownClasses[name] {
						return 0, nil, errValidation("allowed_classes.0", "The selected allowed class "+name+" is invalid.")
					}
					classes = append(classes, name)
				}
				sort.Strings(classes)
				limits.AllowedClasses = classes
			}
		}
		ex.custom = &limits
		return 200, map[string]any{"data": s.sbxQuotaJSON()}, nil
	})
	handle("DELETE /sandbox-quotas/{tenant}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if q.Segs[1] != "default" {
			return 0, nil, &apiErr{status: 404, message: "The organization does not exist."}
		}
		s.sbxExtra().custom = nil
		return 204, nil, nil
	})

	// ── Usage and metrics ──
	handle("GET /sandbox-usage", func(s *Server, q *featureReq) (int, any, *apiErr) {
		for _, k := range []string{"from", "to"} {
			if v := q.query(k); v != "" {
				if _, err := time.Parse("2006-01-02", v); err != nil {
					return 0, nil, errValidation(k, "The "+k+" field must be a valid date.")
				}
			}
		}
		rows := []runr.SandboxUsageRecord{}
		for _, r := range s.sbxExtra().usage {
			if from := q.query("from"); from != "" && r.RecordDate < from {
				continue
			}
			if to := q.query("to"); to != "" && r.RecordDate > to {
				continue
			}
			rows = append(rows, r)
		}
		return 200, map[string]any{"data": rows}, nil
	})
	handle("GET /sandbox-metrics", func(s *Server, q *featureReq) (int, any, *apiErr) {
		window := q.query("window")
		if window == "" {
			window = "24h"
		}
		switch window {
		case "1h", "24h", "7d", "30d":
		default:
			return 0, nil, errValidation("window", "The selected window is invalid.")
		}
		active, warm := 0, 0
		var pool, cold []int
		for _, sb := range s.sbx().list {
			switch sb.data.Status {
			case runr.SandboxPending, runr.SandboxRunning, runr.SandboxPaused:
				active++
			}
			if sb.data.StartMS != nil {
				if sb.data.FromPool {
					pool = append(pool, *sb.data.StartMS)
				} else {
					cold = append(cold, *sb.data.StartMS)
				}
			}
		}
		hits, misses := 0, 0
		for _, p := range s.sbxExtra().pools {
			warm += p.Warm
			hits += p.Hits
			misses += p.Misses
		}
		var rate any
		if hits+misses > 0 {
			rate = float64(hits) / float64(hits+misses)
		}
		hist := func(v []int) map[string]any {
			sum := 0
			for _, x := range v {
				sum += x
			}
			return map[string]any{"count": len(v), "sum_ms": sum, "buckets": []any{map[string]any{"le_ms": nil, "count": len(v)}}}
		}
		return 200, map[string]any{"data": map[string]any{
			"window": window, "active": active, "warm": warm,
			"pool":       map[string]any{"hits": hits, "misses": misses, "hit_rate": rate},
			"starts":     map[string]any{"pool_hit": len(pool), "cold": len(cold)},
			"start_time": map[string]any{"pool_hit": hist(pool), "cold": hist(cold)},
		}}, nil
	})
}
