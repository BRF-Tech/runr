package main

import (
	"bytes"
	"os"
	"path/filepath"
	"testing"
)

func TestInstallCopiesTheExecutable(t *testing.T) {
	dest := filepath.Join(t.TempDir(), "runr", "bin", "runr-wrapper")

	if err := runInstall(dest); err != nil {
		t.Fatal(err)
	}

	info, err := os.Stat(dest)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o755 {
		t.Errorf("mode = %v", info.Mode().Perm())
	}

	self, _ := os.Executable()
	want, _ := os.ReadFile(self)
	got, _ := os.ReadFile(dest)
	if !bytes.Equal(want, got) {
		t.Error("the installed file differs from the executable")
	}

	// Installing again replaces the file
	if err := runInstall(dest); err != nil {
		t.Errorf("reinstall: %v", err)
	}
	entries, _ := os.ReadDir(filepath.Dir(dest))
	if len(entries) != 1 {
		t.Errorf("temporary files left behind: %v", entries)
	}
}
