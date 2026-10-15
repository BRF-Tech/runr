// Package runtimes describes how each RunR runtime runs code, so `runr init` can scaffold a
// starter and `runr dev` can run a function locally on the same executor image and wrapper
// the cluster uses.
//
// Resolution is behind Resolver. The facts come from the platform's runtime catalog
// (GET /runtimes, and GET /runtimes/{id} for the manifest of a runtime plugin), the API's list
// of executor images (GET /settings → executor_images) on APIs without the catalog, and the
// built-in table below (image naming, file extension, dependency file, how dependencies are
// installed — the rules of the built-in runtimes' manifests) when offline.
package runtimes

import (
	"context"
	"errors"
	"fmt"
	"os"
	"sort"
	"strings"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// EnvRegistry overrides the registry the executor images are pulled from (mirrors).
const EnvRegistry = "RUNR_RUNTIME_REGISTRY"

// DefaultRegistry hosts the executor images (brf-tech/exec-<runtime>:<version>).
const DefaultRegistry = "ghcr.io/brf-tech"

// Spec is everything needed to run code of one runtime version.
type Spec struct {
	ID      string `json:"id"`
	Version string `json:"version"`
	// Image is the executor image (server wrapper + language toolchain).
	Image string `json:"image"`
	// Extension of the handler file ("py").
	Extension string `json:"extension"`
	// DependencyFile triggers a dependency install when present ("requirements.txt").
	DependencyFile string `json:"dependency_file,omitempty"`
	// BuilderImage runs InstallScript.
	BuilderImage string `json:"builder_image,omitempty"`
	// InstallScript installs dependencies: it reads the code at /src and writes to /deps.
	InstallScript string `json:"install_script,omitempty"`
	// DepsMount is where /deps is mounted in the runtime container.
	DepsMount string `json:"deps_mount,omitempty"`
	// DepsEnv is set in the runtime container when dependencies are installed.
	DepsEnv map[string]string `json:"deps_env,omitempty"`
	// CodeDir is where the wrapper loads the code from (the ConfigMap mount in the cluster).
	CodeDir string `json:"code_dir"`
	// Workdir and Entrypoint start the wrapper (the image's ENTRYPOINT).
	Workdir    string `json:"workdir"`
	Entrypoint string `json:"entrypoint"`
	// PrepareScript runs in the runtime container before the entrypoint.
	PrepareScript string `json:"prepare_script,omitempty"`
	// Caches are extra directories kept between runs (container path → cache name).
	Caches map[string]string `json:"caches,omitempty"`
	// Compiled runtimes are built before they run (the cluster builds an image per version).
	Compiled bool `json:"compiled"`
	// Port the wrapper listens on.
	Port int `json:"port"`
	// Starter is the handler code `runr init` writes.
	Starter string `json:"-"`
	// DefaultVersion is used when neither the project nor the API names a version.
	DefaultVersion string `json:"-"`
	// Plugin is set for runtime plugins: they run as their manifest says (run mode, command,
	// universal wrapper, builds) instead of through the built-in table.
	Plugin *Plugin `json:"plugin,omitempty"`
}

// Plugin is a runtime plugin version as the platform's catalog describes it.
type Plugin struct {
	Runtime  runr.CatalogRuntime   `json:"runtime"`
	Manifest *runr.RuntimeManifest `json:"manifest"`
	// Files were uploaded with the runtime (the build context of dockerfile versions).
	Files map[string]string `json:"-"`
	// Version is the manifest entry of the resolved version.
	Version *runr.ManifestVersion `json:"version"`
}

// Info is one runtime version as listed by `runr runtimes ls`.
type Info struct {
	ID      string `json:"id"`
	Version string `json:"version"`
	Default bool   `json:"default"`
	EOL     string `json:"eol,omitempty"`
	Image   string `json:"image"`
	// Plugin marks a runtime plugin (registered from a runtime.yaml manifest).
	Plugin bool `json:"plugin,omitempty"`
	// Kind is built-in, plugin or wasm.
	Kind string `json:"kind,omitempty"`
	// Source is builtin, platform or custom; RunMode http, exec or persistent.
	Source  string `json:"source,omitempty"`
	RunMode string `json:"run_mode,omitempty"`
	// Wasm names the WebAssembly shim of a Wasm runtime.
	Wasm string `json:"wasm,omitempty"`
}

// Resolver finds the Spec of a runtime version.
type Resolver interface {
	// Resolve returns the spec of runtime id at version ("" = the default version).
	Resolve(ctx context.Context, id, version string) (*Spec, error)
	// List returns every runtime version available.
	List(ctx context.Context) ([]Info, error)
}

// builtin holds the mechanics of each runtime; Version/Image are filled in by Resolve.
var builtin = map[string]Spec{
	"python": {
		ID: "python", Extension: "py", DependencyFile: "requirements.txt", DefaultVersion: "3.13",
		InstallScript: "pip install --no-cache-dir --target=/deps -r /src/requirements.txt",
		DepsMount:     "/app/vendor", DepsEnv: map[string]string{"PYTHONPATH": "/app/vendor"},
		Starter: "def handler(event, context):\n" +
			"    name = (event or {}).get(\"name\", \"World\") if isinstance(event, dict) else \"World\"\n" +
			"    return {\"statusCode\": 200, \"body\": f\"Hello, {name}!\"}\n",
	},
	"nodejs": {
		ID: "nodejs", Extension: "js", DependencyFile: "package.json", DefaultVersion: "22",
		InstallScript: "cp /src/package.json /deps/ && cd /deps && npm install --production --no-audit --no-fund",
		DepsMount:     "/app/code/node_modules",
		Starter: "exports.handler = async (event, context) => {\n" +
			"  const name = (event && event.name) || 'World';\n" +
			"  return { statusCode: 200, body: `Hello, ${name}!` };\n" +
			"};\n",
	},
	"go": {
		ID: "go", Extension: "go", DefaultVersion: "1.26", Compiled: true,
		PrepareScript: "cd /app/code && if [ ! -f go.mod ]; then go mod init runr-function >/dev/null 2>&1; fi && " +
			"(go mod tidy >/dev/null 2>&1 || true) && cd /app",
		Caches: map[string]string{"/go/pkg/mod": "gomod", "/root/.cache/go-build": "gobuild"},
		Starter: "package main\n\n" +
			"// Handler is the entry point of the function.\n" +
			"func Handler(event interface{}, context interface{}) (interface{}, error) {\n" +
			"\tname := \"World\"\n" +
			"\tif m, ok := event.(map[string]interface{}); ok {\n" +
			"\t\tif n, ok := m[\"name\"].(string); ok && n != \"\" {\n" +
			"\t\t\tname = n\n" +
			"\t\t}\n" +
			"\t}\n" +
			"\treturn map[string]interface{}{\n" +
			"\t\t\"statusCode\": 200,\n" +
			"\t\t\"body\":       \"Hello, \" + name + \"!\",\n" +
			"\t}, nil\n" +
			"}\n",
	},
	"php": {
		ID: "php", Extension: "php", DependencyFile: "composer.json", DefaultVersion: "8.4",
		InstallScript: "cp /src/composer.json /deps/ && cd /deps && composer config platform.php {{version}} && composer install --no-dev --no-interaction",
		DepsMount:     "/app/code/vendor",
		Starter: "<?php\n\n" +
			"function handler($event, $context): array\n" +
			"{\n" +
			"    $name = is_array($event) && !empty($event['name']) ? $event['name'] : 'World';\n" +
			"    return ['statusCode' => 200, 'body' => \"Hello, {$name}!\"];\n" +
			"}\n",
	},
}

func init() {
	// The ML runtimes are Python with a framework preinstalled.
	for _, id := range []string{"tensorflow", "pytorch"} {
		s := builtin["python"]
		s.ID = id
		s.DefaultVersion = ""
		builtin[id] = s
	}
}

// IDs returns the built-in runtime ids sorted.
func IDs() []string {
	ids := make([]string, 0, len(builtin))
	for id := range builtin {
		ids = append(ids, id)
	}
	sort.Strings(ids)
	return ids
}

// Known reports whether id is a built-in runtime.
func Known(id string) bool {
	_, ok := builtin[id]
	return ok
}

// Extension returns the handler file extension of a runtime (py when unknown, like the API).
func Extension(id string) string {
	if s, ok := builtin[id]; ok {
		return s.Extension
	}
	return "py"
}

// Starter returns the starter handler of a runtime.
func Starter(id string) string {
	return builtin[id].Starter
}

// Registry returns the registry the executor images come from.
func Registry() string {
	if reg := strings.TrimRight(os.Getenv(EnvRegistry), "/"); reg != "" {
		return reg
	}
	return DefaultRegistry
}

// ImageFor returns the executor image of a runtime version.
func ImageFor(id, version string) string {
	return fmt.Sprintf("%s/exec-%s:%s", Registry(), id, version)
}

func specFor(id, version string) (*Spec, error) {
	b, ok := builtin[id]
	if !ok {
		return nil, fmt.Errorf("unknown runtime %q (known: %s)", id, strings.Join(IDs(), ", "))
	}
	if version == "" {
		version = b.DefaultVersion
	}
	if version == "" {
		return nil, fmt.Errorf("runtime %s needs a version (runtime_version in runr.yaml)", id)
	}
	s := b
	s.Version = version
	s.Image = ImageFor(id, version)
	s.CodeDir = "/app/code"
	s.Workdir = "/app"
	s.Entrypoint = "./entrypoint.sh"
	s.Port = 8080
	switch id {
	case "php":
		s.BuilderImage = Registry() + "/reqs/composer:latest"
		s.InstallScript = strings.ReplaceAll(s.InstallScript, "{{version}}", version)
	case "go":
	default:
		s.BuilderImage = s.Image
	}
	return &s, nil
}

// Builtin resolves from the built-in table only (offline).
type Builtin struct{}

// Resolve implements Resolver.
func (Builtin) Resolve(_ context.Context, id, version string) (*Spec, error) {
	if !Known(id) {
		return nil, fmt.Errorf("unknown runtime %q (built-in: %s); runtime plugins are read from the platform's catalog, log in with `runr login`",
			id, strings.Join(IDs(), ", "))
	}
	return specFor(id, version)
}

// List implements Resolver.
func (Builtin) List(context.Context) ([]Info, error) {
	var out []Info
	for _, id := range IDs() {
		b := builtin[id]
		if b.DefaultVersion == "" {
			continue
		}
		out = append(out, Info{ID: id, Version: b.DefaultVersion, Default: true, Image: ImageFor(id, b.DefaultVersion),
			Kind: "built-in", Source: "builtin", RunMode: "http"})
	}
	return out, nil
}

// ImageLister is the part of runr.Client the API resolver uses.
type ImageLister interface {
	ExecutorImages(ctx context.Context) ([]runr.ExecutorImage, error)
}

// API resolves versions against the platform's executor images.
type API struct {
	Client ImageLister
	images []runr.ExecutorImage
}

func (a *API) load(ctx context.Context) ([]runr.ExecutorImage, error) {
	if a.images != nil {
		return a.images, nil
	}
	imgs, err := a.Client.ExecutorImages(ctx)
	if err != nil {
		return nil, err
	}
	a.images = imgs
	return imgs, nil
}

// Resolve implements Resolver: version "" picks the platform's default version.
func (a *API) Resolve(ctx context.Context, id, version string) (*Spec, error) {
	imgs, err := a.load(ctx)
	if err != nil {
		return nil, err
	}
	var versions []string
	for _, img := range imgs {
		if img.Runtime != id {
			continue
		}
		versions = append(versions, img.Version)
		if version == "" && img.IsDefault {
			version = img.Version
		}
	}
	if len(versions) == 0 {
		return nil, fmt.Errorf("the platform offers no %s runtime", id)
	}
	if version == "" {
		version = versions[len(versions)-1]
	}
	found := false
	for _, v := range versions {
		found = found || v == version
	}
	if !found {
		return nil, fmt.Errorf("%s %s is not available (available: %s)", id, version, strings.Join(versions, ", "))
	}
	return specFor(id, version)
}

// List implements Resolver.
func (a *API) List(ctx context.Context) ([]Info, error) {
	imgs, err := a.load(ctx)
	if err != nil {
		return nil, err
	}
	out := make([]Info, 0, len(imgs))
	for _, img := range imgs {
		out = append(out, Info{ID: img.Runtime, Version: img.Version, Default: img.IsDefault, EOL: img.EOLAt,
			Image: ImageFor(img.Runtime, img.Version), Kind: "built-in", Source: "builtin", RunMode: "http"})
	}
	sort.SliceStable(out, func(i, j int) bool { return out[i].ID < out[j].ID })
	return out, nil
}

// Chain tries each resolver in order and returns the first answer. Warn is called with the
// error of a resolver that failed before a later one answered.
type Chain struct {
	Resolvers []Resolver
	Warn      func(error)
}

// Resolve implements Resolver.
func (c Chain) Resolve(ctx context.Context, id, version string) (*Spec, error) {
	var last error
	for i, r := range c.Resolvers {
		s, err := r.Resolve(ctx, id, version)
		if err == nil {
			return s, nil
		}
		last = err
		// A definite answer of the platform ("not available", a runtime plugin) is final.
		switch r.(type) {
		case *API, *Catalog:
			if !isTransport(err) {
				return nil, err
			}
		}
		if c.Warn != nil && i < len(c.Resolvers)-1 {
			c.Warn(err)
		}
	}
	return nil, last
}

// List implements Resolver.
func (c Chain) List(ctx context.Context) ([]Info, error) {
	var last error
	for _, r := range c.Resolvers {
		l, err := r.List(ctx)
		if err == nil {
			return l, nil
		}
		last = err
	}
	return nil, last
}

func isTransport(err error) bool {
	if unsupported(err) {
		return true
	}
	var ne *runr.NetworkError
	var ae *runr.APIError
	switch {
	case asErr(err, &ne):
		return true
	case asErr(err, &ae):
		return ae.StatusCode == 401 || ae.StatusCode == 403 || ae.StatusCode >= 500
	}
	return strings.Contains(err.Error(), "not logged in")
}

func asErr[T error](err error, target *T) bool { return errors.As(err, target) }
