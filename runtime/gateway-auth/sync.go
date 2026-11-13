package main

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"math/rand/v2"
	"net/http"
	"sync"
	"sync/atomic"
	"time"
)

// Syncer keeps the snapshot current: GET /api/internal/gateway-auth/{region}/config every
// SYNC_SECONDS with the ETag of the snapshot it has (304: nothing changed). Until the first
// answer the service is not ready (/readyz 503, every check 503); afterwards a failed sync keeps
// the last snapshot — a revoked key works on until RunR answers again.
//
// Between those syncs a check that meets an unknown key asks RunR once more (Refresh, the
// on-demand sync): a key created moments ago works on every pod at once instead of after each
// pod's own next sync. One such sync runs at a time per pod — the checks that need it meanwhile
// wait for it — and a new one starts at the earliest MinRefresh after the last one began, so a
// flood of invalid keys costs RunR one conditional GET per pod per MinRefresh at most.
type Syncer struct {
	URL    string
	Token  string
	Client *http.Client

	// MinRefresh is the least time between the starts of two on-demand syncs
	// (ON_DEMAND_SYNC_SECONDS); 0 turns them off.
	MinRefresh time.Duration
	// RefreshTimeout bounds an on-demand sync (0: DefaultRefreshTimeout); a check waits no longer.
	RefreshTimeout time.Duration
	// Now is the clock of the on-demand syncs' spacing (nil: time.Now).
	Now func() time.Time
	// OnRefresh hears the outcome of each on-demand sync once (nil: nobody).
	OnRefresh func(changed bool, err error)

	current atomic.Pointer[Snapshot]
	synced  atomic.Int64

	// mu serializes the requests to RunR (the scheduled sync and an on-demand one) and guards etag
	mu   sync.Mutex
	etag string

	// refreshMu guards the on-demand sync's state
	refreshMu   sync.Mutex
	refreshing  chan struct{} // closed when the running on-demand sync ends; nil when none runs
	refreshErr  error         // its outcome, for the checks that waited for it
	lastRefresh time.Time     // when the last on-demand sync began
}

const maxConfigBytes = 64 << 20

// DefaultRefreshTimeout is the longest an on-demand sync (and a check waiting for it) takes.
const DefaultRefreshTimeout = 3 * time.Second

// Snapshot is the current snapshot, nil before the first sync.
func (s *Syncer) Snapshot() *Snapshot { return s.current.Load() }

// Ready reports whether a snapshot was loaded.
func (s *Syncer) Ready() bool { return s.current.Load() != nil }

// LastSync is the time of the last successful sync (zero before the first).
func (s *Syncer) LastSync() time.Time {
	if n := s.synced.Load(); n > 0 {
		return time.Unix(0, n)
	}
	return time.Time{}
}

// Sync asks RunR once; changed reports whether a new snapshot was loaded.
func (s *Syncer) Sync(ctx context.Context) (changed bool, err error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, s.URL, nil)
	if err != nil {
		return false, err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Authorization", "Bearer "+s.Token)
	if s.etag != "" && s.current.Load() != nil {
		req.Header.Set("If-None-Match", s.etag)
	}

	resp, err := s.Client.Do(req)
	if err != nil {
		return false, fmt.Errorf("config sync: %w", err)
	}
	defer resp.Body.Close()

	switch resp.StatusCode {
	case http.StatusNotModified:
		s.synced.Store(time.Now().UnixNano())
		return false, nil
	case http.StatusOK:
	default:
		return false, fmt.Errorf("config sync answered %d", resp.StatusCode)
	}

	var cfg Config
	if err := json.NewDecoder(io.LimitReader(resp.Body, maxConfigBytes)).Decode(&cfg); err != nil {
		return false, fmt.Errorf("config sync: %w", err)
	}
	s.current.Store(NewSnapshot(&cfg, s.current.Load()))
	s.etag = resp.Header.Get("ETag")
	s.synced.Store(time.Now().UnixNano())
	return true, nil
}

// Refresh is the on-demand sync of a check that met an unknown key. It asks RunR once when no
// on-demand sync began within MinRefresh; while one runs it waits for that one (at most until
// ctx ends). ran reports whether this call or the one it waited for asked RunR — false when
// MinRefresh holds it back or on-demand syncs are off; err is that sync's error. The snapshot
// it loaded is Snapshot().
func (s *Syncer) Refresh(ctx context.Context) (ran bool, err error) {
	if s.MinRefresh <= 0 || !s.Ready() {
		return false, nil
	}

	s.refreshMu.Lock()
	if running := s.refreshing; running != nil {
		s.refreshMu.Unlock()
		select {
		case <-running:
		case <-ctx.Done():
			return false, ctx.Err()
		}
		s.refreshMu.Lock()
		defer s.refreshMu.Unlock()
		return true, s.refreshErr
	}
	now := s.now()
	if !s.lastRefresh.IsZero() && now.Sub(s.lastRefresh) < s.MinRefresh {
		s.refreshMu.Unlock()
		return false, nil
	}
	done := make(chan struct{})
	s.refreshing, s.lastRefresh = done, now
	s.refreshMu.Unlock()

	// not the check's own deadline: the checks waiting for it get its outcome
	timeout := s.RefreshTimeout
	if timeout <= 0 {
		timeout = DefaultRefreshTimeout
	}
	rctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), timeout)
	changed, err := s.Sync(rctx)
	cancel()
	if s.OnRefresh != nil {
		s.OnRefresh(changed, err)
	}

	s.refreshMu.Lock()
	s.refreshing, s.refreshErr = nil, err
	close(done)
	s.refreshMu.Unlock()
	return true, err
}

func (s *Syncer) now() time.Time {
	if s.Now != nil {
		return s.Now()
	}
	return time.Now()
}

// Run syncs every interval (with a little jitter, so the pods of a region spread out) until ctx
// ends; before the first snapshot it retries every two seconds.
func (s *Syncer) Run(ctx context.Context, interval time.Duration, onError func(error), onChange func(*Snapshot)) {
	for {
		changed, err := s.Sync(ctx)
		if err != nil && onError != nil {
			onError(err)
		}
		if changed && onChange != nil {
			onChange(s.Snapshot())
		}

		wait := interval + time.Duration(rand.Int64N(int64(interval/5)+1))
		if !s.Ready() {
			wait = 2 * time.Second
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(wait):
		}
	}
}
