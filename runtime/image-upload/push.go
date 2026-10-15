package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sort"
	"strings"

	"github.com/google/go-containerregistry/pkg/authn"
	"github.com/google/go-containerregistry/pkg/name"
	v1 "github.com/google/go-containerregistry/pkg/v1"
	"github.com/google/go-containerregistry/pkg/v1/empty"
	"github.com/google/go-containerregistry/pkg/v1/layout"
	"github.com/google/go-containerregistry/pkg/v1/mutate"
	"github.com/google/go-containerregistry/pkg/v1/remote"
	"github.com/google/go-containerregistry/pkg/v1/static"
	"github.com/google/go-containerregistry/pkg/v1/tarball"
	"github.com/google/go-containerregistry/pkg/v1/types"
)

// Media types of WebAssembly OCI artifacts (the CNCF Wasm OCI artifact layout, and what
// `spin registry push` writes).
const (
	WasmConfigMediaType  types.MediaType = "application/vnd.wasm.config.v0+json"
	WasmLayerMediaType   types.MediaType = "application/wasm"
	SpinConfigMediaType  types.MediaType = "application/vnd.fermyon.spin.application.v1+config"
	SpinLayerMediaType   types.MediaType = "application/vnd.wasm.content.layer.v1+wasm"
	ModuleLayerMediaType types.MediaType = "application/vnd.module.wasm.content.layer.v1+wasm"

	titleAnnotation = "org.opencontainers.image.title"
)

// Kinds of a pushed upload.
const (
	KindImage = "image" // a container image (single platform or a multi-platform index)
	KindWasm  = "wasm"  // a WebAssembly module or component (wasm OCI artifact)
	KindSpin  = "spin"  // a Spin application (spin registry push)
)

// Result of a push: what RunR records for the uploaded image.
type Result struct {
	Reference       string   `json:"reference"` // target@digest
	Digest          string   `json:"digest"`
	MediaType       string   `json:"media_type"`
	ConfigMediaType string   `json:"config_media_type,omitempty"`
	Kind            string   `json:"kind"`
	Format          string   `json:"format"`
	SizeBytes       int64    `json:"size_bytes"`
	Platforms       []string `json:"platforms"`
	Layers          int      `json:"layers"`
	ArchiveSHA256   string   `json:"archive_sha256"`
	Note            string   `json:"note,omitempty"`
}

// Push detects the format of the file, pushes it to cfg.Target and checks that the registry
// serves the tag with the digest of what was pushed: the digest recorded in an OCI layout (an
// image index is pushed as it is, multi-platform included), the digest of the image built from a
// `docker save` archive, or the digest of the wasm artifact. The registry verifies every blob
// against its digest on upload, so a corrupted layer fails the push.
func Push(ctx context.Context, cfg Config, file, sha string) (*Result, error) {
	format, tarPath, err := detect(file)
	if err != nil {
		return nil, err
	}
	if cfg.Format != FormatAuto && cfg.Format != format {
		return nil, fmt.Errorf("the file is a %s upload, but the upload was started as %s", describeFormat(format), describeFormat(cfg.Format))
	}

	ref, err := parseTarget(cfg)
	if err != nil {
		return nil, err
	}
	opts := remoteOptions(ctx)

	var res *Result
	switch format {
	case FormatWasm:
		res, err = pushWasm(ref, file, opts)
	case FormatOCI:
		dir := filepath.Join(cfg.Dir, "layout")
		if err := os.RemoveAll(dir); err != nil {
			return nil, err
		}
		if err := extractLayout(tarPath, dir); err != nil {
			return nil, fmt.Errorf("extracting the OCI layout: %w", err)
		}
		// the archive is no longer needed: give its space back before the push
		if tarPath != file {
			_ = os.Remove(tarPath)
		}
		res, err = pushLayout(ref, dir, opts)
	case FormatDocker:
		res, err = pushDockerArchive(ref, tarPath, opts)
	}
	if err != nil {
		return nil, err
	}
	res.Format = format
	res.ArchiveSHA256 = sha

	if err := verifyPushed(ref, res.Digest, opts); err != nil {
		return nil, err
	}
	res.Reference = ref.Context().Digest(res.Digest).String()
	return res, nil
}

