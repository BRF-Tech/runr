package runrtest

import (
	"archive/tar"
	"bytes"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"path"
	"sort"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Sandbox API of the fake (docs/sandbox-api.md): sandboxes live in memory, commands are
// answered by SandboxExecFunc (default: echo the command line), files are kept per sandbox,
// exec and run stream Server-Sent Events when asked.

// SandboxExecCall is one command (or code run) a fake sandbox receives.
type SandboxExecCall struct {
	SandboxID string
	// Cmd is the argv; a string command arrives as ["/bin/sh", "-c", cmd].
	Cmd []string
	// Code and Language are set for POST …/run.
	Code     string
	Language string
	Stdin    []byte
	Env      map[string]string
	Cwd      string
	Timeout  int
	Stream   bool
	// WebSocket is set for a command run over the exec WebSocket; its Stdin holds the initial
	// stdin_base64 followed by every stdin frame.
	WebSocket bool
}

// SandboxChunk is one piece of output in the order the command wrote it.
type SandboxChunk struct {
	// Stream is "stdout" or "stderr".
	Stream string
	Data   []byte
}

// SandboxExecReply is what the command printed and how it ended.
type SandboxExecReply struct {
	Output   []SandboxChunk
	ExitCode int
	TimedOut bool
	// FilesChanged and Outputs are the extras of a code run.
	FilesChanged []runr.SandboxFileChange
	Outputs      []runr.SandboxOutput
}

// SandboxExecFunc answers the commands of the fake's sandboxes.
type SandboxExecFunc func(call SandboxExecCall) SandboxExecReply

type sbxState struct {
	list []*sbxSandbox
	exec SandboxExecFunc
	// startPolls keeps new sandboxes pending for that many GETs (POST answers 202).
	startPolls int
	failStart  bool
	calls      []SandboxExecCall
}

type sbxSandbox struct {
	data  runr.Sandbox
	files map[string][]byte
	modes map[string]string
	// dirs are directories made without files in them (POST …/files/mkdir).
	dirs   map[string]bool
	polls  int
	events []map[string]any
}

func (s *Server) sbx() *sbxState {
	return featureState(s, "sandbox", func() *sbxState { return &sbxState{} })
}

// SetSandboxExec sets how the fake's sandboxes answer commands and code runs.
func (s *Server) SetSandboxExec(fn SandboxExecFunc) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.sbx().exec = fn
}

// SetSandboxStart makes new sandboxes stay pending for polls GETs (POST /sandboxes then answers
// 202), or fail to start (502 start_failed).
func (s *Server) SetSandboxStart(polls int, fail bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.sbx()
	st.startPolls, st.failStart = polls, fail
}

// SeedSandbox creates a running sandbox of runtime ("" = python) and returns it.
func (s *Server) SeedSandbox(runtime string) runr.Sandbox {
	s.mu.Lock()
	defer s.mu.Unlock()
	if runtime == "" {
		runtime = "python"
	}
	sb := s.newSandbox(map[string]any{"runtime": runtime})
	sb.data.Status = runr.SandboxRunning
	sb.data.StartedAt = nowRFC3339()
	return sb.data
}

// Sandbox returns a sandbox by id.
func (s *Server) Sandbox(id string) (runr.Sandbox, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if sb := s.sandboxByID(id); sb != nil {
		return sb.data, true
	}
	return runr.Sandbox{}, false
}

// SandboxFile returns a file of a sandbox.
func (s *Server) SandboxFile(id, p string) ([]byte, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	sb := s.sandboxByID(id)
	if sb == nil {
		return nil, false
	}
	b, ok := sb.files[path.Clean(p)]
	return b, ok
}

// SetSandboxFile writes a file into a sandbox.
func (s *Server) SetSandboxFile(id, p string, content []byte) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if sb := s.sandboxByID(id); sb != nil {
		sb.files[path.Clean(p)] = append([]byte(nil), content...)
	}
}

