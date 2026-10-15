package dev

import (
	"os"
	"path/filepath"
	"regexp"
	"testing"
)

// runr dev installs the same universal wrapper release the platform does: WrapperVersion
// matches the default of executor.wrapper_image in api/config/executor.php (the wrapper's tick
// mode for schedules arrived in 1.1.0).
func TestWrapperVersionMatchesThePlatformDefault(t *testing.T) {
	config, err := os.ReadFile(filepath.Join("..", "..", "..", "api", "config", "executor.php"))
	if err != nil {
		t.Skipf("api/config/executor.php not available: %v", err)
	}

	m := regexp.MustCompile(`'wrapper_image'\s*=>\s*env\('RUNR_WRAPPER_IMAGE',\s*'[^']*/runr-wrapper:([^']+)'\)`).FindSubmatch(config)
	if m == nil {
		t.Fatal("no runr-wrapper default in api/config/executor.php")
	}
	if got := string(m[1]); got != WrapperVersion {
		t.Errorf("WrapperVersion = %q, the API installs runr-wrapper:%s", WrapperVersion, got)
	}
	if want := "ghcr.io/runrsh/runr-wrapper:" + WrapperVersion; WrapperImage() != want && os.Getenv(EnvWrapperImage) == "" && os.Getenv("RUNR_RUNTIME_REGISTRY") == "" {
		t.Errorf("WrapperImage() = %q, want %q", WrapperImage(), want)
	}
}
