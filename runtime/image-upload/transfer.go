package main

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"
)

// States of a transfer agent, as GET /status reports them.
const (
	TransferWorking   = "working"   // send: the tool (or the pull) is still producing its files
	TransferReceiving = "receiving" // receive: chunks are accepted
	TransferReady     = "ready"     // receive: every file verified, in/.ready written; the tool restores
	TransferDone      = "done"      // send: the files are served; receive: the tool restored them
	TransferFailed    = "failed"    // message holds why
)

// Markers in the working directory. The tool container writes .done (optional JSON result) or
// .failed (the reason) when it is finished; a receiving agent writes in/.ready once every file is
// verified — the tool waits for it before it restores.
const (
	markerDone   = ".done"
	markerFailed = ".failed"
	markerReady  = ".ready"
)

// TransferStatus is the answer of GET /status.
type TransferStatus struct {
	Mode    string               `json:"mode"`
	State   string               `json:"state"`
	Message string               `json:"message"`
	Files   []TransferFileStatus `json:"files"`
	Result  json.RawMessage      `json:"result"`
}

// TransferFileStatus is one file of the transfer: received is the contiguous prefix a client
// resumes after (receive), the whole size once it is served (send).
type TransferFileStatus struct {
	Name     string `json:"name"`
	Size     int64  `json:"size"`
	SHA256   string `json:"sha256,omitempty"`
	Received int64  `json:"received"`
}

// Agent is the region side of an organization export or import (docs/org-export.md): RunR starts
// it as a Job next to a tool container (pg_dump / pg_restore, redis-cli, rclone) that shares its
// working directory, and reaches it — like the upload receiver — through the Kubernetes API
// server's service proxy, so the region needs no route to RunR and RunR none to the data.
//
//	send     the tool writes its files into out/, then the marker .done (or .failed with the
//	         reason); the agent lists out/ with sizes and SHA-256 and serves every file, ranges
//	         included, for RunR to download in chunks. With RUNR_TRANSFER_PULL the agent pulls an
//	         image into out/image.tar itself and writes the marker.
//	receive  RunR uploads the files RUNR_TRANSFER_FILES names in chunks at their offsets (any
//	         order, repeats allowed); each is verified (size, SHA-256) on complete; with all of
//	         them verified the agent writes in/.ready, then waits for the tool's .done / .failed.
//
//	GET  /healthz
//	GET  /status                    TransferStatus
//	GET  /files/{name}              send, once done: the file (Range: bytes=a-b | bytes=a-)
//	PUT  /files/{name}?offset=N     receive: raw bytes → {received, size}
//	POST /files/{name}/complete     receive: size + SHA-256 checked → {complete: true}
//	POST /stop                      the agent exits 0 (the Job completes)
type Agent struct {
	cfg  TransferConfig
	pull Puller

	mu      sync.Mutex
	state   string
	message string
	result  json.RawMessage
	files   map[string]*agentFile
	order   []string

	stopped  chan struct{}
	stopOnce sync.Once
}

type agentFile struct {
	TransferFile
	path      string
	file      *os.File // receive: the working file (sparse, the declared size)
	ranges    []span
	verifying bool
	complete  bool
	sum       string // the verified (receive) or computed (send) SHA-256
}

// NewAgent prepares the working directory: out/ for a sender, in/ with one sparse file per
// expected file for a receiver. Both stay writable for the tool container whatever user it runs
// as (the directory is the pod's own emptyDir).
func NewAgent(cfg TransferConfig, pull Puller) (*Agent, error) {
	a := &Agent{cfg: cfg, pull: pull, files: map[string]*agentFile{}, stopped: make(chan struct{})}

	switch cfg.Mode {
	case ModeSend:
		a.state = TransferWorking
		if err := sharedDir(a.outDir()); err != nil {
			return nil, err
		}
	case ModeReceive:
		a.state = TransferReceiving
		if err := sharedDir(a.inDir()); err != nil {
			return nil, err
		}
		_ = os.Remove(filepath.Join(a.inDir(), markerReady))
		for _, want := range cfg.Files {
			path := filepath.Join(a.inDir(), want.Name)
			f, err := os.OpenFile(path, os.O_RDWR|os.O_CREATE|os.O_TRUNC, 0o644)
			if err != nil {
				a.Close()
				return nil, err
			}
			if err := f.Truncate(want.Size); err != nil {
				f.Close()
				a.Close()
				return nil, err
			}
			a.files[want.Name] = &agentFile{TransferFile: want, path: path, file: f}
			a.order = append(a.order, want.Name)
		}
	default:
		return nil, fmt.Errorf("unknown transfer mode %q", cfg.Mode)
	}

	return a, nil
}