// SandboxCalls returns the commands and code runs the fake's sandboxes received.
func (s *Server) SandboxCalls() []SandboxExecCall {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]SandboxExecCall(nil), s.sbx().calls...)
}

func (s *Server) sandboxByID(id string) *sbxSandbox {
	for _, sb := range s.sbx().list {
		if sb.data.ID == id {
			return sb
		}
	}
	return nil
}

func init() {
	handle("POST /sandboxes", func(s *Server, q *featureReq) (int, any, *apiErr) {
		return s.sbxCreate(q)
	})
	handle("GET /sandboxes", func(s *Server, q *featureReq) (int, any, *apiErr) {
		return s.sbxList(q)
	})
	handle("GET /sandboxes/{sandbox}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxFind(q)
		if err != nil {
			return 0, nil, err
		}
		if sb.data.Status == runr.SandboxPending {
			if sb.polls > 0 {
				sb.polls--
			} else {
				sb.data.Status = runr.SandboxRunning
				sb.data.StartedAt = nowRFC3339()
				sb.log("started", "")
			}
		}
		return 200, map[string]any{"data": sb.data}, nil
	})
	handle("DELETE /sandboxes/{sandbox}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxFind(q)
		if err != nil {
			return 0, nil, err
		}
		if !sb.data.Ended() {
			sb.data.Status = runr.SandboxTerminated
			sb.data.EndReason = "deleted"
			sb.data.EndedAt = nowRFC3339()
			sb.log("deleted", "")
		}
		return 200, map[string]any{"data": sb.data}, nil
	})
	handle("GET /sandboxes/{sandbox}/logs", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxFind(q)
		if err != nil {
			return 0, nil, err
		}
		events := []map[string]any{}
		for i := len(sb.events) - 1; i >= 0; i-- {
			events = append(events, sb.events[i])
		}
		data := map[string]any{"events": events}
		if q.query("pod") == "1" && !sb.data.Ended() {
			data["pod"] = sb.pod()
		}
		return 200, map[string]any{"data": data}, nil
	})
	handle("POST /sandboxes/{sandbox}/exec", func(s *Server, q *featureReq) (int, any, *apiErr) {
		return s.sbxExec(q, false)
	})
	handle("POST /sandboxes/{sandbox}/run", func(s *Server, q *featureReq) (int, any, *apiErr) {
		return s.sbxExec(q, true)
	})
	handle("GET /sandboxes/{sandbox}/files", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxRunning(q)
		if err != nil {
			return 0, nil, err
		}
		p := q.query("path")
		if p == "" {
			p = "/workspace"
		}
		p = path.Clean(p)
		if b, ok := sb.files[p]; ok {
			return 200, map[string]any{"data": map[string]any{"path": p, "is_dir": false, "truncated": false,
				"entries": []map[string]any{sb.entry(p, "file", int64(len(b)))}}}, nil
		}
		entries := sb.children(p)
		if entries == nil {
			return 0, nil, &apiErr{status: 404, message: "No such file or directory: " + p}
		}
		return 200, map[string]any{"data": map[string]any{"path": p, "is_dir": true, "entries": entries, "truncated": false}}, nil
	})
	handle("GET /sandboxes/{sandbox}/files/content", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxRunning(q)
		if err != nil {
			return 0, nil, err
		}
		p := path.Clean(q.query("path"))
		b, ok := sb.files[p]
		if !ok {
			return 0, nil, &apiErr{status: 404, message: "No such file: " + p}
		}
		return 200, rawAnswer{contentType: "application/octet-stream", filename: path.Base(p), data: b}, nil
	})
	handle("PUT /sandboxes/{sandbox}/files/content", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxRunning(q)
		if err != nil {
			return 0, nil, err
		}
		p := q.query("path")
		if !strings.HasPrefix(p, "/") {
			return 0, nil, errValidation("path", "The path must be absolute.")
		}
		p = path.Clean(p)
		sb.files[p] = append([]byte{}, q.Raw...)
		if m := q.query("mode"); m != "" {
			sb.modes[p] = m
		}
		sb.log("file.upload", p)
		return 200, map[string]any{"data": map[string]any{"path": p, "size": len(q.Raw)}}, nil
	})
	handle("GET /sandboxes/{sandbox}/files/archive", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxRunning(q)
		if err != nil {
			return 0, nil, err
		}
		p := q.query("path")
		if p == "" {
			p = "/workspace"
		}
		p = path.Clean(p)
		if sb.children(p) == nil {
			return 0, nil, &apiErr{status: 422, message: "Not a directory: " + p}
		}
		var buf bytes.Buffer
		tw := tar.NewWriter(&buf)
		names := make([]string, 0)
		for f := range sb.files {
			if strings.HasPrefix(f, strings.TrimSuffix(p, "/")+"/") {
				names = append(names, f)
			}
		}
		sort.Strings(names)
		for _, f := range names {
			rel := strings.TrimPrefix(f, strings.TrimSuffix(p, "/")+"/")
			mode := int64(0o644)
			if m, ok := sb.modes[f]; ok {
				if n, err := strconv.ParseInt(m, 8, 64); err == nil {
					mode = n
				}
			}
			_ = tw.WriteHeader(&tar.Header{Name: "./" + rel, Mode: mode, Size: int64(len(sb.files[f])), Typeflag: tar.TypeReg})
			_, _ = tw.Write(sb.files[f])
		}
		_ = tw.Close()
		return 200, rawAnswer{contentType: "application/x-tar", filename: path.Base(p) + ".tar", data: buf.Bytes()}, nil
	})
	handle("PUT /sandboxes/{sandbox}/files/archive", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxRunning(q)
		if err != nil {
			return 0, nil, err
		}
		p := q.query("path")
		if p == "" {
			p = "/workspace"
		}
		p = path.Clean(p)
		tr := tar.NewReader(bytes.NewReader(q.Raw))
		for {
			h, terr := tr.Next()
			if terr == io.EOF {
				break
			}
			if terr != nil {
				return 0, nil, &apiErr{status: 422, message: "The archive is not a valid tar: " + terr.Error()}
			}
			if h.Typeflag != tar.TypeReg {
				continue
			}
			name := path.Clean("/" + strings.TrimPrefix(h.Name, "./"))
			data, _ := io.ReadAll(tr)
			full := path.Join(p, name)
			sb.files[full] = data
			sb.modes[full] = fmt.Sprintf("%04o", h.Mode&0o7777)
		}
		sb.log("file.upload", p)
		return 200, map[string]any{"data": map[string]any{"path": p, "bytes": len(q.Raw)}}, nil
	})
}

