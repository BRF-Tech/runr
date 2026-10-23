package session

import (
	"context"
	"log"
	"sort"
	"sync"
	"time"

	corev1 "k8s.io/api/core/v1"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/watch"
	"k8s.io/client-go/kubernetes"
	"k8s.io/client-go/rest"

	"github.com/brf-tech/runr/runtime/agent/internal/podname"
)

const (
	projectLabel = "runr/project-id"
	// serviceLabel is set by Knative on every pod of a Service; the Service is named after
	// the project slug.
	serviceLabel = "serving.knative.dev/service"
	// buildLabel marks the pods of build Jobs, which carry the project label too.
	buildLabel = "runr/build"
	// jobNameLabel is set by the Job controller on every pod of a Job (legacyJobNameLabel by
	// clusters before Kubernetes 1.27, and still alongside it).
	jobNameLabel       = "batch.kubernetes.io/job-name"
	legacyJobNameLabel = "job-name"
	// previewLabel marks the pods of a project's preview environments (instead of projectLabel,
	// so the project's own metrics never count them); it holds the project ID. The preview's
	// Knative Service is <slug>-pr-<number> (previewServiceLabel), its number previewNumberLabel.
	previewLabel        = "runr/preview-of"
	previewServiceLabel = "runr.dev/preview"
	previewNumberLabel  = "runr/pr"
	// environmentLabel marks the pods of a project's environment (docs/environments.md) instead
	// of projectLabel; it holds the project ID, environmentIDLabel the environment's and
	// environmentNameLabel its name. The workload is <slug>-<environment> (Knative Service,
	// CronJob <slug>-<environment>-trigger).
	environmentLabel     = "runr/environment-of"
	environmentIDLabel   = "runr/environment-id"
	environmentNameLabel = "runr/environment"
	// tenantLabel names the organization (its slug) of a tenant's workloads. A preview pod
	// carries it: a Service name that had to shorten a long project slug (<slug>-pr-<number> in
	// 63 characters) lost the tenant's slug with it. Session events pass it on, and the
	// project ID, so the API finds the project without parsing the name.
	tenantLabel = "runr/tenant"
)

// selectors are the label selectors the tracker lists and watches, one watch each: the pods of
// the projects' workloads, of their preview environments and of their environments.
var selectors = []string{projectLabel, previewLabel, environmentLabel}

// Entry points of a session, as the API stores them (deployment_sessions.entry_point). The API
// files a serving pod's session under its deployment's trigger (a queue trigger's function is a
// Knative Service too); a CronJob run is always a schedule.
const (
	entryHTTP     = "http"
	entrySchedule = "schedule"
)

const (
	// defaultHeartbeatEvery is how often the tracker reports the sessions it still sees running.
	// The API closes a session whose heartbeats stopped (the agent or the region is gone) after
	// a grace period of several heartbeats (SessionTrackingService::HEARTBEAT_STALE_AFTER_MINUTES).
	defaultHeartbeatEvery = time.Minute
	// heartbeatBatch caps the sessions of one heartbeat message, which keeps every message small.
	heartbeatBatch = 40
)

// Tracker watches pod lifecycle events to detect session boundaries. A session is the time one
// pod of a project's workload holds its resources, which the API meters at the deployment's size:
//
//   - a Knative serving pod: from its creation (scale-from-zero, scale-up) until it is deleted
//     (scale-down, scale-to-zero) or fails;
//   - the pod of a run of the project's CronJob: from its creation until it succeeds or fails
//     (the finished pod stays until the CronJob's history limit removes it);
//   - a pod of a preview environment (runr/preview-of): like a serving pod; the API meters it
//     at the project's size as the preview's own usage.
//
// Other pods with the project label (builds, queue consumers) are no sessions. Every minute the
// tracker also reports the sessions it still sees running (session_heartbeat).
//
// Session events are emitted while the tracker holds its lock, so they leave in the order the
// tracker saw them: a heartbeat never lists a pod before its session_start or after its
// session_end. emit must therefore not block (the WebSocket client's Send queues or drops).
type Tracker struct {
	namespace      string
	sessions       map[string]*activeSession
	mu             sync.RWMutex
	retryDelay     time.Duration
	heartbeatEvery time.Duration
	now            func() time.Time
}