func sharedDir(dir string) error {
	if err := os.MkdirAll(dir, 0o777); err != nil {
		return err
	}
	// MkdirAll is subject to the umask: the tool container may run as another user
	return os.Chmod(dir, 0o777)
}

func (a *Agent) outDir() string { return filepath.Join(a.cfg.Dir, "out") }
func (a *Agent) inDir() string  { return filepath.Join(a.cfg.Dir, "in") }

// Close releases the receiver's working files.
func (a *Agent) Close() error {
	a.mu.Lock()
	defer a.mu.Unlock()
	for _, f := range a.files {
		if f.file != nil {
			f.file.Close()
			f.file = nil
		}
	}
	return nil
}

// Stopped is closed by POST /stop.
func (a *Agent) Stopped() <-chan struct{} { return a.stopped }

// Start watches the working directory for the tool's markers until the transfer is done or
// failed (or ctx ends) and, with RUNR_TRANSFER_PULL, pulls the image.
func (a *Agent) Start(ctx context.Context) {
	if a.cfg.Pull != "" && a.pull != nil {
		go a.runPull(ctx)
	}
	go func() {
		poll := a.cfg.Poll
		if poll <= 0 {
			poll = time.Second
		}
		t := time.NewTicker(poll)
		defer t.Stop()
		for {
			if a.check() {
				return
			}
			select {
			case <-ctx.Done():
				return
			case <-t.C:
			}
		}
	}()
}

// check applies the markers; true once the state is final.
func (a *Agent) check() bool {
	a.mu.Lock()
	state := a.state
	a.mu.Unlock()
	if state == TransferDone || state == TransferFailed {
		return true
	}

	if msg, ok := readMarker(filepath.Join(a.cfg.Dir, markerFailed)); ok {
		msg = strings.TrimSpace(msg)
		if len(msg) > 2000 {
			msg = msg[:2000]
		}
		if msg == "" {
			msg = "the transfer tool failed without saying why"
		}
		a.finish(TransferFailed, msg, nil, nil)
		return true
	}

	content, ok := readMarker(filepath.Join(a.cfg.Dir, markerDone))
	if !ok {
		return false
	}
	// a receiver's tool restores only after in/.ready: an earlier .done is not about the files
	if a.cfg.Mode == ModeReceive && state != TransferReady {
		return false
	}

	var files []*agentFile
	if a.cfg.Mode == ModeSend {
		listed, err := listOut(a.outDir())
		if err != nil {
			a.finish(TransferFailed, "listing the produced files: "+err.Error(), nil, nil)
			return true
		}
		files = listed
	}
	a.finish(TransferDone, "", resultJSON(content), files)
	return true
}

func (a *Agent) finish(state, message string, result json.RawMessage, files []*agentFile) {
	a.mu.Lock()
	if a.state == TransferDone || a.state == TransferFailed {
		a.mu.Unlock()
		return
	}
	a.state, a.message, a.result = state, message, result
	if files != nil {
		a.files = map[string]*agentFile{}
		a.order = nil
		for _, f := range files {
			a.files[f.Name] = f
			a.order = append(a.order, f.Name)
		}
	}
	a.mu.Unlock()

	if state == TransferFailed {
		log.Printf("transfer failed: %s", message)
	} else {
		log.Printf("transfer %s", state)
	}
	writeTransferTermination(a.Status())
}

// runPull pulls RUNR_TRANSFER_PULL into out/image.tar and writes the marker the watcher reads.
func (a *Agent) runPull(ctx context.Context) {
	ctx, cancel := context.WithTimeout(ctx, a.cfg.PullTimeout)
	defer cancel()

	log.Printf("pulling %s", a.cfg.Pull)
	res, err := a.pull(ctx, a.cfg.Pull, filepath.Join(a.outDir(), "image.tar"), a.cfg.PlainHTTP)
	if err != nil {
		_ = os.WriteFile(filepath.Join(a.cfg.Dir, markerFailed), []byte(err.Error()), 0o644)
		return
	}
	b, _ := json.Marshal(res)
	_ = os.WriteFile(filepath.Join(a.cfg.Dir, markerDone), b, 0o644)
}