func (s *Server) newSandbox(body map[string]any) *sbxSandbox {
	str := func(k string) string { v, _ := body[k].(string); return v }
	runtime, image := str("runtime"), str("image")
	if runtime == "" && image == "" {
		runtime = "python"
	}
	version := str("version")
	if runtime != "" && version == "" {
		for _, img := range s.images {
			if img.Runtime == runtime && img.IsDefault {
				version = img.Version
			}
		}
	}
	if image == "" && runtime != "" {
		image = "ghcr.io/brf-tech/exec-" + runtime + ":" + version
	}
	class := str("sandbox_class")
	switch class {
	case "", "auto", "kata":
		class = "kata-fc"
	}
	isolation := "kata"
	if class == "gvisor" || class == "runc" {
		isolation = class
	}
	var region *runr.Region
	if r := str("region"); r != "" {
		region = s.regionByID(r)
		if region == nil {
			region = s.regionByName(r)
		}
	} else if len(s.regions) > 0 {
		region = s.regions[0]
	}
	cpuM := 500
	if v, ok := body["cpu"]; ok {
		cpuM = sbxMillicores(v)
	}
	mem := 512
	if v, ok := body["memory"]; ok {
		mem = sbxMiB(v)
	}
	disk := 1024
	if v, ok := body["disk"]; ok {
		disk = sbxMiB(v)
	}
	ttl, idle, pids := 900, 600, 256
	if v, ok := body["ttl_seconds"].(float64); ok {
		ttl = int(v)
	}
	if v, ok := body["idle_timeout"].(float64); ok {
		idle = int(v)
	}
	if v, ok := body["pids_limit"].(float64); ok {
		pids = int(v)
	}
	readOnly := true
	if v, ok := body["read_only_root"].(bool); ok {
		readOnly = v
	}
	network := runr.SandboxNetwork{Mode: "none", EgressAllow: []string{}}
	if m, ok := body["network"].(map[string]any); ok {
		network.Mode = "allowlist"
		if list, ok := m["egress_allow"].([]any); ok {
			for _, x := range list {
				network.EgressAllow = append(network.EgressAllow, fmt.Sprint(x))
			}
		}
	}
	envKeys := []string{}
	if env, ok := body["env"].(map[string]any); ok {
		for k := range env {
			envKeys = append(envKeys, k)
		}
		sort.Strings(envKeys)
	}
	metadata := map[string]any{}
	if md, ok := body["metadata"].(map[string]any); ok {
		metadata = md
	}
	sb := &sbxSandbox{files: map[string][]byte{}, modes: map[string]string{}, dirs: map[string]bool{}}
	if files, ok := body["files"].([]any); ok {
		for _, f := range files {
			m, _ := f.(map[string]any)
			p, _ := m["path"].(string)
			c, _ := m["content_base64"].(string)
			b, _ := base64.StdEncoding.DecodeString(c)
			sb.files[path.Clean(p)] = b
		}
	}
	now := nowRFC3339()
	ref := &runr.Ref{}
	if region != nil {
		ref = &runr.Ref{ID: region.ID, Name: region.Name}
	}
	sb.data = runr.Sandbox{
		ID: strings.ToLower(s.newID()), Status: runr.SandboxPending, Region: ref, Runtime: runtime,
		RuntimeVersion: version, Image: image, SandboxClass: class, Isolation: isolation,
		Resources:    runr.SandboxResources{CPU: strconv.Itoa(cpuM) + "m", CPUm: cpuM, MemoryMB: mem, DiskMB: disk, PidsLimit: pids},
		Network:      network,
		ReadOnlyRoot: readOnly, EnvKeys: envKeys, Metadata: metadata, TTLSeconds: ttl, IdleTimeoutSeconds: idle,
		CreatedAt: now, LastActivityAt: now, CreatedBy: s.User.ID,
	}
	s.sbx().list = append(s.sbx().list, sb)
	sb.log("created", runtime+image)
	return sb
}

