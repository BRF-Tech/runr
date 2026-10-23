// Package s3 is the gateway source of kind "s3": it polls a bucket (MinIO, Garage, AWS S3 or any
// S3-compatible store) and emits an event per object created, updated or deleted under a
// prefix.
//
// Configuration (the trigger's "source" object):
//
//	{"endpoint": "s3.example.com", "secure": true, "region": "", "bucket": "uploads", "prefix": "",
//	 "access_key": "...", "secret_key": "...", "path_style": true, "poll_interval": "30s",
//	 "events": ["created", "updated"], "start_from": "latest", "checkpoint_key": ""}
//
// endpoint (host[:port]) and bucket are required; access_key and secret_key go together (both
// empty: anonymous requests). secure (default true) uses HTTPS, path_style (default true)
// addresses the bucket in the path instead of the host name. events picks the changes that
// become events: created, updated, deleted (default created and updated). start_from "latest"
// (default) takes the first listing as the baseline; "earliest" reports every existing object
// as created. checkpoint_key (optional, needs write access) is an object key where the source
// stores its snapshot of the listing after each poll and from where it reads it at start, so
// changes made while the gateway was down are reported too; the checkpoint object itself is
// never reported.
//
// Each change is the event
//
//	id              <bucket>/<key>/<etag>  (deleted: <bucket>/<key>/deleted/<etag before>)
//	type            dev.runr.s3.object.created | .updated | .deleted
//	source          /s3/<bucket>
//	subject         the object key
//	time            the object's last-modified time (deleted: when the deletion was seen)
//	datacontenttype application/json
//	data            {"bucket", "key", "etag", "size", "last_modified", "event"}
//
// Delivery guarantees:
//   - Every poll_interval (or when the trigger is nudged) the source lists the prefix
//     (recursively; keys ending in "/" are folder markers and skipped) and compares the listing
//     with its snapshot (key → etag, size, last-modified). An object is updated when its etag
//     changed. A failed listing is not compared, so it never reports objects as deleted.
//   - The changes are delivered in parallel, up to 2×concurrency at once. The snapshot entry of
//     a key advances only when its event settled; an event not settled (shutdown) is reported
//     again by the next poll with the same id (at-least-once).
//   - Without checkpoint_key the snapshot lives in memory only: changes made while the gateway
//     is down are not reported (start_from earliest reports every object again after a
//     restart). Polling sees the state at each poll: an object created and deleted between two
//     polls is never reported, and several writes between two polls are one update.
package s3

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/url"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/minio/minio-go/v7"
	"github.com/minio/minio-go/v7/pkg/credentials"

	"github.com/brf-tech/runr/runtime/queue-consumer/gateway"
	"github.com/brf-tech/runr/runtime/queue-consumer/internal/wait"
	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

func init() { source.Register("s3", New) }

// Config is the "source" object of an s3 trigger.
type Config struct {
	Endpoint      string           `json:"endpoint"`
	Secure        *bool            `json:"secure"` // default true
	Region        string           `json:"region"`
	Bucket        string           `json:"bucket"`
	Prefix        string           `json:"prefix"`
	AccessKey     string           `json:"access_key"`
	SecretKey     string           `json:"secret_key"`
	PathStyle     *bool            `json:"path_style"`    // default true
	PollInterval  gateway.Duration `json:"poll_interval"` // default 30s
	Events        []string         `json:"events"`        // default created, updated
	StartFrom     string           `json:"start_from"`    // "latest" (default) or "earliest"
	CheckpointKey string           `json:"checkpoint_key"`
}

// object is what the snapshot keeps of an object.
type object struct {
	ETag         string    `json:"etag"`
	Size         int64     `json:"size"`
	LastModified time.Time `json:"last_modified"`
}

// store is the part of the bucket the source uses.
type store interface {
	// list returns every object under the prefix (key → object), or an error when the listing
	// is incomplete.
	list(ctx context.Context) (map[string]object, error)
	// get reads an object; found is false when it does not exist.
	get(ctx context.Context, key string) (data []byte, found bool, err error)
	put(ctx context.Context, key string, data []byte) error
}

