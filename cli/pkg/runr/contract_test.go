package runr

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"testing"
)

// The contract test checks every Endpoint in Contract against the OpenAPI document the API
// exports (php artisan scramble:export, refreshed with `make openapi`):
//
//   - the method and path exist (path parameter names are ignored);
//   - every request body field, query parameter and response field exists in the schema;
//   - where the generator could not type a schema (it emits "string" or an empty schema for
//     arrays built by hand), the field must appear as a quoted key in the endpoint's Sources
//     under api/, so the check still runs against the real code.

const apiDir = "../../../api"

type openAPI struct {
	Paths      map[string]map[string]json.RawMessage `json:"paths"`
	Components struct {
		Schemas map[string]*schema `json:"schemas"`
	} `json:"components"`
}

type operation struct {
	Parameters []struct {
		Name string `json:"name"`
		In   string `json:"in"`
	} `json:"parameters"`
	RequestBody *struct {
		Content map[string]struct {
			Schema *schema `json:"schema"`
		} `json:"content"`
	} `json:"requestBody"`
	Responses map[string]json.RawMessage `json:"responses"`
}

type schema struct {
	Ref                  string             `json:"$ref"`
	Type                 json.RawMessage    `json:"type"`
	Properties           map[string]*schema `json:"properties"`
	Items                *schema            `json:"items"`
	PrefixItems          []*schema          `json:"prefixItems"`
	AdditionalProperties json.RawMessage    `json:"additionalProperties"`
	AllOf                []*schema          `json:"allOf"`
	AnyOf                []*schema          `json:"anyOf"`
	OneOf                []*schema          `json:"oneOf"`
}

func loadSpec(t *testing.T) *openAPI {
	t.Helper()
	data, err := os.ReadFile(filepath.Join("testdata", "openapi.json"))
	if err != nil {
		t.Fatalf("read testdata/openapi.json (run `make openapi`): %v", err)
	}
	var spec openAPI
	if err := json.Unmarshal(data, &spec); err != nil {
		t.Fatalf("parse openapi.json: %v", err)
	}
	return &spec
}

func (s *openAPI) resolve(sc *schema) *schema {
	for i := 0; sc != nil && sc.Ref != "" && i < 16; i++ {
		name := sc.Ref[strings.LastIndex(sc.Ref, "/")+1:]
		sc = s.Components.Schemas[name]
	}
	return sc
}

func (sc *schema) types() []string {
	if sc == nil || len(sc.Type) == 0 {
		return nil
	}
	var one string
	if json.Unmarshal(sc.Type, &one) == nil {
		return []string{one}
	}
	var many []string
	_ = json.Unmarshal(sc.Type, &many)
	return many
}

func (sc *schema) hasType(t string) bool {
	for _, x := range sc.types() {
		if x == t {
			return true
		}
	}
	return false
}

// lookupResult of a field path in a schema.
type lookupResult struct {
	found bool
	// opaque is set when the walk reached an untyped schema; rest are the segments left.
	opaque bool
	rest   []string
}

// lookup walks segs ("data[]", "config", "env_vars") through sc.
func (s *openAPI) lookup(sc *schema, segs []string) lookupResult {
	sc = s.resolve(sc)
	if len(segs) == 0 {
		return lookupResult{found: true}
	}
	if sc == nil {
		return lookupResult{opaque: true, rest: segs}
	}
	if subs := append(append(append([]*schema{}, sc.AllOf...), sc.AnyOf...), sc.OneOf...); len(subs) > 0 {
		var opaque *lookupResult
		for _, sub := range subs {
			r := s.lookup(sub, segs)
			if r.found {
				return r
			}
			if r.opaque && opaque == nil {
				rr := r
				opaque = &rr
			}
		}
		if len(sc.Properties) == 0 {
			if opaque != nil {
				return *opaque
			}
			return lookupResult{}
		}
	}

	name, isArray := strings.CutSuffix(segs[0], "[]")
	if len(sc.Properties) == 0 {
		// Untyped object, string or "any": the generator gave up here.
		return lookupResult{opaque: true, rest: segs}
	}
	prop, ok := sc.Properties[name]
	if !ok {
		return lookupResult{}
	}
	if !isArray {
		return s.lookup(prop, segs[1:])
	}
	p := s.resolve(prop)
	if p == nil {
		return lookupResult{opaque: true, rest: segs[1:]}
	}
	var items *schema
	switch {
	case p.Items != nil:
		items = p.Items
	case len(p.PrefixItems) > 0:
		items = p.PrefixItems[0]
	}
	if items == nil || len(segs) == 1 {
		if len(segs) == 1 {
			return lookupResult{found: true}
		}
		return lookupResult{opaque: true, rest: segs[1:]}
	}
	return s.lookup(items, segs[1:])
}

func splitField(f string) []string {
	return strings.Split(f, ".")
}