func (sb *sbxSandbox) log(typ, message string) {
	sb.events = append(sb.events, map[string]any{"id": len(sb.events) + 1, "type": typ, "message": message,
		"data": []any{}, "user_id": "1", "created_at": nowRFC3339()})
}

func (s *Server) sbxCreate(q *featureReq) (int, any, *apiErr) {
	body := q.Body
	if body == nil {
		body = map[string]any{}
	}
	if rt := q.str("runtime"); rt != "" {
		found := false
		for _, img := range s.images {
			found = found || img.Runtime == rt
		}
		if !found {
			return 0, nil, errValidation("runtime", "The runtime "+rt+" is not offered in this region.")
		}
	}
	switch q.str("sandbox_class") {
	case "", "auto", "kata", "kata-fc", "kata-clh", "kata-qemu", "kata-dragonball", "gvisor":
	default:
		return 0, nil, errValidation("sandbox_class", "The sandbox class "+q.str("sandbox_class")+" is not available in this region.")
	}
	if r := q.str("region"); r != "" && s.regionByID(r) == nil && s.regionByName(r) == nil {
		return 0, nil, errValidation("region", "The region "+r+" offers no sandboxes.")
	}
	if env, ok := body["env"].(map[string]any); ok {
		for k := range env {
			if err := runr.ValidateEnvName(k); err != nil && !strings.Contains(err.Error(), "set by RunR") {
				return 0, nil, errValidation("env", "The environment variable name "+k+" is invalid.")
			}
		}
	}
	st := s.sbx()
	sb := s.newSandbox(body)
	if st.failStart {
		sb.data.Status = runr.SandboxFailed
		sb.data.EndReason = "failed"
		sb.data.Error = "ErrImagePull: the image could not be pulled"
		return 0, nil, &apiErr{status: 502, message: "The sandbox could not start: " + sb.data.Error}
	}
	wait := true
	if v, ok := body["wait"].(bool); ok {
		wait = v
	}
	if !wait || st.startPolls > 0 {
		sb.polls = st.startPolls
		return 202, map[string]any{"data": sb.data}, nil
	}
	sb.data.Status = runr.SandboxRunning
	sb.data.StartedAt = nowRFC3339()
	ms := 640
	sb.data.StartMS = &ms
	return 201, map[string]any{"data": sb.data}, nil
}

