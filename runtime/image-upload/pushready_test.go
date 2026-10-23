package main

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// The import side of an exported build image: once the agent marked the file ready, the image is
// pushed to the target and .done holds the result.
func TestPushWhenReadyPushesTheReceivedImage(t *testing.T) {
	host := testRegistry(t)
	img := randomImage(t, "linux", "amd64")
	writeRemote(t, host+"/runr-builds/api:v3", img)

	dir := t.TempDir()
	if err := os.MkdirAll(filepath.Join(dir, "in"), 0o755); err != nil {
		t.Fatal(err)
	}
	if _, err := pullImage(context.Background(), host+"/runr-builds/api:v3", filepath.Join(dir, "in", "image.tar"), true); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "in", markerReady), nil, 0o644); err != nil {
		t.Fatal(err)
	}

	env := map[string]string{"RUNR_TRANSFER_DIR": dir, "RUNR_UPLOAD_TARGET": host + "/runr-builds/api-copy:v3"}
	if err := pushWhenReady(context.Background(), "image.tar", func(k string) string { return env[k] }, time.Millisecond); err != nil {
		t.Fatal(err)
	}

	want, _ := img.Digest()
	if got := headDigest(t, host+"/runr-builds/api-copy:v3"); got != want.String() {
		t.Fatalf("pushed %s, want %s", got, want)
	}
	b, err := os.ReadFile(filepath.Join(dir, markerDone))
	if err != nil {
		t.Fatal(err)
	}
	var res Result
	if err := json.Unmarshal(b, &res); err != nil || res.Digest != want.String() {
		t.Fatalf(".done %s (%v)", b, err)
	}
}

func TestPushWhenReadyStopsWhenTheTransferFailed(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, markerFailed), []byte("upload aborted"), 0o644); err != nil {
		t.Fatal(err)
	}
	env := map[string]string{"RUNR_TRANSFER_DIR": dir, "RUNR_UPLOAD_TARGET": "registry.local/x:1"}
	err := pushWhenReady(context.Background(), "image.tar", func(k string) string { return env[k] }, time.Millisecond)
	if err == nil || !strings.Contains(err.Error(), "failed before") {
		t.Fatalf("err %v", err)
	}
}

func TestPushWhenReadyRefusesBadInput(t *testing.T) {
	env := map[string]string{"RUNR_TRANSFER_DIR": t.TempDir(), "RUNR_UPLOAD_TARGET": "no-slash"}
	if err := pushWhenReady(context.Background(), "image.tar", func(k string) string { return env[k] }, time.Millisecond); err == nil {
		t.Fatal("a target without a registry was accepted")
	}
	env["RUNR_UPLOAD_TARGET"] = "registry.local/x:1"
	if err := pushWhenReady(context.Background(), "../image.tar", func(k string) string { return env[k] }, time.Millisecond); err == nil {
		t.Fatal("a path was accepted as the file name")
	}
}