func describeFormat(f string) string {
	switch f {
	case FormatDocker:
		return "docker save archive"
	case FormatOCI:
		return "OCI image layout"
	case FormatWasm:
		return "WebAssembly module"
	}
	return f
}

func parseTarget(cfg Config) (name.Tag, error) {
	var opts []name.Option
	if cfg.PlainHTTP {
		opts = append(opts, name.Insecure)
	}
	tag, err := name.NewTag(cfg.Target, opts...)
	if err != nil {
		return name.Tag{}, fmt.Errorf("RUNR_UPLOAD_TARGET %q: %w", cfg.Target, err)
	}
	return tag, nil
}

func remoteOptions(ctx context.Context) []remote.Option {
	return []remote.Option{
		remote.WithContext(ctx),
		// RunR's internal registry takes anonymous pushes from inside the cluster
		remote.WithAuth(authn.Anonymous),
		remote.WithUserAgent("runr-image-upload/" + version),
		remote.WithJobs(4),
	}
}

// verifyPushed reads the tag back from the registry: it must point at the pushed digest.
func verifyPushed(ref name.Tag, digest string, opts []remote.Option) error {
	desc, err := remote.Head(ref, opts...)
	if err != nil {
		return fmt.Errorf("reading %s back from the registry: %w", ref, err)
	}
	if desc.Digest.String() != digest {
		return fmt.Errorf("the registry serves %s as %s, the upload was pushed as %s", ref, desc.Digest, digest)
	}
	return nil
}

// ── OCI image layout ─────────────────────────────────────────────────────────

// pushLayout pushes the image (or image index) of an OCI layout. A layout with several entries
// is narrowed to the one named like the target tag (org.opencontainers.image.ref.name or
// io.containerd.image.name annotation); entries that all name a platform are pushed as one
// multi-platform index.
func pushLayout(ref name.Tag, dir string, opts []remote.Option) (*Result, error) {
	p, err := layout.FromPath(dir)
	if err != nil {
		return nil, fmt.Errorf("the archive is not an OCI image layout: %w", err)
	}
	root, err := p.ImageIndex()
	if err != nil {
		return nil, fmt.Errorf("reading index.json: %w", err)
	}
	im, err := root.IndexManifest()
	if err != nil {
		return nil, fmt.Errorf("reading index.json: %w", err)
	}

	entries := im.Manifests
	if len(entries) == 0 {
		return nil, errors.New("index.json of the archive lists no image")
	}
	if len(entries) > 1 {
		if named := entriesNamed(entries, ref.TagStr()); len(named) == 1 {
			entries = named
		} else if allHavePlatforms(entries) {
			return pushIndex(ref, root, p, opts)
		} else {
			return nil, fmt.Errorf("the archive holds %d images and none is named %q; save one image per upload (docker save <image>)", len(entries), ref.TagStr())
		}
	}

	desc := entries[0]
	switch {
	case desc.MediaType.IsIndex():
		idx, err := root.ImageIndex(desc.Digest)
		if err != nil {
			return nil, err
		}
		return pushIndex(ref, idx, p, opts)
	case desc.MediaType.IsImage():
		img, err := root.Image(desc.Digest)
		if err != nil {
			return nil, err
		}
		return pushImage(ref, img, opts)
	default:
		return nil, fmt.Errorf("the archive's entry has the media type %q, which is neither an image nor an image index", desc.MediaType)
	}
}

