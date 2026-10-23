package globalhealth

import (
	"context"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"k8s.io/apimachinery/pkg/apis/meta/v1/unstructured"
	"k8s.io/apimachinery/pkg/runtime"
	"k8s.io/apimachinery/pkg/runtime/schema"
	dynamicfake "k8s.io/client-go/dynamic/fake"
	k8stesting "k8s.io/client-go/testing"
)

const ns = "runr-projects"

func knativeService(name, namespace string, global bool, ready string) *unstructured.Unstructured {
	labels := map[string]interface{}{"app.kubernetes.io/managed-by": "runr"}
	if global {
		labels[GlobalLabel] = "true"
	}
	obj := map[string]interface{}{
		"apiVersion": "serving.knative.dev/v1",
		"kind":       "Service",
		"metadata": map[string]interface{}{
			"name":      name,
			"namespace": namespace,
			"labels":    labels,
		},
	}
	if ready != "" {
		obj["status"] = map[string]interface{}{
			"conditions": []interface{}{
				map[string]interface{}{"type": "ConfigurationsReady", "status": "True"},
				map[string]interface{}{"type": "Ready", "status": ready},
			},
		}
	}
	return &unstructured.Unstructured{Object: obj}
}

func fakeClient(objects ...runtime.Object) *dynamicfake.FakeDynamicClient {
	return dynamicfake.NewSimpleDynamicClientWithCustomListKinds(
		runtime.NewScheme(),
		map[schema.GroupVersionResource]string{KnativeServices: "ServiceList"},
		objects...,
	)
}

func get(t *testing.T, h http.Handler, method, path string) (int, string) {
	t.Helper()
	req := httptest.NewRequest(method, path, nil)
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, req)
	body, _ := io.ReadAll(rec.Body)
	return rec.Code, string(body)
}

func TestHealthAnswers(t *testing.T) {
	client := fakeClient(
		knativeService("shop", ns, true, "True"),
		knativeService("warming", ns, true, "Unknown"),
		knativeService("broken", ns, true, "False"),
		knativeService("fresh", ns, true, ""),
		knativeService("regional", ns, false, "True"),
		knativeService("elsewhere", "other-ns", true, "True"),
	)
	h := New(client, ns).Handler()

	cases := []struct {
		path   string
		status int
		body   string
	}{
		{"/global-health/shop", http.StatusOK, "ok"},
		{"/global-health/warming", http.StatusServiceUnavailable, "not ready"},
		{"/global-health/broken", http.StatusServiceUnavailable, "not ready"},
		{"/global-health/fresh", http.StatusServiceUnavailable, "not ready"},
		// a regional project's Service is not answered for (no slug enumeration through it)
		{"/global-health/regional", http.StatusNotFound, "not found"},
		// only the agent's own project namespace counts
		{"/global-health/elsewhere", http.StatusNotFound, "not found"},
		{"/global-health/missing", http.StatusNotFound, "not found"},
		{"/healthz", http.StatusOK, "ok"},
	}
	for _, tc := range cases {
		t.Run(tc.path, func(t *testing.T) {
			status, body := get(t, h, http.MethodGet, tc.path)
			if status != tc.status || body != tc.body {
				t.Fatalf("GET %s = %d %q, want %d %q", tc.path, status, body, tc.status, tc.body)
			}
		})
	}
}

func TestHealthRejectsInvalidSlugsAndMethods(t *testing.T) {
	h := New(fakeClient(knativeService("shop", ns, true, "True")), ns).Handler()

	for _, path := range []string{
		"/global-health/",
		"/global-health/Shop",
		"/global-health/shop/extra",
		"/global-health/-shop",
		"/global-health/shop-",
		"/global-health/9shop",
		"/global-health/shop.example",
		"/global-health/" + strings.Repeat("a", maxSlugLength+1),
	} {
		if status, _ := get(t, h, http.MethodGet, path); status != http.StatusBadRequest {
			t.Errorf("GET %q = %d, want 400", path, status)
		}
	}

	for _, method := range []string{http.MethodPost, http.MethodPut, http.MethodDelete, http.MethodPatch} {
		req := httptest.NewRequest(method, "/global-health/shop", nil)
		rec := httptest.NewRecorder()
		h.ServeHTTP(rec, req)
		if rec.Code != http.StatusMethodNotAllowed || rec.Header().Get("Allow") != "GET, HEAD" {
			t.Errorf("%s = %d (Allow %q), want 405 with Allow: GET, HEAD", method, rec.Code, rec.Header().Get("Allow"))
		}
	}

	status, body := get(t, h, http.MethodHead, "/global-health/shop")
	if status != http.StatusOK || body != "" {
		t.Errorf("HEAD = %d %q, want 200 without a body", status, body)
	}
}

