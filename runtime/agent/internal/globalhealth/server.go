// Package globalhealth answers the health checks of global projects (docs/global-projects.md):
// the PowerDNS servers of the global zone check every region's copy of a global project with
// `ifurlup` on http://runr-health.<zone>/global-health/<slug>, which the region's Traefik routes
// here. A copy is healthy when its Knative Service in the project namespace is labelled as a
// global project's and Ready — read from the cluster, so a check never wakes a function that
// scaled to zero.
package globalhealth

import (
	"context"
	"errors"
	"log"
	"net"
	"net/http"
	"regexp"
	"strings"
	"sync"
	"time"

	apierrors "k8s.io/apimachinery/pkg/api/errors"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/apis/meta/v1/unstructured"
	"k8s.io/apimachinery/pkg/runtime/schema"
	"k8s.io/client-go/dynamic"
)

const (
	// GlobalLabel marks the Knative Service of a global project (set by RunR's deploy).
	GlobalLabel = "runr/global-project"

	pathPrefix      = "/global-health/"
	cacheTTL        = 2 * time.Second
	lookupTimeout   = 3 * time.Second
	shutdownTimeout = 5 * time.Second
	maxSlugLength   = 63
)

// KnativeServices is the GroupVersionResource of Knative Services.
var KnativeServices = schema.GroupVersionResource{Group: "serving.knative.dev", Version: "v1", Resource: "services"}

// slugPattern: a DNS-1035 label, what a Knative Service may be named.
var slugPattern = regexp.MustCompile(`^[a-z]([-a-z0-9]*[a-z0-9])?$`)

// State of one global project's copy in this region.
type State int

const (
	// NotFound: no Knative Service of that name, or not a global project's.
	NotFound State = iota
	// NotReady: the Service is not Ready (or the cluster could not be asked).
	NotReady
	// Ready: the Service is Ready and serves.
	Ready
)

// Server answers /global-health/<slug> from the Knative Services of one namespace.
type Server struct {
	client    dynamic.Interface
	namespace string
	now       func() time.Time

	mu    sync.Mutex
	cache map[string]cached
}

type cached struct {
	state State
	at    time.Time
}

// New server for the project namespace of this region.
func New(client dynamic.Interface, namespace string) *Server {
	return &Server{
		client:    client,
		namespace: namespace,
		now:       time.Now,
		cache:     map[string]cached{},
	}
}

// Handler routes /healthz and /global-health/<slug>.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, r *http.Request) {
		if !readOnly(w, r) {
			return
		}
		write(w, r, http.StatusOK, "ok")
	})
	mux.HandleFunc(pathPrefix, s.serveHealth)
	return mux
}

func (s *Server) serveHealth(w http.ResponseWriter, r *http.Request) {
	if !readOnly(w, r) {
		return
	}

	slug := strings.TrimPrefix(r.URL.Path, pathPrefix)
	if !ValidSlug(slug) {
		write(w, r, http.StatusBadRequest, "invalid project")
		return
	}

	switch s.State(r.Context(), slug) {
	case Ready:
		write(w, r, http.StatusOK, "ok")
	case NotFound:
		write(w, r, http.StatusNotFound, "not found")
	default:
		write(w, r, http.StatusServiceUnavailable, "not ready")
	}
}

// ValidSlug reports whether slug can name a Knative Service.
func ValidSlug(slug string) bool {
	return slug != "" && len(slug) <= maxSlugLength && slugPattern.MatchString(slug)
}

// State of the project's copy, cached per slug for cacheTTL: every PowerDNS server of the zone
// checks every region every few seconds, the API server is asked at most once per TTL and slug.
func (s *Server) State(ctx context.Context, slug string) State {
	now := s.now()

	s.mu.Lock()
	if c, ok := s.cache[slug]; ok && now.Sub(c.at) < cacheTTL {
		s.mu.Unlock()
		return c.state
	}
	s.mu.Unlock()

	state := s.lookup(ctx, slug)

	s.mu.Lock()
	s.cache[slug] = cached{state: state, at: now}
	// entries of projects nobody checks any more do not pile up
	for key, c := range s.cache {
		if now.Sub(c.at) >= cacheTTL*10 {
			delete(s.cache, key)
		}
	}
	s.mu.Unlock()

	return state
}

func (s *Server) lookup(ctx context.Context, slug string) State {
	ctx, cancel := context.WithTimeout(ctx, lookupTimeout)
	defer cancel()

	svc, err := s.client.Resource(KnativeServices).Namespace(s.namespace).Get(ctx, slug, metav1.GetOptions{})
	if err != nil {
		if apierrors.IsNotFound(err) {
			return NotFound
		}
		log.Printf("global health: reading Knative Service %s/%s: %v", s.namespace, slug, err)
		return NotReady
	}

	return StateOf(svc)
}

// StateOf a Knative Service: NotFound unless it carries the global-project label, then Ready
// when its Ready condition is True.
func StateOf(svc *unstructured.Unstructured) State {
	if svc.GetLabels()[GlobalLabel] != "true" {
		return NotFound
	}

	conditions, _, _ := unstructured.NestedSlice(svc.Object, "status", "conditions")
	for _, c := range conditions {
		cond, ok := c.(map[string]interface{})
		if !ok || cond["type"] != "Ready" {
			continue
		}
		if cond["status"] == "True" {
			return Ready
		}
		return NotReady
	}
	return NotReady
}

// Run serves on addr until ctx is done, then shuts down gracefully.
func (s *Server) Run(ctx context.Context, addr string) error {
	srv := &http.Server{
		Addr:              addr,
		Handler:           s.Handler(),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       10 * time.Second,
		WriteTimeout:      10 * time.Second,
		BaseContext:       func(net.Listener) context.Context { return ctx },
	}

	errCh := make(chan error, 1)
	go func() {
		errCh <- srv.ListenAndServe()
	}()

	select {
	case err := <-errCh:
		if errors.Is(err, http.ErrServerClosed) {
			return nil
		}
		return err
	case <-ctx.Done():
		shutdownCtx, cancel := context.WithTimeout(context.Background(), shutdownTimeout)
		defer cancel()
		if err := srv.Shutdown(shutdownCtx); err != nil {
			return err
		}
		return nil
	}
}

// readOnly answers 405 to anything but GET / HEAD.
func readOnly(w http.ResponseWriter, r *http.Request) bool {
	if r.Method == http.MethodGet || r.Method == http.MethodHead {
		return true
	}
	w.Header().Set("Allow", "GET, HEAD")
	write(w, r, http.StatusMethodNotAllowed, "method not allowed")
	return false
}

func write(w http.ResponseWriter, r *http.Request, status int, body string) {
	w.Header().Set("Content-Type", "text/plain; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(status)
	if r.Method != http.MethodHead {
		_, _ = w.Write([]byte(body))
	}
}