func entriesNamed(entries []v1.Descriptor, tag string) []v1.Descriptor {
	var out []v1.Descriptor
	for _, d := range entries {
		ociName := d.Annotations["org.opencontainers.image.ref.name"]
		ctrName := d.Annotations["io.containerd.image.name"]
		if ociName == tag || strings.HasSuffix(ctrName, ":"+tag) {
			out = append(out, d)
		}
	}
	return out
}

func allHavePlatforms(entries []v1.Descriptor) bool {
	for _, d := range entries {
		if d.Platform == nil || !d.MediaType.IsImage() {
			return false
		}
	}
	return true
}

// pushIndex pushes an image index. `docker save` of a multi-platform image can carry the layers
// of the local platform only: the index is then pushed with the platforms whose blobs are all in
// the archive (a single one as that platform's image), and the result says so.
func pushIndex(ref name.Tag, idx v1.ImageIndex, p layout.Path, opts []remote.Option) (*Result, error) {
	im, err := idx.IndexManifest()
	if err != nil {
		return nil, err
	}

	var complete, missing []v1.Descriptor
	for _, d := range im.Manifests {
		if blobsPresent(p, d) {
			complete = append(complete, d)
		} else {
			missing = append(missing, d)
		}
	}

	if len(missing) == 0 {
		if err := remote.WriteIndex(ref, idx, opts...); err != nil {
			return nil, fmt.Errorf("pushing the image index: %w", err)
		}
		return describeIndex(idx, p)
	}

	var images []v1.Descriptor
	for _, d := range complete {
		if d.MediaType.IsImage() && !isAttestation(d) {
			images = append(images, d)
		}
	}
	note := fmt.Sprintf("the archive has the layers of %s only (of %d entries of the image index); pushed what it has",
		strings.Join(platformList(images), ", "), len(im.Manifests))

	switch len(images) {
	case 0:
		return nil, fmt.Errorf("none of the %d platform images of the archive's image index has all of its layers in the archive; save it with `docker save --platform <os/arch>`", len(im.Manifests))
	case 1:
		img, err := idx.Image(images[0].Digest)
		if err != nil {
			return nil, err
		}
		res, err := pushImage(ref, img, opts)
		if err != nil {
			return nil, err
		}
		res.Platforms = platformList(images)
		res.Note = note
		return res, nil
	}

	var adds []mutate.IndexAddendum
	for _, d := range images {
		img, err := idx.Image(d.Digest)
		if err != nil {
			return nil, err
		}
		adds = append(adds, mutate.IndexAddendum{Add: img, Descriptor: v1.Descriptor{MediaType: d.MediaType, Platform: d.Platform, Annotations: d.Annotations}})
	}
	narrowed := mutate.AppendManifests(mutate.IndexMediaType(empty.Index, types.OCIImageIndex), adds...)
	if err := remote.WriteIndex(ref, narrowed, opts...); err != nil {
		return nil, fmt.Errorf("pushing the image index: %w", err)
	}
	res, err := describeIndex(narrowed, p)
	if err != nil {
		return nil, err
	}
	res.Note = note
	return res, nil
}

func isAttestation(d v1.Descriptor) bool {
	return d.Annotations["vnd.docker.reference.type"] == "attestation-manifest"
}

// blobsPresent: the manifest of d, its config and every layer are files of the layout.
func blobsPresent(p layout.Path, d v1.Descriptor) bool {
	has := func(h v1.Hash) bool {
		_, err := os.Stat(filepath.Join(string(p), "blobs", h.Algorithm, h.Hex))
		return err == nil
	}
	if !has(d.Digest) {
		return false
	}
	if d.MediaType.IsIndex() {
		return true
	}
	rc, err := p.Blob(d.Digest)
	if err != nil {
		return false
	}
	defer rc.Close()
	m, err := v1.ParseManifest(rc)
	if err != nil {
		return false
	}
	if !has(m.Config.Digest) {
		return false
	}
	for _, l := range m.Layers {
		if !has(l.Digest) {
			return false
		}
	}
	return true
}

