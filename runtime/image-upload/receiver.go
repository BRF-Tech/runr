package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"sync"
	"time"
)

// States of an upload, as GET /status reports them.
const (
	StateReceiving = "receiving" // chunks are accepted
	StateVerifying = "verifying" // size and SHA-256 of the file
	StatePushing   = "pushing"   // into the region registry
	StatePushed    = "pushed"    // done: result holds the digest
	StateFailed    = "failed"    // done: error holds why
)

// Status is the answer of GET /status (and of POST /complete).
type Status struct {
	State    string  `json:"state"`
	Received int64   `json:"received"`
	Size     int64   `json:"size"`
	SHA256   string  `json:"sha256,omitempty"`
	Error    string  `json:"error,omitempty"`
	Result   *Result `json:"result,omitempty"`
}

// Pusher pushes the verified file to the target (push.go); tests replace it.
type Pusher func(ctx context.Context, cfg Config, path, sha string) (*Result, error)

// Receiver takes the chunks of one upload. Every chunk is written at its offset (any order,
// repeats allowed); the received byte ranges are merged, and `received` is the contiguous
// prefix from byte 0 — what a client resumes after.
//
//	GET  /healthz
//	PUT  /upload?offset=N   raw bytes → {received, size}
//	POST /complete          202: verification and push start (409 while bytes are missing)
//	GET  /status            Status
type Receiver struct {
	cfg  Config
	push Pusher
	path string
	file *os.File

	mu     sync.Mutex
	state  string
	ranges []span
	err    string
	sha    string
	result *Result
	done   chan struct{}
}

type span struct{ start, end int64 } // [start, end)

// NewReceiver creates the working file (sparse, the declared size) in cfg.Dir.
func NewReceiver(cfg Config, push Pusher) (*Receiver, error) {
	if err := os.MkdirAll(cfg.Dir, 0o755); err != nil {
		return nil, err
	}
	path := filepath.Join(cfg.Dir, "upload.bin")
	f, err := os.OpenFile(path, os.O_RDWR|os.O_CREATE|os.O_TRUNC, 0o600)
	if err != nil {
		return nil, err
	}
	if err := f.Truncate(cfg.Size); err != nil {
		f.Close()
		return nil, err
	}
	return &Receiver{cfg: cfg, push: push, path: path, file: f, state: StateReceiving, done: make(chan struct{})}, nil
}

// Close releases the working file.
func (r *Receiver) Close() error { return r.file.Close() }

// Done is closed when the upload reached pushed or failed.
func (r *Receiver) Done() <-chan struct{} { return r.done }

func (r *Receiver) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
	})
	mux.HandleFunc("PUT /upload", r.handleChunk)
	mux.HandleFunc("POST /complete", r.handleComplete)
	mux.HandleFunc("GET /status", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, http.StatusOK, r.Status())
	})
	return mux
}

func (r *Receiver) Status() Status {
	r.mu.Lock()
	defer r.mu.Unlock()
	return Status{State: r.state, Received: contiguous(r.ranges), Size: r.cfg.Size, SHA256: r.sha, Error: r.err, Result: r.result}
}

func (r *Receiver) handleChunk(w http.ResponseWriter, req *http.Request) {
	offset, err := strconv.ParseInt(req.URL.Query().Get("offset"), 10, 64)
	if err != nil || offset < 0 || offset >= r.cfg.Size {
		writeError(w, http.StatusBadRequest, fmt.Sprintf("offset must be between 0 and %d", r.cfg.Size-1))
		return
	}

	r.mu.Lock()
	state := r.state
	r.mu.Unlock()
	if state != StateReceiving {
		writeError(w, http.StatusConflict, "the upload is "+state+"; no more chunks are taken")
		return
	}

	limit := r.cfg.MaxChunk
	if rest := r.cfg.Size - offset; rest < limit {
		limit = rest
	}
	body, err := io.ReadAll(io.LimitReader(req.Body, limit+1))
	if err != nil {
		writeError(w, http.StatusBadRequest, "reading the chunk: "+err.Error())
		return
	}
	if int64(len(body)) > limit {
		writeError(w, http.StatusRequestEntityTooLarge, fmt.Sprintf("the chunk at %d is larger than %d bytes (chunk size, or the rest of the file)", offset, limit))
		return
	}
	if len(body) == 0 {
		writeError(w, http.StatusBadRequest, "the chunk is empty")
		return
	}
	if _, err := r.file.WriteAt(body, offset); err != nil {
		writeError(w, http.StatusInternalServerError, "writing the chunk: "+err.Error())
		return
	}

	r.mu.Lock()
	r.ranges = addSpan(r.ranges, span{offset, offset + int64(len(body))})
	received := contiguous(r.ranges)
	r.mu.Unlock()

	writeJSON(w, http.StatusOK, map[string]int64{"received": received, "size": r.cfg.Size})
}

