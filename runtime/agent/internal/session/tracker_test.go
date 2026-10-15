package session

import (
	"context"
	"sync"
	"testing"
	"time"

	corev1 "k8s.io/api/core/v1"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/runtime"
	"k8s.io/apimachinery/pkg/watch"
	"k8s.io/client-go/kubernetes/fake"
	k8stesting "k8s.io/client-go/testing"
)

const ns = "tenant-ns"

type fakeClock struct {
	mu sync.Mutex
	t  time.Time
}

func (c *fakeClock) Now() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.t
}

func (c *fakeClock) Advance(d time.Duration) {
	c.mu.Lock()
	c.t = c.t.Add(d)
	c.mu.Unlock()
}

// recorder collects emitted events; emit may be called from the tracker goroutine.
type recorder struct{ ch chan map[string]interface{} }

func newRecorder() *recorder { return &recorder{ch: make(chan map[string]interface{}, 64)} }

func (r *recorder) emit(e map[string]interface{}) { r.ch <- e }

func (r *recorder) next(t *testing.T) map[string]interface{} {
	t.Helper()
	select {
	case e := <-r.ch:
		return e
	case <-time.After(2 * time.Second):
		t.Fatal("timed out waiting for an event")
		return nil
	}
}

func (r *recorder) none(t *testing.T) {
	t.Helper()
	select {
	case e := <-r.ch:
		t.Fatalf("unexpected event %v", e)
	case <-time.After(50 * time.Millisecond):
	}
}

func servingPod(name, slug string) *corev1.Pod {
	return &corev1.Pod{ObjectMeta: metav1.ObjectMeta{
		Name:      name,
		Namespace: ns,
		Labels: map[string]string{
			projectLabel: "42",
			serviceLabel: slug,
		},
	}}
}

func newTestTracker() (*Tracker, *fakeClock) {
	clock := &fakeClock{t: time.Date(2026, 1, 1, 12, 0, 0, 0, time.UTC)}
	tr := NewTracker(ns)
	tr.now = clock.Now
	tr.retryDelay = 10 * time.Millisecond
	return tr, clock
}

func TestHandleEventSessionLifecycle(t *testing.T) {
	tr, clock := newTestTracker()
	rec := newRecorder()
	pod := servingPod("shop-00001-deployment-6b8f9c7d4-xk9pq", "shop")

	tr.handleEvent(watch.Event{Type: watch.Added, Object: pod}, rec.emit)
	start := rec.next(t)
	want := map[string]interface{}{
		"type": "session_start", "project_slug": "shop", "project_id": "42",
		"entry_point": "http", "pod": pod.Name, "cold_start": true,
	}
	for k, v := range want {
		if start[k] != v {
			t.Errorf("session_start[%q] = %v, want %v", k, start[k], v)
		}
	}

	// a second ADDED for the same pod (watch replay) must not start another session
	tr.handleEvent(watch.Event{Type: watch.Added, Object: pod}, rec.emit)
	// MODIFIED is not a session boundary
	tr.handleEvent(watch.Event{Type: watch.Modified, Object: pod}, rec.emit)
	rec.none(t)

	clock.Advance(1500 * time.Millisecond)
	tr.handleEvent(watch.Event{Type: watch.Deleted, Object: pod}, rec.emit)
	end := rec.next(t)
	if end["type"] != "session_end" || end["project_slug"] != "shop" || end["pod"] != pod.Name {
		t.Fatalf("unexpected session_end %v", end)
	}
	if end["duration_ms"] != int64(1500) {
		t.Fatalf("duration_ms = %v, want 1500", end["duration_ms"])
	}

	// deleting an untracked pod emits nothing; the session map is empty again
	tr.handleEvent(watch.Event{Type: watch.Deleted, Object: pod}, rec.emit)
	rec.none(t)
	if len(tr.sessions) != 0 {
		t.Fatalf("%d sessions left, want 0", len(tr.sessions))
	}
}

func TestHandleEventIgnoresNonServingObjects(t *testing.T) {
	tr, _ := newTestTracker()
	rec := newRecorder()

	buildPod := &corev1.Pod{ObjectMeta: metav1.ObjectMeta{
		Name:   "build-shop-v3-abcde",
		Labels: map[string]string{projectLabel: "42"},
	}}
	tr.handleEvent(watch.Event{Type: watch.Added, Object: buildPod}, rec.emit)
	tr.handleEvent(watch.Event{Type: watch.Added, Object: &corev1.Event{}}, rec.emit)
	tr.handleEvent(watch.Event{Type: watch.Error, Object: &metav1.Status{}}, rec.emit)
	rec.none(t)
}

func TestProjectSlug(t *testing.T) {
	cases := []struct {
		name string
		pod  *corev1.Pod
		want string
	}{
		{"service label wins", servingPod("x-00001-deployment-a-b", "shop"), "shop"},
		{"pod name fallback", &corev1.Pod{ObjectMeta: metav1.ObjectMeta{Name: "my-api-00004-deployment-7d4f-2xk9p"}}, "my-api"},
		{"project id label is not a slug", &corev1.Pod{ObjectMeta: metav1.ObjectMeta{Name: "worker", Labels: map[string]string{projectLabel: "42"}}}, ""},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := projectSlug(tc.pod); got != tc.want {
				t.Fatalf("projectSlug = %q, want %q", got, tc.want)
			}
		})
	}
}