func (s *Server) sbxList(q *featureReq) (int, any, *apiErr) {
	status := q.query("status")
	if status == "" {
		status = "active"
	}
	match := map[string]func(string) bool{
		"active": func(x string) bool {
			return x == runr.SandboxPending || x == runr.SandboxRunning || x == runr.SandboxPaused
		},
		"running": func(x string) bool { return x == runr.SandboxRunning },
		"paused":  func(x string) bool { return x == runr.SandboxPaused },
		"pending": func(x string) bool { return x == runr.SandboxPending },
		"ended": func(x string) bool {
			return x == runr.SandboxTerminated || x == runr.SandboxFailed || x == runr.SandboxTerminating
		},
		"all": func(string) bool { return true },
	}[status]
	if match == nil {
		return 0, nil, errValidation("status", "The selected status is invalid.")
	}
	rows := []runr.Sandbox{}
	list := s.sbx().list
	for i := len(list) - 1; i >= 0; i-- {
		d := list[i].data
		if !match(d.Status) {
			continue
		}
		if r := q.query("region"); r != "" && (d.Region == nil || d.Region.ID != r) {
			continue
		}
		if rt := q.query("runtime"); rt != "" && d.Runtime != rt {
			continue
		}
		rows = append(rows, d)
	}
	perPage, page := 50, 1
	if n, err := strconv.Atoi(q.query("per_page")); err == nil && n > 0 {
		perPage = n
	}
	if n, err := strconv.Atoi(q.query("page")); err == nil && n > 0 {
		page = n
	}
	total := len(rows)
	from := (page - 1) * perPage
	if from > len(rows) {
		from = len(rows)
	}
	to := from + perPage
	if to > len(rows) {
		to = len(rows)
	}
	return 200, map[string]any{"data": rows[from:to], "meta": map[string]any{"total": total, "page": page, "per_page": perPage}}, nil
}

func (s *Server) sbxFind(q *featureReq) (*sbxSandbox, *apiErr) {
	if len(q.Segs) < 2 {
		return nil, errNotFound()
	}
	sb := s.sandboxByID(q.Segs[1])
	if sb == nil {
		return nil, &apiErr{status: 404, message: "Sandbox not found."}
	}
	return sb, nil
}

// sbxRunning finds the sandbox of a command or file call; it must run (409 not_running).
func (s *Server) sbxRunning(q *featureReq) (*sbxSandbox, *apiErr) {
	sb, err := s.sbxFind(q)
	if err != nil {
		return nil, err
	}
	if sb.data.Status != runr.SandboxRunning {
		return nil, &apiErr{status: 409, message: "The sandbox is not running (status: " + sb.data.Status + ")."}
	}
	return sb, nil
}