type activeSession struct {
	projectSlug   string
	projectID     string
	entryPoint    string
	preview       string // the preview's Knative Service; "" for the project's own pods
	environmentID string // the environment of an environment's pod; "" for the project's own pods
	environment   string
	tenant        string // the organization of a tenant's pod (runr/tenant); "" for none
	selector      string // the watch that sees the pod
	podName       string
	startedAt     time.Time
	coldStart     bool
}

func NewTracker(namespace string) *Tracker {
	return &Tracker{
		namespace:      namespace,
		sessions:       make(map[string]*activeSession),
		retryDelay:     5 * time.Second,
		heartbeatEvery: defaultHeartbeatEvery,
		now:            time.Now,
	}
}

// Run starts watching pod events and emits session start/end events and heartbeats.
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

// RunWithClient watches pods through the given client — the project pods and the preview pods,
// each with its own watch — and re-establishes a watch after it ends, until ctx is cancelled.
// Heartbeats go out every heartbeatEvery meanwhile.
func (t *Tracker) RunWithClient(ctx context.Context, clientset kubernetes.Interface, emit func(map[string]interface{})) {
	go t.heartbeats(ctx, emit)

	var wg sync.WaitGroup
	for _, selector := range selectors {
		wg.Add(1)
		go func(selector string) {
			defer wg.Done()
			t.loop(ctx, clientset, emit, selector)
		}(selector)
	}
	wg.Wait()
}

// loop keeps one watch (pods with the selector's label) running until ctx is cancelled.
func (t *Tracker) loop(ctx context.Context, clientset kubernetes.Interface, emit func(map[string]interface{}), selector string) {
	for {
		select {
		case <-ctx.Done():
			return
		default:
		}

		t.watch(ctx, clientset, emit, selector)

		select {
		case <-ctx.Done():
			return
		case <-time.After(t.retryDelay):
		}
	}
}