func (a *Agent) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
	})
	mux.HandleFunc("GET /status", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, http.StatusOK, a.Status())
	})
	mux.HandleFunc("GET /files/{name}", a.handleDownload)
	mux.HandleFunc("PUT /files/{name}", a.handleChunk)
	mux.HandleFunc("POST /files/{name}/complete", a.handleComplete)
	mux.HandleFunc("POST /stop", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, http.StatusOK, map[string]bool{"stopping": true})
		a.stopOnce.Do(func() { close(a.stopped) })
	})
	if a.cfg.Token == "" {
		return mux
	}
	// The Service is reachable from the cluster while the transfer runs: only RunR, which set
	// the token, may read or write the files (the health check stays open for the kubelet)
	return http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		if req.URL.Path != "/healthz" && subtle.ConstantTimeCompare([]byte(req.Header.Get(TransferTokenHeader)), []byte(a.cfg.Token)) != 1 {
			writeError(w, http.StatusUnauthorized, "missing or wrong "+TransferTokenHeader)
			return
		}
		mux.ServeHTTP(w, req)
	})
}

// TransferTokenHeader carries RUNR_TRANSFER_TOKEN on every request but /healthz.
const TransferTokenHeader = "X-Runr-Transfer-Token"

func (a *Agent) Status() TransferStatus {
	a.mu.Lock()
	defer a.mu.Unlock()

	st := TransferStatus{Mode: a.cfg.Mode, State: a.state, Message: a.message, Files: []TransferFileStatus{}, Result: a.result}
	if len(st.Result) == 0 {
		st.Result = json.RawMessage("{}")
	}
	for _, name := range a.order {
		f := a.files[name]
		received := contiguous(f.ranges)
		if a.cfg.Mode == ModeSend || f.complete {
			received = f.Size
		}
		st.Files = append(st.Files, TransferFileStatus{Name: f.Name, Size: f.Size, SHA256: f.sum, Received: received})
	}
	return st
}

// handleDownload serves a produced file once the tool is done; only the listed names (no path,
// no dotfile) are served.
func (a *Agent) handleDownload(w http.ResponseWriter, req *http.Request) {
	if a.cfg.Mode != ModeSend {
		writeError(w, http.StatusConflict, "this agent receives files; it serves none")
		return
	}
	name := req.PathValue("name")

	a.mu.Lock()
	state := a.state
	f := a.files[name]
	a.mu.Unlock()

	if state != TransferDone {
		writeError(w, http.StatusConflict, "the files are not ready: the transfer is "+state)
		return
	}
	if f == nil {
		writeError(w, http.StatusNotFound, fmt.Sprintf("no file %q was produced", name))
		return
	}

	file, err := os.Open(f.path)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "opening the file: "+err.Error())
		return
	}
	defer file.Close()

	w.Header().Set("Content-Type", "application/octet-stream")
	w.Header().Set("X-Content-SHA256", f.sum)
	// single ranges (bytes=a-b, bytes=a-) → 206 + Content-Range; past the end → 416
	http.ServeContent(w, req, "", time.Time{}, file)
}

