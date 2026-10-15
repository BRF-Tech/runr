package projectfile

import (
	"fmt"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"sort"
	"strings"
	"unicode/utf8"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// DefaultIgnore are never uploaded.
var DefaultIgnore = []string{
	".git", ".hg", ".svn", ".runr", ".idea", ".vscode", ".DS_Store", "Thumbs.db",
	"node_modules", "vendor", "__pycache__", "*.pyc", ".venv", "venv",
	".env", ".env.*", ".gitignore", ".runrignore", FileName, "runr.yml",
}

// Ignored reports whether a slash-separated relative path matches one of the patterns. A
// pattern without "/" matches any path element; with "/" it matches the whole path.
func Ignored(rel string, patterns []string) bool {
	rel = filepath.ToSlash(rel)
	parts := strings.Split(rel, "/")
	for _, p := range patterns {
		p = strings.TrimSpace(p)
		if p == "" || strings.HasPrefix(p, "#") {
			continue
		}
		p = strings.TrimSuffix(strings.TrimPrefix(p, "/"), "/")
		if strings.Contains(p, "/") {
			if ok, _ := path.Match(p, rel); ok {
				return true
			}
			if strings.HasPrefix(rel, p+"/") {
				return true
			}
			continue
		}
		for _, el := range parts {
			if ok, _ := path.Match(p, el); ok {
				return true
			}
		}
	}
	return false
}

// readIgnoreFile reads .runrignore in dir (one pattern per line).
func readIgnoreFile(dir string) []string {
	data, err := os.ReadFile(filepath.Join(dir, ".runrignore"))
	if err != nil {
		return nil
	}
	var out []string
	for _, line := range strings.Split(string(data), "\n") {
		if l := strings.TrimSpace(line); l != "" && !strings.HasPrefix(l, "#") {
			out = append(out, l)
		}
	}
	return out
}

// CollectFiles returns the files of a faas project directory as the API stores them: a flat
// set (each file becomes a ConfigMap key), UTF-8 text, at most runr.MaxCodeBytes in total.
func CollectFiles(dir string, extraIgnore []string) ([]runr.File, error) {
	patterns := append(append(append([]string{}, DefaultIgnore...), extraIgnore...), readIgnoreFile(dir)...)
	var files []runr.File
	var nested []string
	total := 0
	err := filepath.WalkDir(dir, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, err := filepath.Rel(dir, p)
		if err != nil {
			return err
		}
		if rel == "." {
			return nil
		}
		if Ignored(rel, patterns) {
			if d.IsDir() {
				return filepath.SkipDir
			}
			return nil
		}
		if d.IsDir() {
			return nil
		}
		if !d.Type().IsRegular() {
			return nil
		}
		if strings.ContainsRune(filepath.ToSlash(rel), '/') {
			nested = append(nested, filepath.ToSlash(rel))
			return nil
		}
		data, err := os.ReadFile(p)
		if err != nil {
			return err
		}
		if !utf8.Valid(data) {
			return fmt.Errorf("%s is not UTF-8 text; RunR stores code as text (add it to ignore in runr.yaml)", rel)
		}
		if len(data) == 0 {
			return fmt.Errorf("%s is empty; the API does not store empty files (remove it or add it to ignore)", rel)
		}
		total += len(rel) + len(data)
		files = append(files, runr.File{Name: rel, Content: string(data)})
		return nil
	})
	if err != nil {
		return nil, err
	}
	if len(nested) > 0 {
		sort.Strings(nested)
		return nil, fmt.Errorf("faas projects hold a flat set of files; move these to the project root or add them to ignore: %s",
			strings.Join(nested, ", "))
	}
	if total > runr.MaxCodeBytes {
		return nil, fmt.Errorf("the code is %d bytes; the limit is %d bytes (names + contents)", total, runr.MaxCodeBytes)
	}
	sort.Slice(files, func(i, j int) bool { return files[i].Name < files[j].Name })
	return files, nil
}
