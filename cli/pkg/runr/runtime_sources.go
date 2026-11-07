package runr

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"sort"
	"strings"
)

// Runtime sources (docs/runtimes.md#runtime-sources): where runtimes come from — RunR's signed
// catalog (the built-in runtimes, the default source) and the OCI catalogs and Git repositories
// platform administrators (runtimes.manage, for every organization) and organizations
// (runtimes.use_custom while custom runtimes are enabled, for themselves) connect. RunR reads a
// source when it is connected or changed, on its interval and on demand (sync). Credentials are
// write-only: an answer says which are set, never their value.

// Runtime source kinds.
const (
	RuntimeSourceOCI = "oci-catalog"
	RuntimeSourceGit = "git"
)

// Runtime source statuses (RuntimeSource.Status).
const (
	RuntimeSourceNever            = "never"
	RuntimeSourceOK               = "ok"
	RuntimeSourcePartial          = "partial"
	RuntimeSourceUnreachable      = "unreachable"
	RuntimeSourceNotFound         = "not_found"
	RuntimeSourceAuthFailed       = "auth_failed"
	RuntimeSourceInvalidSignature = "invalid_signature"
	RuntimeSourceInvalidCatalog   = "invalid_catalog"
	RuntimeSourceDisabled         = "disabled"
)

// RuntimeSourceFailed reports whether a status is a read that left the runtimes as they were
// (the last good read stays).
func RuntimeSourceFailed(status string) bool {
	switch status {
	case RuntimeSourceUnreachable, RuntimeSourceNotFound, RuntimeSourceAuthFailed, RuntimeSourceInvalidSignature, RuntimeSourceInvalidCatalog:
		return true
	}
	return false
}

// RuntimeSource is one source of runtimes (RuntimeSource::toApi()).
type RuntimeSource struct {
	ID   string `json:"id"`
	Name string `json:"name"`
	// Kind is oci-catalog or git; Scope platform (every organization) or organization.
	Kind     string `json:"kind"`
	Scope    string `json:"scope"`
	TenantID string `json:"tenant_id,omitempty"`
	// IsDefault marks RunR's own catalog: only IsEnabled and IntervalMinutes can change.
	IsDefault bool   `json:"is_default"`
	URL       string `json:"url"`
	// Ref and Path are a Git source's branch / tag / commit and the directory of <id>/runtime.yaml.
	Ref  string `json:"ref,omitempty"`
	Path string `json:"path,omitempty"`
	// PublicKey is the cosign key (PEM) the catalog and the patch feed must verify with.
	PublicKey    string                   `json:"public_key,omitempty"`
	PatchFeed    string                   `json:"patch_feed,omitempty"`
	RegistryHost string                   `json:"registry_host,omitempty"`
	Credentials  RuntimeSourceCredentials `json:"credentials"`
	IsEnabled    bool                     `json:"is_enabled"`
	// IntervalMinutes is how often RunR reads the source.
	IntervalMinutes int `json:"interval_minutes"`
	// Status is never, ok, partial (some manifests were refused: Problems), unreachable,
	// not_found (missing, or not public to a read without credentials), auth_failed (the source's
	// credentials were refused), invalid_signature, invalid_catalog or disabled; Error says why a
	// read failed.
	Status            string                 `json:"status"`
	Error             string                 `json:"error,omitempty"`
	Problems          []RuntimeSourceProblem `json:"problems"`
	Revision          string                 `json:"revision,omitempty"`
	SignatureVerified bool                   `json:"signature_verified"`
	RuntimesCount     int                    `json:"runtimes_count"`
	CheckedAt         string                 `json:"checked_at,omitempty"`
	SyncedAt          string                 `json:"synced_at,omitempty"`
	NextCheckAt       string                 `json:"next_check_at,omitempty"`
	CreatedBy         FlexID                 `json:"created_by,omitempty"`
	CreatedAt         string                 `json:"created_at,omitempty"`
	UpdatedAt         string                 `json:"updated_at,omitempty"`
}

// RuntimeSourceCredentials says which credentials a source has (never their values).
type RuntimeSourceCredentials struct {
	// Git is token (HTTPS), ssh (a deploy key) or "" (none).
	Git          string `json:"git,omitempty"`
	GitUsername  string `json:"git_username,omitempty"`
	GitTokenHint string `json:"git_token_hint,omitempty"`
	SSHKeySet    bool   `json:"ssh_key_set"`
	// KnownHostsSet: without known_hosts the SSH host key is accepted on first use.
	KnownHostsSet       bool   `json:"known_hosts_set"`
	RegistryUsername    string `json:"registry_username,omitempty"`
	RegistryPasswordSet bool   `json:"registry_password_set"`
}