func platformList(ds []v1.Descriptor) []string {
	seen := map[string]bool{}
	var out []string
	for _, d := range ds {
		if d.Platform == nil || isAttestation(d) {
			continue
		}
		s := d.Platform.String()
		if s == "unknown/unknown" || seen[s] {
			continue
		}
		seen[s] = true
		out = append(out, s)
	}
	sort.Strings(out)
	return out
}

func describeIndex(idx v1.ImageIndex, p layout.Path) (*Result, error) {
	digest, err := idx.Digest()
	if err != nil {
		return nil, err
	}
	mt, err := idx.MediaType()
	if err != nil {
		return nil, err
	}
	raw, err := idx.RawManifest()
	if err != nil {
		return nil, err
	}
	im, err := idx.IndexManifest()
	if err != nil {
		return nil, err
	}

	size := int64(len(raw))
	seen := map[string]bool{}
	layers := 0
	for _, d := range im.Manifests {
		size += d.Size
		if !d.MediaType.IsImage() {
			continue
		}
		img, err := idx.Image(d.Digest)
		if err != nil {
			return nil, err
		}
		m, err := img.Manifest()
		if err != nil {
			return nil, err
		}
		for _, b := range append([]v1.Descriptor{m.Config}, m.Layers...) {
			if !seen[b.Digest.String()] {
				seen[b.Digest.String()] = true
				size += b.Size
			}
		}
		if !isAttestation(d) {
			layers += len(m.Layers)
		}
	}

	return &Result{
		Digest:    digest.String(),
		MediaType: string(mt),
		Kind:      KindImage,
		SizeBytes: size,
		Platforms: platformList(im.Manifests),
		Layers:    layers,
	}, nil
}

// ── Single images (OCI layout entry or docker save archive) ───────────────────

func pushImage(ref name.Tag, img v1.Image, opts []remote.Option) (*Result, error) {
	if err := remote.Write(ref, img, opts...); err != nil {
		return nil, fmt.Errorf("pushing the image: %w", err)
	}
	return describeImage(img)
}

func describeImage(img v1.Image) (*Result, error) {
	digest, err := img.Digest()
	if err != nil {
		return nil, err
	}
	mt, err := img.MediaType()
	if err != nil {
		return nil, err
	}
	m, err := img.Manifest()
	if err != nil {
		return nil, err
	}
	raw, err := img.RawManifest()
	if err != nil {
		return nil, err
	}

	size := int64(len(raw)) + m.Config.Size
	for _, l := range m.Layers {
		size += l.Size
	}

	res := &Result{
		Digest:          digest.String(),
		MediaType:       string(mt),
		ConfigMediaType: string(m.Config.MediaType),
		Kind:            KindImage,
		SizeBytes:       size,
		Layers:          len(m.Layers),
		Platforms:       []string{},
	}
	switch m.Config.MediaType {
	case WasmConfigMediaType:
		res.Kind = KindWasm
	case SpinConfigMediaType:
		res.Kind = KindSpin
	default:
		if cf, err := img.ConfigFile(); err == nil && cf.OS != "" {
			p := v1.Platform{OS: cf.OS, Architecture: cf.Architecture, Variant: cf.Variant}
			res.Platforms = []string{p.String()}
		}
	}
	return res, nil
}

