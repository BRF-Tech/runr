package runtimes

import (
	"context"
	"errors"
	"fmt"
	"sort"
	"strings"

	"github.com/runrsh/core/cli/pkg/runr"
)

// CatalogLister is the part of runr.Client the catalog resolver uses.
type CatalogLister interface {
	ListRuntimes(ctx context.Context) ([]runr.CatalogRuntime, error)
}

// RuntimeGetter reads a runtime's manifest (GET /runtimes/{id}); the catalog resolver uses it
// for runtime plugins when its client has it (runr.Client does).
type RuntimeGetter interface {
	GetRuntime(ctx context.Context, id string) (*runr.RuntimeDetail, error)
}

// Catalog resolves against the API's runtime catalog (GET /runtimes): built-in runtimes and
// runtime plugins, their versions and images. APIs without the catalog answer
// runr.ErrUnsupported, and Chain moves on to the next resolver.
type Catalog struct {
	Client CatalogLister
	list   []runr.CatalogRuntime
	loaded bool
}

// WasmError means the runtime compiles functions to WebAssembly components that run on the
// region's WebAssembly shim, not in a container.
type WasmError struct {
	Runtime *runr.CatalogRuntime
}

func (e *WasmError) Error() string {
	return fmt.Sprintf("%s is a WebAssembly runtime: its functions run as components on the %s shim of the region; "+
		"run them on the platform with `runr deploy` (runr dev runs container runtimes)", e.Runtime.ID, e.Runtime.Wasm)
}

func (c *Catalog) load(ctx context.Context) ([]runr.CatalogRuntime, error) {
	if c.loaded {
		return c.list, nil
	}
	list, err := c.Client.ListRuntimes(ctx)
	if err != nil {
		return nil, err
	}
	c.list, c.loaded = list, true
	return list, nil
}

// Find returns a catalog runtime by id.
func (c *Catalog) Find(ctx context.Context, id string) (*runr.CatalogRuntime, error) {
	list, err := c.load(ctx)
	if err != nil {
		return nil, err
	}
	for i := range list {
		if list[i].ID == id {
			return &list[i], nil
		}
	}
	ids := make([]string, 0, len(list))
	for _, r := range list {
		ids = append(ids, r.ID)
	}
	sort.Strings(ids)
	return nil, fmt.Errorf("the platform offers no runtime %q (available: %s)", id, strings.Join(ids, ", "))
}

// Resolve implements Resolver: built-in runtimes get their mechanics from the built-in table
// and the image from the catalog; runtime plugins get theirs from their manifest
// (GET /runtimes/{id}) and run through the universal wrapper the way the cluster runs them.
func (c *Catalog) Resolve(ctx context.Context, id, version string) (*Spec, error) {
	rt, err := c.Find(ctx, id)
	if err != nil {
		return nil, err
	}
	v := rt.FindVersion(version)
	if v == nil {
		var vs []string
		for _, x := range rt.Versions {
			vs = append(vs, x.Version)
		}
		return nil, fmt.Errorf("%s %s is not available (available: %s)", id, version, strings.Join(vs, ", "))
	}
	if rt.Wasm != "" {
		return nil, &WasmError{Runtime: rt}
	}
	if !Known(id) {
		return c.pluginSpec(ctx, rt, v)
	}
	s, err := specFor(id, v.Version)
	if err != nil {
		return nil, err
	}
	if v.Image != "" && strings.TrimRight(Registry(), "/") == DefaultRegistry {
		s.Image = v.Image
		if s.BuilderImage != "" && id != "php" {
			s.BuilderImage = v.Image
		}
	}
	return s, nil
}

// pluginSpec describes a runtime plugin version from its manifest.
func (c *Catalog) pluginSpec(ctx context.Context, rt *runr.CatalogRuntime, v *runr.RuntimeVersion) (*Spec, error) {
	g, ok := c.Client.(RuntimeGetter)
	if !ok {
		return nil, fmt.Errorf("%s is a runtime plugin; its manifest is read from the platform (GET /runtimes/%s)", rt.ID, rt.ID)
	}
	d, err := g.GetRuntime(ctx, rt.ID)
	if err != nil {
		return nil, err
	}
	if d.Manifest == nil {
		return nil, fmt.Errorf("the platform sent no manifest for the runtime plugin %s", rt.ID)
	}
	mv := d.Manifest.Version(v.Version)
	if mv == nil {
		return nil, fmt.Errorf("the manifest of %s has no version %s", rt.ID, v.Version)
	}
	image := v.Image
	if image == "" {
		image = mv.Image
	}
	s := &Spec{
		ID: rt.ID, Version: v.Version, Image: image, Extension: d.Manifest.Extension(),
		CodeDir: "/app/code", Workdir: "/app", Port: 8080, Compiled: d.Manifest.Build != nil,
		Plugin: &Plugin{Runtime: d.CatalogRuntime, Manifest: d.Manifest, Files: d.Files, Version: mv},
	}
	if d.Manifest.Dependencies != nil {
		s.DependencyFile = d.Manifest.Dependencies.File
	}
	return s, nil
}

// List implements Resolver.
func (c *Catalog) List(ctx context.Context) ([]Info, error) {
	list, err := c.load(ctx)
	if err != nil {
		return nil, err
	}
	return CatalogInfos(list), nil
}

// CatalogInfos lists every version of catalog runtimes, sorted by runtime.
func CatalogInfos(list []runr.CatalogRuntime) []Info {
	out := []Info{}
	for _, rt := range list {
		for _, v := range rt.Versions {
			img := v.Image
			if img == "" {
				img = "(built in the region)"
			}
			out = append(out, Info{ID: rt.ID, Version: v.Version, Default: v.IsDefault, EOL: v.EOLAt, Image: img,
				Plugin: !Known(rt.ID) || rt.Source == "custom", Kind: rt.Kind(), Source: rt.Source, RunMode: rt.RunMode,
				Wasm: rt.Wasm})
		}
	}
	sort.SliceStable(out, func(i, j int) bool { return out[i].ID < out[j].ID })
	return out
}

// unsupported reports whether err means "this API has no such endpoint".
func unsupported(err error) bool { return errors.Is(err, runr.ErrUnsupported) }