func (s *Server) sbxExec(q *featureReq, run bool) (int, any, *apiErr) {
	sb, err := s.sbxRunning(q)
	if err != nil {
		return 0, nil, err
	}
	call := SandboxExecCall{SandboxID: sb.data.ID, Cwd: q.str("cwd"), Env: map[string]string{}}
	if env, ok := q.Body["env"].(map[string]any); ok {
		for k, v := range env {
			call.Env[k] = fmt.Sprint(v)
		}
	}
	if t, ok := q.Body["timeout"].(float64); ok {
		call.Timeout = int(t)
	}
	call.Stream, _ = q.Body["stream"].(bool)
	call.Stream = call.Stream || strings.Contains(q.R.Header.Get("Accept"), "text/event-stream")
	if run {
		call.Code = q.str("code")
		if call.Code == "" {
			return 0, nil, errValidation("code", "The code field is required.")
		}
		call.Language = q.str("language")
		if call.Language == "" {
			call.Language = map[string]string{"nodejs": "javascript"}[sb.data.Runtime]
			if call.Language == "" {
				call.Language = sb.data.Runtime
			}
		}
		call.Cmd = []string{call.Language, "/tmp/.runr/code/main"}
	} else if err := sbxCommand(q, &call); err != nil {
		return 0, nil, err
	}
	st := s.sbx()
	st.calls = append(st.calls, call)
	reply := sbxDefaultReply(call)
	if st.exec != nil {
		reply = st.exec(call)
	}
	sb.log("exec", strings.Join(call.Cmd, " "))

	var stdout, stderr []byte
	for _, ch := range reply.Output {
		if ch.Stream == "stderr" {
			stderr = append(stderr, ch.Data...)
		} else {
			stdout = append(stdout, ch.Data...)
		}
	}
	exit := map[string]any{"exit_code": reply.ExitCode, "timed_out": reply.TimedOut, "duration_ms": 12, "truncated": false}
	files := reply.FilesChanged
	if files == nil {
		files = []runr.SandboxFileChange{}
	}
	outputs := reply.Outputs
	if outputs == nil {
		outputs = []runr.SandboxOutput{}
	}

	if call.Stream {
		var b strings.Builder
		frame := func(event string, data any) {
			enc, _ := json.Marshal(data)
			fmt.Fprintf(&b, "event: %s\ndata: %s\n\n", event, enc)
		}
		b.WriteString(": stream\n\n")
		start := map[string]any{"sandbox_id": sb.data.ID}
		if !run {
			start["exec_id"] = strings.ToLower(s.newID())
		}
		frame("start", start)
		for _, ch := range reply.Output {
			frame(ch.Stream, sbxChunk(ch.Data))
			b.WriteString(": ping\n\n")
		}
		frame("exit", exit)
		if run {
			frame("result", map[string]any{"language": call.Language, "exit_code": reply.ExitCode, "timed_out": reply.TimedOut,
				"duration_ms": 12, "truncated": false, "files_changed": files, "outputs": outputs})
		}
		return 200, rawAnswer{contentType: "text/event-stream; charset=utf-8", data: []byte(b.String())}, nil
	}

	data := map[string]any{}
	for k, v := range exit {
		data[k] = v
	}
	for field, out := range map[string][]byte{"stdout": stdout, "stderr": stderr} {
		if len(out) == 0 || utf8.Valid(out) {
			data[field] = string(out)
		} else {
			data[field] = ""
			data[field+"_base64"] = base64.StdEncoding.EncodeToString(out)
		}
	}
	if run {
		data["language"] = call.Language
		data["files_changed"] = files
		data["outputs"] = outputs
	}
	return 200, map[string]any{"data": data}, nil
}

// sbxCommand reads cmd (a string run by /bin/sh -c, or an argv list) and stdin_base64 of an
// exec body into call.
func sbxCommand(q *featureReq, call *SandboxExecCall) *apiErr {
	switch c := q.Body["cmd"].(type) {
	case string:
		if strings.TrimSpace(c) == "" {
			return errValidation("cmd", "The command is required.")
		}
		call.Cmd = []string{"/bin/sh", "-c", c}
	case []any:
		for _, a := range c {
			call.Cmd = append(call.Cmd, fmt.Sprint(a))
		}
	}
	if len(call.Cmd) == 0 {
		return errValidation("cmd", "The command is required.")
	}
	if b64 := q.str("stdin_base64"); b64 != "" {
		b, derr := base64.StdEncoding.DecodeString(b64)
		if derr != nil {
			return errValidation("stdin_base64", "The value is not valid base64.")
		}
		call.Stdin = b
	}
	return nil
}

