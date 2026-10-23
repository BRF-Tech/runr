package authz

import (
	"context"
	"log"
	"net"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	corev1 "k8s.io/api/core/v1"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/runtime"
	"k8s.io/apimachinery/pkg/watch"
	"k8s.io/client-go/kubernetes"
	"k8s.io/client-go/tools/cache"
)

// Labels of the pods RunR runs for a project (Knative revisions, schedule runs, queue consumers).
const (
	LabelProject = "runr/project-id"
	// LabelEnvironmentOf replaces LabelProject on the pods of a project's environment
	// (docs/environments.md): the project's id; the pod's internal name is the environment's own,
	// <project>-<environment>.<organization>.
	LabelEnvironmentOf = "runr/environment-of"
	// LabelInternalName is set on the pods of workloads deployed with the internal network.
	LabelInternalName = "runr.dev/internal-name"
)

// podSelectors select the pods that identify a caller: a project's own and its environments'.
var podSelectors = []string{LabelProject, LabelEnvironmentOf}

// PodIdentity is what the address of a caller without a token says about it: the pod RunR runs
// for a project there.
type PodIdentity struct {
	UID       string
	Namespace string
	ProjectID string
	// Name is the pod's internal name label; empty on pods deployed before the internal network
	// (the policy's project index names them).
	Name string
}

// Pods identifies a caller by its address: the pods of RunR's projects by pod IP, kept by a
// watch. A pod's address is its own — the network plugin routes the answer of a call back to
// it — so a workload deployed before the internal network (no RUNR_INTERNAL_TOKEN), a queue
// consumer of an earlier release or a function that calls its bound model service without a
// header is still recognised as its project. Pods on the host network, preview environments
// (their pods carry no project label) and finished pods identify nobody.
type Pods struct {
	mu     sync.RWMutex
	byIP   map[string]PodIdentity
	synced atomic.Bool

	// lookup finds a pod the watch has not delivered yet (a pod that calls right after it
	// started); nil: the watch only
	lookup func(ctx context.Context, ip string) (*corev1.Pod, error)

	missMu sync.Mutex
	misses map[string]time.Time
}

// NewPods is an empty index (Set / Remove fill it; tests use it directly).
func NewPods() *Pods {
	return &Pods{byIP: map[string]PodIdentity{}, misses: map[string]time.Time{}}
}

// NewClusterPods is an index that asks the API server for a pod the watch has not delivered yet.
func NewClusterPods(cs kubernetes.Interface) *Pods {
	p := NewPods()
	p.lookup = func(ctx context.Context, ip string) (*corev1.Pod, error) {
		for _, selector := range podSelectors {
			list, err := cs.CoreV1().Pods(metav1.NamespaceAll).List(ctx, metav1.ListOptions{
				LabelSelector: selector,
				FieldSelector: "status.podIP=" + ip,
			})
			if err != nil {
				return nil, err
			}
			if len(list.Items) > 0 {
				return &list.Items[0], nil
			}
		}
		return nil, nil
	}
	return p
}

func identityOf(pod *corev1.Pod) (string, PodIdentity, bool) {
	ip := pod.Status.PodIP
	project := pod.Labels[LabelProject]
	name := strings.ToLower(pod.Labels[LabelInternalName])
	if project == "" && name != "" {
		// an environment's pod is known by its own internal name only: without one it would
		// stand for the project's own workload (ProjectNames)
		project = pod.Labels[LabelEnvironmentOf]
	}
	if ip == "" || project == "" || pod.Spec.HostNetwork ||
		pod.Status.Phase == corev1.PodSucceeded || pod.Status.Phase == corev1.PodFailed {
		return "", PodIdentity{}, false
	}
	return ip, PodIdentity{
		UID:       string(pod.UID),
		Namespace: pod.Namespace,
		ProjectID: project,
		Name:      name,
	}, true
}

// Set records a pod (or forgets it when it no longer identifies anybody).
func (p *Pods) Set(pod *corev1.Pod) {
	ip, id, ok := identityOf(pod)
	p.mu.Lock()
	defer p.mu.Unlock()
	if !ok {
		p.removeLocked(string(pod.UID))
		return
	}
	// the pod moved to another address (never in practice) or the address had another pod
	p.removeLocked(id.UID)
	p.byIP[ip] = id
}

// Remove forgets a pod by its UID.
func (p *Pods) Remove(uid string) {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.removeLocked(uid)
}

func (p *Pods) removeLocked(uid string) {
	for ip, id := range p.byIP {
		if id.UID == uid {
			delete(p.byIP, ip)
		}
	}
}

