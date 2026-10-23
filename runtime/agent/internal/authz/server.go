package authz

import (
	"context"
	"encoding/json"
	"log"
	"net/http"
	"strings"
	"time"

	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/runtime/schema"
	"k8s.io/client-go/dynamic"
	"k8s.io/client-go/kubernetes"
	"k8s.io/client-go/rest"
)

// Header names on the ForwardAuth request and answer.
const (
	HeaderToken    = "X-Runr-Internal-Token"
	HeaderCaller   = "X-Runr-Caller"
	HeaderDecision = "X-Runr-Internal-Decision"

	// ConfigName names the ConfigMap (mode.<namespace>, cross_tenant) and the Secret
	// (key.<region id>) RunR writes in the Serving namespace.
	ConfigName = "runr-internal-network"
)

var knativeServices = schema.GroupVersionResource{Group: "serving.knative.dev", Version: "v1", Resource: "services"}

// Handler answers Traefik's ForwardAuth requests (/authz) and readiness (/healthz). pods
// identifies a caller without a token by its address (nil: tokens only).
func Handler(store *Store, pods *Pods, now func() time.Time) http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, r *http.Request) {
		if store.Get() == nil {
			http.Error(w, "policy not loaded", http.StatusServiceUnavailable)
			return
		}
		// RunR's own workloads without a token are known by their pods: not ready before
		if pods != nil && !pods.Synced() {
			http.Error(w, "pods not listed yet", http.StatusServiceUnavailable)
			return
		}
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte("ok"))
	})
	mux.HandleFunc("/authz", func(w http.ResponseWriter, r *http.Request) {
		p := store.Get()
		if p == nil {
			// not loaded yet: the pod is not ready either, Traefik should not have sent it
			http.Error(w, "policy not loaded", http.StatusServiceUnavailable)
			return
		}
		host := r.Header.Get("X-Forwarded-Host")
		if host == "" {
			host = r.Host
		}
		var identify func() (string, bool)
		if pods != nil {
			identify = func() (string, bool) {
				id, ok := pods.Lookup(r.Context(), sourceAddress(r.Header.Get("X-Forwarded-For")))
				if !ok {
					return "", false
				}
				return p.NameOf(id)
			}
		}
		d := p.Decide(host, r.Header.Get(HeaderToken), func(token string) (*Claims, error) {
			return Verify(token, p.Keys, now())
		}, identify)
		if d.Caller != "" {
			w.Header().Set(HeaderCaller, d.Caller)
		}
		switch {
		case d.Allowed:
			w.Header().Set(HeaderDecision, "allow")
			w.WriteHeader(http.StatusOK)
		case d.Admit:
			w.Header().Set(HeaderDecision, "audit-deny")
			logDecision("audit", host, d, r)
			w.WriteHeader(http.StatusOK)
		default:
			logDecision("deny", host, d, r)
			w.Header().Set(HeaderDecision, "deny")
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(d.Status)
			_ = json.NewEncoder(w).Encode(map[string]string{"error": d.Reason})
		}
	})
	return mux
}

func logDecision(kind, host string, d Decision, r *http.Request) {
	line, _ := json.Marshal(map[string]string{
		"decision": kind,
		"host":     host,
		"caller":   d.Caller,
		"via":      d.Via,
		"reason":   d.Reason,
		"method":   r.Header.Get("X-Forwarded-Method"),
		"uri":      r.Header.Get("X-Forwarded-Uri"),
		"source":   r.Header.Get("X-Forwarded-For"),
	})
	log.Print(string(line))
}

// Loader reads the policy from the cluster: the Knative Services RunR manages (every
// namespace, label runr.dev/internal-network) and the ConfigMap and Secret in Namespace.
type Loader struct {
	Namespace string
	Dynamic   dynamic.Interface
	Clientset kubernetes.Interface
}

// NewLoader uses the pod's service account.
func NewLoader(namespace string) (*Loader, error) {
	cfg, err := rest.InClusterConfig()
	if err != nil {
		return nil, err
	}
	dyn, err := dynamic.NewForConfig(cfg)
	if err != nil {
		return nil, err
	}
	cs, err := kubernetes.NewForConfig(cfg)
	if err != nil {
		return nil, err
	}
	return &Loader{Namespace: namespace, Dynamic: dyn, Clientset: cs}, nil
}

// Load builds the current policy.
func (l *Loader) Load(ctx context.Context) (*Policy, error) {
	list, err := l.Dynamic.Resource(knativeServices).Namespace(metav1.NamespaceAll).List(ctx, metav1.ListOptions{LabelSelector: LabelManaged})
	if err != nil {
		return nil, err
	}
	services := make([]*Service, 0, len(list.Items))
	for _, item := range list.Items {
		if s, ok := NewService(item.GetNamespace(), item.GetName(), item.GetAnnotations()); ok {
			s.ProjectID = item.GetLabels()[LabelProject]
			services = append(services, s)
		}
	}

	modes := map[string]string{}
	cm, err := l.Clientset.CoreV1().ConfigMaps(l.Namespace).Get(ctx, ConfigName, metav1.GetOptions{})
	if err != nil {
		return nil, err
	}
	for k, v := range cm.Data {
		if ns, ok := strings.CutPrefix(k, "mode."); ok {
			modes[ns] = strings.TrimSpace(v)
		}
	}
	crossTenant := strings.TrimSpace(cm.Data["cross_tenant"]) != "false"

	keys := map[string][]byte{}
	secret, err := l.Clientset.CoreV1().Secrets(l.Namespace).Get(ctx, ConfigName, metav1.GetOptions{})
	if err != nil {
		return nil, err
	}
	for k, v := range secret.Data {
		if region, ok := strings.CutPrefix(k, "key."); ok {
			keys[region] = v
		}
	}

	return Build(services, keys, modes, crossTenant), nil
}

// Run loads the policy every interval until ctx ends; a failed load keeps the previous policy.
func (l *Loader) Run(ctx context.Context, store *Store, interval time.Duration) {
	for {
		if p, err := l.Load(ctx); err != nil {
			log.Printf("authz: policy not loaded: %v", err)
		} else {
			store.Set(p)
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(interval):
		}
	}
}

// Serve runs the authz until ctx ends: the policy reloaded every interval, the handler on addr.
func Serve(ctx context.Context, namespace, addr string, interval time.Duration) error {
	loader, err := NewLoader(namespace)
	if err != nil {
		return err
	}
	store := &Store{}
	go loader.Run(ctx, store, interval)
	pods := NewClusterPods(loader.Clientset)
	go pods.Watch(ctx, loader.Clientset)

	srv := &http.Server{Addr: addr, Handler: Handler(store, pods, time.Now), ReadHeaderTimeout: 5 * time.Second}
	go func() {
		<-ctx.Done()
		shutdown, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = srv.Shutdown(shutdown)
	}()
	log.Printf("authz: serving %s (policy from %s, every %s)", addr, namespace, interval)
	if err := srv.ListenAndServe(); err != http.ErrServerClosed {
		return err
	}
	return nil
}