func (r *Receiver) handleComplete(w http.ResponseWriter, _ *http.Request) {
	r.mu.Lock()
	if r.state != StateReceiving {
		st := r.state
		r.mu.Unlock()
		writeError(w, http.StatusConflict, "the upload is already "+st)
		return
	}
	if got := contiguous(r.ranges); got != r.cfg.Size {
		r.mu.Unlock()
		writeJSON(w, http.StatusConflict, map[string]any{"error": fmt.Sprintf("%d of %d bytes received", got, r.cfg.Size), "received": got})
		return
	}
	r.state = StateVerifying
	r.err = ""
	r.mu.Unlock()

	go r.finish()

	writeJSON(w, http.StatusAccepted, r.Status())
}

// finish verifies the file and pushes it. A SHA-256 mismatch reopens the upload with nothing
// received (the client sends the file again); a failed push is final.
func (r *Receiver) finish() {
	if err := r.file.Sync(); err != nil {
		r.fail(fmt.Errorf("syncing the file: %w", err))
		return
	}

	sum, err := fileSHA256(r.path)
	if err != nil {
		r.fail(fmt.Errorf("reading the file: %w", err))
		return
	}
	if r.cfg.SHA256 != "" && sum != r.cfg.SHA256 {
		r.mu.Lock()
		r.state = StateReceiving
		r.ranges = nil
		r.err = fmt.Sprintf("the file's SHA-256 is %s, the upload declared %s; send the file again", sum, r.cfg.SHA256)
		r.mu.Unlock()
		log.Printf("verification failed: sha256 %s, declared %s", sum, r.cfg.SHA256)
		return
	}

	r.mu.Lock()
	r.sha = sum
	r.state = StatePushing
	r.mu.Unlock()
	log.Printf("verified %d bytes, sha256 %s; pushing to %s", r.cfg.Size, sum, r.cfg.Target)

	ctx, cancel := context.WithTimeout(context.Background(), r.cfg.PushTimeout)
	defer cancel()
	res, err := r.push(ctx, r.cfg, r.path, sum)
	if err != nil {
		r.fail(err)
		return
	}

	r.mu.Lock()
	r.state = StatePushed
	r.result = res
	r.mu.Unlock()
	log.Printf("pushed %s (%s, %s)", res.Reference, res.Kind, res.MediaType)
	writeTerminationMessage(r.Status())
	close(r.done)
}

func (r *Receiver) fail(err error) {
	r.mu.Lock()
	r.state = StateFailed
	r.err = err.Error()
	r.mu.Unlock()
	log.Printf("upload failed: %v", err)
	writeTerminationMessage(r.Status())
	close(r.done)
}

// serve runs the receiver until the Job is deleted (RunR reads the final status and removes
// the Job) or the context ends.
func serve(ctx context.Context, cfg Config) error {
	rec, err := NewReceiver(cfg, Push)
	if err != nil {
		return err
	}
	defer rec.Close()

	srv := &http.Server{
		Addr:              fmt.Sprintf(":%d", cfg.Port),
		Handler:           rec.Handler(),
		ReadHeaderTimeout: 30 * time.Second,
	}
	errc := make(chan error, 1)
	go func() { errc <- srv.ListenAndServe() }()
	log.Printf("runr-image-upload %s: receiving %d bytes for %s on :%d", version, cfg.Size, cfg.Target, cfg.Port)

	select {
	case <-ctx.Done():
	case err := <-errc:
		if !errors.Is(err, http.ErrServerClosed) {
			return err
		}
	}
	shutdown, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	return srv.Shutdown(shutdown)
}

// addSpan merges s into the sorted, non-overlapping spans.
func addSpan(spans []span, s span) []span {
	spans = append(spans, s)
	sort.Slice(spans, func(i, j int) bool { return spans[i].start < spans[j].start })
	merged := spans[:0:0]
	for _, cur := range spans {
		if n := len(merged); n > 0 && cur.start <= merged[n-1].end {
			if cur.end > merged[n-1].end {
				merged[n-1].end = cur.end
			}
			continue
		}
		merged = append(merged, cur)
	}
	return merged
}

// contiguous is the number of bytes received from byte 0 without a gap.
func contiguous(spans []span) int64 {
	if len(spans) == 0 || spans[0].start != 0 {
		return 0
	}
	return spans[0].end
}

func fileSHA256(path string) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

func writeError(w http.ResponseWriter, status int, msg string) {
	writeJSON(w, status, map[string]string{"error": msg})
}

// writeTerminationMessage leaves the final status where `kubectl describe` shows it.
func writeTerminationMessage(st Status) {
	b, err := json.Marshal(st)
	if err != nil || len(b) > 4096 {
		return
	}
	_ = os.WriteFile("/dev/termination-log", b, 0o644)
}