// pushDockerArchive pushes the image of a `docker save` archive (one image, or the one tagged
// with the target tag). Its layers are compressed for the registry, so the pushed digest is the
// one of the resulting image, checked against the registry afterwards.
func pushDockerArchive(ref name.Tag, tarPath string, opts []remote.Option) (*Result, error) {
	m, err := tarball.LoadManifest(func() (io.ReadCloser, error) { return os.Open(tarPath) })
	if err != nil {
		return nil, fmt.Errorf("reading manifest.json of the archive: %w", err)
	}

	var tag *name.Tag
	if len(m) > 1 {
		var match []string
		for _, entry := range m {
			for _, t := range entry.RepoTags {
				if strings.HasSuffix(t, ":"+ref.TagStr()) {
					match = append(match, t)
				}
			}
		}
		if len(match) != 1 {
			return nil, fmt.Errorf("the archive holds %d images and none is uniquely tagged %q; save one image per upload (docker save <image>)", len(m), ref.TagStr())
		}
		t, err := name.NewTag(match[0])
		if err != nil {
			return nil, err
		}
		tag = &t
	}

	img, err := tarball.ImageFromPath(tarPath, tag)
	if err != nil {
		return nil, fmt.Errorf("reading the image of the archive: %w", err)
	}
	return pushImage(ref, img, opts)
}

// ── WebAssembly modules ──────────────────────────────────────────────────────

type rawManifest struct {
	b  []byte
	mt types.MediaType
}

func (r rawManifest) RawManifest() ([]byte, error)        { return r.b, nil }
func (r rawManifest) MediaType() (types.MediaType, error) { return r.mt, nil }

// wasmArtifact builds the OCI artifact of a .wasm file: the module as one application/wasm layer
// (titled module.wasm), a wasm config naming the layer. The same file always gives the same digest.
func wasmArtifact(module []byte) (manifest []byte, config, layer v1.Layer, err error) {
	if !bytes.HasPrefix(module, wasmMagic) || len(module) < 8 {
		return nil, nil, nil, errors.New("the file is not a WebAssembly binary")
	}
	osName := "wasip1"
	if module[4] != 0x01 { // a component (layer 0x0d) rather than a core module (version 1)
		osName = "wasip2"
	}

	layer = static.NewLayer(module, WasmLayerMediaType)
	ld, err := layer.Digest()
	if err != nil {
		return nil, nil, nil, err
	}
	cfg, err := json.Marshal(map[string]any{"architecture": "wasm", "os": osName, "layerDigests": []string{ld.String()}})
	if err != nil {
		return nil, nil, nil, err
	}
	config = static.NewLayer(cfg, WasmConfigMediaType)
	cd, _ := config.Digest()

	manifest, err = json.Marshal(v1.Manifest{
		SchemaVersion: 2,
		MediaType:     types.OCIManifestSchema1,
		Config:        v1.Descriptor{MediaType: WasmConfigMediaType, Size: int64(len(cfg)), Digest: cd},
		Layers: []v1.Descriptor{{
			MediaType:   WasmLayerMediaType,
			Size:        int64(len(module)),
			Digest:      ld,
			Annotations: map[string]string{titleAnnotation: "module.wasm"},
		}},
	})
	return manifest, config, layer, err
}

func pushWasm(ref name.Tag, file string, opts []remote.Option) (*Result, error) {
	module, err := os.ReadFile(file)
	if err != nil {
		return nil, err
	}
	manifest, config, layer, err := wasmArtifact(module)
	if err != nil {
		return nil, err
	}
	for _, l := range []v1.Layer{layer, config} {
		if err := remote.WriteLayer(ref.Context(), l, opts...); err != nil {
			return nil, fmt.Errorf("pushing the module: %w", err)
		}
	}
	if err := remote.Put(ref, rawManifest{manifest, types.OCIManifestSchema1}, opts...); err != nil {
		return nil, fmt.Errorf("pushing the module's manifest: %w", err)
	}

	digest, _, err := v1.SHA256(bytes.NewReader(manifest))
	if err != nil {
		return nil, err
	}
	osName := "wasip1"
	if module[4] != 0x01 {
		osName = "wasip2"
	}
	return &Result{
		Digest:          digest.String(),
		MediaType:       string(types.OCIManifestSchema1),
		ConfigMediaType: string(WasmConfigMediaType),
		Kind:            KindWasm,
		SizeBytes:       int64(len(manifest)) + int64(len(module)),
		Platforms:       []string{osName + "/wasm"},
		Layers:          1,
	}, nil
}
