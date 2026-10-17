// Package config stores the CLI's contexts (API URL + token pairs) in the OS config
// directory: $XDG_CONFIG_HOME/runr/config.yaml or ~/.config/runr on Linux,
// ~/Library/Application Support/runr on macOS, %AppData%\runr on Windows. RUNR_CONFIG
// overrides the file path. The file is written with 0600 permissions.
package config

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"gopkg.in/yaml.v3"
)

// Environment variables that override the stored configuration (CI).
const (
	EnvConfig  = "RUNR_CONFIG"
	EnvAPIURL  = "RUNR_API_URL"
	EnvToken   = "RUNR_TOKEN"
	EnvContext = "RUNR_CONTEXT"
)

// Context is one RunR API the CLI can talk to.
type Context struct {
	APIURL string `yaml:"api_url"`
	Token  string `yaml:"token,omitempty"`
	// TokenID is set when `runr login --with-password` created the token, so logout can revoke it.
	TokenID string `yaml:"token_id,omitempty"`
	// TokenExpiresAt is when the token `runr login --with-password` created expires (nil: never,
	// or a token the CLI did not create).
	TokenExpiresAt *time.Time `yaml:"token_expires_at,omitempty"`
	User           string     `yaml:"user,omitempty"`
}

// TokenExpired reports whether the token the CLI created for this context has expired at now.
func (c *Context) TokenExpired(now time.Time) bool {
	return c != nil && c.TokenExpiresAt != nil && !now.Before(*c.TokenExpiresAt)
}

// File is the configuration file.
type File struct {
	CurrentContext string              `yaml:"current_context,omitempty"`
	Contexts       map[string]*Context `yaml:"contexts,omitempty"`
	// UpdateCheck enables the release check of `runr version` (off by default).
	UpdateCheck bool `yaml:"update_check,omitempty"`

	path string
}

// Path returns the configuration file path.
func Path() (string, error) {
	if p := os.Getenv(EnvConfig); p != "" {
		return p, nil
	}
	dir, err := os.UserConfigDir()
	if err != nil {
		return "", fmt.Errorf("locate the config directory: %w", err)
	}
	return filepath.Join(dir, "runr", "config.yaml"), nil
}

// Load reads the configuration; a missing file is an empty configuration.
func Load() (*File, error) {
	p, err := Path()
	if err != nil {
		return nil, err
	}
	return LoadFrom(p)
}

// LoadFrom reads the configuration file at p.
func LoadFrom(p string) (*File, error) {
	f := &File{Contexts: map[string]*Context{}, path: p}
	data, err := os.ReadFile(p)
	if errors.Is(err, os.ErrNotExist) {
		return f, nil
	}
	if err != nil {
		return nil, fmt.Errorf("read %s: %w", p, err)
	}
	if err := yaml.Unmarshal(data, f); err != nil {
		return nil, fmt.Errorf("parse %s: %w", p, err)
	}
	if f.Contexts == nil {
		f.Contexts = map[string]*Context{}
	}
	f.path = p
	return f, nil
}

// Save writes the configuration with 0600 permissions (directory 0700).
func (f *File) Save() error {
	if f.path == "" {
		p, err := Path()
		if err != nil {
			return err
		}
		f.path = p
	}
	if err := os.MkdirAll(filepath.Dir(f.path), 0o700); err != nil {
		return fmt.Errorf("create %s: %w", filepath.Dir(f.path), err)
	}
	data, err := yaml.Marshal(f)
	if err != nil {
		return err
	}
	tmp := f.path + ".tmp"
	if err := os.WriteFile(tmp, data, 0o600); err != nil {
		return fmt.Errorf("write %s: %w", tmp, err)
	}
	if err := os.Chmod(tmp, 0o600); err != nil {
		return err
	}
	if err := os.Rename(tmp, f.path); err != nil {
		_ = os.Remove(tmp)
		return fmt.Errorf("write %s: %w", f.path, err)
	}
	return nil
}

// FilePath returns where the configuration is stored.
func (f *File) FilePath() string { return f.path }

// Names returns the context names sorted.
func (f *File) Names() []string {
	names := make([]string, 0, len(f.Contexts))
	for n := range f.Contexts {
		names = append(names, n)
	}
	sort.Strings(names)
	return names
}

// Set stores a context and makes it current.
func (f *File) Set(name string, c *Context) {
	if f.Contexts == nil {
		f.Contexts = map[string]*Context{}
	}
	f.Contexts[name] = c
	f.CurrentContext = name
}

// Remove deletes a context; the current context moves to another one when it was removed.
func (f *File) Remove(name string) error {
	if _, ok := f.Contexts[name]; !ok {
		return fmt.Errorf("context %q does not exist", name)
	}
	delete(f.Contexts, name)
	if f.CurrentContext == name {
		f.CurrentContext = ""
		if names := f.Names(); len(names) > 0 {
			f.CurrentContext = names[0]
		}
	}
	return nil
}

// Use makes a context current.
func (f *File) Use(name string) error {
	if _, ok := f.Contexts[name]; !ok {
		return fmt.Errorf("context %q does not exist (known: %s)", name, strings.Join(f.Names(), ", "))
	}
	f.CurrentContext = name
	return nil
}

// Resolved is the API URL and token a command uses, and where they came from.
type Resolved struct {
	Name   string
	APIURL string
	Token  string
	// FromEnv is true when RUNR_API_URL or RUNR_TOKEN supplied a value.
	FromEnv bool
	Context *Context
}

// Resolve picks the context: the name given (--context), else RUNR_CONTEXT, else the current
// context; RUNR_API_URL and RUNR_TOKEN override its values.
func (f *File) Resolve(name string) (*Resolved, error) {
	if name == "" {
		name = os.Getenv(EnvContext)
	}
	if name == "" {
		name = f.CurrentContext
	}
	r := &Resolved{Name: name}
	if name != "" {
		c, ok := f.Contexts[name]
		if !ok && os.Getenv(EnvAPIURL) == "" {
			return nil, fmt.Errorf("context %q does not exist; run `runr login` or `runr context ls`", name)
		}
		if ok {
			r.Context = c
			r.APIURL = c.APIURL
			r.Token = c.Token
		}
	}
	if v := os.Getenv(EnvAPIURL); v != "" {
		r.APIURL = v
		r.FromEnv = true
	}
	if v := os.Getenv(EnvToken); v != "" {
		r.Token = v
		r.FromEnv = true
	}
	return r, nil
}

// LoginToken returns the context when the command authenticates with the API token that
// `runr login --with-password` created for it, not with one RUNR_API_URL / RUNR_TOKEN override.
func (r *Resolved) LoginToken() *Context {
	if r == nil || r.Context == nil || r.Context.TokenID == "" || r.Token != r.Context.Token || r.APIURL != r.Context.APIURL {
		return nil
	}
	return r.Context
}
