package runtimes

import (
	"context"
	"errors"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

type lister struct {
	imgs []runr.ExecutorImage
	err  error
	n    int
}

func (l *lister) ExecutorImages(context.Context) ([]runr.ExecutorImage, error) {
	l.n++
	return l.imgs, l.err
}

func TestBuiltin(t *testing.T) {
	t.Setenv(EnvRegistry, "")
	s, err := Builtin{}.Resolve(context.Background(), "nodejs", "")
	if err != nil || s.Version != "22" || s.Image != "ghcr.io/brf-tech/runr/exec-nodejs:22" || s.CodeDir != "/app/code" || s.Port != 8080 {
		t.Fatalf("nodejs = %+v %v", s, err)
	}
	if s.BuilderImage != s.Image {
		t.Fatal("node installs dependencies in its executor image (FaasBuildService::builderImage)")
	}
	if _, err := (Builtin{}).Resolve(context.Background(), "cobol", ""); err == nil {
		t.Fatal("unknown runtime resolved")
	}
	if _, err := (Builtin{}).Resolve(context.Background(), "pytorch", ""); err == nil {
		t.Fatal("pytorch has no default version")
	}
	if s, _ := (Builtin{}).Resolve(context.Background(), "pytorch", "2.5"); s.DependencyFile != "requirements.txt" || s.Image != "ghcr.io/brf-tech/runr/exec-pytorch:2.5" {
		t.Fatalf("pytorch = %+v", s)
	}
	list, _ := Builtin{}.List(context.Background())
	if len(list) != 4 {
		t.Fatalf("builtin list = %+v", list)
	}
	if Extension("go") != "go" || Extension("unknown") != "py" || !Known("php") || Known("ruby") {
		t.Fatal("helpers")
	}
}

func TestRegistryOverride(t *testing.T) {
	t.Setenv(EnvRegistry, "registry.local:5000/mirror/")
	if ImageFor("python", "3.13") != "registry.local:5000/mirror/exec-python:3.13" {
		t.Fatalf("image = %s", ImageFor("python", "3.13"))
	}
	s, _ := Builtin{}.Resolve(context.Background(), "php", "8.3")
	if s.BuilderImage != "registry.local:5000/mirror/reqs/composer:latest" {
		t.Fatalf("composer image = %s", s.BuilderImage)
	}
}

func TestAPIResolver(t *testing.T) {
	t.Setenv(EnvRegistry, "")
	l := &lister{imgs: []runr.ExecutorImage{
		{Runtime: "python", Version: "3.12"}, {Runtime: "python", Version: "3.14", IsDefault: true},
		{Runtime: "go", Version: "1.25"}, {Runtime: "go", Version: "1.26"},
	}}
	a := &API{Client: l}
	s, err := a.Resolve(context.Background(), "python", "")
	if err != nil || s.Version != "3.14" {
		t.Fatalf("default = %+v %v", s, err)
	}
	s, _ = a.Resolve(context.Background(), "python", "3.12")
	if s.Image != "ghcr.io/brf-tech/runr/exec-python:3.12" {
		t.Fatalf("explicit = %+v", s)
	}
	if s, _ := a.Resolve(context.Background(), "go", ""); s.Version != "1.26" {
		t.Fatalf("no default flag: %+v", s)
	}
	if _, err := a.Resolve(context.Background(), "python", "2.7"); err == nil || !strings.Contains(err.Error(), "3.12, 3.14") {
		t.Fatalf("unavailable: %v", err)
	}
	if _, err := a.Resolve(context.Background(), "php", ""); err == nil {
		t.Fatal("runtime the platform lacks resolved")
	}
	if l.n != 1 {
		t.Fatalf("executor images fetched %d times", l.n)
	}
	list, _ := a.List(context.Background())
	if len(list) != 4 || list[0].ID != "go" {
		t.Fatalf("list = %+v", list)
	}
}

type catalogLister struct {
	list []runr.CatalogRuntime
	err  error
}

func (c *catalogLister) ListRuntimes(context.Context) ([]runr.CatalogRuntime, error) {
	return c.list, c.err
}

func TestCatalogResolver(t *testing.T) {
	t.Setenv(EnvRegistry, "")
	cat := &Catalog{Client: &catalogLister{list: []runr.CatalogRuntime{
		{ID: "python", Versions: []runr.RuntimeVersion{{Version: "3.12", Image: "ghcr.io/brf-tech/runr/exec-python:3.12"},
			{Version: "3.14", IsDefault: true, Image: "registry.example.com/exec-python:3.14"}}},
		{ID: "q", RunMode: "persistent", Source: "custom", Versions: []runr.RuntimeVersion{{Version: "1.0", Built: true}}},
	}}}
	s, err := cat.Resolve(context.Background(), "python", "")
	if err != nil || s.Version != "3.14" || s.Image != "registry.example.com/exec-python:3.14" || s.BuilderImage != s.Image {
		t.Fatalf("python = %+v %v", s, err)
	}
	if _, err := cat.Resolve(context.Background(), "python", "2.7"); err == nil || !strings.Contains(err.Error(), "3.12, 3.14") {
		t.Fatalf("unavailable version: %v", err)
	}
	// A plugin needs its manifest; a client without GET /runtimes/{id} cannot describe it.
	if _, err := cat.Resolve(context.Background(), "q", ""); err == nil || !strings.Contains(err.Error(), "runtime plugin") {
		t.Fatalf("plugin without manifest: %v", err)
	}
	if _, err := cat.Resolve(context.Background(), "ruby", ""); err == nil || !strings.Contains(err.Error(), "python, q") {
		t.Fatalf("unknown runtime: %v", err)
	}
	list, _ := cat.List(context.Background())
	if len(list) != 3 || !list[2].Plugin || list[2].Image != "(built in the region)" || list[2].Kind != "plugin" ||
		list[2].RunMode != "persistent" {
		t.Fatalf("list = %+v", list)
	}

	// A mirror (RUNR_RUNTIME_REGISTRY) wins over the catalog's image.
	t.Setenv(EnvRegistry, "mirror.example.com/runr")
	s, _ = cat.Resolve(context.Background(), "python", "3.12")
	if s.Image != "mirror.example.com/runr/exec-python:3.12" {
		t.Fatalf("mirror image = %s", s.Image)
	}
}

func TestChainSkipsAnAPIWithoutCatalog(t *testing.T) {
	t.Setenv(EnvRegistry, "")
	c := Chain{Resolvers: []Resolver{
		&Catalog{Client: &catalogLister{err: runr.ErrUnsupported}},
		&API{Client: &lister{imgs: []runr.ExecutorImage{{Runtime: "nodejs", Version: "24", IsDefault: true}}}},
		Builtin{},
	}}
	s, err := c.Resolve(context.Background(), "nodejs", "")
	if err != nil || s.Version != "24" {
		t.Fatalf("fallback to executor images = %+v %v", s, err)
	}
	// The plugin answer of the catalog is final: the built-in table does not guess.
	c.Resolvers[0] = &Catalog{Client: &rtDetailLister{catalogLister: catalogLister{list: []runr.CatalogRuntime{
		{ID: "q", Source: "custom", RunMode: "persistent", Versions: []runr.RuntimeVersion{{Version: "1", Built: true}}}}},
		detail: rtQDetail()}}
	s, err = c.Resolve(context.Background(), "q", "")
	if err != nil || s.Plugin == nil || s.Plugin.Manifest.Run.Mode != "persistent" {
		t.Fatalf("plugin through the chain: %+v %v", s, err)
	}
}

// rtDetailLister is a catalog client that also answers GET /runtimes/{id}.
type rtDetailLister struct {
	catalogLister
	detail *runr.RuntimeDetail
	gets   int
}

func (l *rtDetailLister) GetRuntime(_ context.Context, id string) (*runr.RuntimeDetail, error) {
	l.gets++
	if l.detail == nil || l.detail.ID != id {
		return nil, &runr.NotFoundError{Kind: "runtime", Ref: id}
	}
	return l.detail, nil
}

func rtQDetail() *runr.RuntimeDetail {
	return &runr.RuntimeDetail{
		CatalogRuntime: runr.CatalogRuntime{ID: "q", Source: "custom", RunMode: "persistent",
			Versions: []runr.RuntimeVersion{{Version: "1", IsDefault: true, Built: true}}},
		Manifest: &runr.RuntimeManifest{ID: "q", Extensions: []string{"q"},
			Versions: []runr.ManifestVersion{{Version: "1", Dockerfile: "Dockerfile", Default: true}},
			Handler:  runr.ManifestHandler{File: "main", Function: "handler"},
			Run:      runr.ManifestRun{Mode: "persistent", Command: []string{"q", "/app/code/{file}"}, Workers: 2}},
		Files: map[string]string{"Dockerfile": "FROM alpine\n"},
	}
}

func TestCatalogResolvesPlugins(t *testing.T) {
	t.Setenv(EnvRegistry, "")
	cl := &rtDetailLister{catalogLister: catalogLister{list: []runr.CatalogRuntime{
		{ID: "q", Source: "custom", RunMode: "persistent", Versions: []runr.RuntimeVersion{{Version: "1", IsDefault: true, Built: true}}},
		{ID: "bash", Source: "platform", RunMode: "exec", Versions: []runr.RuntimeVersion{{Version: "5", IsDefault: true, Image: "bash:5"}}},
		{ID: "wasm-rust", Source: "builtin", RunMode: "http", Wasm: "spin",
			Versions: []runr.RuntimeVersion{{Version: "4.1", IsDefault: true, Image: "ghcr.io/brf-tech/runr/exec-wasm-rust:4.1"}}},
	}}, detail: rtQDetail()}
	cat := &Catalog{Client: cl}

	s, err := cat.Resolve(context.Background(), "q", "")
	if err != nil {
		t.Fatal(err)
	}
	if s.Plugin == nil || s.Image != "" || s.Extension != "q" || s.CodeDir != "/app/code" || s.Port != 8080 ||
		s.Plugin.Version.Dockerfile != "Dockerfile" || s.Plugin.Files["Dockerfile"] == "" {
		t.Fatalf("q = %+v", s)
	}
	if _, err := cat.Resolve(context.Background(), "q", "9"); err == nil || !strings.Contains(err.Error(), "available: 1") {
		t.Fatalf("unknown plugin version: %v", err)
	}
	if _, err := cat.Resolve(context.Background(), "bash", ""); err == nil || !strings.Contains(err.Error(), "not found") {
		t.Fatalf("plugin whose manifest is not there: %v", err)
	}

	var we *WasmError
	if _, err := cat.Resolve(context.Background(), "wasm-rust", ""); !errors.As(err, &we) ||
		!strings.Contains(err.Error(), "runr deploy") || !strings.Contains(err.Error(), "spin") {
		t.Fatalf("wasm: %v", err)
	}
	list, _ := cat.List(context.Background())
	kinds := map[string]string{}
	for _, i := range list {
		kinds[i.ID] = i.Kind
	}
	if kinds["wasm-rust"] != "wasm" || kinds["q"] != "plugin" || kinds["bash"] != "plugin" {
		t.Fatalf("kinds = %v", kinds)
	}
}

func TestBuiltinRefusesPluginsOffline(t *testing.T) {
	if _, err := (Builtin{}).Resolve(context.Background(), "q", ""); err == nil || !strings.Contains(err.Error(), "runr login") {
		t.Fatalf("offline plugin: %v", err)
	}
}

func TestChainFallsBackOnTransportErrors(t *testing.T) {
	var warned error
	down := &API{Client: &lister{err: &runr.NetworkError{Method: "GET", URL: "x", Err: errors.New("refused")}}}
	c := Chain{Resolvers: []Resolver{down, Builtin{}}, Warn: func(err error) { warned = err }}
	s, err := c.Resolve(context.Background(), "python", "")
	if err != nil || s.Version != "3.13" || warned == nil {
		t.Fatalf("fallback = %+v %v warned=%v", s, err, warned)
	}

	// A definite "not available" answer of the platform is not overridden by the defaults.
	up := &API{Client: &lister{imgs: []runr.ExecutorImage{{Runtime: "python", Version: "3.12", IsDefault: true}}}}
	c = Chain{Resolvers: []Resolver{up, Builtin{}}}
	if _, err := c.Resolve(context.Background(), "python", "3.13"); err == nil {
		t.Fatal("the built-in default overrode the platform's runtime list")
	}

	if list, err := (Chain{Resolvers: []Resolver{down, Builtin{}}}).List(context.Background()); err != nil || len(list) == 0 {
		t.Fatalf("list fallback = %v %v", list, err)
	}
}