// Synced reports whether the watch has listed every pod once.
func (p *Pods) Synced() bool {
	return p.synced.Load()
}

// Lookup returns the pod at ip.
func (p *Pods) Lookup(ctx context.Context, ip string) (PodIdentity, bool) {
	ip = strings.TrimSpace(ip)
	if net.ParseIP(ip) == nil {
		return PodIdentity{}, false
	}
	p.mu.RLock()
	id, ok := p.byIP[ip]
	p.mu.RUnlock()
	if ok || p.lookup == nil || !p.lookupDue(ip) {
		return id, ok
	}

	lookupCtx, cancel := context.WithTimeout(ctx, 2*time.Second)
	defer cancel()
	for attempt := 0; ; attempt++ {
		pod, err := p.lookup(lookupCtx, ip)
		if err == nil && pod != nil {
			p.Set(pod)
			_, id, ok = identityOf(pod)
			return id, ok
		}
		// the watch may have delivered the pod meanwhile
		p.mu.RLock()
		id, ok = p.byIP[ip]
		p.mu.RUnlock()
		if ok {
			return id, true
		}
		// A pod that calls the moment its container starts: the kubelet reports its address to
		// the API server a moment later. Ask again a few times before the caller is nobody.
		if err != nil || attempt >= len(lookupRetries) {
			return PodIdentity{}, false
		}
		select {
		case <-lookupCtx.Done():
			return PodIdentity{}, false
		case <-time.After(lookupRetries[attempt]):
		}
	}
}

// lookupRetries are the waits between the live lookups of an address the API server does not
// know yet (1.5 s in all, within the lookup's 2 s).
var lookupRetries = []time.Duration{250 * time.Millisecond, 500 * time.Millisecond, 750 * time.Millisecond}

// lookupDue reports whether a live lookup of ip is due: once per address every five seconds, so
// a caller that is no pod of a project costs the API server one request at most that often.
func (p *Pods) lookupDue(ip string) bool {
	p.missMu.Lock()
	defer p.missMu.Unlock()
	now := time.Now()
	if last, ok := p.misses[ip]; ok && now.Sub(last) < 5*time.Second {
		return false
	}
	if len(p.misses) > 10000 {
		p.misses = map[string]time.Time{}
	}
	p.misses[ip] = now
	return true
}

// Watch keeps the index current from the cluster until ctx ends: one watch per selector (the
// pods of the projects' own workloads and of their environments).
func (p *Pods) Watch(ctx context.Context, cs kubernetes.Interface) {
	synced := make([]cache.InformerSynced, 0, len(podSelectors))
	for _, selector := range podSelectors {
		lw := &cache.ListWatch{
			ListFunc: func(o metav1.ListOptions) (runtime.Object, error) {
				o.LabelSelector = selector
				return cs.CoreV1().Pods(metav1.NamespaceAll).List(ctx, o)
			},
			WatchFunc: func(o metav1.ListOptions) (watch.Interface, error) {
				o.LabelSelector = selector
				return cs.CoreV1().Pods(metav1.NamespaceAll).Watch(ctx, o)
			},
		}
		informer := cache.NewSharedIndexInformer(lw, &corev1.Pod{}, 10*time.Minute, cache.Indexers{})
		_, err := informer.AddEventHandler(cache.ResourceEventHandlerFuncs{
			AddFunc:    func(obj any) { p.setObject(obj) },
			UpdateFunc: func(_, obj any) { p.setObject(obj) },
			DeleteFunc: func(obj any) {
				if tomb, ok := obj.(cache.DeletedFinalStateUnknown); ok {
					obj = tomb.Obj
				}
				if pod, ok := obj.(*corev1.Pod); ok {
					p.Remove(string(pod.UID))
				}
			},
		})
		if err != nil {
			log.Printf("authz: pod watch (%s): %v", selector, err)
			return
		}
		go informer.Run(ctx.Done())
		synced = append(synced, informer.HasSynced)
	}
	if cache.WaitForCacheSync(ctx.Done(), synced...) {
		p.synced.Store(true)
		log.Printf("authz: watching the pods of RunR's projects and their environments")
	}
}

func (p *Pods) setObject(obj any) {
	if pod, ok := obj.(*corev1.Pod); ok {
		p.Set(pod)
	}
}

// sourceAddress is the caller's address as Traefik's ForwardAuth passes it: the last entry of
// X-Forwarded-For, the connection's own peer (the private entry point trusts no forwarded
// header, so the list is that one address).
func sourceAddress(xff string) string {
	if i := strings.LastIndexByte(xff, ','); i >= 0 {
		xff = xff[i+1:]
	}
	return strings.TrimSpace(xff)
}