func (a *Agent) handleChunk(w http.ResponseWriter, req *http.Request) {
	if a.cfg.Mode != ModeReceive {
		writeError(w, http.StatusConflict, "this agent sends files; it takes none")
		return
	}
	name := req.PathValue("name")

	a.mu.Lock()
	f := a.files[name]
	state := a.state
	var busy string
	if f != nil {
		switch {
		case f.complete:
			busy = name + " is complete"
		case f.verifying:
			busy = name + " is being verified"
		}
	}
	a.mu.Unlock()

	if f == nil {
		writeError(w, http.StatusNotFound, fmt.Sprintf("no file %q is expected", name))
		return
	}
	if state != TransferReceiving {
		writeError(w, http.StatusConflict, "the transfer is "+state+"; no more chunks are taken")
		return
	}
	if busy != "" {
		writeError(w, http.StatusConflict, busy+"; no more chunks are taken")
		return
	}

	offset, err := strconv.ParseInt(req.URL.Query().Get("offset"), 10, 64)
	if err != nil || offset < 0 || offset >= f.Size {
		if f.Size == 0 {
			writeError(w, http.StatusUnprocessableEntity, name+" is empty: it takes no chunks, only complete")
			return
		}
		writeError(w, http.StatusUnprocessableEntity, fmt.Sprintf("offset must be between 0 and %d", f.Size-1))
		return
	}

	limit := a.cfg.MaxChunk
	if rest := f.Size - offset; rest < limit {
		limit = rest
	}
	body, err := io.ReadAll(io.LimitReader(req.Body, limit+1))
	if err != nil {
		writeError(w, http.StatusBadRequest, "reading the chunk: "+err.Error())
		return
	}
	if int64(len(body)) > limit {
		writeError(w, http.StatusUnprocessableEntity, fmt.Sprintf("the chunk at %d is larger than %d bytes (chunk size, or the rest of the file)", offset, limit))
		return
	}
	if len(body) == 0 {
		writeError(w, http.StatusUnprocessableEntity, "the chunk is empty")
		return
	}

	a.mu.Lock()
	file := f.file
	a.mu.Unlock()
	if file == nil {
		writeError(w, http.StatusConflict, "the agent is stopping")
		return
	}
	if _, err := file.WriteAt(body, offset); err != nil {
		writeError(w, http.StatusInternalServerError, "writing the chunk: "+err.Error())
		return
	}

	a.mu.Lock()
	f.ranges = addSpan(f.ranges, span{offset, offset + int64(len(body))})
	received := contiguous(f.ranges)
	a.mu.Unlock()

	writeJSON(w, http.StatusOK, map[string]int64{"received": received, "size": f.Size})
}

// handleComplete verifies one file. A SHA-256 mismatch empties it (the client sends it again);
// once every expected file is verified the agent writes in/.ready for the tool.
func (a *Agent) handleComplete(w http.ResponseWriter, req *http.Request) {
	if a.cfg.Mode != ModeReceive {
		writeError(w, http.StatusConflict, "this agent sends files; it takes none")
		return
	}
	name := req.PathValue("name")

	a.mu.Lock()
	f := a.files[name]
	if f == nil {
		a.mu.Unlock()
		writeError(w, http.StatusNotFound, fmt.Sprintf("no file %q is expected", name))
		return
	}
	if f.complete {
		sum, ready := f.sum, a.state != TransferReceiving
		a.mu.Unlock()
		writeJSON(w, http.StatusOK, map[string]any{"complete": true, "sha256": sum, "ready": ready})
		return
	}
	if f.verifying {
		a.mu.Unlock()
		writeError(w, http.StatusConflict, name+" is being verified")
		return
	}
	if a.state != TransferReceiving {
		st := a.state
		a.mu.Unlock()
		writeError(w, http.StatusConflict, "the transfer is "+st)
		return
	}
	if got := contiguous(f.ranges); got != f.Size {
		a.mu.Unlock()
		writeJSON(w, http.StatusConflict, map[string]any{"error": fmt.Sprintf("%d of %d bytes of %s received", got, f.Size, name), "received": got})
		return
	}
	f.verifying = true
	file := f.file
	a.mu.Unlock()

	var sum string
	err := errors.New("the agent is stopping")
	if file != nil {
		if err = file.Sync(); err == nil {
			sum, err = fileSHA256(f.path)
		}
	}

	a.mu.Lock()
	f.verifying = false
	if err != nil {
		a.mu.Unlock()
		writeError(w, http.StatusInternalServerError, "reading "+name+": "+err.Error())
		return
	}
	if f.SHA256 != "" && sum != f.SHA256 {
		f.ranges = nil
		if file != nil {
			_ = file.Truncate(0)
			_ = file.Truncate(f.Size)
		}
		a.mu.Unlock()
		log.Printf("verification of %s failed: sha256 %s, declared %s", name, sum, f.SHA256)
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{
			"error":    fmt.Sprintf("the SHA-256 of %s is %s, the transfer declared %s; send the file again", name, sum, f.SHA256),
			"received": 0,
		})
		return
	}
	f.complete = true
	f.sum = sum
	all := true
	for _, other := range a.files {
		all = all && other.complete
	}
	a.mu.Unlock()
	log.Printf("verified %s: %d bytes, sha256 %s", name, f.Size, sum)

	if all {
		if err := os.WriteFile(filepath.Join(a.inDir(), markerReady), []byte(time.Now().UTC().Format(time.RFC3339)), 0o644); err != nil {
			a.finish(TransferFailed, "writing in/.ready: "+err.Error(), nil, nil)
			writeError(w, http.StatusInternalServerError, "writing in/.ready: "+err.Error())
			return
		}
		a.mu.Lock()
		if a.state == TransferReceiving {
			a.state = TransferReady
		}
		a.mu.Unlock()
		log.Printf("every file verified: in/.ready written, the tool restores")
	}

	writeJSON(w, http.StatusOK, map[string]any{"complete": true, "sha256": sum, "ready": all})
}

