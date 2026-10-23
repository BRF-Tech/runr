package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// pushWhenReady is the tool side of an image import (docs/org-export.md): it runs next to a
// receiving transfer agent in the same pod, waits until the agent has received and verified the
// file (in/.ready), pushes it to RUNR_UPLOAD_TARGET like an upload's receiver does (docker save /
// OCI layout tars, multi-platform indexes) and writes the agent's marker — .done with the push
// result, or .failed with the reason.
//
//	runr-image-upload push-when-ready NAME     (RUNR_TRANSFER_DIR, RUNR_UPLOAD_TARGET,
//	                                            RUNR_UPLOAD_PUSH_TIMEOUT, RUNR_REGISTRY_PLAIN_HTTP)
func pushWhenReady(ctx context.Context, name string, getenv func(string) string, poll time.Duration) error {
	dir := getenv("RUNR_TRANSFER_DIR")
	if dir == "" {
		dir = "/work"
	}
	if !transferName.MatchString(name) {
		return fmt.Errorf("%q is not a plain file name", name)
	}
	target := strings.TrimSpace(getenv("RUNR_UPLOAD_TARGET"))
	if target == "" || !strings.Contains(target, "/") {
		return fmt.Errorf("RUNR_UPLOAD_TARGET must be registry/repository:tag")
	}
	timeout := time.Hour
	if v := getenv("RUNR_UPLOAD_PUSH_TIMEOUT"); v != "" {
		d, err := time.ParseDuration(v + "s")
		if err != nil || d <= 0 {
			return fmt.Errorf("RUNR_UPLOAD_PUSH_TIMEOUT %q is not a positive number of seconds", v)
		}
		timeout = d
	}

	ready := filepath.Join(dir, "in", markerReady)
	for {
		if _, err := os.Stat(ready); err == nil {
			break
		}
		if _, err := os.Stat(filepath.Join(dir, markerFailed)); err == nil {
			return errors.New("the transfer failed before the file arrived")
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(poll):
		}
	}

	cfg := Config{
		Dir:         filepath.Join(dir, "push"),
		Target:      target,
		Format:      FormatAuto,
		PushTimeout: timeout,
		PlainHTTP:   getenv("RUNR_REGISTRY_PLAIN_HTTP") != "false",
	}
	if err := os.MkdirAll(cfg.Dir, 0o755); err != nil {
		return fail(dir, err)
	}

	pctx, cancel := context.WithTimeout(ctx, cfg.PushTimeout)
	defer cancel()
	res, err := Push(pctx, cfg, filepath.Join(dir, "in", name), "")
	if err != nil {
		return fail(dir, fmt.Errorf("push to %s: %w", target, err))
	}
	b, _ := json.Marshal(res)
	return os.WriteFile(filepath.Join(dir, markerDone), b, 0o644)
}

// fail writes the .failed marker the agent reports, and returns err.
func fail(dir string, err error) error {
	msg := err.Error()
	if len(msg) > 2000 {
		msg = msg[:2000]
	}
	_ = os.WriteFile(filepath.Join(dir, markerFailed), []byte(msg), 0o644)
	return err
}