func TestReconcileStartsNewAndEndsVanishedSessions(t *testing.T) {
	tr, clock := newTestTracker()
	rec := newRecorder()

	gone := servingPod("old-00001-deployment-a-bcdfg", "old")
	kept := servingPod("kept-00001-deployment-a-bcdfg", "kept")
	tr.reconcile([]corev1.Pod{*gone, *kept}, rec.emit)
	rec.next(t)
	rec.next(t)

	clock.Advance(time.Second)
	fresh := servingPod("new-00001-deployment-a-bcdfg", "new")
	tr.reconcile([]corev1.Pod{*kept, *fresh}, rec.emit)

	got := map[string]map[string]interface{}{}
	for i := 0; i < 2; i++ {
		e := rec.next(t)
		got[e["type"].(string)+":"+e["pod"].(string)] = e
	}
	rec.none(t) // the kept pod's session is not restarted

	if _, ok := got["session_start:"+fresh.Name]; !ok {
		t.Errorf("no session_start for the new pod: %v", got)
	}
	end, ok := got["session_end:"+gone.Name]
	if !ok {
		t.Fatalf("no session_end for the vanished pod: %v", got)
	}
	if end["duration_ms"] != int64(1000) {
		t.Errorf("duration_ms = %v, want 1000", end["duration_ms"])
	}
}

// watchHarness hands every pod watch the tracker opens to the test.
func watchHarness(objects ...runtime.Object) (*fake.Clientset, chan *watch.RaceFreeFakeWatcher) {
	cs := fake.NewSimpleClientset(objects...)
	watches := make(chan *watch.RaceFreeFakeWatcher, 4)
	cs.PrependWatchReactor("pods", func(k8stesting.Action) (bool, watch.Interface, error) {
		w := watch.NewRaceFreeFake()
		watches <- w
		return true, w, nil
	})
	return cs, watches
}

func nextWatch(t *testing.T, watches chan *watch.RaceFreeFakeWatcher) *watch.RaceFreeFakeWatcher {
	t.Helper()
	select {
	case w := <-watches:
		return w
	case <-time.After(2 * time.Second):
		t.Fatal("tracker did not open a watch")
		return nil
	}
}

func TestRunWithClientListsWatchesAndRewatches(t *testing.T) {
	running := servingPod("shop-00001-deployment-6b8f9c7d4-xk9pq", "shop")
	cs, watches := watchHarness(running)
	tr, _ := newTestTracker()
	rec := newRecorder()

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		tr.RunWithClient(ctx, cs, rec.emit)
		close(done)
	}()

	// the initial list starts a session for the pod that is already running
	if e := rec.next(t); e["type"] != "session_start" || e["pod"] != running.Name {
		t.Fatalf("unexpected first event %v", e)
	}
	w := nextWatch(t, watches)

	// list and watch are both scoped to the namespace and the project label
	verbs := map[string]bool{}
	for _, a := range cs.Actions() {
		var sel string
		switch act := a.(type) {
		case k8stesting.ListAction:
			sel = act.GetListRestrictions().Labels.String()
		case k8stesting.WatchAction:
			sel = act.GetWatchRestrictions().Labels.String()
		default:
			continue
		}
		verbs[a.GetVerb()] = true
		if a.GetNamespace() != ns || sel != projectLabel {
			t.Errorf("%s in namespace %q with selector %q", a.GetVerb(), a.GetNamespace(), sel)
		}
	}
	if !verbs["list"] || !verbs["watch"] {
		t.Fatalf("actions %v, want a list and a watch", verbs)
	}

	scaled := servingPod("shop-00001-deployment-6b8f9c7d4-bcdfg", "shop")
	w.Add(scaled)
	if e := rec.next(t); e["type"] != "session_start" || e["pod"] != scaled.Name {
		t.Fatalf("unexpected event %v", e)
	}

	// the watch ends (API server timeout); meanwhile the scaled pod was deleted
	if err := cs.Tracker().Add(scaled); err != nil {
		t.Fatal(err)
	}
	if err := cs.Tracker().Delete(corev1.SchemeGroupVersion.WithResource("pods"), ns, running.Name); err != nil {
		t.Fatal(err)
	}
	w.Stop()

	// after the re-list: the deleted pod's session ends, the still-running pod is not restarted
	if e := rec.next(t); e["type"] != "session_end" || e["pod"] != running.Name {
		t.Fatalf("unexpected event after re-list %v", e)
	}
	w2 := nextWatch(t, watches)
	rec.none(t)

	w2.Delete(scaled)
	if e := rec.next(t); e["type"] != "session_end" || e["pod"] != scaled.Name {
		t.Fatalf("unexpected event %v", e)
	}

	cancel()
	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("RunWithClient did not return after cancel")
	}
}

func TestRunWithClientRetriesWhenListFails(t *testing.T) {
	cs, watches := watchHarness()
	var mu sync.Mutex
	fails := 2
	cs.PrependReactor("list", "pods", func(k8stesting.Action) (bool, runtime.Object, error) {
		mu.Lock()
		defer mu.Unlock()
		if fails > 0 {
			fails--
			return true, nil, context.DeadlineExceeded
		}
		return false, nil, nil
	})
	tr, _ := newTestTracker()
	rec := newRecorder()

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go tr.RunWithClient(ctx, cs, rec.emit)

	nextWatch(t, watches) // reached after two failed lists, 10ms apart
	mu.Lock()
	defer mu.Unlock()
	if fails != 0 {
		t.Fatalf("watch opened with %d list failures left", fails)
	}
}

func TestRunWithClientStopsOnCancelledContext(t *testing.T) {
	cs, _ := watchHarness()
	tr, _ := newTestTracker()
	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	done := make(chan struct{})
	go func() {
		tr.RunWithClient(ctx, cs, func(map[string]interface{}) {})
		close(done)
	}()
	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("RunWithClient did not return")
	}
}