// serveTransfer runs the agent until POST /stop (RunR has read the final status and the files),
// the Job is deleted or the context ends.
func serveTransfer(ctx context.Context, cfg TransferConfig) error {
	agent, err := NewAgent(cfg, pullImage)
	if err != nil {
		return err
	}
	defer agent.Close()

	srv := &http.Server{
		Addr:              fmt.Sprintf(":%d", cfg.Port),
		Handler:           agent.Handler(),
		ReadHeaderTimeout: 30 * time.Second,
	}
	errc := make(chan error, 1)
	go func() { errc <- srv.ListenAndServe() }()

	watch, cancel := context.WithCancel(ctx)
	defer cancel()
	agent.Start(watch)
	switch cfg.Mode {
	case ModeReceive:
		log.Printf("runr-image-upload %s: transfer agent receiving %d file(s) on :%d", version, len(cfg.Files), cfg.Port)
	default:
		log.Printf("runr-image-upload %s: transfer agent sending from %s on :%d", version, filepath.Join(cfg.Dir, "out"), cfg.Port)
	}

	select {
	case <-ctx.Done():
	case <-agent.Stopped():
		log.Printf("stopped by RunR")
	case err := <-errc:
		if !errors.Is(err, http.ErrServerClosed) {
			return err
		}
	}
	writeTransferTermination(agent.Status())

	shutdown, done := context.WithTimeout(context.Background(), 5*time.Second)
	defer done()
	return srv.Shutdown(shutdown)
}

// listOut lists the regular files the tool produced (dotfiles — the markers — left out) with
// their size and SHA-256.
func listOut(dir string) ([]*agentFile, error) {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil, err
	}
	var files []*agentFile
	for _, e := range entries {
		if !e.Type().IsRegular() || !transferName.MatchString(e.Name()) {
			continue
		}
		path := filepath.Join(dir, e.Name())
		info, err := e.Info()
		if err != nil {
			return nil, err
		}
		sum, err := fileSHA256(path)
		if err != nil {
			return nil, err
		}
		files = append(files, &agentFile{
			TransferFile: TransferFile{Name: e.Name(), Size: info.Size(), SHA256: sum},
			path:         path,
			complete:     true,
			sum:          sum,
		})
	}
	return files, nil
}

func readMarker(path string) (string, bool) {
	b, err := os.ReadFile(path)
	if err != nil {
		if !errors.Is(err, fs.ErrNotExist) {
			log.Printf("reading %s: %v", path, err)
		}
		return "", false
	}
	return string(b), true
}

// resultJSON: the .done marker's content when it is a JSON object, else none.
func resultJSON(content string) json.RawMessage {
	content = strings.TrimSpace(content)
	if strings.HasPrefix(content, "{") && json.Valid([]byte(content)) {
		return json.RawMessage(content)
	}
	return nil
}

// writeTransferTermination leaves the final status where `kubectl describe` shows it (without
// the file list when it does not fit).
func writeTransferTermination(st TransferStatus) {
	b, err := json.Marshal(st)
	if err == nil && len(b) > 4096 {
		st.Files, st.Result = nil, nil
		b, err = json.Marshal(st)
	}
	if err != nil || len(b) > 4096 {
		return
	}
	_ = os.WriteFile("/dev/termination-log", b, 0o644)
}
