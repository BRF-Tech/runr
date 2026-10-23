package session

import (
	"context"
	"fmt"
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

// cronRunPod is the pod of a Job the project's CronJob (nightly-trigger) started.
func cronRunPod(name, job string, phase corev1.PodPhase) *corev1.Pod {
	return &corev1.Pod{
		ObjectMeta: metav1.ObjectMeta{
			Name:      name,
			Namespace: ns,
			Labels: map[string]string{
				projectLabel:       "42",
				jobNameLabel:       job,
				legacyJobNameLabel: job,
			},
		},
		Status: corev1.PodStatus{Phase: phase},
	}
}

func withPhase(pod *corev1.Pod, phase corev1.PodPhase) *corev1.Pod {
	p := pod.DeepCopy()
	p.Status.Phase = phase
	return p
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
		Labels: map[string]string{projectLabel: "42", buildLabel: "true", jobNameLabel: "build-shop-v3"},
	}}
	consumerPod := &corev1.Pod{ObjectMeta: metav1.ObjectMeta{
		Name:            "shop-consumer-6b8f9c7d4-xk9pq",
		Labels:          map[string]string{projectLabel: "42", "runr/component": "queue-consumer"},
		OwnerReferences: []metav1.OwnerReference{{Kind: "ReplicaSet", Name: "shop-consumer-6b8f9c7d4"}},
	}}
	tr.handleEvent(watch.Event{Type: watch.Added, Object: buildPod}, rec.emit)
	tr.handleEvent(watch.Event{Type: watch.Added, Object: consumerPod}, rec.emit)
	tr.handleEvent(watch.Event{Type: watch.Modified, Object: withPhase(buildPod, corev1.PodSucceeded)}, rec.emit)
	tr.handleEvent(watch.Event{Type: watch.Added, Object: &corev1.Event{}}, rec.emit)
	tr.handleEvent(watch.Event{Type: watch.Error, Object: &metav1.Status{}}, rec.emit)
	rec.none(t)
}

func TestScheduleRunSessionEndsWhenItsPodFinishes(t *testing.T) {
	tr, clock := newTestTracker()
	rec := newRecorder()
	pod := cronRunPod("nightly-trigger-29345678-x7k2p", "nightly-trigger-29345678", corev1.PodPending)

	tr.handleEvent(watch.Event{Type: watch.Added, Object: pod}, rec.emit)
	start := rec.next(t)
	want := map[string]interface{}{
		"type": "session_start", "project_slug": "nightly", "project_id": "42",
		"entry_point": "schedule", "pod": pod.Name, "cold_start": true,
	}
	for k, v := range want {
		if start[k] != v {
			t.Errorf("session_start[%q] = %v, want %v", k, start[k], v)
		}
	}

	// running is no boundary
	clock.Advance(20 * time.Second)
	tr.handleEvent(watch.Event{Type: watch.Modified, Object: withPhase(pod, corev1.PodRunning)}, rec.emit)
	rec.none(t)

	// the run ends when its pod succeeds, not when the CronJob's history limit deletes it
	clock.Advance(40 * time.Second)
	done := withPhase(pod, corev1.PodSucceeded)
	tr.handleEvent(watch.Event{Type: watch.Modified, Object: done}, rec.emit)
	end := rec.next(t)
	if end["type"] != "session_end" || end["project_slug"] != "nightly" || end["pod"] != pod.Name {
		t.Fatalf("unexpected session_end %v", end)
	}
	if end["duration_ms"] != int64(60000) {
		t.Fatalf("duration_ms = %v, want 60000", end["duration_ms"])
	}

	clock.Advance(time.Hour)
	tr.handleEvent(watch.Event{Type: watch.Modified, Object: done}, rec.emit)
	tr.handleEvent(watch.Event{Type: watch.Deleted, Object: done}, rec.emit)
	rec.none(t)
	if len(tr.sessions) != 0 {
		t.Fatalf("%d sessions left, want 0", len(tr.sessions))
	}
}

