package main

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"io"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/google/go-containerregistry/pkg/name"
	"github.com/google/go-containerregistry/pkg/registry"
	v1 "github.com/google/go-containerregistry/pkg/v1"
	"github.com/google/go-containerregistry/pkg/v1/empty"
	"github.com/google/go-containerregistry/pkg/v1/layout"
	"github.com/google/go-containerregistry/pkg/v1/mutate"
	"github.com/google/go-containerregistry/pkg/v1/random"
	"github.com/google/go-containerregistry/pkg/v1/remote"
	"github.com/google/go-containerregistry/pkg/v1/tarball"
	"github.com/google/go-containerregistry/pkg/v1/types"
)

// An in-memory OCI registry (go-containerregistry's pkg/registry) stands in for the region's Zot.
func testRegistry(t *testing.T) string {
	t.Helper()
	srv := httptest.NewServer(registry.New())
	t.Cleanup(srv.Close)
	return strings.TrimPrefix(srv.URL, "http://")
}

func pushConfig(t *testing.T, host, target string) Config {
	t.Helper()
	return Config{Dir: t.TempDir(), Target: host + "/" + target, Format: FormatAuto, PushTimeout: time.Minute, PlainHTTP: true}
}

func headDigest(t *testing.T, ref string) string {
	t.Helper()
	r, err := name.ParseReference(ref, name.Insecure)
	if err != nil {
		t.Fatal(err)
	}
	d, err := remote.Head(r)
	if err != nil {
		t.Fatalf("head %s: %v", ref, err)
	}
	return d.Digest.String()
}