// RuntimeSourceProblem is one problem of a read: the file, the runtime, the JSON path in the
// manifest and the message.
type RuntimeSourceProblem struct {
	File    string `json:"file,omitempty"`
	Runtime string `json:"runtime,omitempty"`
	Path    string `json:"path"`
	Message string `json:"message"`
}

// RuntimeSourceList is GET /runtime-sources.
type RuntimeSourceList struct {
	Sources []RuntimeSource `json:"data"`
	// Catalog says which built-in catalog is in use: RunR's as last read (source) or the API
	// image's offline fallback (bundle), and how many runtimes it has.
	Catalog struct {
		Mode     string `json:"mode"`
		Runtimes int    `json:"runtimes"`
	} `json:"catalog"`
	Abilities struct {
		Manage       bool `json:"manage"`
		Organization bool `json:"organization"`
	} `json:"abilities"`
	CustomEnabled bool `json:"custom_enabled"`
}

// RuntimeSourcePatchFeed is the patch feed a source carries (RuntimePatchFeed::toApi()).
type RuntimeSourcePatchFeed struct {
	Reference         string `json:"reference"`
	Enabled           bool   `json:"enabled"`
	Status            string `json:"status"`
	Error             string `json:"error,omitempty"`
	SignatureVerified bool   `json:"signature_verified"`
	GeneratedAt       string `json:"generated_at,omitempty"`
	CheckedAt         string `json:"checked_at,omitempty"`
	FetchedAt         string `json:"fetched_at,omitempty"`
}

// RuntimeSourceDetail is GET /runtime-sources/{source}: the source, its runtimes (each with its
// lifecycle) and the patches it carries for them.
type RuntimeSourceDetail struct {
	Source    RuntimeSource           `json:"source"`
	Runtimes  []CatalogRuntime        `json:"runtimes"`
	Patches   []RuntimePatch          `json:"patches"`
	PatchFeed *RuntimeSourcePatchFeed `json:"patch_feed"`
}

// RuntimeSourceInput is the body of POST and PUT /runtime-sources and POST /runtime-sources/test.
// Nil fields are not sent. Ref, Path, PublicKey, PatchFeed and RegistryHost set to "" remove the
// value. The credentials are write-only: nil keeps the stored one, "" removes it.
type RuntimeSourceInput struct {
	Name            *string
	Kind            *string
	URL             *string
	Ref             *string
	Path            *string
	PublicKey       *string
	PatchFeed       *string
	RegistryHost    *string
	IntervalMinutes *int
	IsEnabled       *bool

	GitToken         *string
	GitUsername      *string
	SSHPrivateKey    *string
	SSHKnownHosts    *string
	RegistryUsername *string
	RegistryPassword *string
}

// ClearGitCredentials removes the source's Git token, username, deploy key and known_hosts.
func (in *RuntimeSourceInput) ClearGitCredentials() {
	empty := ""
	in.GitToken, in.GitUsername, in.SSHPrivateKey, in.SSHKnownHosts = &empty, &empty, &empty, &empty
}

// ClearRegistryCredentials removes the source's registry username and password.
func (in *RuntimeSourceInput) ClearRegistryCredentials() {
	empty := ""
	in.RegistryUsername, in.RegistryPassword = &empty, &empty
}

// Empty reports whether the input changes nothing.
func (in RuntimeSourceInput) Empty() bool { return len(in.body()) == 0 }

func (in RuntimeSourceInput) body() map[string]any {
	b := map[string]any{}
	set := func(key string, v *string) {
		if v != nil {
			b[key] = *v
		}
	}
	clearable := func(key string, v *string) {
		if v == nil {
			return
		}
		if *v == "" {
			b[key] = nil
		} else {
			b[key] = *v
		}
	}
	set("name", in.Name)
	set("kind", in.Kind)
	set("url", in.URL)
	clearable("ref", in.Ref)
	clearable("path", in.Path)
	clearable("public_key", in.PublicKey)
	clearable("patch_feed", in.PatchFeed)
	clearable("registry_host", in.RegistryHost)
	if in.IntervalMinutes != nil {
		b["interval_minutes"] = *in.IntervalMinutes
	}
	if in.IsEnabled != nil {
		b["is_enabled"] = *in.IsEnabled
	}
	clearable("git_token", in.GitToken)
	clearable("git_username", in.GitUsername)
	clearable("ssh_private_key", in.SSHPrivateKey)
	clearable("ssh_known_hosts", in.SSHKnownHosts)
	clearable("registry_username", in.RegistryUsername)
	clearable("registry_password", in.RegistryPassword)
	return b
}