func TestAFailedPodEndsItsSession(t *testing.T) {
	tr, clock := newTestTracker()
	rec := newRecorder()
	run := cronRunPod("nightly-trigger-29345678-x7k2p", "nightly-trigger-29345678", corev1.PodRunning)
	serving := servingPod("shop-00001-deployment-6b8f9c7d4-xk9pq", "shop")

	tr.handleEvent(watch.Event{Type: watch.Added, Object: run}, rec.emit)
	tr.handleEvent(watch.Event{Type: watch.Added, Object: serving}, rec.emit)
	rec.next(t)
	rec.next(t)

	clock.Advance(3 * time.Second)
	// a run that failed, a serving pod that was evicted
	tr.handleEvent(watch.Event{Type: watch.Modified, Object: withPhase(run, corev1.PodFailed)}, rec.emit)
	tr.handleEvent(watch.Event{Type: watch.Modified, Object: withPhase(serving, corev1.PodFailed)}, rec.emit)
	for _, name := range []string{run.Name, serving.Name} {
		e := rec.next(t)
		if e["type"] != "session_end" || e["pod"] != name || e["duration_ms"] != int64(3000) {
			t.Fatalf("unexpected event %v, want the end of %s after 3000 ms", e, name)
		}
	}
	rec.none(t)
}

func TestFinishedPodsStartNoSession(t *testing.T) {
	tr, _ := newTestTracker()
	rec := newRecorder()
	done := cronRunPod("nightly-trigger-29345678-x7k2p", "nightly-trigger-29345678", corev1.PodSucceeded)

	// a replayed ADDED of a run that finished, and a re-list that still shows it
	tr.handleEvent(watch.Event{Type: watch.Added, Object: done}, rec.emit)
	tr.reconcile(projectLabel, []corev1.Pod{*done}, rec.emit)
	rec.none(t)
	if len(tr.sessions) != 0 {
		t.Fatalf("%d sessions, want 0", len(tr.sessions))
	}
}

func TestReconcileEndsARunThatFinishedWhileTheWatchWasDown(t *testing.T) {
	tr, clock := newTestTracker()
	rec := newRecorder()
	run := cronRunPod("nightly-trigger-29345678-x7k2p", "nightly-trigger-29345678", corev1.PodRunning)

	tr.reconcile(projectLabel, []corev1.Pod{*run}, rec.emit)
	if e := rec.next(t); e["type"] != "session_start" || e["entry_point"] != "schedule" {
		t.Fatalf("unexpected event %v", e)
	}

	clock.Advance(5 * time.Second)
	tr.reconcile(projectLabel, []corev1.Pod{*withPhase(run, corev1.PodSucceeded)}, rec.emit)
	e := rec.next(t)
	if e["type"] != "session_end" || e["pod"] != run.Name || e["duration_ms"] != int64(5000) {
		t.Fatalf("unexpected event %v", e)
	}
	rec.none(t)
}