// Source polls one bucket prefix.
type Source struct {
	cfg    Config
	host   string // endpoint, for logs and the name
	poll   time.Duration
	events map[string]bool
	store  store
}

// New builds an s3 source from its configuration.
func New(raw json.RawMessage) (source.Source, error) {
	var c Config
	if err := json.Unmarshal(raw, &c); err != nil {
		return nil, err
	}
	endpoint := strings.TrimSpace(c.Endpoint)
	if endpoint == "" {
		return nil, errors.New("endpoint is required")
	}
	secure := c.Secure == nil || *c.Secure
	if strings.Contains(endpoint, "://") {
		u, err := url.Parse(endpoint)
		if err != nil || u.Host == "" || (u.Scheme != "http" && u.Scheme != "https") {
			return nil, errors.New("endpoint must be host[:port]")
		}
		endpoint = u.Host
		if c.Secure == nil {
			secure = u.Scheme == "https"
		}
	}
	if c.Bucket == "" {
		return nil, errors.New("bucket is required")
	}
	if (c.AccessKey == "") != (c.SecretKey == "") {
		return nil, errors.New("access_key and secret_key go together")
	}
	switch c.StartFrom {
	case "":
		c.StartFrom = "latest"
	case "latest", "earliest":
	default:
		return nil, fmt.Errorf("start_from %q: want latest or earliest", c.StartFrom)
	}
	events := map[string]bool{}
	if len(c.Events) == 0 {
		c.Events = []string{"created", "updated"}
	}
	for _, e := range c.Events {
		switch e {
		case "created", "updated", "deleted":
			events[e] = true
		default:
			return nil, fmt.Errorf("events: %q is not created, updated or deleted", e)
		}
	}
	poll := c.PollInterval.D()
	if poll <= 0 {
		poll = 30 * time.Second
	}
	lookup := minio.BucketLookupPath
	if c.PathStyle != nil && !*c.PathStyle {
		lookup = minio.BucketLookupDNS
	}
	client, err := minio.New(endpoint, &minio.Options{
		Creds:        credentials.NewStaticV4(c.AccessKey, c.SecretKey, ""),
		Secure:       secure,
		Region:       c.Region,
		BucketLookup: lookup,
	})
	if err != nil {
		return nil, fmt.Errorf("endpoint: %w", err)
	}
	return &Source{
		cfg:    c,
		host:   endpoint,
		poll:   poll,
		events: events,
		store:  &minioStore{c: client, bucket: c.Bucket, prefix: c.Prefix},
	}, nil
}

// Name is "s3 <bucket>/<prefix>@<endpoint>".
func (s *Source) Name() string { return "s3 " + s.cfg.Bucket + "/" + s.cfg.Prefix + "@" + s.host }

// snapshot is the source's view of the bucket: the state of every object whose last change
// settled.
type snapshot struct {
	mu      sync.Mutex
	objects map[string]object
	dirty   bool // changed since the last checkpoint
}

// change is one difference between the snapshot and a listing.
type change struct {
	key   string
	event string // created, updated, deleted
	obj   object // the new state; deleted: the state before
}

func (sn *snapshot) apply(c change) {
	sn.mu.Lock()
	defer sn.mu.Unlock()
	if c.event == "deleted" {
		delete(sn.objects, c.key)
	} else {
		sn.objects[c.key] = c.obj
	}
	sn.dirty = true
}

// diff lists the changes from the snapshot to listing, sorted by key.
func (sn *snapshot) diff(listing map[string]object) []change {
	sn.mu.Lock()
	defer sn.mu.Unlock()
	var out []change
	for key, obj := range listing {
		old, ok := sn.objects[key]
		switch {
		case !ok:
			out = append(out, change{key: key, event: "created", obj: obj})
		case old.ETag != obj.ETag:
			out = append(out, change{key: key, event: "updated", obj: obj})
		}
	}
	for key, old := range sn.objects {
		if _, ok := listing[key]; !ok {
			out = append(out, change{key: key, event: "deleted", obj: old})
		}
	}
	sort.Slice(out, func(i, j int) bool { return out[i].key < out[j].key })
	return out
}

