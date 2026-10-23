// Package wait holds the small waiting helpers the gateway's core and sources share.
package wait

import (
	"context"
	"sync"
	"time"
)

// Sleep waits d or until ctx ends; it reports whether the full d elapsed.
func Sleep(ctx context.Context, d time.Duration) bool {
	if d <= 0 {
		return ctx.Err() == nil
	}
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-t.C:
		return true
	}
}

// Window counts the events a source has handed to emit and not yet seen settled, so it reads
// at most size events ahead. One goroutine (the source's read loop) takes slots; any goroutine
// gives them back.
type Window struct {
	mu    sync.Mutex
	size  int
	used  int
	freed chan struct{}
}

// NewWindow is a window of size slots (at least 1).
func NewWindow(size int) *Window {
	return &Window{size: max(size, 1), freed: make(chan struct{}, 1)}
}

// Free is the number of slots not taken.
func (w *Window) Free() int {
	w.mu.Lock()
	defer w.mu.Unlock()
	return w.size - w.used
}

// Take takes n slots; the caller checked Free first.
func (w *Window) Take(n int) {
	w.mu.Lock()
	w.used += n
	w.mu.Unlock()
}

// Done gives one slot back and wakes a WaitFree.
func (w *Window) Done() {
	w.mu.Lock()
	w.used--
	w.mu.Unlock()
	select {
	case w.freed <- struct{}{}:
	default:
	}
}

// WaitFree waits until a slot is free, at most timeout; it reports whether one is.
func (w *Window) WaitFree(ctx context.Context, timeout time.Duration) bool {
	t := time.NewTimer(timeout)
	defer t.Stop()
	for w.Free() <= 0 {
		select {
		case <-ctx.Done():
			return false
		case <-t.C:
			return w.Free() > 0
		case <-w.freed:
		}
	}
	return true
}
