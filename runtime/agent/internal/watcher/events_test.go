package watcher

import (
	"context"
	"errors"
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

func podEvent(reason, pod, message string) *corev1.Event {
	return &corev1.Event{
		ObjectMeta:     metav1.ObjectMeta{Name: pod + "." + reason, Namespace: ns},
		InvolvedObject: corev1.ObjectReference{Kind: "Pod", Name: pod, Namespace: ns},
		Reason:         reason,
		Message:        message,
	}
}

func TestHandleEventClassifiesReasons(t *testing.T) {
	const pod = "shop-00001-deployment-6b8f9c7d4-2xk9p"
	cases := []struct {
		reason string
		typ    watch.EventType
		kind   string // "" = not emitted
	}{
		{"OOMKilled", watch.Added, "pod_oom"},
		{"BackOff", watch.Added, "pod_crash_loop"},
		{"BackOff", watch.Modified, "pod_crash_loop"}, // repeated back-off bumps the event count
		{"Failed", watch.Added, "pod_failed"},
		{"Failed", watch.Deleted, ""},
		{"Scheduled", watch.Added, ""},
		{"Pulled", watch.Modified, ""},
	}
	for _, tc := range cases {
		t.Run(tc.reason+"/"+string(tc.typ), func(t *testing.T) {
			var got []map[string]interface{}
			New(ns).handleEvent(watch.Event{Type: tc.typ, Object: podEvent(tc.reason, pod, "msg")},
				func(e map[string]interface{}) { got = append(got, e) })

			if tc.kind == "" {
				if len(got) != 0 {
					t.Fatalf("emitted %v, want nothing", got)
				}
				return
			}
			if len(got) != 1 {
				t.Fatalf("emitted %d events, want 1", len(got))
			}
			e := got[0]
			if e["type"] != "event" || e["kind"] != tc.kind || e["project_slug"] != "shop" || e["pod"] != pod {
				t.Fatalf("unexpected event %v", e)
			}
			details := e["details"].(map[string]interface{})
			if details["reason"] != tc.reason || details["message"] != "msg" {
				t.Fatalf("unexpected details %v", details)
			}
		})
	}
}

func TestHandleEventSlugFallbackAndForeignObjects(t *testing.T) {
	w := New(ns)
	var got []map[string]interface{}
	emit := func(e map[string]interface{}) { got = append(got, e) }

	w.handleEvent(watch.Event{Type: watch.Added, Object: podEvent("OOMKilled", "build-shop-abcde", "")}, emit)
	if len(got) != 1 || got[0]["project_slug"] != "build-shop-abcde" {
		t.Fatalf("non-Knative pod: %v, want its own name as project_slug", got)
	}

	got = nil
	w.handleEvent(watch.Event{Type: watch.Added, Object: &corev1.Pod{}}, emit)
	w.handleEvent(watch.Event{Type: watch.Error, Object: &metav1.Status{Reason: "Expired"}}, emit)
	if len(got) != 0 {
		t.Fatalf("emitted %v for non-event objects", got)
	}
}

// harness hands every event watch the watcher opens to the test.
func harness(objects ...runtime.Object) (*fake.Clientset, chan *watch.RaceFreeFakeWatcher) {
	cs := fake.NewSimpleClientset(objects...)
	watches := make(chan *watch.RaceFreeFakeWatcher, 4)
	cs.PrependWatchReactor("events", func(k8stesting.Action) (bool, watch.Interface, error) {
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
		t.Fatal("watcher did not open a watch")
		return nil
	}
}

func TestRunWithClientWatchesFromListVersionAndReconnects(t *testing.T) {
	// an OOM kill from before the agent started must not be reported again
	old := podEvent("OOMKilled", "shop-00001-deployment-a-bcdfg", "old")
	cs, watches := harness(old)
	cs.PrependReactor("list", "events", func(k8stesting.Action) (bool, runtime.Object, error) {
		return true, &corev1.EventList{ListMeta: metav1.ListMeta{ResourceVersion: "4711"}, Items: []corev1.Event{*old}}, nil
	})

	w := New(ns)
	w.retryDelay = 10 * time.Millisecond
	emitted := make(chan map[string]interface{}, 16)

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		w.RunWithClient(ctx, cs, func(e map[string]interface{}) { emitted <- e })
		close(done)
	}()

	fw := nextWatch(t, watches)
	for _, a := range cs.Actions() {
		wa, ok := a.(k8stesting.WatchAction)
		if !ok {
			continue
		}
		r := wa.GetWatchRestrictions()
		if r.ResourceVersion != "4711" {
			t.Errorf("watch resourceVersion = %q, want the list's 4711", r.ResourceVersion)
		}
		if r.Fields.String() != podEventSelector || a.GetNamespace() != ns {
			t.Errorf("watch fields %q in %q", r.Fields.String(), a.GetNamespace())
		}
	}

	fw.Add(podEvent("BackOff", "api-00002-deployment-a-bcdfg", "Back-off restarting failed container"))
	select {
	case e := <-emitted:
		if e["kind"] != "pod_crash_loop" || e["project_slug"] != "api" {
			t.Fatalf("unexpected event %v", e)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("no event emitted")
	}
	select {
	case e := <-emitted:
		t.Fatalf("old event replayed: %v", e)
	default:
	}

	// the API server closes the watch: the watcher opens a new one
	fw.Stop()
	fw2 := nextWatch(t, watches)
	fw2.Modify(podEvent("Failed", "api-00002-deployment-a-bcdfg", "Error: ImagePullBackOff"))
	select {
	case e := <-emitted:
		if e["kind"] != "pod_failed" {
			t.Fatalf("unexpected event %v", e)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("no event after reconnect")
	}

	cancel()
	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("RunWithClient did not return after cancel")
	}
}

func TestRunWithClientRetriesFailedWatch(t *testing.T) {
	cs := fake.NewSimpleClientset()
	var mu sync.Mutex
	attempts := 0
	opened := make(chan struct{})
	cs.PrependWatchReactor("events", func(k8stesting.Action) (bool, watch.Interface, error) {
		mu.Lock()
		defer mu.Unlock()
		attempts++
		if attempts < 3 {
			return true, nil, errors.New("connection refused")
		}
		close(opened)
		return true, watch.NewRaceFreeFake(), nil
	})

	w := New(ns)
	w.retryDelay = 5 * time.Millisecond
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go w.RunWithClient(ctx, cs, func(map[string]interface{}) {})

	select {
	case <-opened:
	case <-time.After(2 * time.Second):
		t.Fatal("watch never succeeded")
	}
	mu.Lock()
	defer mu.Unlock()
	if attempts != 3 {
		t.Fatalf("%d watch attempts, want 3", attempts)
	}
}