// RuntimeSourceTest is the answer of POST /runtime-sources/test: what a read of the source sees.
type RuntimeSourceTest struct {
	// OK is false when the source could not be read or did not verify (Status, Error).
	OK                bool                   `json:"ok"`
	Status            string                 `json:"status"`
	Error             string                 `json:"error,omitempty"`
	Revision          string                 `json:"revision,omitempty"`
	SignatureVerified bool                   `json:"signature_verified"`
	Runtimes          []RuntimeSourceEntry   `json:"runtimes"`
	Problems          []RuntimeSourceProblem `json:"problems"`
}

// RuntimeSourceEntry is one runtime a tested source defines.
type RuntimeSourceEntry struct {
	ID    string `json:"id,omitempty"`
	Path  string `json:"path"`
	Valid bool   `json:"valid"`
}

// ListRuntimeSources returns the sources the caller sees (every one for runtimes.manage, the
// organization's for runtimes.use_custom); ErrUnsupported on APIs without runtime sources.
func (c *Client) ListRuntimeSources(ctx context.Context) (*RuntimeSourceList, error) {
	var out RuntimeSourceList
	err := c.do(ctx, request{method: http.MethodGet, path: "/runtime-sources"}, &out)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	if out.Sources == nil {
		out.Sources = []RuntimeSource{}
	}
	return &out, nil
}

// FindRuntimeSource resolves a source by id, else by name (any case).
func (c *Client) FindRuntimeSource(ctx context.Context, ref string) (*RuntimeSource, error) {
	list, err := c.ListRuntimeSources(ctx)
	if err != nil {
		return nil, err
	}
	return PickRuntimeSource(list.Sources, ref)
}

// PickRuntimeSource applies FindRuntimeSource's rule to a list. A name two sources share (sources
// of different organizations, seen by a platform administrator) is ambiguous: use the id.
func PickRuntimeSource(list []RuntimeSource, ref string) (*RuntimeSource, error) {
	ref = strings.TrimSpace(ref)
	for i := range list {
		if list[i].ID == ref {
			return &list[i], nil
		}
	}
	var found []*RuntimeSource
	for i := range list {
		if strings.EqualFold(list[i].Name, ref) {
			found = append(found, &list[i])
		}
	}
	switch len(found) {
	case 1:
		return found[0], nil
	case 0:
		return nil, &NotFoundError{Kind: "runtime source", Ref: ref}
	}
	ids := make([]string, 0, len(found))
	for _, s := range found {
		ids = append(ids, s.ID)
	}
	sort.Strings(ids)
	return nil, fmt.Errorf("%d runtime sources are named %q: use the id (%s)", len(found), ref, strings.Join(ids, ", "))
}

// GetRuntimeSource returns a source with its runtimes, patches and patch feed.
func (c *Client) GetRuntimeSource(ctx context.Context, id string) (*RuntimeSourceDetail, error) {
	var raw []byte
	err := c.do(ctx, request{method: http.MethodGet, path: "/runtime-sources/" + seg(id)}, &raw)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "runtime source", Ref: id}
	}
	if err != nil {
		return nil, err
	}
	var d RuntimeSourceDetail
	if err := decodeData(raw, &d); err != nil {
		return nil, err
	}
	if d.Runtimes == nil {
		d.Runtimes = []CatalogRuntime{}
	}
	if d.Patches == nil {
		d.Patches = []RuntimePatch{}
	}
	return &d, nil
}

func decodeRuntimeSource(raw []byte) (*RuntimeSource, error) {
	var s RuntimeSource
	if err := decodeData(raw, &s); err != nil {
		return nil, err
	}
	return &s, nil
}

// CreateRuntimeSource connects a source: a platform source with runtimes.manage, a source of the
// organization with runtimes.use_custom. RunR reads it right away; its Status says how that went.
func (c *Client) CreateRuntimeSource(ctx context.Context, in RuntimeSourceInput) (*RuntimeSource, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPost, path: "/runtime-sources", body: in.body()}, &raw); err != nil {
		if IsNotFound(err) {
			return nil, ErrUnsupported
		}
		return nil, err
	}
	return decodeRuntimeSource(raw)
}

// UpdateRuntimeSource changes a source and reads it right away. RunR's catalog (the default
// source) takes IsEnabled and IntervalMinutes only.
func (c *Client) UpdateRuntimeSource(ctx context.Context, id string, in RuntimeSourceInput) (*RuntimeSource, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPut, path: "/runtime-sources/" + seg(id), body: in.body()}, &raw); err != nil {
		if IsNotFound(err) {
			return nil, &NotFoundError{Kind: "runtime source", Ref: id}
		}
		return nil, err
	}
	return decodeRuntimeSource(raw)
}

