package main

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"strconv"
	"sync"
	"sync/atomic"
	"time"
)

const (
	restartBackoffMin = 100 * time.Millisecond
	restartBackoffMax = 5 * time.Second
	// A worker that lived this long (or served a request) restarts after the minimum backoff.
	healthyLifetime = 10 * time.Second
	stopGrace       = 5 * time.Second
)

// pool runs RUNR_WORKERS long-lived processes (persistent mode). An invocation writes the
// envelope as one line to an idle worker's stdin and reads its stdout lines until one is JSON
// (parseLine); non-JSON lines are logs. A supervisor per slot restarts a worker that exited.
type pool struct {
	cfg    config
	idle   chan *worker
	ctx    context.Context
	cancel context.CancelFunc
	wg     sync.WaitGroup

	mu      sync.Mutex
	current map[int]*worker
}

// worker is one generation of a pool slot's process.
type worker struct {
	slot     int
	proc     *waiter
	stdin    io.WriteCloser
	resp     chan lineResult // JSON lines read while a request is in flight
	dead     chan struct{}   // closed once the process exited and its stdout was read to the end
	inflight atomic.Bool
	served   atomic.Int64
}

// isDead reports whether the worker's process is gone.
func (w *worker) isDead() bool {
	select {
	case <-w.dead:
		return true
	default:
		return false
	}
}

// lineResult is one JSON stdout line of a worker; tooLong when it exceeded the size limit.
type lineResult struct {
	data    []byte
	tooLong bool
}

func newPool(cfg config) *pool {
	ctx, cancel := context.WithCancel(context.Background())
	p := &pool{
		cfg:     cfg,
		idle:    make(chan *worker, cfg.Workers),
		ctx:     ctx,
		cancel:  cancel,
		current: map[int]*worker{},
	}

	for slot := 0; slot < cfg.Workers; slot++ {
		p.wg.Add(1)
		go p.supervise(slot)
	}

	return p
}

// supervise keeps one worker process alive in its slot until the pool closes.
func (p *pool) supervise(slot int) {
	defer p.wg.Done()

	backoff := restartBackoffMin
	for {
		if p.ctx.Err() != nil {
			return
		}

		w, err := p.start(slot)
		if err != nil {
			logger.Printf("worker %d: start failed: %v (retry in %s)", slot, err, backoff)
			if !p.sleep(backoff) {
				return
			}
			backoff = min(backoff*2, restartBackoffMax)
			continue
		}

		started := time.Now()
		p.setCurrent(slot, w)
		p.idle <- w // capacity Workers: each slot holds at most one live worker

		select {
		case <-w.dead:
		case <-p.ctx.Done():
			w.proc.stop(stopGrace)
			return
		}

		if p.ctx.Err() != nil {
			return
		}

		logger.Printf("worker %d: process exited (%v), restarting", slot, describeExit(w.proc))
		if w.served.Load() > 0 || time.Since(started) >= healthyLifetime {
			backoff = restartBackoffMin
		}
		if !p.sleep(backoff) {
			return
		}
		backoff = min(backoff*2, restartBackoffMax)
	}
}

// start launches a worker process and its stdout reader. stdout is an os.Pipe read by readLoop
// (not cmd.StdoutPipe, which Wait closes before the last line may have been read).
func (p *pool) start(slot int) (*worker, error) {
	cmd := command(nil, p.cfg, "RUNR_WORKER="+strconv.Itoa(slot))
	cmd.Stderr = p.cfg.Stderr

	stdin, err := cmd.StdinPipe()
	if err != nil {
		return nil, err
	}
	stdoutR, stdoutW, err := os.Pipe()
	if err != nil {
		return nil, err
	}
	cmd.Stdout = stdoutW

	proc, err := startWaiter(cmd)
	_ = stdoutW.Close() // the child holds its own copy
	if err != nil {
		_ = stdoutR.Close()
		return nil, err
	}

	w := &worker{slot: slot, proc: proc, stdin: stdin, resp: make(chan lineResult, 1), dead: make(chan struct{})}

	readerDone := make(chan struct{})
	go func() {
		p.readLoop(w, stdoutR)
		close(readerDone)
	}()
	go func() {
		<-proc.done
		// The last lines are read before the worker counts as dead — unless a background child
		// keeps the pipe open
		select {
		case <-readerDone:
		case <-time.After(pipeGrace):
		}
		_ = stdoutR.Close()
		close(w.dead)
	}()

	return w, nil
}

// readLoop reads a worker's stdout: JSON lines answer the request in flight, anything else is
// logged as the program's output.
func (p *pool) readLoop(w *worker, stdout io.Reader) {
	br := bufio.NewReaderSize(stdout, 64*1024)
	for {
		line, tooLong, err := readLine(br, p.cfg.MaxBody)
		trimmed := bytes.TrimSpace(line)

		switch {
		case tooLong:
			p.deliver(w, lineResult{tooLong: true})
		case len(trimmed) == 0:
		case json.Valid(trimmed):
			p.deliver(w, lineResult{data: append([]byte(nil), trimmed...)})
		default:
			p.logLine(trimmed)
		}

		if err != nil {
			return
		}
	}
}

