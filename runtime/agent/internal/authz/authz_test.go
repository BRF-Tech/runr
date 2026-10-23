package authz

import (
	"context"
	"encoding/base64"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	corev1 "k8s.io/api/core/v1"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/apis/meta/v1/unstructured"
	"k8s.io/apimachinery/pkg/runtime"
	"k8s.io/apimachinery/pkg/runtime/schema"
	"k8s.io/apimachinery/pkg/types"
	dynamicfake "k8s.io/client-go/dynamic/fake"
	"k8s.io/client-go/kubernetes/fake"
)

var (
	keyEU    = []byte("0123456789abcdef0123456789abcdef")
	keyOther = []byte("fedcba9876543210fedcba9876543210")
	now      = time.Unix(1_800_000_000, 0)
)

func token(sub string) string {
	return Sign(Claims{Subject: sub, Region: "eu", IssuedAt: now.Unix()}, keyEU)
}

func policy(mode string, crossTenant bool) *Policy {
	orders, _ := NewService("fn", "orders", map[string]string{
		AnnotationName:    "orders.platform",
		AnnotationOrg:     "platform",
		AnnotationCallers: "shop.platform, Billing.acme",
		AnnotationKind:    "project",
	})
	model, _ := NewService("fn", "m-embed-acme", map[string]string{
		AnnotationName:    "m-embed.acme",
		AnnotationCallers: "chat.acme",
		AnnotationKind:    "model",
	})
	return Build([]*Service{orders, model}, map[string][]byte{"eu": keyEU}, map[string]string{"fn": mode}, crossTenant)
}

func decide(p *Policy, host, tok string) Decision {
	return p.Decide(host, tok, func(t string) (*Claims, error) { return Verify(t, p.Keys, now) }, nil)
}

