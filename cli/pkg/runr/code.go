package runr

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"sort"
)

// MaxCodeBytes is the API's limit for one version's code (names + contents): it is deployed
// as a ConfigMap, which Kubernetes caps at 1 MiB.
const MaxCodeBytes = 1_000_000

// SaveFiles replaces a faas project's files and returns the version it saved. Saving files
// that equal the latest version returns that version.
func (c *Client) SaveFiles(ctx context.Context, projectID string, files []File, message string) (*Version, error) {
	body := struct {
		Files   []File `json:"files"`
		Message string `json:"message,omitempty"`
	}{Files: files, Message: message}
	var out struct {
		Version Version `json:"version"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/files", body: body}, &out); err != nil {
		return nil, err
	}
	return &out.Version, nil
}

// SaveCode replaces an xaas project's script and returns the version it saved.
func (c *Client) SaveCode(ctx context.Context, projectID, code, message string) (*Version, error) {
	body := struct {
		Code    string `json:"code"`
		Message string `json:"message,omitempty"`
	}{Code: code, Message: message}
	var out struct {
		Version Version `json:"version"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/code", body: body}, &out); err != nil {
		return nil, err
	}
	return &out.Version, nil
}

// GetFiles returns a faas project's files at a version ("" = latest). Without any version the
// API returns its starter files and IsDefault is true.
func (c *Client) GetFiles(ctx context.Context, projectID, versionID string) (files map[string]string, isDefault bool, err error) {
	q := url.Values{}
	if versionID != "" {
		q.Set("version_id", versionID)
	}
	var out struct {
		Files     json.RawMessage `json:"files"`
		IsDefault bool            `json:"is_default"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/files", query: q}, &out); err != nil {
		return nil, false, err
	}
	files, err = decodeFileMap(out.Files)
	return files, out.IsDefault, err
}

// GetCode returns an xaas project's script at a version ("" = latest).
func (c *Client) GetCode(ctx context.Context, projectID, versionID string) (code string, isDefault bool, err error) {
	q := url.Values{}
	if versionID != "" {
		q.Set("version_id", versionID)
	}
	var out struct {
		Code      string `json:"code"`
		IsDefault bool   `json:"is_default"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/code", query: q}, &out); err != nil {
		return "", false, err
	}
	return out.Code, out.IsDefault, nil
}

// decodeFileMap decodes the files object ({name: content}); PHP encodes an empty one as [].
func decodeFileMap(raw json.RawMessage) (map[string]string, error) {
	out := map[string]string{}
	if len(raw) == 0 || raw[0] != '{' {
		return out, nil
	}
	if err := json.Unmarshal(raw, &out); err != nil {
		return nil, fmt.Errorf("decode files: %w", err)
	}
	return out, nil
}

// SortedFiles turns a name → content map into a sorted file list.
func SortedFiles(m map[string]string) []File {
	names := make([]string, 0, len(m))
	for n := range m {
		names = append(names, n)
	}
	sort.Strings(names)
	out := make([]File, 0, len(names))
	for _, n := range names {
		out = append(out, File{Name: n, Content: m[n]})
	}
	return out
}