// watch lists the pods of the selector, reconciles the tracked sessions with that list, and then
// watches for changes from the list's resource version. Listing first means a re-established
// watch does not replay an ADDED event for every running pod (which started a duplicate
// session per pod), and sessions of pods deleted while the watch was down are ended.
func (t *Tracker) watch(ctx context.Context, clientset kubernetes.Interface, emit func(map[string]interface{}), selector string) {
	pods := clientset.CoreV1().Pods(t.namespace)

	list, err := pods.List(ctx, metav1.ListOptions{LabelSelector: selector})
	if err != nil {
		log.Printf("Session tracker: list failed: %v", err)
		return
	}
	t.reconcile(selector, list.Items, emit)

	watcher, err := pods.Watch(ctx, metav1.ListOptions{
		LabelSelector:   selector,
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

// reconcile starts sessions for listed pods that are not tracked yet and ends the sessions of
// the selector's tracked pods that are gone or finished (the other watch's sessions are its own).
func (t *Tracker) reconcile(selector string, pods []corev1.Pod, emit func(map[string]interface{})) {
	present := make(map[string]bool, len(pods))
	for i := range pods {
		if finished(&pods[i]) {
			continue
		}
		present[pods[i].Name] = true
		t.start(&pods[i], emit)
	}

	t.mu.RLock()
	var gone []string
	for name, s := range t.sessions {
		if s.selector == selector && !present[name] {
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
	case watch.Modified:
		// A finished pod holds nothing any more, though it stays until it is deleted
		if finished(pod) {
			t.end(pod.Name, emit)
		}
	case watch.Deleted:
		t.end(pod.Name, emit)
	}
}

// start opens a session for a serving pod or a CronJob run's pod that is not tracked yet.
func (t *Tracker) start(pod *corev1.Pod, emit func(map[string]interface{})) {
	w, ok := workload(pod)
	if !ok {
		return // none of them (e.g. a build pod or a queue consumer)
	}
	if finished(pod) {
		return // ran before the agent saw it (or a replayed event of a finished run)
	}

	t.mu.Lock()
	defer t.mu.Unlock()
	if _, tracked := t.sessions[pod.Name]; tracked {
		return
	}
	t.sessions[pod.Name] = &activeSession{
		projectSlug:   w.slug,
		projectID:     w.projectID,
		entryPoint:    w.entryPoint,
		preview:       w.preview,
		environmentID: w.environmentID,
		environment:   w.environment,
		tenant:        w.tenant,
		selector:      w.selector,
		podName:       pod.Name,
		startedAt:     t.now(),
		coldStart:     true,
	}

	event := map[string]interface{}{
		"type":         "session_start",
		"project_slug": w.slug,
		"project_id":   w.projectID,
		"entry_point":  w.entryPoint,
		"pod":          pod.Name,
		"cold_start":   true,
	}
	if w.preview != "" {
		event["preview"] = w.preview
	}
	if w.environmentID != "" {
		event["environment_id"] = w.environmentID
		event["environment"] = w.environment
	}
	if w.tenant != "" {
		event["tenant"] = w.tenant
	}
	emit(event)
}

// end closes the session of a tracked pod.
func (t *Tracker) end(podName string, emit func(map[string]interface{})) {
	t.mu.Lock()
	defer t.mu.Unlock()
	sess, exists := t.sessions[podName]
	if !exists {
		return
	}
	delete(t.sessions, podName)

	event := map[string]interface{}{
		"type":         "session_end",
		"project_slug": sess.projectSlug,
		"pod":          podName,
		"duration_ms":  t.now().Sub(sess.startedAt).Milliseconds(),
	}
	// the project by its ID too: a preview's slug may be only the start of the project's
	if sess.projectID != "" {
		event["project_id"] = sess.projectID
	}
	if sess.environmentID != "" {
		event["environment_id"] = sess.environmentID
		event["environment"] = sess.environment
	}
	if sess.tenant != "" {
		event["tenant"] = sess.tenant
	}
	emit(event)
}

// heartbeats reports the running sessions every heartbeatEvery until ctx is cancelled.
func (t *Tracker) heartbeats(ctx context.Context, emit func(map[string]interface{})) {
	ticker := time.NewTicker(t.heartbeatEvery)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			t.heartbeat(emit)
		}
	}
}

// heartbeat reports every session the tracker still sees running, sorted by pod, at most
// heartbeatBatch per message; nothing without sessions. The API marks these sessions seen (and
// starts the session of a pod whose session_start it never got); a session it hears nothing
// about for a while is closed as of when it was last seen.
func (t *Tracker) heartbeat(emit func(map[string]interface{})) {
	t.mu.Lock()
	defer t.mu.Unlock()

	pods := make([]string, 0, len(t.sessions))
	for name := range t.sessions {
		pods = append(pods, name)
	}
	sort.Strings(pods)

	for i := 0; i < len(pods); i += heartbeatBatch {
		batch := pods[i:min(i+heartbeatBatch, len(pods))]
		sessions := make([]map[string]interface{}, 0, len(batch))
		for _, name := range batch {
			s := t.sessions[name]
			entry := map[string]interface{}{
				"project_slug": s.projectSlug,
				"project_id":   s.projectID,
				"pod":          s.podName,
				"entry_point":  s.entryPoint,
			}
			if s.preview != "" {
				entry["preview"] = s.preview
			}
			if s.environmentID != "" {
				entry["environment_id"] = s.environmentID
				entry["environment"] = s.environment
			}
			if s.tenant != "" {
				entry["tenant"] = s.tenant
			}
			sessions = append(sessions, entry)
		}
		emit(map[string]interface{}{"type": "session_heartbeat", "sessions": sessions})
	}
}

// workload tells which project a pod belongs to and what its session is: a pod of a preview
// environment (http, with the preview), a Knative serving pod (http) or the pod of a run of the
// project's CronJob (schedule). Other pods are none of them. The organization of a tenant's pod
// comes from its runr/tenant label.
func workload(pod *corev1.Pod) (podWorkload, bool) {
	w, ok := workloadOf(pod)
	if ok {
		w.tenant = pod.Labels[tenantLabel]
	}
	return w, ok
}

// workloadOf is workload without the organization. A preview's slug is what its Service name
// <slug>-pr-<number> keeps of the project's slug: all of it, or the start of a long one.
func workloadOf(pod *corev1.Pod) (podWorkload, bool) {
	if of := pod.Labels[previewLabel]; of != "" {
		service := pod.Labels[serviceLabel]
		if service == "" {
			service = pod.Labels[previewServiceLabel]
		}
		if slug := podname.PreviewSlug(service, pod.Labels[previewNumberLabel]); slug != "" {
			return podWorkload{slug: slug, projectID: of, entryPoint: entryHTTP, preview: service, selector: previewLabel}, true
		}
		return podWorkload{}, false
	}
	if of := pod.Labels[environmentLabel]; of != "" {
		// a pod of the project's environment: the environment's workload is the session's
		w := podWorkload{projectID: of, environmentID: pod.Labels[environmentIDLabel], environment: pod.Labels[environmentNameLabel], selector: environmentLabel}
		if w.environmentID == "" {
			return podWorkload{}, false
		}
		if s := projectSlug(pod); s != "" {
			w.slug, w.entryPoint = s, entryHTTP
			return w, true
		}
		if s := scheduleSlug(pod); s != "" {
			w.slug, w.entryPoint = s, entrySchedule
			return w, true
		}
		return podWorkload{}, false
	}
	if s := projectSlug(pod); s != "" {
		return podWorkload{slug: s, projectID: pod.Labels[projectLabel], entryPoint: entryHTTP, selector: projectLabel}, true
	}
	if s := scheduleSlug(pod); s != "" {
		return podWorkload{slug: s, projectID: pod.Labels[projectLabel], entryPoint: entrySchedule, selector: projectLabel}, true
	}
	return podWorkload{}, false
}

// podWorkload is what a tracked pod runs: the project (its slug, as the platform knows it, and
// id), the session's entry point, for a preview environment's pod the preview's Knative Service,
// and the watch that sees the pod.
type podWorkload struct {
	slug          string
	projectID     string
	entryPoint    string
	preview       string
	environmentID string
	environment   string
	tenant        string
	selector      string
}

// projectSlug is the slug the platform knows the project by: the Knative Service name
// (the runr/project-id label holds the project ID, not the slug).
func projectSlug(pod *corev1.Pod) string {
	if s := pod.Labels[serviceLabel]; s != "" {
		return s
	}
	return podname.Slug(pod.Name)
}

// scheduleSlug is the slug of the project whose CronJob ({slug}-trigger) started the pod's
// Job, or "" for any other pod. The Job's name comes from the pod's labels, else from its owner.
func scheduleSlug(pod *corev1.Pod) string {
	if pod.Labels[buildLabel] != "" {
		return ""
	}
	job := pod.Labels[jobNameLabel]
	if job == "" {
		job = pod.Labels[legacyJobNameLabel]
	}
	if job == "" {
		for _, ref := range pod.OwnerReferences {
			if ref.Kind == "Job" {
				job = ref.Name
				break
			}
		}
	}
	return podname.ScheduleSlug(job)
}

// finished reports whether the pod's containers stopped for good: a CronJob run that succeeded
// or failed, or an evicted serving pod.
func finished(pod *corev1.Pod) bool {
	return pod.Status.Phase == corev1.PodSucceeded || pod.Status.Phase == corev1.PodFailed
}