// deliver hands a response line to the request in flight; a JSON line outside a request is
// logged and dropped (it can not belong to any request).
func (p *pool) deliver(w *worker, r lineResult) {
	if w.inflight.Load() {
		select {
		case w.resp <- r:
			return
		default:
		}
	}
	if r.tooLong {
		logger.Printf("worker %d: dropped an output line over %d bytes outside a request", w.slot, p.cfg.MaxBody)
		return
	}
	logger.Printf("worker %d: unexpected output outside a request: %s", w.slot, truncate(r.data, 200))
}

func (p *pool) logLine(line []byte) {
	_, _ = p.cfg.Stderr.Write(append(append([]byte(nil), line...), '\n'))
}

func (p *pool) Invoke(ctx context.Context, env *Envelope, _ []byte) *Response {
	ctx, cancel := context.WithTimeout(ctx, p.cfg.Timeout)
	defer cancel()

	w, err := p.acquire(ctx)
	if err != nil {
		if errors.Is(err, context.DeadlineExceeded) {
			return errorResponse(http.StatusGatewayTimeout, "handler timed out after "+seconds(p.cfg.Timeout))
		}
		return errorResponse(http.StatusServiceUnavailable, "no handler process available")
	}

	// A late line of the previous request must not answer this one
	select {
	case <-w.resp:
	default:
	}
	w.inflight.Store(true)

	line := encodeEnvelope(env)
	go func() {
		// A worker that exits mid-write is reported through proc.done below
		_, _ = w.stdin.Write(line)
	}()

	select {
	case r := <-w.resp:
		return p.answer(w, r)
	case <-w.dead:
		select {
		case r := <-w.resp:
			return p.answer(w, r)
		default:
		}
		logger.Printf("request %s: worker %d exited (%v)", env.ID, w.slot, describeExit(w.proc))
		return errorResponse(http.StatusBadGateway, "handler process exited")
	case <-ctx.Done():
		w.proc.kill() // the supervisor starts a new worker
		if errors.Is(ctx.Err(), context.DeadlineExceeded) {
			logger.Printf("request %s: worker %d timed out after %s, restarting it", env.ID, w.slot, seconds(p.cfg.Timeout))
			return errorResponse(http.StatusGatewayTimeout, "handler timed out after "+seconds(p.cfg.Timeout))
		}
		return errorResponse(http.StatusServiceUnavailable, "request cancelled")
	}
}

// answer releases the worker and turns its response line into a response.
func (p *pool) answer(w *worker, r lineResult) *Response {
	w.inflight.Store(false)
	w.served.Add(1)
	p.release(w)

	if r.tooLong {
		return errorResponse(http.StatusBadGateway, fmt.Sprintf("handler response exceeds %d bytes", p.cfg.MaxBody))
	}
	return parseLine(r.data)
}

// acquire waits for an idle, live worker.
func (p *pool) acquire(ctx context.Context) (*worker, error) {
	for {
		select {
		case w := <-p.idle:
			if w.isDead() {
				continue // its supervisor brings a new one
			}
			return w, nil
		case <-ctx.Done():
			return nil, ctx.Err()
		case <-p.ctx.Done():
			return nil, errors.New("pool closed")
		}
	}
}

func (p *pool) release(w *worker) {
	if w.isDead() {
		return
	}
	select {
	case p.idle <- w:
	default:
	}
}

func (p *pool) setCurrent(slot int, w *worker) {
	p.mu.Lock()
	p.current[slot] = w
	p.mu.Unlock()
}

// Close stops the supervisors and terminates the workers (SIGTERM, SIGKILL after stopGrace).
func (p *pool) Close() {
	p.cancel()
	p.wg.Wait()
}

// sleep waits d unless the pool closes; false when it closed.
func (p *pool) sleep(d time.Duration) bool {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-t.C:
		return true
	case <-p.ctx.Done():
		return false
	}
}

// readLine reads one line of at most max bytes; a longer line is consumed and reported tooLong.
func readLine(br *bufio.Reader, max int64) ([]byte, bool, error) {
	var buf []byte
	tooLong := false
	for {
		frag, err := br.ReadSlice('\n')
		if !tooLong {
			if int64(len(buf)+len(frag)) > max {
				tooLong = true
				buf = nil
			} else {
				buf = append(buf, frag...)
			}
		}
		if errors.Is(err, bufio.ErrBufferFull) {
			continue
		}
		return buf, tooLong, err
	}
}

func describeExit(w *waiter) string {
	if w.cmd.ProcessState == nil {
		if w.err != nil {
			return w.err.Error()
		}
		return "unknown"
	}
	return "status " + strconv.Itoa(exitCode(w.cmd.ProcessState))
}

func truncate(b []byte, n int) string {
	if len(b) <= n {
		return string(b)
	}
	return string(b[:n]) + "…"
}