// checkpoint is the JSON stored at checkpoint_key.
type checkpoint struct {
	Version int               `json:"version"`
	Bucket  string            `json:"bucket"`
	Prefix  string            `json:"prefix"`
	Objects map[string]object `json:"objects"`
}

// Run polls until ctx ends and returns after the deliveries in flight returned. A checkpoint
// that cannot be read is returned as an error (the gateway retries), so the source never starts
// from a wrong baseline.
func (s *Source) Run(ctx context.Context, emit func(context.Context, source.Event) error) error {
	snap, baselined, err := s.load(ctx)
	if err != nil {
		if ctx.Err() != nil {
			return nil
		}
		return err
	}
	win := wait.NewWindow(2 * source.Concurrency(ctx))
	nudges := source.Nudges(ctx)
	for ctx.Err() == nil {
		s.pollOnce(ctx, emit, snap, win, &baselined)
		t := time.NewTimer(s.poll)
		select {
		case <-ctx.Done():
		case <-t.C:
		case <-nudges:
		}
		t.Stop()
	}
	return nil
}

// load reads the checkpoint (when checkpoint_key is set) and reports whether the snapshot is a
// baseline to compare with: a checkpoint, or the empty bucket of start_from earliest.
func (s *Source) load(ctx context.Context) (*snapshot, bool, error) {
	snap := &snapshot{objects: map[string]object{}}
	if s.cfg.CheckpointKey != "" {
		data, found, err := s.store.get(ctx, s.cfg.CheckpointKey)
		if err != nil {
			return nil, false, fmt.Errorf("read checkpoint %s: %w", s.cfg.CheckpointKey, err)
		}
		if found {
			var cp checkpoint
			if err := json.Unmarshal(data, &cp); err != nil {
				return nil, false, fmt.Errorf("checkpoint %s: %w", s.cfg.CheckpointKey, err)
			}
			if cp.Objects != nil {
				snap.objects = cp.Objects
			}
			log.Printf("%s: resuming from checkpoint %s (%d objects)", s.Name(), s.cfg.CheckpointKey, len(snap.objects))
			return snap, true, nil
		}
	}
	return snap, s.cfg.StartFrom == "earliest", nil
}

// pollOnce lists the prefix, delivers the changes and writes the checkpoint.
func (s *Source) pollOnce(ctx context.Context, emit func(context.Context, source.Event) error, snap *snapshot, win *wait.Window, baselined *bool) {
	listing, err := s.store.list(ctx)
	if err != nil {
		if ctx.Err() == nil {
			log.Printf("%s: listing failed, retry in %s: %v", s.Name(), s.poll, err)
		}
		return
	}
	if s.cfg.CheckpointKey != "" {
		delete(listing, s.cfg.CheckpointKey)
	}
	if !*baselined {
		snap.mu.Lock()
		snap.objects, snap.dirty = listing, true
		snap.mu.Unlock()
		*baselined = true
		log.Printf("%s: baseline of %d objects, reporting changes from now on", s.Name(), len(listing))
		s.save(ctx, snap)
		return
	}

	detected := time.Now().UTC()
	var wg sync.WaitGroup
	for _, c := range snap.diff(listing) {
		if !s.events[c.event] {
			snap.apply(c) // not reported: the snapshot follows the bucket
			continue
		}
		for win.Free() == 0 && ctx.Err() == nil {
			win.WaitFree(ctx, time.Second)
		}
		if ctx.Err() != nil {
			break
		}
		win.Take(1)
		wg.Add(1)
		go func() {
			defer wg.Done()
			defer win.Done()
			if source.Settled(emit(ctx, s.event(c, detected))) {
				snap.apply(c)
			}
		}()
	}
	wg.Wait()
	s.save(context.WithoutCancel(ctx), snap)
}