func TestVerify(t *testing.T) {
	keys := map[string][]byte{"eu": keyEU}
	c, err := Verify(token("shop.platform"), keys, now)
	if err != nil || c.Subject != "shop.platform" {
		t.Fatalf("valid token: %v %+v", err, c)
	}

	for name, tok := range map[string]string{
		"empty":        "",
		"other key":    Sign(Claims{Subject: "shop.platform", Region: "eu"}, keyOther),
		"other region": Sign(Claims{Subject: "shop.platform", Region: "us"}, keyEU),
		"expired":      Sign(Claims{Subject: SystemSubject, Region: "eu", Expires: now.Unix() - 1}, keyEU),
		"garbage":      "rit1.not-json.sig",
		"version":      "rit2." + token("shop.platform")[5:],
	} {
		if _, err := Verify(tok, keys, now); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
	if _, err := Verify(Sign(Claims{Subject: SystemSubject, Region: "eu", Expires: now.Unix() + 60}, keyEU), keys, now); err != nil {
		t.Errorf("unexpired system token: %v", err)
	}
}

// A token the RunR API signed (tests/Unit/InternalNetworkTest.php signs the same claims with the
// same application key): the region key derivation and the encoding agree.
func TestVerifiesTheAPIsTokens(t *testing.T) {
	key, _ := base64.StdEncoding.DecodeString("hP09Z69c26X5B11zOXzpkNdiWS/kH9Y47hLz7Jaijx4=")
	const apiToken = "rit1.eyJzdWIiOiJzaG9wLmFjbWUiLCJyZWciOiIwMUpURVNUUkVHSU9OMDAwMDAwMDAwMDAwMCIsImlhdCI6MTc5MDAwMDAwMH0.mm2iMkh1xfljaSg1kDLzhwMr5NgXetVMS9tMoD1KjFw"
	c, err := Verify(apiToken, map[string][]byte{"01JTESTREGION0000000000000": key}, now)
	if err != nil || c.Subject != "shop.acme" || c.IssuedAt != 1_790_000_000 {
		t.Fatalf("the API's token: %v %+v", err, c)
	}
}

func TestDecideEnforce(t *testing.T) {
	p := policy(ModeEnforce, true)

	cases := []struct {
		name, host, token string
		allowed           bool
		status            int
	}{
		{"listed caller", "orders.fn.svc.cluster.local", token("shop.platform"), true, 0},
		{"internal host", "orders.platform.runr.internal", token("shop.platform"), true, 0},
		{"short host with port", "orders.fn:80", token("shop.platform"), true, 0},
		{"self", "orders.fn.svc", token("orders.platform"), true, 0},
		{"runr itself", "orders.fn.svc.cluster.local", token(SystemSubject), true, 0},
		{"cross-organization grant", "orders.fn", token("billing.acme"), true, 0},
		{"not listed", "orders.fn.svc.cluster.local", token("stranger.platform"), false, 403},
		{"no token", "orders.fn.svc.cluster.local", "", false, 401},
		{"forged token", "orders.fn.svc.cluster.local", Sign(Claims{Subject: "shop.platform", Region: "eu"}, keyOther), false, 401},
		{"model service, bound project", "m-embed-acme.fn.svc.cluster.local", token("chat.acme"), true, 0},
		{"model service, other project", "m-embed-acme.fn.svc.cluster.local", token("shop.platform"), false, 403},
		{"traffic tag of the service", "canary-orders.fn.svc.cluster.local", token("stranger.platform"), false, 403},
		{"traffic tag, listed caller", "canary-orders.fn", token("shop.platform"), true, 0},
	}
	for _, c := range cases {
		d := decide(p, c.host, c.token)
		if d.Allowed != c.allowed || d.Admit != c.allowed || (!c.allowed && d.Status != c.status) || !d.Managed {
			t.Errorf("%s: %+v", c.name, d)
		}
	}

	if d := decide(p, "something.else.svc.cluster.local", ""); !d.Admit || d.Managed {
		t.Errorf("a host RunR does not manage is admitted as it is: %+v", d)
	}
}

func TestCrossOrganizationGrantsSwitchedOff(t *testing.T) {
	p := policy(ModeEnforce, false)
	if d := decide(p, "orders.fn", token("billing.acme")); d.Allowed || d.Status != 403 {
		t.Fatalf("cross-organization caller admitted: %+v", d)
	}
	if d := decide(p, "orders.fn", token("shop.platform")); !d.Allowed {
		t.Fatalf("same organization refused: %+v", d)
	}
}

func TestAuditAdmitsWhatItWouldDeny(t *testing.T) {
	p := policy(ModeAudit, true)
	d := decide(p, "orders.fn", token("stranger.platform"))
	if d.Allowed || !d.Admit || d.Status != 403 {
		t.Fatalf("audit: %+v", d)
	}
	// a namespace without a mode enforces: audit is the operator's explicit choice
	p.Modes = map[string]string{}
	if d := decide(p, "orders.fn", ""); d.Admit || d.Status != 401 {
		t.Fatalf("no mode: %+v", d)
	}
}

func TestHandler(t *testing.T) {
	store := &Store{}
	h := Handler(store, nil, func() time.Time { return now })

	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/healthz", nil))
	if rec.Code != http.StatusServiceUnavailable {
		t.Fatalf("healthz before the policy loaded: %d", rec.Code)
	}

	store.Set(policy(ModeEnforce, true))
	call := func(host, tok string) *httptest.ResponseRecorder {
		req := httptest.NewRequest(http.MethodGet, "/authz", nil)
		req.Header.Set("X-Forwarded-Host", host)
		if tok != "" {
			req.Header.Set(HeaderToken, tok)
		}
		rec := httptest.NewRecorder()
		h.ServeHTTP(rec, req)
		return rec
	}

	ok := call("orders.platform.runr.internal", token("shop.platform"))
	if ok.Code != http.StatusOK || ok.Header().Get(HeaderCaller) != "shop.platform" || ok.Header().Get(HeaderDecision) != "allow" {
		t.Fatalf("allowed call: %d %v", ok.Code, ok.Header())
	}
	if rec := call("orders.fn", ""); rec.Code != http.StatusUnauthorized || rec.Header().Get(HeaderDecision) != "deny" {
		t.Fatalf("no token: %d", rec.Code)
	}
	if rec := call("orders.fn", token("stranger.platform")); rec.Code != http.StatusForbidden {
		t.Fatalf("not listed: %d", rec.Code)
	}

	store.Set(policy(ModeAudit, true))
	if rec := call("orders.fn", token("stranger.platform")); rec.Code != http.StatusOK || rec.Header().Get(HeaderDecision) != "audit-deny" {
		t.Fatalf("audit: %d %v", rec.Code, rec.Header())
	}
}

func TestLoad(t *testing.T) {
	ksvc := func(ns, name string, labels, annotations map[string]string) *unstructured.Unstructured {
		u := &unstructured.Unstructured{}
		u.SetAPIVersion("serving.knative.dev/v1")
		u.SetKind("Service")
		u.SetNamespace(ns)
		u.SetName(name)
		u.SetLabels(labels)
		u.SetAnnotations(annotations)
		return u
	}
	managed := map[string]string{LabelManaged: "managed"}
	scheme := runtime.NewScheme()
	dyn := dynamicfake.NewSimpleDynamicClientWithCustomListKinds(scheme,
		map[schema.GroupVersionResource]string{knativeServices: "ServiceList"},
		ksvc("fn", "orders", map[string]string{LabelManaged: "managed", LabelProject: "p-orders"}, map[string]string{AnnotationName: "orders.platform", AnnotationCallers: "shop.platform", AnnotationKind: "project"}),
		ksvc("fn", "orders-pr-3", managed, map[string]string{AnnotationName: "orders.platform", AnnotationKind: "preview"}),
		ksvc("other", "foreign", nil, map[string]string{AnnotationName: "foreign.platform"}),
	)
	cs := fake.NewSimpleClientset(
		&corev1.ConfigMap{ObjectMeta: metav1.ObjectMeta{Name: ConfigName, Namespace: "knative-serving"}, Data: map[string]string{"mode.fn": "enforce", "cross_tenant": "false"}},
		&corev1.Secret{ObjectMeta: metav1.ObjectMeta{Name: ConfigName, Namespace: "knative-serving"}, Data: map[string][]byte{"key.eu": keyEU}},
	)

	p, err := (&Loader{Namespace: "knative-serving", Dynamic: dyn, Clientset: cs}).Load(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := p.Services["orders.platform.runr.internal"]; !ok {
		t.Fatalf("managed service missing: %v", p.Services)
	}
	if s := p.Services["orders.platform.runr.internal"]; s.Service != "orders" {
		t.Fatalf("a preview took the project's runr.internal host: %+v", s)
	}
	if _, ok := p.Services["orders-pr-3.fn.svc.cluster.local"]; !ok {
		t.Fatal("a preview keeps its cluster-local hosts")
	}
	if _, ok := p.Services["foreign.other"]; ok {
		t.Fatal("a Knative Service without the label is not RunR's")
	}
	if p.Modes["fn"] != ModeEnforce || p.CrossTenant || string(p.Keys["eu"]) != string(keyEU) {
		t.Fatalf("config: %+v", p)
	}
	if name, ok := p.NameOf(PodIdentity{Namespace: "fn", ProjectID: "p-orders"}); !ok || name != "orders.platform" {
		t.Fatalf("project index: %q %v", name, ok)
	}
}

func pod(ns, name, ip string, labels map[string]string) *corev1.Pod {
	return &corev1.Pod{
		ObjectMeta: metav1.ObjectMeta{Namespace: ns, Name: name, UID: types.UID(name), Labels: labels},
		Status:     corev1.PodStatus{PodIP: ip, Phase: corev1.PodRunning},
	}
}

// A caller without a token is the project whose pod has its address: a workload deployed before
// the internal network, a queue consumer of an earlier release, a function calling its bound
// model service without a header.
func TestCallerWithoutTokenIsKnownByItsPod(t *testing.T) {
	p := policy(ModeEnforce, true)
	shop, _ := NewService("fn", "shop", map[string]string{AnnotationName: "shop.platform", AnnotationKind: "project"})
	shop.ProjectID = "p-shop"
	orders := p.Services["orders.fn"]
	orders.ProjectID = "p-orders"
	p = Build([]*Service{orders, shop, p.Services["m-embed-acme.fn"]}, p.Keys, p.Modes, true)

	pods := NewPods()
	pods.Set(pod("fn", "shop-00001-deployment-abc", "10.244.1.5", map[string]string{LabelProject: "p-shop"}))
	pods.Set(pod("fn", "orders-qc-xyz", "10.244.1.6", map[string]string{LabelProject: "p-orders"}))
	pods.Set(pod("fn", "chat-00003-deployment-def", "10.244.1.7", map[string]string{LabelProject: "p-chat", LabelInternalName: "chat.acme"}))
	hostNet := pod("fn", "host", "192.168.1.10", map[string]string{LabelProject: "p-shop"})
	hostNet.Spec.HostNetwork = true
	pods.Set(hostNet)
	done := pod("fn", "shop-trigger-1", "10.244.1.8", map[string]string{LabelProject: "p-shop"})
	done.Status.Phase = corev1.PodSucceeded
	pods.Set(done)

	from := func(ip string) func() (string, bool) {
		return func() (string, bool) {
			id, ok := pods.Lookup(context.Background(), ip)
			if !ok {
				return "", false
			}
			return p.NameOf(id)
		}
	}
	verify := func(t string) (*Claims, error) { return Verify(t, p.Keys, now) }

	cases := []struct {
		name, host, ip string
		allowed        bool
		status         int
	}{
		{"listed project, old pod", "orders.fn.svc.cluster.local", "10.244.1.5", true, 0},
		{"queue consumer to its own sink", "orders.fn.svc.cluster.local", "10.244.1.6", true, 0},
		{"bound project to its model service", "m-embed-acme.fn.svc.cluster.local", "10.244.1.7", true, 0},
		{"project not on the allow list", "m-embed-acme.fn.svc.cluster.local", "10.244.1.5", false, 403},
		{"no pod of a project", "orders.fn", "10.244.9.9", false, 401},
		{"host network pod", "orders.fn", "192.168.1.10", false, 401},
		{"finished pod", "orders.fn", "10.244.1.8", false, 401},
	}
	for _, c := range cases {
		d := p.Decide(c.host, "", verify, from(c.ip))
		if d.Allowed != c.allowed || (!c.allowed && d.Status != c.status) || (c.allowed && d.Via != "address") {
			t.Errorf("%s: %+v", c.name, d)
		}
	}

	// a token wins over the address, a bad token is refused even from a known pod
	if d := p.Decide("orders.fn", token("stranger.platform"), verify, from("10.244.1.5")); d.Allowed || d.Via != "token" {
		t.Errorf("token over address: %+v", d)
	}
	if d := p.Decide("orders.fn", "rit1.bad.token", verify, from("10.244.1.5")); d.Allowed || d.Status != 401 {
		t.Errorf("bad token: %+v", d)
	}

	// the pod is gone: its address names nobody
	pods.Remove("shop-00001-deployment-abc")
	if d := p.Decide("orders.fn", "", verify, from("10.244.1.5")); d.Allowed || d.Status != 401 {
		t.Errorf("deleted pod: %+v", d)
	}
}

// An environment's pod carries runr/environment-of instead of runr/project-id
// (docs/environments.md): it is known by its own internal name, <project>-<environment>.<org>,
// never as the project's own workload.
func TestEnvironmentPodIsKnownByItsOwnName(t *testing.T) {
	orders, _ := NewService("fn", "orders", map[string]string{AnnotationName: "orders.platform", AnnotationCallers: "shop.platform", AnnotationKind: "project"})
	orders.ProjectID = "p-orders"
	stagingOrders, _ := NewService("fn", "orders-staging", map[string]string{AnnotationName: "orders-staging.platform", AnnotationCallers: "shop-staging.platform", AnnotationKind: "project"})
	shop, _ := NewService("fn", "shop", map[string]string{AnnotationName: "shop.platform", AnnotationKind: "project"})
	shop.ProjectID = "p-shop"
	p := Build([]*Service{orders, stagingOrders, shop}, map[string][]byte{"eu": keyEU}, map[string]string{"fn": ModeEnforce}, true)

	pods := NewPods()
	pods.Set(pod("fn", "shop-staging-00001-deployment-abc", "10.244.2.5", map[string]string{LabelEnvironmentOf: "p-shop", LabelInternalName: "shop-staging.platform"}))
	// without its internal name an environment's pod is nobody (not the project's production)
	pods.Set(pod("fn", "shop-dev-00001-deployment-def", "10.244.2.6", map[string]string{LabelEnvironmentOf: "p-shop"}))

	from := func(ip string) func() (string, bool) {
		return func() (string, bool) {
			id, ok := pods.Lookup(context.Background(), ip)
			if !ok {
				return "", false
			}
			return p.NameOf(id)
		}
	}
	verify := func(t string) (*Claims, error) { return Verify(t, p.Keys, now) }

	if d := p.Decide("orders-staging.fn.svc.cluster.local", "", verify, from("10.244.2.5")); !d.Allowed || d.Via != "address" {
		t.Errorf("staging to staging: %+v", d)
	}
	if d := p.Decide("orders.fn.svc.cluster.local", "", verify, from("10.244.2.5")); d.Allowed || d.Status != 403 {
		t.Errorf("staging to production: %+v", d)
	}
	if d := p.Decide("orders-staging.fn.svc.cluster.local", "", verify, from("10.244.2.6")); d.Allowed || d.Status != 401 {
		t.Errorf("an environment's pod without its name: %+v", d)
	}
}

func TestHandlerKnowsAPodBySourceAddress(t *testing.T) {
	store := &Store{}
	pods := NewPods()
	h := Handler(store, pods, func() time.Time { return now })
	p := policy(ModeEnforce, true)
	shop, _ := NewService("fn", "shop", map[string]string{AnnotationName: "shop.platform", AnnotationKind: "project"})
	shop.ProjectID = "p-shop"
	store.Set(Build([]*Service{p.Services["orders.fn"], shop}, p.Keys, p.Modes, true))

	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/healthz", nil))
	if rec.Code != http.StatusServiceUnavailable {
		t.Fatalf("healthz before the pods were listed: %d", rec.Code)
	}
	pods.synced.Store(true)
	pods.Set(pod("fn", "shop-1", "10.244.1.5", map[string]string{LabelProject: "p-shop"}))

	req := httptest.NewRequest(http.MethodGet, "/authz", nil)
	req.Header.Set("X-Forwarded-Host", "orders.fn.svc.cluster.local")
	req.Header.Set("X-Forwarded-For", "10.244.1.5")
	rec = httptest.NewRecorder()
	h.ServeHTTP(rec, req)
	if rec.Code != http.StatusOK || rec.Header().Get(HeaderCaller) != "shop.platform" {
		t.Fatalf("pod of a listed project: %d %v", rec.Code, rec.Header())
	}

	// only the connection's own address counts: an entry a caller put in front is not it
	req.Header.Set("X-Forwarded-For", "10.244.1.5, 10.244.7.7")
	rec = httptest.NewRecorder()
	h.ServeHTTP(rec, req)
	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("forwarded entry taken for the caller: %d", rec.Code)
	}
}

// A pod that calls the moment its container starts is found although the API server learns its
// address a moment later: the live lookup asks again before the caller is nobody.
func TestLookupWaitsForAnAddressTheAPIServerReportsLate(t *testing.T) {
	calls := 0
	pods := NewPods()
	pods.lookup = func(ctx context.Context, ip string) (*corev1.Pod, error) {
		calls++
		if calls < 3 {
			return nil, nil
		}
		return pod("fn", "legacy-00001-deployment-abc", ip, map[string]string{LabelProject: "p-legacy"}), nil
	}

	id, ok := pods.Lookup(context.Background(), "10.244.3.7")
	if !ok || id.ProjectID != "p-legacy" || calls != 3 {
		t.Fatalf("late address: %+v %v after %d lookups", id, ok, calls)
	}
	// known now: no further lookup
	if _, ok := pods.Lookup(context.Background(), "10.244.3.7"); !ok || calls != 3 {
		t.Fatalf("second call: %v after %d lookups", ok, calls)
	}

	// an address of no pod: a few lookups, then nobody — and no new lookup within five seconds
	calls = 0
	pods.lookup = func(ctx context.Context, ip string) (*corev1.Pod, error) {
		calls++
		return nil, nil
	}
	if _, ok := pods.Lookup(context.Background(), "10.244.9.9"); ok || calls != len(lookupRetries)+1 {
		t.Fatalf("no pod: %v after %d lookups", ok, calls)
	}
	if _, ok := pods.Lookup(context.Background(), "10.244.9.9"); ok || calls != len(lookupRetries)+1 {
		t.Fatalf("throttled: %v after %d lookups", ok, calls)
	}
}