func TestValidSlug(t *testing.T) {
	cases := map[string]bool{
		"a":         true,
		"shop":      true,
		"shop-acme": true,
		"a1":        true,
		"":          false,
		"1a":        false,
		"a_b":       false,
		"A":         false,
		"a-":        false,
	}
	for slug, want := range cases {
		if got := ValidSlug(slug); got != want {
			t.Errorf("ValidSlug(%q) = %v, want %v", slug, got, want)
		}
	}
}

func TestStateIsCachedPerSlug(t *testing.T) {
	client := fakeClient(knativeService("shop", ns, true, "True"))
	var gets atomic.Int32
	client.PrependReactor("get", "services", func(k8stesting.Action) (bool, runtime.Object, error) {
		gets.Add(1)
		return false, nil, nil
	})

	s := New(client, ns)
	now := time.Date(2026, 10, 21, 10, 0, 0, 0, time.UTC)
	s.now = func() time.Time { return now }

	for i := 0; i < 5; i++ {
		if got := s.State(context.Background(), "shop"); got != Ready {
			t.Fatalf("state = %v, want Ready", got)
		}
	}
	if gets.Load() != 1 {
		t.Fatalf("%d API reads within the TTL, want 1", gets.Load())
	}

	// another slug has its own entry
	if got := s.State(context.Background(), "other"); got != NotFound {
		t.Fatalf("other = %v, want NotFound", got)
	}
	if gets.Load() != 2 {
		t.Fatalf("%d API reads, want 2", gets.Load())
	}

	// past the TTL the cluster is asked again and a change shows
	now = now.Add(cacheTTL)
	if err := client.Tracker().Update(KnativeServices, knativeService("shop", ns, true, "False"), ns); err != nil {
		t.Fatal(err)
	}
	if got := s.State(context.Background(), "shop"); got != NotReady {
		t.Fatalf("state after the TTL = %v, want NotReady", got)
	}
	if gets.Load() != 3 {
		t.Fatalf("%d API reads, want 3", gets.Load())
	}
}

func TestOldCacheEntriesAreDropped(t *testing.T) {
	s := New(fakeClient(), ns)
	now := time.Date(2026, 10, 21, 10, 0, 0, 0, time.UTC)
	s.now = func() time.Time { return now }

	s.State(context.Background(), "gone")
	now = now.Add(cacheTTL * 10)
	s.State(context.Background(), "shop")

	s.mu.Lock()
	defer s.mu.Unlock()
	if _, ok := s.cache["gone"]; ok {
		t.Fatal("an entry nobody asked for during 10 TTLs is still cached")
	}
	if _, ok := s.cache["shop"]; !ok {
		t.Fatal("the fresh entry is missing")
	}
}

func TestAPIErrorsAreNotReady(t *testing.T) {
	client := fakeClient(knativeService("shop", ns, true, "True"))
	client.PrependReactor("get", "services", func(k8stesting.Action) (bool, runtime.Object, error) {
		return true, nil, errors.New("connection refused")
	})

	status, body := get(t, New(client, ns).Handler(), http.MethodGet, "/global-health/shop")
	if status != http.StatusServiceUnavailable || body != "not ready" {
		t.Fatalf("GET with an API error = %d %q, want 503 not ready", status, body)
	}
}

func TestStateOfWithoutConditions(t *testing.T) {
	svc := knativeService("shop", ns, true, "")
	if got := StateOf(svc); got != NotReady {
		t.Fatalf("StateOf without status = %v, want NotReady", got)
	}
	svc.SetLabels(map[string]string{GlobalLabel: "false"})
	if got := StateOf(svc); got != NotFound {
		t.Fatalf("StateOf with %s=false = %v, want NotFound", GlobalLabel, got)
	}
}

func TestRunServesUntilCancelled(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	addr := ln.Addr().String()
	ln.Close()

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- New(fakeClient(knativeService("shop", ns, true, "True")), ns).Run(ctx, addr) }()

	var resp *http.Response
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		resp, err = http.Get("http://" + addr + "/global-health/shop")
		if err == nil {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	if err != nil {
		t.Fatalf("server did not come up: %v", err)
	}
	resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		t.Fatalf("GET = %d, want 200", resp.StatusCode)
	}

	cancel()
	select {
	case err := <-done:
		if err != nil {
			t.Fatalf("Run returned %v after the context was cancelled, want nil", err)
		}
	case <-time.After(10 * time.Second):
		t.Fatal("Run did not return after the context was cancelled")
	}
}
