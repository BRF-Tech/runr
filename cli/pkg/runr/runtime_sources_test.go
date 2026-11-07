package runr

import (
	"encoding/json"
	"errors"
	"net/http"
	"strings"
	"testing"
)

// A runtime source's credentials stay out of the debug trace.
func TestRuntimeSourceCredentialsRedacted(t *testing.T) {
	out := RedactJSON([]byte(`{"name":"acme","git_token":"glpat-s3cr3t","ssh_private_key":"-----BEGIN KEY-----","registry_password":"pw-s3cr3t","registry_username":"ci"}`))
	for _, secret := range []string{"glpat-s3cr3t", "BEGIN KEY", "pw-s3cr3t"} {
		if strings.Contains(out, secret) {
			t.Fatalf("trace shows %q: %s", secret, out)
		}
	}
	if !strings.Contains(out, `"registry_username":"ci"`) || !strings.Contains(out, `"name":"acme"`) {
		t.Fatalf("trace = %s", out)
	}
}

// Nil fields are not sent; "" of a removable field or a credential is null.
func TestRuntimeSourceInputBody(t *testing.T) {
	name, empty, token := "acme", "", "t0ken"
	n := 30
	in := RuntimeSourceInput{Name: &name, Ref: &empty, GitToken: &token, IntervalMinutes: &n}
	in.ClearRegistryCredentials()
	b := in.body()
	want := map[string]any{"name": "acme", "ref": nil, "git_token": "t0ken", "interval_minutes": 30,
		"registry_username": nil, "registry_password": nil}
	got, _ := json.Marshal(b)
	exp, _ := json.Marshal(want)
	if string(got) != string(exp) {
		t.Fatalf("body = %s, want %s", got, exp)
	}
	if !(RuntimeSourceInput{}).Empty() || in.Empty() {
		t.Fatal("Empty")
	}
}

func TestPickRuntimeSource(t *testing.T) {
	list := []RuntimeSource{{ID: "a1", Name: "acme"}, {ID: "b2", Name: "Shared"}, {ID: "c3", Name: "shared"}}
	if s, err := PickRuntimeSource(list, "ACME"); err != nil || s.ID != "a1" {
		t.Fatalf("by name: %+v %v", s, err)
	}
	if s, err := PickRuntimeSource(list, "c3"); err != nil || s.Name != "shared" {
		t.Fatalf("by id: %+v %v", s, err)
	}
	if _, err := PickRuntimeSource(list, "shared"); err == nil || !strings.Contains(err.Error(), "use the id (b2, c3)") {
		t.Fatalf("ambiguous: %v", err)
	}
	var nf *NotFoundError
	if _, err := PickRuntimeSource(list, "nope"); !errors.As(err, &nf) {
		t.Fatalf("missing: %v", err)
	}
}

// A 502 of a sync carries the source: the error answer's data is kept.
func TestAPIErrorKeepsData(t *testing.T) {
	e := parseAPIError(http.StatusBadGateway, "POST", "/runtime-sources/x/sync", []byte(`{"data":{"id":"x","status":"unreachable"}}`))
	if string(e.Data) != `{"id":"x","status":"unreachable"}` {
		t.Fatalf("data = %s", e.Data)
	}
	if e := parseAPIError(422, "POST", "/x", []byte(`{"message":"no","data":null}`)); e.Data != nil {
		t.Fatalf("null data kept: %s", e.Data)
	}
}

func TestRuntimeVersionState(t *testing.T) {
	rt := CatalogRuntime{Lifecycle: &RuntimeLifecycle{State: LifecycleDeprecated}}
	cases := []struct {
		runtime, version, want string
	}{
		{LifecycleActive, LifecycleActive, LifecycleActive},
		{LifecycleDeprecated, LifecycleActive, LifecycleDeprecated},
		{LifecycleActive, LifecycleDeprecated, LifecycleDeprecated},
		{LifecycleDeprecated, LifecycleEOL, LifecycleEOL},
		{LifecycleSunset, LifecycleActive, LifecycleSunset},
		{LifecycleOrphaned, LifecycleEOL, LifecycleOrphaned},
	}
	for _, c := range cases {
		rt.Lifecycle.State = c.runtime
		if got := rt.State(&RuntimeVersion{Lifecycle: c.version}); got != c.want {
			t.Errorf("runtime %s, version %s: %s, want %s", c.runtime, c.version, got, c.want)
		}
	}
	if got := (&CatalogRuntime{}).State(&RuntimeVersion{}); got != "" {
		t.Errorf("older API: %q", got)
	}
}