func TestScheduleSlugOfAPod(t *testing.T) {
	job := "nightly-trigger-29345678"
	cases := []struct {
		name string
		pod  *corev1.Pod
		want string
	}{
		{"job name label", cronRunPod("nightly-trigger-29345678-x7k2p", job, corev1.PodRunning), "nightly"},
		{"legacy job name label", &corev1.Pod{ObjectMeta: metav1.ObjectMeta{Name: "p", Labels: map[string]string{projectLabel: "42", legacyJobNameLabel: job}}}, "nightly"},
		{"owner reference", &corev1.Pod{ObjectMeta: metav1.ObjectMeta{Name: "p", Labels: map[string]string{projectLabel: "42"},
			OwnerReferences: []metav1.OwnerReference{{Kind: "Job", Name: job}}}}, "nightly"},
		{"build job", &corev1.Pod{ObjectMeta: metav1.ObjectMeta{Name: "p", Labels: map[string]string{projectLabel: "42", buildLabel: "true", jobNameLabel: job}}}, ""},
		{"another job", &corev1.Pod{ObjectMeta: metav1.ObjectMeta{Name: "p", Labels: map[string]string{projectLabel: "42", jobNameLabel: "nightly-backfill"}}}, ""},
		{"no job", &corev1.Pod{ObjectMeta: metav1.ObjectMeta{Name: "p", Labels: map[string]string{projectLabel: "42"},
			OwnerReferences: []metav1.OwnerReference{{Kind: "ReplicaSet", Name: "nightly-trigger-29345678"}}}}, ""},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := scheduleSlug(tc.pod); got != tc.want {
				t.Fatalf("scheduleSlug = %q, want %q", got, tc.want)
			}
		})
	}
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
	tr.reconcile(projectLabel, []corev1.Pod{*gone, *kept}, rec.emit)
	rec.next(t)
	rec.next(t)

	clock.Advance(time.Second)
	fresh := servingPod("new-00001-deployment-a-bcdfg", "new")
	tr.reconcile(projectLabel, []corev1.Pod{*kept, *fresh}, rec.emit)

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

// watchSet holds the pod watches the tracker opens, per label selector.
type watchSet map[string]chan *watch.RaceFreeFakeWatcher

// watchHarness hands every pod watch the tracker opens to the test, by its label selector.
func watchHarness(objects ...runtime.Object) (*fake.Clientset, watchSet) {
	cs := fake.NewSimpleClientset(objects...)
	watches := watchSet{}
	for _, s := range selectors {
		watches[s] = make(chan *watch.RaceFreeFakeWatcher, 4)
	}
	cs.PrependWatchReactor("pods", func(a k8stesting.Action) (bool, watch.Interface, error) {
		w := watch.NewRaceFreeFake()
		watches[a.(k8stesting.WatchAction).GetWatchRestrictions().Labels.String()] <- w
		return true, w, nil
	})
	return cs, watches
}