// tarDir writes the files below dir into a tar (names relative to dir, "./" prefixed like
// `docker save` does for some entries).
func tarDir(t *testing.T, dir, out string, gz bool) string {
	t.Helper()
	f, err := os.Create(out)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	var w io.Writer = f
	var zw *gzip.Writer
	if gz {
		zw = gzip.NewWriter(f)
		w = zw
	}
	tw := tar.NewWriter(w)
	err = filepath.Walk(dir, func(p string, info os.FileInfo, err error) error {
		if err != nil || p == dir {
			return err
		}
		rel, _ := filepath.Rel(dir, p)
		hdr, err := tar.FileInfoHeader(info, "")
		if err != nil {
			return err
		}
		hdr.Name = "./" + filepath.ToSlash(rel)
		if err := tw.WriteHeader(hdr); err != nil {
			return err
		}
		if info.IsDir() {
			return nil
		}
		b, err := os.ReadFile(p)
		if err != nil {
			return err
		}
		_, err = tw.Write(b)
		return err
	})
	if err != nil {
		t.Fatal(err)
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	if zw != nil {
		if err := zw.Close(); err != nil {
			t.Fatal(err)
		}
	}
	return out
}

func randomImage(t *testing.T, osName, arch string) v1.Image {
	t.Helper()
	img, err := random.Image(512, 2)
	if err != nil {
		t.Fatal(err)
	}
	cf, err := img.ConfigFile()
	if err != nil {
		t.Fatal(err)
	}
	cf = cf.DeepCopy()
	cf.OS, cf.Architecture = osName, arch
	img, err = mutate.ConfigFile(img, cf)
	if err != nil {
		t.Fatal(err)
	}
	return img
}

func TestPushDockerSaveArchive(t *testing.T) {
	host := testRegistry(t)
	cfg := pushConfig(t, host, "runr-uploads/platform/app:1.0")
	img := randomImage(t, "linux", "amd64")

	tag, _ := name.NewTag("app:1.0")
	file := filepath.Join(cfg.Dir, "upload.bin")
	if err := tarball.WriteToFile(file, tag, img); err != nil {
		t.Fatal(err)
	}

	res, err := Push(context.Background(), cfg, file, "abc")
	if err != nil {
		t.Fatal(err)
	}
	want, _ := img.Digest()
	if res.Digest != want.String() || headDigest(t, cfg.Target) != want.String() {
		t.Fatalf("pushed %s, image %s", res.Digest, want)
	}
	if res.Format != FormatDocker || res.Kind != KindImage || res.Layers != 2 || res.ArchiveSHA256 != "abc" {
		t.Fatalf("result %+v", res)
	}
	if len(res.Platforms) != 1 || res.Platforms[0] != "linux/amd64" {
		t.Fatalf("platforms %v", res.Platforms)
	}
	if !strings.HasSuffix(res.Reference, "@"+want.String()) || !strings.Contains(res.Reference, "/runr-uploads/platform/app@") {
		t.Fatalf("reference %s", res.Reference)
	}
}

func TestPushGzipDockerArchiveWithSeveralImagesPicksTheTargetTag(t *testing.T) {
	host := testRegistry(t)
	cfg := pushConfig(t, host, "runr-uploads/platform/app:2.0")
	one, two := randomImage(t, "linux", "amd64"), randomImage(t, "linux", "arm64")

	t1, _ := name.NewTag("app:1.0")
	t2, _ := name.NewTag("app:2.0")
	var buf bytes.Buffer
	zw := gzip.NewWriter(&buf)
	if err := tarball.MultiWrite(map[name.Tag]v1.Image{t1: one, t2: two}, zw); err != nil {
		t.Fatal(err)
	}
	zw.Close()
	file := filepath.Join(cfg.Dir, "upload.bin")
	if err := os.WriteFile(file, buf.Bytes(), 0o600); err != nil {
		t.Fatal(err)
	}

	res, err := Push(context.Background(), cfg, file, "")
	if err != nil {
		t.Fatal(err)
	}
	want, _ := two.Digest()
	if res.Digest != want.String() || res.Platforms[0] != "linux/arm64" {
		t.Fatalf("pushed %+v, want the image tagged app:2.0 (%s)", res, want)
	}

	// a tag the archive does not have
	cfg.Target = host + "/runr-uploads/platform/app:3.0"
	if _, err := Push(context.Background(), cfg, file, ""); err == nil || !strings.Contains(err.Error(), "save one image per upload") {
		t.Fatalf("err %v", err)
	}
}

func TestPushOCILayoutKeepsTheDigest(t *testing.T) {
	host := testRegistry(t)
	cfg := pushConfig(t, host, "runr-uploads/t-1/web:latest")
	img := randomImage(t, "linux", "amd64")

	dir := filepath.Join(t.TempDir(), "layout")
	p, err := layout.Write(dir, empty.Index)
	if err != nil {
		t.Fatal(err)
	}
	if err := p.AppendImage(img, layout.WithAnnotations(map[string]string{"org.opencontainers.image.ref.name": "latest"})); err != nil {
		t.Fatal(err)
	}
	file := tarDir(t, dir, filepath.Join(cfg.Dir, "upload.bin"), false)

	res, err := Push(context.Background(), cfg, file, "")
	if err != nil {
		t.Fatal(err)
	}
	want, _ := img.Digest()
	if res.Format != FormatOCI || res.Digest != want.String() || headDigest(t, cfg.Target) != want.String() {
		t.Fatalf("result %+v, want digest %s", res, want)
	}
}

func multiArchLayout(t *testing.T, dir string) (v1.ImageIndex, map[string]v1.Image) {
	t.Helper()
	imgs := map[string]v1.Image{"amd64": randomImage(t, "linux", "amd64"), "arm64": randomImage(t, "linux", "arm64")}
	idx := mutate.AppendManifests(mutate.IndexMediaType(empty.Index, types.OCIImageIndex),
		mutate.IndexAddendum{Add: imgs["amd64"], Descriptor: v1.Descriptor{Platform: &v1.Platform{OS: "linux", Architecture: "amd64"}}},
		mutate.IndexAddendum{Add: imgs["arm64"], Descriptor: v1.Descriptor{Platform: &v1.Platform{OS: "linux", Architecture: "arm64"}}},
	)
	p, err := layout.Write(dir, empty.Index)
	if err != nil {
		t.Fatal(err)
	}
	if err := p.AppendIndex(idx); err != nil {
		t.Fatal(err)
	}
	return idx, imgs
}

func TestPushMultiArchIndex(t *testing.T) {
	host := testRegistry(t)
	cfg := pushConfig(t, host, "runr-uploads/platform/multi:1.0")
	dir := filepath.Join(t.TempDir(), "layout")
	idx, _ := multiArchLayout(t, dir)
	file := tarDir(t, dir, filepath.Join(cfg.Dir, "upload.bin"), true)

	res, err := Push(context.Background(), cfg, file, "")
	if err != nil {
		t.Fatal(err)
	}
	want, _ := idx.Digest()
	if res.Digest != want.String() || headDigest(t, cfg.Target) != want.String() {
		t.Fatalf("pushed %s, index %s", res.Digest, want)
	}
	if res.MediaType != string(types.OCIImageIndex) || strings.Join(res.Platforms, ",") != "linux/amd64,linux/arm64" || res.Layers != 4 || res.Note != "" {
		t.Fatalf("result %+v", res)
	}
}

func TestPushIndexWithOnePlatformInTheArchive(t *testing.T) {
	host := testRegistry(t)
	cfg := pushConfig(t, host, "runr-uploads/platform/multi:1.0")
	dir := filepath.Join(t.TempDir(), "layout")
	_, imgs := multiArchLayout(t, dir)

	// like `docker save` of a multi-platform image: the other platform's layers are not there
	layers, _ := imgs["arm64"].Layers()
	h, _ := layers[0].Digest()
	if err := os.Remove(filepath.Join(dir, "blobs", h.Algorithm, h.Hex)); err != nil {
		t.Fatal(err)
	}
	file := tarDir(t, dir, filepath.Join(cfg.Dir, "upload.bin"), false)

	res, err := Push(context.Background(), cfg, file, "")
	if err != nil {
		t.Fatal(err)
	}
	want, _ := imgs["amd64"].Digest()
	if res.Digest != want.String() || res.MediaType == string(types.OCIImageIndex) {
		t.Fatalf("result %+v, want the amd64 image %s", res, want)
	}
	if strings.Join(res.Platforms, ",") != "linux/amd64" || !strings.Contains(res.Note, "linux/amd64 only") {
		t.Fatalf("result %+v", res)
	}
}

func TestPushWasmModuleAndFetchItBack(t *testing.T) {
	host := testRegistry(t)
	cfg := pushConfig(t, host, "runr-uploads/platform/q-interpreter:1.0")
	module := append([]byte{0x00, 'a', 's', 'm', 0x01, 0x00, 0x00, 0x00}, []byte("rest of the module")...)
	file := filepath.Join(cfg.Dir, "upload.bin")
	if err := os.WriteFile(file, module, 0o600); err != nil {
		t.Fatal(err)
	}

	res, err := Push(context.Background(), cfg, file, sha(module))
	if err != nil {
		t.Fatal(err)
	}
	if res.Kind != KindWasm || res.Format != FormatWasm || res.ConfigMediaType != string(WasmConfigMediaType) || res.Platforms[0] != "wasip1/wasm" {
		t.Fatalf("result %+v", res)
	}
	if headDigest(t, cfg.Target) != res.Digest {
		t.Fatal("the tag does not point at the pushed artifact")
	}

	// the same module gives the same artifact
	again, err := Push(context.Background(), pushConfig(t, host, "runr-uploads/platform/q-interpreter:1.1"), file, "")
	if err != nil {
		t.Fatal(err)
	}
	if again.Digest != res.Digest {
		t.Fatalf("digest %s, then %s", res.Digest, again.Digest)
	}

	out := filepath.Join(t.TempDir(), "main.wasm")
	layerDigest, err := fetchModule(context.Background(), res.Reference, out, true)
	if err != nil {
		t.Fatal(err)
	}
	got, _ := os.ReadFile(out)
	if !bytes.Equal(got, module) || layerDigest != "sha256:"+sha(module) {
		t.Fatalf("fetched %q (%s)", got, layerDigest)
	}
}

func TestFetchRefusesAnImageWithoutAWasmLayer(t *testing.T) {
	host := testRegistry(t)
	img := randomImage(t, "linux", "amd64")
	ref, _ := name.NewTag(host+"/runr-uploads/platform/app:1.0", name.Insecure)
	if err := remote.Write(ref, img); err != nil {
		t.Fatal(err)
	}
	if _, err := fetchModule(context.Background(), ref.String(), filepath.Join(t.TempDir(), "m.wasm"), true); err == nil || !strings.Contains(err.Error(), "no WebAssembly layer") {
		t.Fatalf("err %v", err)
	}
}

func TestDeclaredFormatMustMatch(t *testing.T) {
	cfg := pushConfig(t, "registry.test", "runr-uploads/platform/app:1.0")
	cfg.Format = FormatDocker
	file := filepath.Join(cfg.Dir, "upload.bin")
	os.WriteFile(file, []byte{0x00, 'a', 's', 'm', 0x01, 0, 0, 0}, 0o600)
	if _, err := Push(context.Background(), cfg, file, ""); err == nil || !strings.Contains(err.Error(), "WebAssembly module upload") {
		t.Fatalf("err %v", err)
	}
}

func TestDetectRefusesOtherFiles(t *testing.T) {
	dir := t.TempDir()
	plain := filepath.Join(dir, "notes.txt")
	os.WriteFile(plain, []byte("hello, this is not an archive"), 0o600)
	if _, _, err := detect(plain); err == nil {
		t.Fatal("a text file was accepted")
	}

	// a tar without image files
	src := filepath.Join(dir, "src")
	os.MkdirAll(src, 0o755)
	os.WriteFile(filepath.Join(src, "README"), []byte("x"), 0o600)
	tarFile := tarDir(t, src, filepath.Join(dir, "other.tar"), false)
	if _, _, err := detect(tarFile); err == nil || !strings.Contains(err.Error(), "neither an OCI image layout") {
		t.Fatalf("err %v", err)
	}
}

func TestExtractRefusesEntriesLeavingTheDirectory(t *testing.T) {
	dir := t.TempDir()
	file := filepath.Join(dir, "evil.tar")
	f, _ := os.Create(file)
	tw := tar.NewWriter(f)
	for _, n := range []string{"oci-layout", "index.json", "blobs/../../escape"} {
		tw.WriteHeader(&tar.Header{Name: n, Mode: 0o644, Size: 2, Typeflag: tar.TypeReg})
		tw.Write([]byte("{}"))
	}
	tw.Close()
	f.Close()

	if err := extractLayout(file, filepath.Join(dir, "out")); err == nil || !strings.Contains(err.Error(), "leaves the archive") {
		t.Fatalf("err %v", err)
	}
	if _, err := os.Stat(filepath.Join(dir, "escape")); err == nil {
		t.Fatal("a file was written outside the extraction directory")
	}

	for name, ok := range map[string]bool{"./blobs/sha256/ab": true, "/etc/passwd": false, "../x": false, "a/../../x": false, ".": false} {
		if _, got := cleanEntry(name); got != ok {
			t.Errorf("cleanEntry(%q) = %v, want %v", name, got, ok)
		}
	}
}