func readSources(t *testing.T, files []string) string {
	t.Helper()
	var b strings.Builder
	for _, f := range files {
		data, err := os.ReadFile(filepath.Join(apiDir, filepath.FromSlash(f)))
		if err != nil {
			t.Fatalf("read source %s: %v", f, err)
		}
		b.Write(data)
		b.WriteByte('\n')
	}
	return b.String()
}

func inSources(src string, segs []string) []string {
	var missing []string
	for _, sg := range segs {
		name := strings.TrimSuffix(sg, "[]")
		if !strings.Contains(src, "'"+name+"'") && !strings.Contains(src, "\""+name+"\"") {
			missing = append(missing, name)
		}
	}
	return missing
}

func findOperation(t *testing.T, spec *openAPI, e Endpoint) *operation {
	t.Helper()
	want := NormalizePath(e.Path)
	for p, item := range spec.Paths {
		if NormalizePath(p) != want {
			continue
		}
		raw, ok := item[strings.ToLower(e.Method)]
		if !ok {
			continue
		}
		var op operation
		if err := json.Unmarshal(raw, &op); err != nil {
			t.Fatalf("%s %s: parse operation: %v", e.Method, e.Path, err)
		}
		return &op
	}
	return nil
}

func responseSchema(spec *openAPI, op *operation, status int) *schema {
	raw, ok := op.Responses[strconv.Itoa(status)]
	if !ok {
		return nil
	}
	var r struct {
		Ref     string `json:"$ref"`
		Content map[string]struct {
			Schema *schema `json:"schema"`
		} `json:"content"`
	}
	if json.Unmarshal(raw, &r) != nil {
		return nil
	}
	for _, c := range r.Content {
		return c.Schema
	}
	return nil
}

func requestSchema(op *operation) *schema {
	if op.RequestBody == nil {
		return nil
	}
	for _, c := range op.RequestBody.Content {
		return c.Schema
	}
	return nil
}

func TestContractAgainstOpenAPI(t *testing.T) {
	spec := loadSpec(t)
	for _, e := range Contract {
		e := e
		t.Run(e.Method+" "+e.Path, func(t *testing.T) {
			op := findOperation(t, spec, e)
			if op == nil && e.Optional {
				t.Skipf("optional endpoint %s %s is not in this API's OpenAPI document (the client detects it)", e.Method, e.Path)
			}
			src := ""
			if len(e.Sources) > 0 {
				src = readSources(t, e.Sources)
			}
			if op == nil {
				if !e.NotInSpec {
					t.Fatalf("%s %s is not in the OpenAPI document", e.Method, e.Path)
				}
				// Closure route: the route and its fields must be in the declared sources.
				if !strings.Contains(src, "'"+e.Path+"'") {
					t.Fatalf("%s %s: route not found in %v", e.Method, e.Path, e.Sources)
				}
				for _, f := range e.Response {
					if m := inSources(src, splitField(f)); len(m) > 0 {
						t.Errorf("response field %s: %v not in %v", f, m, e.Sources)
					}
				}
				return
			}
			if e.NotInSpec {
				t.Errorf("%s %s is marked NotInSpec but the OpenAPI document has it", e.Method, e.Path)
			}

			check := func(kind string, sc *schema, f string) {
				if sc == nil {
					if m := inSources(src, splitField(f)); len(src) == 0 || len(m) > 0 {
						t.Errorf("%s field %s: the operation has no %s schema and the field is not in Sources %v", kind, f, kind, e.Sources)
					}
					return
				}
				r := spec.lookup(sc, splitField(f))
				switch {
				case r.found:
				case r.opaque:
					if len(src) == 0 {
						t.Errorf("%s field %s: schema is untyped at %v; declare Sources for this endpoint", kind, f, r.rest)
						return
					}
					if m := inSources(src, r.rest); len(m) > 0 {
						t.Errorf("%s field %s: untyped in the spec and %v not found in %v", kind, f, m, e.Sources)
					}
				default:
					t.Errorf("%s field %s does not exist in the OpenAPI schema", kind, f)
				}
			}

			req := requestSchema(op)
			for _, f := range e.Request {
				check("request", req, f)
			}
			for _, q := range e.Query {
				documented := false
				for _, p := range op.Parameters {
					if p.In == "query" && p.Name == q {
						documented = true
					}
				}
				if !documented {
					if len(src) == 0 || len(inSources(src, []string{q})) > 0 {
						t.Errorf("query parameter %s is neither documented nor found in Sources %v", q, e.Sources)
					}
				}
			}
			if _, ok := op.Responses[strconv.Itoa(e.Status)]; !ok && e.Status != 204 && e.Status != 200 {
				t.Errorf("success status %d is not documented", e.Status)
			}
			for _, f := range e.Response {
				status := e.Status
				if s, rest, ok := strings.Cut(f, ":"); ok {
					n, err := strconv.Atoi(s)
					if err != nil {
						t.Fatalf("bad status prefix in %q", f)
					}
					status, f = n, rest
				}
				check(fmt.Sprintf("response %d", status), responseSchema(spec, op, status), f)
			}
			for _, f := range e.Capabilities {
				var sc *schema
				if strings.HasPrefix(f, "config.") && requestSchema(op) != nil {
					sc = requestSchema(op)
				} else {
					sc = responseSchema(spec, op, e.Status)
				}
				r := spec.lookup(sc, splitField(f))
				present := r.found || (r.opaque && len(src) > 0 && len(inSources(src, r.rest)) == 0)
				t.Logf("capability field %s present in this API: %v", f, present)
			}
		})
	}
}

