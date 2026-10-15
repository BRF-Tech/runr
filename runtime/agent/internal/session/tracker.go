package session

import (
	"context"
	"log"
	"sync"
	"time"

	corev1 "k8s.io/api/core/v1"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/watch"
	"k8s.io/client-go/kubernetes"
	"k8s.io/client-go/rest"

	"github.com/runrsh/core/runtime/agent/internal/podname"
)

const (
	projectLabel = "runr/project-id"
	// serviceLabel is set by Knative on every pod of a Service; the Service is named after
	// the project slug.
	serviceLabel = "serving.knative.dev/service"
)

// Tracker watches pod lifecycle events to detect session boundaries.
// A session starts when a pod is created (scale-from-zero) and ends
// when the pod is terminated (scale-to-zero).
type Tracker struct {
	namespace  string
	sessions   map[string]*activeSession
	mu         sync.RWMutex
	retryDelay time.Duration
	now        func() time.Time
}

type activeSession struct {
	projectSlug string
	podName     string
	startedAt   time.Time
	coldStart   bool
}

func NewTracker(namespace string) *Tracker {
	return &Tracker{
		namespace:  namespace,
		sessions:   make(map[string]*activeSession),
		retryDelay: 5 * time.Second,
		now:        time.Now,
	}
}

// Run starts watching pod events and emits session start/end events.
func (t *Tracker) Run(ctx context.Context, emit func(map[string]interface{})) {
	config, err := rest.InClusterConfig()
	if err != nil {
		log.Printf("Session tracker: cannot get in-cluster config: %v", err)
		return
	}

	clientset, err := kubernetes.NewForConfig(config)
	if err != nil {
		log.Printf("Session tracker: cannot create client: %v", err)
		return
	}

	t.RunWithClient(ctx, clientset, emit)
}

// RunWithClient watches pods through the given client and re-establishes the watch after
// it ends, until ctx is cancelled.
func (t *Tracker) RunWithClient(ctx context.Context, clientset kubernetes.Interface, emit func(map[string]interface{})) {
	for {
		select {
		case <-ctx.Done():
			return
		default:
		}

		t.watch(ctx, clientset, emit)

		select {
		case <-ctx.Done():
			return
		case <-time.After(t.retryDelay):
		}
	}
}

// watch lists the project pods, reconciles the tracked sessions with that list, and then
// watches for changes from the list's resource version. Listing first means a re-established
// watch does not replay an ADDED event for every running pod (which started a duplicate
// session per pod), and sessions of pods deleted while the watch was down are ended.
func (t *Tracker) watch(ctx context.Context, clientset kubernetes.Interface, emit func(map[string]interface{})) {
	pods := clientset.CoreV1().Pods(t.namespace)

	list, err := pods.List(ctx, metav1.ListOptions{LabelSelector: projectLabel})
	if err != nil {
		log.Printf("Session tracker: list failed: %v", err)
		return
	}
	t.reconcile(list.Items, emit)

	watcher, err := pods.Watch(ctx, metav1.ListOptions{
		LabelSelector:   projectLabel,
		ResourceVersion: list.ResourceVersion,
	})
	if err != nil {
		log.Printf("Session tracker: watch failed: %v", err)
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
			t.handleEvent(event, emit)
		}
	}
}

// reconcile starts sessions for listed pods that are not tracked yet and ends the sessions
// of tracked pods that are gone.
func (t *Tracker) reconcile(pods []corev1.Pod, emit func(map[string]interface{})) {
	present := make(map[string]bool, len(pods))
	for i := range pods {
		present[pods[i].Name] = true
		t.start(&pods[i], emit)
	}

	t.mu.RLock()
	var gone []string
	for name := range t.sessions {
		if !present[name] {
			gone = append(gone, name)
		}
	}
	t.mu.RUnlock()

	for _, name := range gone {
		t.end(name, emit)
	}
}

func (t *Tracker) handleEvent(event watch.Event, emit func(map[string]interface{})) {
	pod, ok := event.Object.(*corev1.Pod)
	if !ok {
		return
	}

	switch event.Type {
	case watch.Added:
		t.start(pod, emit)
	case watch.Deleted:
		t.end(pod.Name, emit)
	}
}

// start opens a session for a serving pod that is not tracked yet.
func (t *Tracker) start(pod *corev1.Pod, emit func(map[string]interface{})) {
	slug := projectSlug(pod)
	if slug == "" {
		return // not a Knative serving pod (e.g. a build pod)
	}

	t.mu.Lock()
	if _, tracked := t.sessions[pod.Name]; tracked {
		t.mu.Unlock()
		return
	}
	t.sessions[pod.Name] = &activeSession{
		projectSlug: slug,
		podName:     pod.Name,
		startedAt:   t.now(),
		coldStart:   true,
	}
	t.mu.Unlock()

	emit(map[string]interface{}{
		"type":         "session_start",
		"project_slug": slug,
		"project_id":   pod.Labels[projectLabel],
		"entry_point":  "http",
		"pod":          pod.Name,
		"cold_start":   true,
	})
}

// end closes the session of a tracked pod.
func (t *Tracker) end(podName string, emit func(map[string]interface{})) {
	t.mu.Lock()
	sess, exists := t.sessions[podName]
	delete(t.sessions, podName)
	t.mu.Unlock()

	if !exists {
		return
	}

	emit(map[string]interface{}{
		"type":         "session_end",
		"project_slug": sess.projectSlug,
		"pod":          podName,
		"duration_ms":  t.now().Sub(sess.startedAt).Milliseconds(),
	})
}

// projectSlug is the slug the platform knows the project by: the Knative Service name
// (the runr/project-id label holds the project ID, not the slug).
func projectSlug(pod *corev1.Pod) string {
	if s := pod.Labels[serviceLabel]; s != "" {
		return s
	}
	return podname.Slug(pod.Name)
}