// save writes the snapshot to checkpoint_key when it changed since the last write; a failed
// write is retried after the next poll.
func (s *Source) save(ctx context.Context, snap *snapshot) {
	snap.mu.Lock()
	if !snap.dirty {
		snap.mu.Unlock()
		return
	}
	if s.cfg.CheckpointKey == "" {
		snap.dirty = false
		snap.mu.Unlock()
		return
	}
	data, err := json.Marshal(checkpoint{Version: 1, Bucket: s.cfg.Bucket, Prefix: s.cfg.Prefix, Objects: snap.objects})
	snap.dirty = false
	snap.mu.Unlock()
	if err != nil {
		log.Printf("%s: checkpoint: %v", s.Name(), err)
		return
	}
	pctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	if err := s.store.put(pctx, s.cfg.CheckpointKey, data); err != nil {
		log.Printf("%s: writing checkpoint %s failed (retried after the next poll): %v", s.Name(), s.cfg.CheckpointKey, err)
		snap.mu.Lock()
		snap.dirty = true
		snap.mu.Unlock()
	}
}

// eventData is the data of an object event.
type eventData struct {
	Bucket       string `json:"bucket"`
	Key          string `json:"key"`
	ETag         string `json:"etag"`
	Size         int64  `json:"size"`
	LastModified string `json:"last_modified"`
	Event        string `json:"event"`
}

// event maps a change to the event to deliver; detected is the time of the poll.
func (s *Source) event(c change, detected time.Time) source.Event {
	data, _ := json.Marshal(eventData{
		Bucket:       s.cfg.Bucket,
		Key:          c.key,
		ETag:         c.obj.ETag,
		Size:         c.obj.Size,
		LastModified: c.obj.LastModified.UTC().Format(time.RFC3339Nano),
		Event:        c.event,
	})
	ev := source.Event{
		ID:              s.cfg.Bucket + "/" + c.key + "/" + c.obj.ETag,
		Type:            "dev.runr.s3.object." + c.event,
		Source:          "/s3/" + s.cfg.Bucket,
		Subject:         c.key,
		Time:            c.obj.LastModified,
		DataContentType: "application/json",
		Data:            data,
	}
	if c.event == "deleted" {
		ev.ID = s.cfg.Bucket + "/" + c.key + "/deleted/" + c.obj.ETag
		ev.Time = detected
	}
	return ev
}

// minioStore is the store of a bucket prefix, through minio-go.
type minioStore struct {
	c      *minio.Client
	bucket string
	prefix string
}

func (m *minioStore) list(ctx context.Context) (map[string]object, error) {
	lctx, cancel := context.WithCancel(ctx) // stops the listing goroutine on an early return
	defer cancel()
	out := map[string]object{}
	for obj := range m.c.ListObjects(lctx, m.bucket, minio.ListObjectsOptions{Prefix: m.prefix, Recursive: true}) {
		if obj.Err != nil {
			return nil, obj.Err
		}
		if strings.HasSuffix(obj.Key, "/") {
			continue // a folder marker
		}
		out[obj.Key] = object{ETag: obj.ETag, Size: obj.Size, LastModified: obj.LastModified.UTC()}
	}
	if ctx.Err() != nil {
		return nil, ctx.Err()
	}
	return out, nil
}

// maxCheckpoint bounds the checkpoint object the source reads.
const maxCheckpoint = 512 << 20

func (m *minioStore) get(ctx context.Context, key string) ([]byte, bool, error) {
	obj, err := m.c.GetObject(ctx, m.bucket, key, minio.GetObjectOptions{})
	if err == nil {
		defer obj.Close()
		var data []byte
		data, err = io.ReadAll(io.LimitReader(obj, maxCheckpoint))
		if err == nil {
			return data, true, nil
		}
	}
	if minio.ToErrorResponse(err).Code == minio.NoSuchKey {
		return nil, false, nil
	}
	return nil, false, err
}

func (m *minioStore) put(ctx context.Context, key string, data []byte) error {
	_, err := m.c.PutObject(ctx, m.bucket, key, bytes.NewReader(data), int64(len(data)), minio.PutObjectOptions{ContentType: "application/json"})
	return err
}