// sbxDefaultReply echoes the command line (or the code run's language) on stdout.
func sbxDefaultReply(call SandboxExecCall) SandboxExecReply {
	text := "ran " + strings.Join(call.Cmd, " ") + "\n"
	if call.Code != "" {
		text = "ran " + call.Language + " code (" + strconv.Itoa(len(call.Code)) + " bytes)\n"
	}
	return SandboxExecReply{Output: []SandboxChunk{{Stream: "stdout", Data: []byte(text)}}}
}

func sbxChunk(b []byte) map[string]any {
	if utf8.Valid(b) {
		return map[string]any{"data": string(b)}
	}
	return map[string]any{"data_base64": base64.StdEncoding.EncodeToString(b)}
}

func (sb *sbxSandbox) entry(p, typ string, size int64) map[string]any {
	mode := "0644"
	if typ == "dir" {
		mode = "0755"
	} else if m, ok := sb.modes[p]; ok {
		mode = m
	}
	return map[string]any{"name": path.Base(p), "path": p, "type": typ, "size": size, "mode": mode, "modified_at": nowRFC3339()}
}

// children lists the direct entries of directory p (dirs first); nil when p is no directory.
// /workspace, /output, /tmp and /home/sandbox always exist.
func (sb *sbxSandbox) children(p string) []map[string]any {
	prefix := strings.TrimSuffix(p, "/") + "/"
	dirs := map[string]bool{}
	files := map[string]int64{}
	for f, b := range sb.files {
		if !strings.HasPrefix(f, prefix) {
			continue
		}
		rest := strings.TrimPrefix(f, prefix)
		if i := strings.IndexByte(rest, '/'); i >= 0 {
			dirs[rest[:i]] = true
		} else {
			files[rest] = int64(len(b))
		}
	}
	for d := range sb.dirs {
		if !strings.HasPrefix(d, prefix) {
			continue
		}
		rest := strings.TrimPrefix(d, prefix)
		if i := strings.IndexByte(rest, '/'); i >= 0 {
			rest = rest[:i]
		}
		dirs[rest] = true
	}
	known := map[string]bool{"/workspace": true, "/output": true, "/tmp": true, "/home/sandbox": true, "/": true}
	if len(dirs) == 0 && len(files) == 0 && !known[path.Clean(p)] && !sb.dirs[path.Clean(p)] {
		return nil
	}
	out := []map[string]any{}
	names := make([]string, 0, len(dirs))
	for d := range dirs {
		names = append(names, d)
	}
	sort.Strings(names)
	for _, d := range names {
		out = append(out, sb.entry(prefix+d, "dir", 4096))
	}
	names = names[:0]
	for f := range files {
		names = append(names, f)
	}
	sort.Strings(names)
	for _, f := range names {
		out = append(out, sb.entry(prefix+f, "file", files[f]))
	}
	return out
}

func sbxMillicores(v any) int {
	switch t := v.(type) {
	case float64:
		return int(t * 1000)
	case string:
		if strings.HasSuffix(t, "m") {
			n, _ := strconv.Atoi(strings.TrimSuffix(t, "m"))
			return n
		}
		f, _ := strconv.ParseFloat(t, 64)
		return int(f * 1000)
	}
	return 500
}

func sbxMiB(v any) int {
	switch t := v.(type) {
	case float64:
		return int(t)
	case string:
		for suffix, mul := range map[string]float64{"Gi": 1024, "Mi": 1, "G": 1000.0 * 1000 * 1000 / (1024 * 1024), "M": 1000.0 * 1000 / (1024 * 1024)} {
			if strings.HasSuffix(t, suffix) {
				f, _ := strconv.ParseFloat(strings.TrimSuffix(t, suffix), 64)
				return int(f*mul + 0.999)
			}
		}
		n, _ := strconv.Atoi(t)
		return n
	}
	return 512
}