// DeleteRuntimeSource removes a source: its runtimes no project uses go, the others stay
// orphaned until their sunset. The API refuses RunR's catalog (422).
func (c *Client) DeleteRuntimeSource(ctx context.Context, id string) error {
	err := c.do(ctx, request{method: http.MethodDelete, path: "/runtime-sources/" + seg(id)}, nil)
	if IsNotFound(err) {
		return &NotFoundError{Kind: "runtime source", Ref: id}
	}
	return err
}

// SyncRuntimeSource reads a source now, even when its revision did not change. A read that
// failed or did not verify returns the source (its Status and Error) with a *FailedError; a read
// already running is a 409 APIError.
func (c *Client) SyncRuntimeSource(ctx context.Context, id string) (*RuntimeSource, error) {
	var raw []byte
	err := c.do(ctx, request{method: http.MethodPost, path: "/runtime-sources/" + seg(id) + "/sync"}, &raw)
	var ae *APIError
	if errors.As(err, &ae) && ae.StatusCode == http.StatusBadGateway && len(ae.Data) > 0 && ae.Data[0] == '{' {
		var s RuntimeSource
		if json.Unmarshal(ae.Data, &s) == nil && s.ID != "" {
			return &s, &FailedError{What: "the read of runtime source", Ref: s.Name, Status: s.Status, Message: s.Error}
		}
	}
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "runtime source", Ref: id}
	}
	if err != nil {
		return nil, err
	}
	return decodeRuntimeSource(raw)
}

// TestRuntimeSource reads and validates a source as given, without saving it. With sourceID the
// input changes that connected source and its stored credentials are used.
func (c *Client) TestRuntimeSource(ctx context.Context, sourceID string, in RuntimeSourceInput) (*RuntimeSourceTest, error) {
	body := in.body()
	if sourceID != "" {
		body["source"] = sourceID
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPost, path: "/runtime-sources/test", body: body}, &raw); err != nil {
		if IsNotFound(err) && sourceID != "" {
			return nil, &NotFoundError{Kind: "runtime source", Ref: sourceID}
		}
		if IsNotFound(err) {
			return nil, ErrUnsupported
		}
		return nil, err
	}
	var t RuntimeSourceTest
	if err := decodeData(raw, &t); err != nil {
		return nil, err
	}
	if t.Runtimes == nil {
		t.Runtimes = []RuntimeSourceEntry{}
	}
	if t.Problems == nil {
		t.Problems = []RuntimeSourceProblem{}
	}
	return &t, nil
}

// BuiltinCatalog is GET /runtime-catalog: which built-in catalog is in use — RunR's signed
// catalog as last read (mode source) or the API image's offline fallback (bundle) — and the
// platform setting Built-in runtimes.
type BuiltinCatalog struct {
	Mode   string        `json:"mode"`
	Source RuntimeSource `json:"source"`
	// Bundle lists the runtimes of the API image's fallback catalog.
	Bundle []string `json:"bundle"`
	// BuiltinRuntimes is the setting: nil offers every runtime of the catalog (also one it gains
	// later), a list only those.
	BuiltinRuntimes []string `json:"builtin_runtimes"`
	// Saved: the setting was saved (else EXECUTOR_RUNTIMES or the default applies).
	Saved    bool                    `json:"saved"`
	Runtimes []BuiltinCatalogRuntime `json:"runtimes"`
}

// BuiltinCatalogRuntime is one runtime of the built-in catalog.
type BuiltinCatalogRuntime struct {
	ID       string `json:"id"`
	Name     string `json:"name"`
	Offered  bool   `json:"offered"`
	InBundle bool   `json:"in_bundle"`
}

// GetBuiltinCatalog returns the built-in catalog and the Built-in runtimes setting
// (runtimes.manage); ErrUnsupported on APIs without runtime sources.
func (c *Client) GetBuiltinCatalog(ctx context.Context) (*BuiltinCatalog, error) {
	var raw []byte
	err := c.do(ctx, request{method: http.MethodGet, path: "/runtime-catalog"}, &raw)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	var out BuiltinCatalog
	if err := decodeData(raw, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// SetBuiltinRuntimes saves the Built-in runtimes setting: nil offers every runtime of the
// catalog (also one it gains later), a list only those. A built-in left out is not offered for
// new projects; its projects keep deploying.
func (c *Client) SetBuiltinRuntimes(ctx context.Context, ids []string) (*BuiltinCatalog, error) {
	var value any
	if ids != nil {
		value = ids
	}
	var raw []byte
	err := c.do(ctx, request{method: http.MethodPut, path: "/runtime-catalog", body: map[string]any{"builtin_runtimes": value}}, &raw)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	var out BuiltinCatalog
	if err := decodeData(raw, &out); err != nil {
		return nil, err
	}
	return &out, nil
}
