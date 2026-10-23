package main

import (
	"context"
	"path/filepath"
	"strings"
	"testing"

	"github.com/google/go-containerregistry/pkg/name"
	v1 "github.com/google/go-containerregistry/pkg/v1"
	"github.com/google/go-containerregistry/pkg/v1/empty"
	"github.com/google/go-containerregistry/pkg/v1/mutate"
	"github.com/google/go-containerregistry/pkg/v1/remote"
	"github.com/google/go-containerregistry/pkg/v1/types"
)

func writeRemote(t *testing.T, ref string, img v1.Image) {
	t.Helper()
	tag, err := name.NewTag(ref, name.Insecure)
	if err != nil {
		t.Fatal(err)
	}
	if err := remote.Write(tag, img); err != nil {
		t.Fatal(err)
	}
}

// A pulled image is an OCI layout tar the upload receiver pushes back unchanged: same digest in
// the other registry — what an org import does with an exported build image.
func TestPullWritesAnOCILayoutThatPushesBack(t *testing.T) {
	host := testRegistry(t)
	img := randomImage(t, "linux", "amd64")
	writeRemote(t, host+"/runr-builds/api:v3", img)

	out := filepath.Join(t.TempDir(), "image.tar")
	res, err := pullImage(context.Background(), host+"/runr-builds/api:v3", out, true)
	if err != nil {
		t.Fatal(err)
	}
	want, _ := img.Digest()
	if res.Digest != want.String() || res.Reference != host+"/runr-builds/api@"+want.String() ||
		strings.Join(res.Platforms, ",") != "linux/amd64" || res.SizeBytes == 0 || !types.MediaType(res.MediaType).IsImage() {
		t.Fatalf("result %+v, want digest %s", res, want)
	}

	format, tarPath, err := detect(out)
	if err != nil || format != FormatOCI || tarPath != out {
		t.Fatalf("detect: %s %s %v", format, tarPath, err)
	}
	names, err := tarNames(out)
	if err != nil || !names["oci-layout"] || !names["index.json"] {
		t.Fatalf("tar entries %v (%v)", names, err)
	}

	cfg := pushConfig(t, host, "runr-builds/api-copy:v1")
	pushed, err := Push(context.Background(), cfg, out, "")
	if err != nil {
		t.Fatal(err)
	}
	if pushed.Digest != want.String() || headDigest(t, cfg.Target) != want.String() {
		t.Fatalf("pushed %s, pulled %s", pushed.Digest, want)
	}
}

func TestPullKeepsEveryPlatformOfAnIndex(t *testing.T) {
	host := testRegistry(t)
	idx := mutate.AppendManifests(mutate.IndexMediaType(empty.Index, types.OCIImageIndex),
		mutate.IndexAddendum{Add: randomImage(t, "linux", "amd64"), Descriptor: v1.Descriptor{Platform: &v1.Platform{OS: "linux", Architecture: "amd64"}}},
		mutate.IndexAddendum{Add: randomImage(t, "linux", "arm64"), Descriptor: v1.Descriptor{Platform: &v1.Platform{OS: "linux", Architecture: "arm64"}}},
	)
	tag, _ := name.NewTag(host+"/runr-builds/multi:v1", name.Insecure)
	if err := remote.WriteIndex(tag, idx); err != nil {
		t.Fatal(err)
	}

	out := filepath.Join(t.TempDir(), "image.tar")
	res, err := pullImage(context.Background(), tag.String(), out, true)
	if err != nil {
		t.Fatal(err)
	}
	want, _ := idx.Digest()
	if res.Digest != want.String() || res.MediaType != string(types.OCIImageIndex) || strings.Join(res.Platforms, ",") != "linux/amd64,linux/arm64" {
		t.Fatalf("result %+v", res)
	}

	cfg := pushConfig(t, host, "runr-builds/multi-copy:v1")
	pushed, err := Push(context.Background(), cfg, out, "")
	if err != nil {
		t.Fatal(err)
	}
	if pushed.Digest != want.String() || strings.Join(pushed.Platforms, ",") != "linux/amd64,linux/arm64" || pushed.Note != "" {
		t.Fatalf("pushed %+v, index %s", pushed, want)
	}
}

func TestPullOfAMissingImageFails(t *testing.T) {
	host := testRegistry(t)
	out := filepath.Join(t.TempDir(), "image.tar")
	if _, err := pullImage(context.Background(), host+"/runr-builds/none:v1", out, true); err == nil || !strings.Contains(err.Error(), "reading") {
		t.Fatalf("err %v", err)
	}
	if _, err := pullImage(context.Background(), "UPPER CASE", out, true); err == nil {
		t.Fatal("a bad reference was accepted")
	}
}
