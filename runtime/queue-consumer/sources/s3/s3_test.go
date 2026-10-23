package s3

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"sort"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/minio/minio-go/v7"
	"github.com/minio/minio-go/v7/pkg/credentials"

	"github.com/brf-tech/runr/runtime/queue-consumer/internal/wait"
	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

func TestConfig(t *testing.T) {
	for name, tc := range map[string]struct{ cfg, wantErr string }{
		"no endpoint":  {`{"bucket": "b"}`, "endpoint"},
		"bad endpoint": {`{"endpoint": "ftp://h", "bucket": "b"}`, "endpoint"},
		"no bucket":    {`{"endpoint": "s3.example.com"}`, "bucket"},
		"half creds":   {`{"endpoint": "s3.example.com", "bucket": "b", "access_key": "a"}`, "access_key and secret_key"},
		"start_from":   {`{"endpoint": "s3.example.com", "bucket": "b", "start_from": "now"}`, "start_from"},
		"events":       {`{"endpoint": "s3.example.com", "bucket": "b", "events": ["copied"]}`, "events"},
	} {
		t.Run(name, func(t *testing.T) {
			_, err := New(json.RawMessage(tc.cfg))
			if err == nil || !strings.Contains(err.Error(), tc.wantErr) {
				t.Fatalf("New(%s) = %v, want an error naming %q", tc.cfg, err, tc.wantErr)
			}
		})
	}
	s, err := New(json.RawMessage(`{"endpoint": "https://s3.example.com:9000", "bucket": "uploads", "prefix": "in/", "access_key": "a", "secret_key": "hunter2"}`))
	if err != nil {
		t.Fatal(err)
	}
	src := s.(*Source)
	if src.poll != 30*time.Second || !src.events["created"] || !src.events["updated"] || src.events["deleted"] || src.cfg.StartFrom != "latest" {
		t.Errorf("defaults %+v poll %s events %v", src.cfg, src.poll, src.events)
	}
	if src.Name() != "s3 uploads/in/@s3.example.com:9000" || strings.Contains(src.Name(), "hunter2") {
		t.Errorf("Name %q", src.Name())
	}
}

// fakeStore is a bucket in memory.
type fakeStore struct {
	mu      sync.Mutex
	objects map[string]object
	data    map[string][]byte
	listErr error
	lists   int
}

func newFakeStore() *fakeStore {
	return &fakeStore{objects: map[string]object{}, data: map[string][]byte{}}
}

func (f *fakeStore) set(key, etag string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.objects[key] = object{ETag: etag, Size: int64(len(etag)), LastModified: time.Date(2026, 10, 21, 10, 0, 0, 0, time.UTC)}
}

func (f *fakeStore) remove(key string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	delete(f.objects, key)
}

func (f *fakeStore) list(context.Context) (map[string]object, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.lists++
	if f.listErr != nil {
		return nil, f.listErr
	}
	out := make(map[string]object, len(f.objects))
	for k, v := range f.objects {
		out[k] = v
	}
	return out, nil
}

func (f *fakeStore) get(_ context.Context, key string) ([]byte, bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	d, ok := f.data[key]
	return d, ok, nil
}

func (f *fakeStore) put(_ context.Context, key string, data []byte) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.data[key] = data
	f.objects[key] = object{ETag: fmt.Sprintf("cp-%d", len(data)), Size: int64(len(data))}
	return nil
}

func testSource(t *testing.T, st store, extra string) *Source {
	t.Helper()
	s, err := New(json.RawMessage(`{"endpoint": "s3.example.com", "bucket": "b"` + extra + `}`))
	if err != nil {
		t.Fatal(err)
	}
	src := s.(*Source)
	src.store = st
	return src
}

// poller runs polls by hand.
type poller struct {
	s         *Source
	snap      *snapshot
	baselined bool
	win       *wait.Window
}

