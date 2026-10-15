package watcher

import (
	"context"
	"log"
	"time"

	corev1 "k8s.io/api/core/v1"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/watch"
	"k8s.io/client-go/kubernetes"
	"k8s.io/client-go/rest"

	"github.com/brf-tech/runr/runtime/agent/internal/podname"
)

const podEventSelector = "involvedObject.kind=Pod"

// Watcher monitors Kubernetes events for critical conditions
// (OOMKilled, CrashLoopBackOff) and emits them to the platform.
type Watcher struct {
	namespace  string
	retryDelay time.Duration
}

func New(namespace string) *Watcher {
	return &Watcher{namespace: namespace, retryDelay: 5 * time.Second}
}

// Run starts the event watcher with reconnection logic.
func (w *Watcher) Run(ctx context.Context, emit func(map[string]interface{})) {
	config, err := rest.InClusterConfig()
	if err != nil {
		log.Printf("Event watcher: cannot get in-cluster config: %v", err)
		return
	}

	clientset, err := kubernetes.NewForConfig(config)
	if err != nil {
		log.Printf("Event watcher: cannot create client: %v", err)
		return
	}

	w.RunWithClient(ctx, clientset, emit)
}

// RunWithClient watches pod events through the given client and re-establishes the watch
// after it ends, until ctx is cancelled.
func (w *Watcher) RunWithClient(ctx context.Context, clientset kubernetes.Interface, emit func(map[string]interface{})) {
	for {
		select {
		case <-ctx.Done():
			return
		default:
		}

		w.watch(ctx, clientset, emit)

		select {
		case <-ctx.Done():
			return
		case <-time.After(w.retryDelay):
		}
	}
}

// watch starts from the current resource version of the event list, so only events that
// happen from now on are reported. A watch without a resource version replays every stored
// event (Kubernetes keeps them for an hour) as ADDED, which re-reported old OOM kills and
// crash loops on every agent start and every watch reconnect.
func (w *Watcher) watch(ctx context.Context, clientset kubernetes.Interface, emit func(map[string]interface{})) {
	events := clientset.CoreV1().Events(w.namespace)

	list, err := events.List(ctx, metav1.ListOptions{FieldSelector: podEventSelector, Limit: 1})
	if err != nil {
		log.Printf("Event watcher: list failed: %v", err)
		return
	}

	watcher, err := events.Watch(ctx, metav1.ListOptions{
		FieldSelector:   podEventSelector,
		ResourceVersion: list.ResourceVersion,
	})
	if err != nil {
		log.Printf("Event watcher: watch failed: %v", err)
		return
	}
	defer watcher.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case event, ok := <-watcher.ResultChan():
			if !ok {
				return
			}
			w.handleEvent(event, emit)
		}
	}
}

func (w *Watcher) handleEvent(event watch.Event, emit func(map[string]interface{})) {
	if event.Type != watch.Added && event.Type != watch.Modified {
		return
	}

	ev, ok := event.Object.(*corev1.Event)
	if !ok {
		return
	}

	var kind string
	switch ev.Reason {
	case "OOMKilled":
		kind = "pod_oom"
	case "BackOff":
		kind = "pod_crash_loop"
	case "Failed":
		kind = "pod_failed"
	default:
		return
	}

	pod := ev.InvolvedObject.Name
	// The involved object is the pod; the platform knows the project by its slug. A pod
	// outside a Knative Service keeps its own name.
	slug := podname.Slug(pod)
	if slug == "" {
		slug = pod
	}

	emit(map[string]interface{}{
		"type":         "event",
		"kind":         kind,
		"project_slug": slug,
		"pod":          pod,
		"details": map[string]interface{}{
			"reason":  ev.Reason,
			"message": ev.Message,
		},
	})
}
