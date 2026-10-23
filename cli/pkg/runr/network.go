package runr

import (
	"context"
	"net/http"
	"net/url"
)

// Access modes of a project (docs/internal-network.md).
const (
	AccessPublic    = "public"    // anyone reaches the public URL (the default)
	AccessAllowlist = "allowlist" // the public URL answers the listed IP ranges only
	AccessInternal  = "internal"  // no public URL: other projects of the region call it internally
)

// Network is a project's network: who reaches it from outside the cluster and which projects
// may call it on the region's internal network.
type Network struct {
	AccessMode        string          `json:"access_mode"`
	AllowedCIDRs      []string        `json:"allowed_cidrs"`
	InternalName      string          `json:"internal_name"`
	InternalHost      string          `json:"internal_host"`
	InternalURL       string          `json:"internal_url"`
	Organization      string          `json:"organization"`
	TokenHeader       string          `json:"token_header"`
	TokenEnv          string          `json:"token_env"`
	CrossTenantGrants bool            `json:"cross_tenant_grants"`
	Enforced          *bool           `json:"enforced"`
	Callers           []NetworkCaller `json:"callers"`
}

// NetworkCaller is a project allowed to call another one, by its internal name
// <project>.<organization>; CrossTenant marks a grant to another organization's project.
type NetworkCaller struct {
	Caller       string `json:"caller"`
	Organization string `json:"organization"`
	CrossTenant  bool   `json:"cross_tenant"`
	CreatedAt    string `json:"created_at,omitempty"`
}

// GetNetwork returns a project's network.
func (c *Client) GetNetwork(ctx context.Context, projectID string) (*Network, error) {
	var out struct {
		Data Network `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/network"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// SetNetwork sets a project's access mode; cidrs are the source ranges of AccessAllowlist.
func (c *Client) SetNetwork(ctx context.Context, projectID, mode string, cidrs []string) (*Network, error) {
	body := map[string]any{"access_mode": mode}
	if mode == AccessAllowlist {
		body["allowed_cidrs"] = cidrs
	}
	var out struct {
		Data Network `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPut, path: "/projects/" + projectID + "/network", body: body}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// AddNetworkCaller allows caller (a project name or id of the organization, or an internal
// name <project>.<organization>) to call the project.
func (c *Client) AddNetworkCaller(ctx context.Context, projectID, caller string) (*NetworkCaller, error) {
	var out struct {
		Data NetworkCaller `json:"data"`
	}
	body := map[string]any{"caller": caller}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/network/callers", body: body}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// RemoveNetworkCaller removes caller (its internal name) from the project's allow list.
func (c *Client) RemoveNetworkCaller(ctx context.Context, projectID, caller string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/projects/" + projectID + "/network/callers/" + url.PathEscape(caller)}, nil)
}