// Every entry's Permission matches api/config/route_permissions.php, the map the API's
// AuthorizeApiRoute middleware enforces. Skipped for an API without role-based access control.
func TestContractPermissions(t *testing.T) {
	data, err := os.ReadFile(filepath.Join(apiDir, "config", "route_permissions.php"))
	if os.IsNotExist(err) {
		t.Skip("this API has no config/route_permissions.php")
	}
	if err != nil {
		t.Fatal(err)
	}
	re := regexp.MustCompile(`'([A-Z]+) ([^']+)'\s*=>\s*'([^']+)'`)
	required := map[string]string{}
	for _, m := range re.FindAllStringSubmatch(string(data), -1) {
		required[m[1]+" "+NormalizePath("/"+m[2])] = m[3]
	}
	// ['a', 'b'] — all of them; the contract writes "a+b".
	all := regexp.MustCompile(`'([A-Z]+) ([^']+)'\s*=>\s*\[([^\]]+)\]`)
	quoted := regexp.MustCompile(`'([^']+)'`)
	for _, m := range all.FindAllStringSubmatch(string(data), -1) {
		var perms []string
		for _, q := range quoted.FindAllStringSubmatch(m[3], -1) {
			perms = append(perms, q[1])
		}
		required[m[1]+" "+NormalizePath("/"+m[2])] = strings.Join(perms, "+")
	}
	for _, e := range Contract {
		if e.NotInSpec || e.Path == "/auth/login" {
			continue // unauthenticated routes
		}
		want, ok := required[e.Method+" "+NormalizePath(e.Path)]
		if !ok {
			t.Errorf("%s %s has no entry in route_permissions.php", e.Method, e.Path)
			continue
		}
		want = strings.TrimSuffix(want, ":any")
		if want == "@authenticated" || want == "@policy" {
			want = ""
		}
		if e.Permission != want {
			t.Errorf("%s %s: contract Permission %q, route_permissions.php %q", e.Method, e.Path, e.Permission, want)
		}
	}
}

// Every contract path is unique per method, so MatchEndpoint is unambiguous.
func TestContractEntriesUnique(t *testing.T) {
	seen := map[string]bool{}
	for _, e := range Contract {
		k := e.Method + " " + NormalizePath(e.Path)
		if seen[k] {
			t.Errorf("duplicate contract entry %s", k)
		}
		seen[k] = true
	}
}

// A FreeForm field is a request field too (the contract test checks it against the spec).
func TestContractFreeFormDeclared(t *testing.T) {
	for _, e := range Contract {
		for _, f := range e.FreeForm {
			found := false
			for _, r := range e.Request {
				found = found || r == f
			}
			if !found {
				t.Errorf("%s %s: FreeForm field %s is not in Request", e.Method, e.Path, f)
			}
		}
		if e.RawBody && len(e.Request) > 0 {
			t.Errorf("%s %s: a RawBody endpoint declares JSON request fields", e.Method, e.Path)
		}
	}
}

func TestMatchEndpoint(t *testing.T) {
	cases := map[string]string{
		"GET /projects": "/projects",
		"GET /projects/01HZX3Y7K9ABCDEFGHJKMNPQRS": "/projects/{project}",
		"POST /projects/abc/deploy":                "/projects/{project}/deploy",
		"GET /projects/abc/deployments/def/logs":   "/projects/{project}/deployments/{deployment}/logs",
		"PUT /projects/abc/domains/def/tls":        "/projects/{project}/domains/{domain}/tls",
		"GET /knative-regions/r1":                  "/knative-regions/{region}",
		"DELETE /tokens/12":                        "/tokens/{token}",
		"GET /projects/abc/versions/def/files":     "/projects/{project}/versions/{version}/files",
		// A fixed segment wins over a parameter in the same position.
		"PUT /roles/default":     "/roles/default",
		"PUT /roles/release-ops": "/roles/{role}",
	}
	for in, want := range cases {
		method, path, _ := strings.Cut(in, " ")
		e := MatchEndpoint(method, path)
		if e == nil || e.Path != want {
			t.Errorf("MatchEndpoint(%s) = %v, want %s", in, e, want)
		}
	}
	if MatchEndpoint("GET", "/internal/secret") != nil {
		t.Error("an undeclared path matched")
	}
}
