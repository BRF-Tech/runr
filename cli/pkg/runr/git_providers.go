package runr

import (
	"context"
	"fmt"
	"net/http"
	"strings"
)

// Git providers of the installation (Settings → Git providers, git_providers.manage): GitHub
// Apps, GitLab instances and Forgejo / Gitea instances projects connect through.

// GitProviderInput is the body of POST /git-providers and PUT /git-providers/{provider}. Nil
// fields are not sent; on an update an empty secret keeps the stored one. Type is set on
// create only. The secrets (ClientSecret, PrivateKey, WebhookSecret) are write-only: the API
// answers has_* flags.
type GitProviderInput struct {
	Type          *string `json:"type,omitempty"`
	Name          *string `json:"name,omitempty"`
	BaseURL       *string `json:"base_url,omitempty"`
	APIURL        *string `json:"api_url,omitempty"`
	IsEnabled     *bool   `json:"is_enabled,omitempty"`
	ClientID      *string `json:"client_id,omitempty"`
	ClientSecret  *string `json:"client_secret,omitempty"`
	AppID         *string `json:"app_id,omitempty"`
	AppSlug       *string `json:"app_slug,omitempty"`
	PrivateKey    *string `json:"private_key,omitempty"`
	WebhookSecret *string `json:"webhook_secret,omitempty"`
}

// CreateGitProvider adds a git provider. The answer carries the URLs to enter at the provider
// (webhook, setup or callback URL).
func (c *Client) CreateGitProvider(ctx context.Context, in GitProviderInput) (*GitProviderInfo, error) {
	var out struct {
		Data GitProviderInfo `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/git-providers", body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// UpdateGitProvider changes a git provider.
func (c *Client) UpdateGitProvider(ctx context.Context, providerID string, in GitProviderInput) (*GitProviderInfo, error) {
	var out struct {
		Data GitProviderInfo `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPut, path: "/git-providers/" + seg(providerID), body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// DeleteGitProvider removes a git provider; refused (422) while projects are connected
// through it.
func (c *Client) DeleteGitProvider(ctx context.Context, providerID string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/git-providers/" + seg(providerID)}, nil)
}

// FindGitProvider resolves a provider of Settings → Git providers by id or name (a type works
// when exactly one provider has it).
func (c *Client) FindGitProvider(ctx context.Context, ref string) (*GitProviderInfo, error) {
	list, err := c.ListGitProviders(ctx)
	if err != nil {
		return nil, err
	}
	return PickGitProviderInfo(list, ref)
}

// PickGitProviderInfo applies FindGitProvider's rule to a list.
func PickGitProviderInfo(list []GitProviderInfo, ref string) (*GitProviderInfo, error) {
	ref = strings.TrimSpace(ref)
	for i := range list {
		if list[i].ID == ref {
			return &list[i], nil
		}
	}
	for i := range list {
		if strings.EqualFold(list[i].Name, ref) {
			return &list[i], nil
		}
	}
	typ := strings.ToLower(ref)
	if typ == "gitea" {
		typ = GitProviderForgejo
	}
	var match []*GitProviderInfo
	for i := range list {
		if list[i].Type == typ {
			match = append(match, &list[i])
		}
	}
	switch len(match) {
	case 1:
		return match[0], nil
	case 0:
		return nil, &NotFoundError{Kind: "git provider", Ref: ref}
	}
	names := make([]string, 0, len(match))
	for _, m := range match {
		names = append(names, m.Name)
	}
	return nil, fmt.Errorf("several %s providers exist (%s): name one", typ, strings.Join(names, ", "))
}
