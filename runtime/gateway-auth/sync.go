package main

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"math/rand/v2"
	"net/http"
	"sync/atomic"
	"time"
)

// Syncer keeps the snapshot current: GET /api/internal/gateway-auth/{region}/config every
// SYNC_SECONDS with the ETag of the snapshot it has (304: nothing changed). Until the first
// answer the service is not ready (/readyz 503, every check 503); afterwards a failed sync keeps
// the last snapshot — a revoked key works on until RunR answers again.
type Syncer struct {
	URL    string
	Token  string
	Client *http.Client

	current atomic.Pointer[Snapshot]
	etag    string
	synced  atomic.Int64
}

const maxConfigBytes = 64 << 20

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