func newPoller(t *testing.T, s *Source) *poller {
	t.Helper()
	snap, baselined, err := s.load(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	return &poller{s: s, snap: snap, baselined: baselined, win: wait.NewWindow(4)}
}

// poll runs one poll with emit and returns the ids it was handed, sorted.
func (p *poller) poll(ctx context.Context, emit func(context.Context, source.Event) error) []string {
	var mu sync.Mutex
	var ids []string
	p.s.pollOnce(ctx, func(ctx context.Context, ev source.Event) error {
		mu.Lock()
		ids = append(ids, ev.Type[len("dev.runr.s3.object."):]+" "+ev.ID)
		mu.Unlock()
		return emit(ctx, ev)
	}, p.snap, p.win, &p.baselined)
	sort.Strings(ids)
	return ids
}

func delivered(context.Context, source.Event) error { return nil }

func TestLatestBaselineThenChanges(t *testing.T) {
	st := newFakeStore()
	st.set("a.txt", "e1")
	p := newPoller(t, testSource(t, st, `, "events": ["created", "updated", "deleted"]`))
	ctx := context.Background()

	if ids := p.poll(ctx, delivered); len(ids) != 0 {
		t.Fatalf("baseline poll reported %v", ids)
	}
	st.set("a.txt", "e2")
	st.set("b.txt", "e3")
	if ids := p.poll(ctx, delivered); fmt.Sprint(ids) != "[created b/b.txt/e3 updated b/a.txt/e2]" {
		t.Fatalf("ids %v", ids)
	}
	st.remove("a.txt")
	if ids := p.poll(ctx, delivered); fmt.Sprint(ids) != "[deleted b/a.txt/deleted/e2]" {
		t.Fatalf("ids %v", ids)
	}
	if ids := p.poll(ctx, delivered); len(ids) != 0 {
		t.Fatalf("an unchanged bucket reported %v", ids)
	}
}

func TestEarliestReportsExistingObjects(t *testing.T) {
	st := newFakeStore()
	st.set("a.txt", "e1")
	st.set("dir/b.txt", "e2")
	p := newPoller(t, testSource(t, st, `, "start_from": "earliest"`))
	if ids := p.poll(context.Background(), delivered); fmt.Sprint(ids) != "[created b/a.txt/e1 created b/dir/b.txt/e2]" {
		t.Fatalf("ids %v", ids)
	}
}

func TestUnsettledChangeIsReportedAgain(t *testing.T) {
	st := newFakeStore()
	p := newPoller(t, testSource(t, st, `, "start_from": "earliest"`))
	st.set("a.txt", "e1")
	st.set("b.txt", "e2")

	ids := p.poll(context.Background(), func(ctx context.Context, ev source.Event) error {
		if ev.Subject == "b.txt" {
			return context.Canceled // interrupted by a shutdown: not settled
		}
		return fmt.Errorf("%w after 6 attempts", source.ErrDeadLettered)
	})
	if len(ids) != 2 {
		t.Fatalf("ids %v", ids)
	}
	ids = p.poll(context.Background(), delivered)
	if fmt.Sprint(ids) != "[created b/b.txt/e2]" {
		t.Fatalf("ids %v, want only the unsettled change again, with the same id", ids)
	}
}

func TestEventsFilterKeepsTheSnapshotCurrent(t *testing.T) {
	st := newFakeStore()
	st.set("a.txt", "e1")
	p := newPoller(t, testSource(t, st, `, "events": ["deleted"]`))
	ctx := context.Background()
	p.poll(ctx, delivered) // baseline
	st.set("b.txt", "e2")
	if ids := p.poll(ctx, delivered); len(ids) != 0 {
		t.Fatalf("a creation was reported though events is [deleted]: %v", ids)
	}
	st.remove("b.txt")
	if ids := p.poll(ctx, delivered); fmt.Sprint(ids) != "[deleted b/b.txt/deleted/e2]" {
		t.Fatalf("ids %v", ids)
	}
}

func TestFailedListingReportsNothing(t *testing.T) {
	st := newFakeStore()
	st.set("a.txt", "e1")
	p := newPoller(t, testSource(t, st, `, "events": ["created", "updated", "deleted"]`))
	ctx := context.Background()
	p.poll(ctx, delivered)
	st.mu.Lock()
	st.listErr = errors.New("503 SlowDown")
	st.mu.Unlock()
	if ids := p.poll(ctx, delivered); len(ids) != 0 {
		t.Fatalf("a failed listing reported %v", ids)
	}
}

func TestCheckpointSurvivesARestart(t *testing.T) {
	st := newFakeStore()
	st.set("a.txt", "e1")
	cfg := `, "checkpoint_key": ".runr/t.json", "events": ["created", "updated", "deleted"]`
	p := newPoller(t, testSource(t, st, cfg))
	ctx := context.Background()
	p.poll(ctx, delivered) // baseline, written to the checkpoint
	st.set("b.txt", "e2")
	if ids := p.poll(ctx, delivered); fmt.Sprint(ids) != "[created b/b.txt/e2]" {
		t.Fatalf("ids %v (the checkpoint object must not be reported)", ids)
	}

	// The gateway is down while objects change.
	st.set("c.txt", "e3")
	st.remove("a.txt")
	p2 := newPoller(t, testSource(t, st, cfg))
	if !p2.baselined {
		t.Fatal("a source with a checkpoint must not take a new baseline")
	}
	if ids := p2.poll(ctx, delivered); fmt.Sprint(ids) != "[created b/c.txt/e3 deleted b/a.txt/deleted/e1]" {
		t.Fatalf("ids after restart %v", ids)
	}
	var cp checkpoint
	if err := json.Unmarshal(st.data[".runr/t.json"], &cp); err != nil || len(cp.Objects) != 2 || cp.Objects["c.txt"].ETag != "e3" {
		t.Fatalf("checkpoint %s (%v)", st.data[".runr/t.json"], err)
	}
}

func TestEvent(t *testing.T) {
	s := &Source{cfg: Config{Bucket: "uploads"}}
	lm := time.Date(2026, 10, 21, 10, 0, 0, 0, time.UTC)
	detected := lm.Add(time.Hour)
	ev := s.event(change{key: "in/a.csv", event: "created", obj: object{ETag: "abc", Size: 12, LastModified: lm}}, detected)
	if ev.ID != "uploads/in/a.csv/abc" || ev.Type != "dev.runr.s3.object.created" || ev.Source != "/s3/uploads" || ev.Subject != "in/a.csv" ||
		!ev.Time.Equal(lm) || ev.DataContentType != "application/json" {
		t.Errorf("event %+v", ev)
	}
	if string(ev.Data) != `{"bucket":"uploads","key":"in/a.csv","etag":"abc","size":12,"last_modified":"2026-10-21T10:00:00Z","event":"created"}` {
		t.Errorf("data %s", ev.Data)
	}
	ev = s.event(change{key: "in/a.csv", event: "deleted", obj: object{ETag: "abc", LastModified: lm}}, detected)
	if ev.ID != "uploads/in/a.csv/deleted/abc" || !ev.Time.Equal(detected) || ev.Type != "dev.runr.s3.object.deleted" {
		t.Errorf("deleted event %+v", ev)
	}
}

func TestRunWindowAndNudge(t *testing.T) {
	st := newFakeStore()
	for i := range 6 {
		st.set(fmt.Sprintf("k%d", i), "e")
	}
	s := testSource(t, st, `, "start_from": "earliest", "poll_interval": "1h"`)
	nudge := make(chan struct{}, 1)
	ctx, cancel := context.WithCancel(source.WithNudges(source.WithConcurrency(context.Background(), 1), nudge))
	defer cancel()

	var mu sync.Mutex
	active, peak, total := 0, 0, 0
	emit := func(context.Context, source.Event) error {
		mu.Lock()
		active++
		total++
		peak = max(peak, active)
		mu.Unlock()
		time.Sleep(10 * time.Millisecond)
		mu.Lock()
		active--
		mu.Unlock()
		return nil
	}
	done := make(chan error, 1)
	go func() { done <- s.Run(ctx, emit) }()
	waitFor := func(cond func() bool) {
		deadline := time.Now().Add(5 * time.Second)
		for !cond() && time.Now().Before(deadline) {
			time.Sleep(5 * time.Millisecond)
		}
	}
	waitFor(func() bool { mu.Lock(); defer mu.Unlock(); return total == 6 })
	st.set("new", "e")
	nudge <- struct{}{} // poll now instead of in an hour
	waitFor(func() bool { mu.Lock(); defer mu.Unlock(); return total == 7 })
	cancel()
	if err := <-done; err != nil {
		t.Fatalf("Run = %v", err)
	}
	mu.Lock()
	defer mu.Unlock()
	if total != 7 || peak > 2 {
		t.Fatalf("total %d peak %d, want 7 events and at most 2×concurrency at once", total, peak)
	}
}

// ── Integration: an S3 store with QC_TEST_S3=http://host:9000 QC_TEST_S3_ACCESS_KEY=…
// QC_TEST_S3_SECRET_KEY=… QC_TEST_S3_BUCKET=… go test ./sources/s3 ──

func TestS3Store(t *testing.T) {
	endpoint := os.Getenv("QC_TEST_S3")
	if endpoint == "" {
		t.Skip("QC_TEST_S3 not set")
	}
	ak, sk, bucket := os.Getenv("QC_TEST_S3_ACCESS_KEY"), os.Getenv("QC_TEST_S3_SECRET_KEY"), os.Getenv("QC_TEST_S3_BUCKET")
	prefix := fmt.Sprintf("qc-test-%d/", time.Now().UnixNano())
	raw, _ := json.Marshal(map[string]any{"endpoint": endpoint, "bucket": bucket, "prefix": prefix, "access_key": ak, "secret_key": sk,
		"start_from": "earliest", "poll_interval": "200ms", "events": []string{"created", "updated", "deleted"},
		"checkpoint_key": prefix + ".runr/checkpoint.json"})
	s, err := New(raw)
	if err != nil {
		t.Fatal(err)
	}
	host := strings.TrimPrefix(strings.TrimPrefix(endpoint, "http://"), "https://")
	mc, err := minio.New(host, &minio.Options{Creds: credentials.NewStaticV4(ak, sk, ""), Secure: strings.HasPrefix(endpoint, "https://"), BucketLookup: minio.BucketLookupPath})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()
	defer func() {
		for obj := range mc.ListObjects(context.Background(), bucket, minio.ListObjectsOptions{Prefix: prefix, Recursive: true}) {
			mc.RemoveObject(context.Background(), bucket, obj.Key, minio.RemoveObjectOptions{})
		}
	}()
	put := func(key, body string) {
		if _, err := mc.PutObject(ctx, bucket, prefix+key, bytes.NewReader([]byte(body)), int64(len(body)), minio.PutObjectOptions{}); err != nil {
			t.Fatal(err)
		}
	}
	put("a.txt", "one")

	var mu sync.Mutex
	var got []string
	runCtx, stop := context.WithCancel(ctx)
	done := make(chan error, 1)
	go func() {
		done <- s.Run(runCtx, func(_ context.Context, ev source.Event) error {
			mu.Lock()
			got = append(got, ev.Type+" "+ev.Subject)
			mu.Unlock()
			return nil
		})
	}()
	waitFor := func(n int) {
		for ctx.Err() == nil {
			mu.Lock()
			l := len(got)
			mu.Unlock()
			if l >= n {
				return
			}
			time.Sleep(50 * time.Millisecond)
		}
	}
	waitFor(1)
	put("a.txt", "two")
	waitFor(2)
	mc.RemoveObject(ctx, bucket, prefix+"a.txt", minio.RemoveObjectOptions{})
	waitFor(3)
	stop()
	if err := <-done; err != nil {
		t.Fatalf("Run = %v", err)
	}
	mu.Lock()
	defer mu.Unlock()
	want := []string{"dev.runr.s3.object.created " + prefix + "a.txt", "dev.runr.s3.object.updated " + prefix + "a.txt", "dev.runr.s3.object.deleted " + prefix + "a.txt"}
	if fmt.Sprint(got) != fmt.Sprint(want) {
		t.Fatalf("events %v, want %v", got, want)
	}
}