func nextWatch(t *testing.T, watches watchSet, selector string) *watch.RaceFreeFakeWatcher {
	t.Helper()
	select {
	case w := <-watches[selector]:
		return w
	case <-time.After(2 * time.Second):
		t.Fatalf("tracker did not open a watch of %s", selector)
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
	w := nextWatch(t, watches, projectLabel)
	nextWatch(t, watches, previewLabel)
	nextWatch(t, watches, environmentLabel)

	// lists and watches are scoped to the namespace: one of the project pods, one of the preview
	// pods, one of the environments' pods
	seen := map[string]bool{}
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
		seen[a.GetVerb()+" "+sel] = true
		if a.GetNamespace() != ns || (sel != projectLabel && sel != previewLabel && sel != environmentLabel) {
			t.Errorf("%s in namespace %q with selector %q", a.GetVerb(), a.GetNamespace(), sel)
		}
	}
	for _, want := range []string{"list " + projectLabel, "watch " + projectLabel, "list " + previewLabel, "watch " + previewLabel, "list " + environmentLabel, "watch " + environmentLabel} {
		if !seen[want] {
			t.Fatalf("actions %v, want %q", seen, want)
		}
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
	w2 := nextWatch(t, watches, projectLabel)
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

	nextWatch(t, watches, projectLabel) // reached after two failed lists, 10ms apart
	mu.Lock()
	defer mu.Unlock()
	if fails != 0 {
		t.Fatalf("watch opened with %d list failures left", fails)
	}
}

// previewPod is a pod of the preview environment <slug>-pr-<number> of project 42.
func previewPod(name, service, number string) *corev1.Pod {
	return &corev1.Pod{ObjectMeta: metav1.ObjectMeta{
		Name:      name,
		Namespace: ns,
		Labels: map[string]string{
			previewLabel:        "42",
			previewServiceLabel: service,
			previewNumberLabel:  number,
			serviceLabel:        service,
		},
	}}
}

func TestPreviewPodsAreTrackedAsSessionsOfTheirPreview(t *testing.T) {
	tr, clock := newTestTracker()
	rec := newRecorder()
	pod := previewPod("shop-pr-12-00001-deployment-6b8f9c7d4-xk9pq", "shop-pr-12", "12")

	tr.handleEvent(watch.Event{Type: watch.Added, Object: pod}, rec.emit)
	start := rec.next(t)
	want := map[string]interface{}{
		"type": "session_start", "project_slug": "shop", "project_id": "42",
		"entry_point": "http", "pod": pod.Name, "preview": "shop-pr-12",
	}
	for k, v := range want {
		if start[k] != v {
			t.Errorf("session_start[%q] = %v, want %v", k, start[k], v)
		}
	}

	tr.heartbeat(rec.emit)
	entry := rec.next(t)["sessions"].([]map[string]interface{})[0]
	if entry["preview"] != "shop-pr-12" || entry["project_slug"] != "shop" || entry["pod"] != pod.Name {
		t.Fatalf("heartbeat entry %v", entry)
	}

	clock.Advance(2 * time.Second)
	tr.handleEvent(watch.Event{Type: watch.Deleted, Object: pod}, rec.emit)
	end := rec.next(t)
	if end["type"] != "session_end" || end["project_slug"] != "shop" || end["pod"] != pod.Name || end["duration_ms"] != int64(2000) {
		t.Fatalf("unexpected session_end %v", end)
	}

	// a serving pod's session_start carries no preview
	tr.handleEvent(watch.Event{Type: watch.Added, Object: servingPod("shop-00001-deployment-6b8f9c7d4-bcdfg", "shop")}, rec.emit)
	if _, ok := rec.next(t)["preview"]; ok {
		t.Fatal("a serving pod's session names a preview")
	}

	// a preview pod whose Service does not follow <slug>-pr-<number> is not tracked
	tr.handleEvent(watch.Event{Type: watch.Added, Object: previewPod("odd-00001-deployment-a-b", "odd", "")}, rec.emit)
	rec.none(t)
}

// A preview of a project with a long slug: its Service name <slug>-pr-<number> keeps 63
// characters, so the slug lost its end — the tenant's slug included. The events carry the
// project's ID and the pod's tenant, which the API finds the project by.
func TestAShortenedPreviewNameTravelsWithItsProjectIDAndTenant(t *testing.T) {
	tr, clock := newTestTracker()
	rec := newRecorder()
	// the 61 characters of the slug of tenant acme-eu's project, cut to 58: "…-reconciliations-acme"
	slug := "orders-of-the-very-long-department-of-reconciliations-acme-eu"
	service := slug[:63-len("-pr-7")] + "-pr-7"
	pod := previewPod(service+"-00001-deployment-6b8f9c7d4-xk9pq", service, "7")
	pod.Labels[tenantLabel] = "acme-eu"

	tr.handleEvent(watch.Event{Type: watch.Added, Object: pod}, rec.emit)
	start := rec.next(t)
	want := map[string]interface{}{
		"type": "session_start", "project_slug": slug[:63-len("-pr-7")], "project_id": "42",
		"preview": service, "tenant": "acme-eu",
	}
	for k, v := range want {
		if start[k] != v {
			t.Errorf("session_start[%q] = %v, want %v", k, start[k], v)
		}
	}

	tr.heartbeat(rec.emit)
	entry := rec.next(t)["sessions"].([]map[string]interface{})[0]
	if entry["project_id"] != "42" || entry["tenant"] != "acme-eu" || entry["preview"] != service {
		t.Fatalf("heartbeat entry %v", entry)
	}

	clock.Advance(time.Second)
	tr.handleEvent(watch.Event{Type: watch.Deleted, Object: pod}, rec.emit)
	end := rec.next(t)
	if end["type"] != "session_end" || end["project_id"] != "42" || end["tenant"] != "acme-eu" {
		t.Fatalf("unexpected session_end %v", end)
	}

	// a pod without the label (a central project's, or a preview deployed before it) sends none
	tr.handleEvent(watch.Event{Type: watch.Added, Object: servingPod("shop-00001-deployment-6b8f9c7d4-bcdfg", "shop")}, rec.emit)
	if _, ok := rec.next(t)["tenant"]; ok {
		t.Fatal("a pod without runr/tenant names a tenant")
	}
}

// An environment's pod (runr/environment-of instead of runr/project-id, docs/environments.md) is a
// session of the environment's workload: the API meters it as the environment's usage.
func TestEnvironmentPodsAreTrackedWithTheirEnvironment(t *testing.T) {
	tr, clock := newTestTracker()
	rec := newRecorder()
	pod := &corev1.Pod{ObjectMeta: metav1.ObjectMeta{
		Name:      "shop-staging-00001-deployment-6b8f9c7d4-xk9pq",
		Namespace: ns,
		Labels: map[string]string{
			environmentLabel:     "42",
			environmentIDLabel:   "env-7",
			environmentNameLabel: "staging",
			serviceLabel:         "shop-staging",
		},
	}}

	tr.handleEvent(watch.Event{Type: watch.Added, Object: pod}, rec.emit)
	start := rec.next(t)
	want := map[string]interface{}{
		"type": "session_start", "project_slug": "shop-staging", "project_id": "42",
		"entry_point": "http", "pod": pod.Name, "environment_id": "env-7", "environment": "staging",
	}
	for k, v := range want {
		if start[k] != v {
			t.Errorf("session_start[%q] = %v, want %v", k, start[k], v)
		}
	}

	tr.heartbeat(rec.emit)
	entry := rec.next(t)["sessions"].([]map[string]interface{})[0]
	if entry["environment_id"] != "env-7" || entry["project_id"] != "42" {
		t.Fatalf("heartbeat entry %v", entry)
	}

	clock.Advance(time.Second)
	tr.handleEvent(watch.Event{Type: watch.Deleted, Object: pod}, rec.emit)
	if end := rec.next(t); end["type"] != "session_end" || end["environment_id"] != "env-7" || end["project_id"] != "42" {
		t.Fatalf("unexpected session_end %v", end)
	}

	// without the environment's id the pod is nobody's
	odd := pod.DeepCopy()
	odd.Name = "shop-dev-00001-deployment-a-b"
	delete(odd.Labels, environmentIDLabel)
	tr.handleEvent(watch.Event{Type: watch.Added, Object: odd}, rec.emit)
	rec.none(t)
}

func TestEachWatchReconcilesOnlyItsOwnSessions(t *testing.T) {
	tr, _ := newTestTracker()
	rec := newRecorder()
	serving := servingPod("shop-00001-deployment-6b8f9c7d4-xk9pq", "shop")
	preview := previewPod("shop-pr-12-00001-deployment-6b8f9c7d4-xk9pq", "shop-pr-12", "12")
	tr.handleEvent(watch.Event{Type: watch.Added, Object: serving}, rec.emit)
	tr.handleEvent(watch.Event{Type: watch.Added, Object: preview}, rec.emit)
	rec.next(t)
	rec.next(t)

	// the project pods' list does not show the preview pod: its session stays
	tr.reconcile(projectLabel, []corev1.Pod{*serving}, rec.emit)
	rec.none(t)

	// the preview pods' list without it ends only the preview's session
	tr.reconcile(previewLabel, nil, rec.emit)
	if e := rec.next(t); e["type"] != "session_end" || e["pod"] != preview.Name {
		t.Fatalf("unexpected event %v", e)
	}
	rec.none(t)
	if len(tr.sessions) != 1 {
		t.Fatalf("%d sessions, want the serving pod's", len(tr.sessions))
	}
}

// heartbeatPods returns the pods a session_heartbeat lists, in order.
func heartbeatPods(t *testing.T, e map[string]interface{}) []string {
	t.Helper()
	if e["type"] != "session_heartbeat" {
		t.Fatalf("event %v, want a session_heartbeat", e)
	}
	sessions, ok := e["sessions"].([]map[string]interface{})
	if !ok {
		t.Fatalf("sessions = %T, want a list", e["sessions"])
	}
	pods := make([]string, 0, len(sessions))
	for _, s := range sessions {
		pods = append(pods, s["pod"].(string))
	}
	return pods
}

func TestHeartbeatReportsTheRunningSessionsInBatches(t *testing.T) {
	tr, _ := newTestTracker()
	rec := newRecorder()

	// nothing to report, nothing sent
	tr.heartbeat(rec.emit)
	rec.none(t)

	for i := 0; i < heartbeatBatch+5; i++ {
		tr.handleEvent(watch.Event{Type: watch.Added, Object: servingPod(fmt.Sprintf("shop-00001-deployment-6b8f9c7d4-p%03d", i), "shop")}, rec.emit)
		rec.next(t)
	}
	run := cronRunPod("nightly-trigger-29345678-x7k2p", "nightly-trigger-29345678", corev1.PodRunning)
	tr.handleEvent(watch.Event{Type: watch.Added, Object: run}, rec.emit)
	rec.next(t)

	tr.heartbeat(rec.emit)
	first, second := rec.next(t), rec.next(t)
	rec.none(t)
	pods := append(heartbeatPods(t, first), heartbeatPods(t, second)...)
	if len(heartbeatPods(t, first)) != heartbeatBatch || len(pods) != heartbeatBatch+6 {
		t.Fatalf("batches of %d and %d pods, want %d and 6", len(heartbeatPods(t, first)), len(heartbeatPods(t, second)), heartbeatBatch)
	}
	// sorted by pod: the CronJob run first, with what the API needs to find its session
	entry := first["sessions"].([]map[string]interface{})[0]
	want := map[string]interface{}{"project_slug": "nightly", "project_id": "42", "pod": run.Name, "entry_point": "schedule"}
	for k, v := range want {
		if entry[k] != v {
			t.Errorf("heartbeat entry[%q] = %v, want %v", k, entry[k], v)
		}
	}
	if pods[1] != "shop-00001-deployment-6b8f9c7d4-p000" || pods[len(pods)-1] != fmt.Sprintf("shop-00001-deployment-6b8f9c7d4-p%03d", heartbeatBatch+4) {
		t.Fatalf("pods out of order: %v", pods)
	}

	// an ended session is not reported any more
	tr.handleEvent(watch.Event{Type: watch.Modified, Object: withPhase(run, corev1.PodSucceeded)}, rec.emit)
	rec.next(t)
	tr.heartbeat(rec.emit)
	if got := len(heartbeatPods(t, rec.next(t))) + len(heartbeatPods(t, rec.next(t))); got != heartbeatBatch+5 {
		t.Fatalf("%d pods after the run ended, want %d", got, heartbeatBatch+5)
	}
	rec.none(t)
}

func TestRunWithClientSendsHeartbeats(t *testing.T) {
	running := servingPod("shop-00001-deployment-6b8f9c7d4-xk9pq", "shop")
	cs, watches := watchHarness(running)
	tr, _ := newTestTracker()
	tr.heartbeatEvery = 20 * time.Millisecond
	rec := newRecorder()

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		tr.RunWithClient(ctx, cs, rec.emit)
		close(done)
	}()
	defer func() {
		cancel()
		<-done
	}()

	// heartbeats before the first list have nothing to report: the start comes first
	if e := rec.next(t); e["type"] != "session_start" || e["pod"] != running.Name {
		t.Fatalf("unexpected first event %v", e)
	}
	nextWatch(t, watches, projectLabel)
	for i := 0; i < 2; i++ {
		if pods := heartbeatPods(t, rec.next(t)); len(pods) != 1 || pods[0] != running.Name {
			t.Fatalf("heartbeat lists %v, want the running pod", pods)
		}
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
