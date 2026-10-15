package main

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
)

// runInstall copies this executable to dest (mode 0755), creating its directory. It needs no
// shell, so it runs in the scratch wrapper image: an init container installs the wrapper into a
// shared emptyDir, a build Job into the Kaniko context.
func runInstall(dest string) error {
	src, err := os.Executable()
	if err != nil {
		return fmt.Errorf("locate executable: %w", err)
	}
	if resolved, err := filepath.EvalSymlinks(src); err == nil {
		src = resolved
	}

	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()

	if err := os.MkdirAll(filepath.Dir(dest), 0o755); err != nil {
		return err
	}

	tmp, err := os.CreateTemp(filepath.Dir(dest), ".runr-wrapper-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name()) // no-op after the rename

	if _, err := io.Copy(tmp, in); err != nil {
		_ = tmp.Close()
		return err
	}
	if err := tmp.Chmod(0o755); err != nil {
		_ = tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}

	if err := os.Rename(tmp.Name(), dest); err != nil {
		return err
	}

	logger.Printf("installed %s to %s", version, dest)
	return nil
}
