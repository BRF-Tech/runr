package config

import (
	"os"
	"path/filepath"
	"runtime"
	"testing"
)

func TestSaveLoadRoundTripWithPrivatePermissions(t *testing.T) {
	p := filepath.Join(t.TempDir(), "nested", "config.yaml")
	f, err := LoadFrom(p)
	if err != nil {
		t.Fatal(err)
	}
	f.Set("prod", &Context{APIURL: "https://runr.example.com", Token: "secret", TokenID: "7", User: "ada"})
	f.Set("dev", &Context{APIURL: "http://localhost:8000", Token: "t2"})
	if err := f.Save(); err != nil {
		t.Fatal(err)
	}
	if runtime.GOOS != "windows" {
		st, _ := os.Stat(p)
		if st.Mode().Perm() != 0o600 {
			t.Fatalf("file mode %v", st.Mode().Perm())
		}
		dst, _ := os.Stat(filepath.Dir(p))
		if dst.Mode().Perm() != 0o700 {
			t.Fatalf("dir mode %v", dst.Mode().Perm())
		}
	}
	g, err := LoadFrom(p)
	if err != nil {
		t.Fatal(err)
	}
	if g.CurrentContext != "dev" || g.Contexts["prod"].TokenID != "7" || len(g.Names()) != 2 {
		t.Fatalf("loaded = %+v", g)
	}
	if _, err := os.Stat(p + ".tmp"); err == nil {
		t.Fatal("temporary file left behind")
	}
}

func TestResolvePrecedence(t *testing.T) {
	t.Setenv(EnvAPIURL, "")
	t.Setenv(EnvToken, "")
	t.Setenv(EnvContext, "")
	f := &File{Contexts: map[string]*Context{
		"a": {APIURL: "https://a", Token: "ta"},
		"b": {APIURL: "https://b", Token: "tb"},
	}, CurrentContext: "a"}

	r, err := f.Resolve("")
	if err != nil || r.Name != "a" || r.Token != "ta" || r.FromEnv {
		t.Fatalf("current = %+v %v", r, err)
	}
	r, _ = f.Resolve("b")
	if r.APIURL != "https://b" {
		t.Fatalf("flag = %+v", r)
	}
	t.Setenv(EnvContext, "b")
	r, _ = f.Resolve("")
	if r.Name != "b" {
		t.Fatalf("RUNR_CONTEXT = %+v", r)
	}
	t.Setenv(EnvToken, "from-env")
	r, _ = f.Resolve("")
	if r.Token != "from-env" || r.APIURL != "https://b" || !r.FromEnv {
		t.Fatalf("RUNR_TOKEN = %+v", r)
	}
	if _, err := f.Resolve("missing"); err == nil {
		t.Fatal("missing context resolved")
	}
	t.Setenv(EnvAPIURL, "https://env")
	r, err = f.Resolve("missing")
	if err != nil || r.APIURL != "https://env" || r.Token != "from-env" {
		t.Fatalf("env only = %+v %v", r, err)
	}
}

func TestRemoveAndUse(t *testing.T) {
	f := &File{Contexts: map[string]*Context{"a": {}, "b": {}}, CurrentContext: "a"}
	if err := f.Use("c"); err == nil {
		t.Fatal("unknown context used")
	}
	if err := f.Remove("a"); err != nil || f.CurrentContext != "b" {
		t.Fatalf("remove current: %v %q", err, f.CurrentContext)
	}
	if err := f.Remove("a"); err == nil {
		t.Fatal("removed twice")
	}
	_ = f.Remove("b")
	if f.CurrentContext != "" {
		t.Fatalf("current after removing all = %q", f.CurrentContext)
	}
}

func TestPathHonoursRUNR_CONFIG(t *testing.T) {
	t.Setenv(EnvConfig, "/tmp/x.yaml")
	if p, _ := Path(); p != "/tmp/x.yaml" {
		t.Fatalf("Path = %s", p)
	}
	t.Setenv(EnvConfig, "")
	if p, err := Path(); err != nil || filepath.Base(p) != "config.yaml" || filepath.Base(filepath.Dir(p)) != "runr" {
		t.Fatalf("default Path = %s %v", p, err)
	}
}
