package runr

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// Git deploy (docs/git-deploy.md): every project has a git remote on the console domain
// (https://<console>/git/<organization>/<project>.git, an API token as the password), and a
// project can be connected to a GitHub, GitLab or Forgejo/Gitea repository instead, whose pull
// and merge requests get preview environments.

// Git provider types.
const (
	GitProviderGitHub  = "github"
	GitProviderGitLab  = "gitlab"
	GitProviderForgejo = "forgejo"
)

// Git deploy statuses (GitDeploy.Status).
const (
	GitDeployQueued    = "queued"
	GitDeployImporting = "importing"
	GitDeployBuilding  = "building"
	GitDeployDeploying = "deploying"
	GitDeployActive    = "active"
	GitDeployFailed    = "failed"
	GitDeployUnchanged = "unchanged"
	GitDeployImported  = "imported"
	GitDeploySkipped   = "skipped"
)

// Preview statuses (GitPreview.Status).
const (
	PreviewPending   = "pending"
	PreviewBuilding  = "building"
	PreviewDeploying = "deploying"
	PreviewReady     = "ready"
	PreviewFailed    = "failed"
	PreviewSkipped   = "skipped"
	PreviewDeleting  = "deleting"
	PreviewDeleted   = "deleted"
)

// GitSettings are the git settings of a project (the Git tab).
type GitSettings struct {
	DeployBranch    string `json:"deploy_branch"`
	RootDir         string `json:"root_dir,omitempty"`
	AutoDeploy      bool   `json:"auto_deploy"`
	AllowForcePush  bool   `json:"allow_force_push"`
	PreviewsEnabled bool   `json:"previews_enabled"`
	PreviewLimit    int    `json:"preview_limit"`
	PreviewTTLHours int    `json:"preview_ttl_hours"`
	// PreviewEnv are the environment overrides of previews (non-secret KEY=value).
	PreviewEnv   EnvMap `json:"preview_env"`
	ForkPreviews bool   `json:"fork_previews"`
	ForkSecrets  bool   `json:"fork_secrets"`
}

// GitConnection is the provider repository a project deploys from (no credentials).
type GitConnection struct {
	ID                string `json:"id"`
	ProviderID        string `json:"provider_id"`
	ProviderType      string `json:"provider_type"`
	ProviderName      string `json:"provider_name,omitempty"`
	RepoID            string `json:"repo_id,omitempty"`
	RepoFullName      string `json:"repo_full_name"`
	RepoURL           string `json:"repo_url,omitempty"`
	DefaultBranch     string `json:"default_branch,omitempty"`
	AuthMode          string `json:"auth_mode,omitempty"`
	InstallationID    FlexID `json:"installation_id,omitempty"`
	WebhookRegistered bool   `json:"webhook_registered"`
	LastEventAt       string `json:"last_event_at,omitempty"`
	CreatedAt         string `json:"created_at,omitempty"`
}

// GitProviderRef is a provider a project can connect through.
type GitProviderRef struct {
	ID           string   `json:"id"`
	Type         string   `json:"type"`
	Name         string   `json:"name"`
	BaseURL      string   `json:"base_url,omitempty"`
	ConnectModes []string `json:"connect_modes,omitempty"`
}

// GitAbilities say what the caller may do on the project's Git tab.
type GitAbilities struct {
	Pull           bool `json:"pull"`
	Push           bool `json:"push"`
	Manage         bool `json:"manage"`
	ManagePreviews bool `json:"manage_previews"`
}

// GitLimits are the platform's limits of git deploy.
type GitLimits struct {
	PreviewLimitMax int `json:"preview_limit_max"`
	PreviewTTLMax   int `json:"preview_ttl_max"`
	MaxPushMB       int `json:"max_push_mb"`
}

// GitInfo is GET /projects/{project}/git: the remote, the settings, the connected repository.
type GitInfo struct {
	RemoteURL   string `json:"remote_url"`
	RemoteReady bool   `json:"remote_ready"`
	// RemoteHead is the commit the deploy branch points to ("" for an empty remote).
	RemoteHead string `json:"remote_head,omitempty"`
	// ReadOnly is set when the project deploys from a provider repository: its RunR remote
	// can be cloned but not pushed to.
	ReadOnly     bool             `json:"read_only"`
	Settings     GitSettings      `json:"settings"`
	Connection   *GitConnection   `json:"connection"`
	Providers    []GitProviderRef `json:"providers"`
	Limits       GitLimits        `json:"limits"`
	Abilities    GitAbilities     `json:"abilities"`
	UsernameHint string           `json:"username_hint,omitempty"`
}

// GitDeployVersion is the version a git deploy created.
type GitDeployVersion struct {
	ID     string `json:"id"`
	Number int    `json:"number"`
	Label  string `json:"label"`
}

// GitDeploy is one deploy of the deploy history: a push (production) or a pull/merge request
// commit (preview).
type GitDeploy struct {
	ID            string            `json:"id"`
	Kind          string            `json:"kind"`
	Trigger       string            `json:"trigger,omitempty"`
	Provider      string            `json:"provider,omitempty"`
	SHA           string            `json:"sha"`
	ShortSHA      string            `json:"short_sha,omitempty"`
	Branch        string            `json:"branch,omitempty"`
	AuthorName    string            `json:"author_name,omitempty"`
	Message       string            `json:"message,omitempty"`
	URL           string            `json:"url,omitempty"`
	PRNumber      *int              `json:"pr_number,omitempty"`
	Status        string            `json:"status"`
	StatusMessage string            `json:"status_message,omitempty"`
	Warnings      []any             `json:"warnings,omitempty"`
	Skipped       []any             `json:"skipped,omitempty"`
	Applied       json.RawMessage   `json:"applied,omitempty"`
	Version       *GitDeployVersion `json:"version,omitempty"`
	DeploymentID  string            `json:"deployment_id,omitempty"`
	PreviewID     string            `json:"preview_id,omitempty"`
	CreatedAt     string            `json:"created_at,omitempty"`
	UpdatedAt     string            `json:"updated_at,omitempty"`
}

// Final reports whether the deploy ended.
func (d *GitDeploy) Final() bool {
	switch d.Status {
	case GitDeployActive, GitDeployFailed, GitDeployUnchanged, GitDeployImported, GitDeploySkipped:
		return true
	}
	return false
}

// Failed reports whether the deploy ended without deploying because of an error or a rule.
func (d *GitDeploy) Failed() bool { return d.Status == GitDeployFailed || d.Status == GitDeploySkipped }

// GitPreview is the preview environment of a pull/merge request.
type GitPreview struct {
	ID             string `json:"id"`
	Number         int    `json:"number"`
	Provider       string `json:"provider,omitempty"`
	Title          string `json:"title,omitempty"`
	Branch         string `json:"branch,omitempty"`
	BaseBranch     string `json:"base_branch,omitempty"`
	HeadSHA        string `json:"head_sha,omitempty"`
	ShortSHA       string `json:"short_sha,omitempty"`
	Author         string `json:"author,omitempty"`
	PRURL          string `json:"pr_url,omitempty"`
	IsFork         bool   `json:"is_fork"`
	ServiceName    string `json:"service_name,omitempty"`
	URL            string `json:"url,omitempty"`
	Status         string `json:"status"`
	StatusMessage  string `json:"status_message,omitempty"`
	LastDeployedAt string `json:"last_deployed_at,omitempty"`
	ExpiresAt      string `json:"expires_at,omitempty"`
	ClosedAt       string `json:"closed_at,omitempty"`
	CreatedAt      string `json:"created_at,omitempty"`
}

// GitRepository is a repository the credentials of a connect can deploy from.
type GitRepository struct {
	ID            string `json:"id"`
	FullName      string `json:"full_name"`
	URL           string `json:"url,omitempty"`
	DefaultBranch string `json:"default_branch,omitempty"`
	Private       bool   `json:"private"`
}

// GitInstallation is an installation of the GitHub App linked to the organization.
type GitInstallation struct {
	ID             string `json:"id"`
	InstallationID FlexID `json:"installation_id"`
	AccountLogin   string `json:"account_login"`
	AccountType    string `json:"account_type,omitempty"`
}

// GitProviderInfo is a provider as Settings → Git providers lists it (secrets as has_* flags).
type GitProviderInfo struct {
	ID               string            `json:"id"`
	Type             string            `json:"type"`
	Name             string            `json:"name"`
	BaseURL          string            `json:"base_url,omitempty"`
	APIURL           string            `json:"api_url,omitempty"`
	WebURL           string            `json:"web_url,omitempty"`
	AppID            FlexID            `json:"app_id,omitempty"`
	AppSlug          string            `json:"app_slug,omitempty"`
	ClientID         string            `json:"client_id,omitempty"`
	IsEnabled        bool              `json:"is_enabled"`
	Configured       bool              `json:"configured"`
	ConnectModes     []string          `json:"connect_modes,omitempty"`
	HasClientSecret  bool              `json:"has_client_secret"`
	HasPrivateKey    bool              `json:"has_private_key"`
	HasWebhookSecret bool              `json:"has_webhook_secret"`
	LinksCount       int               `json:"links_count"`
	URLs             map[string]string `json:"urls,omitempty"`
	CreatedAt        string            `json:"created_at,omitempty"`
}

// UnmarshalJSON tolerates urls encoded as [] (an empty PHP array).
func (p *GitProviderInfo) UnmarshalJSON(b []byte) error {
	type plain GitProviderInfo
	var raw struct {
		plain
		URLs json.RawMessage `json:"urls"`
	}
	if err := json.Unmarshal(b, &raw); err != nil {
		return err
	}
	*p = GitProviderInfo(raw.plain)
	p.URLs = nil
	if len(raw.URLs) > 0 && raw.URLs[0] == '{' {
		_ = json.Unmarshal(raw.URLs, &p.URLs)
	}
	return nil
}

// GitSettingsInput is the body of PUT /projects/{project}/git; nil fields are not sent.
// Preview fields need previews.manage.
type GitSettingsInput struct {
	DeployBranch    *string            `json:"deploy_branch,omitempty"`
	RootDir         *string            `json:"root_dir,omitempty"`
	AutoDeploy      *bool              `json:"auto_deploy,omitempty"`
	AllowForcePush  *bool              `json:"allow_force_push,omitempty"`
	PreviewsEnabled *bool              `json:"previews_enabled,omitempty"`
	PreviewLimit    *int               `json:"preview_limit,omitempty"`
	PreviewTTLHours *int               `json:"preview_ttl_hours,omitempty"`
	PreviewEnv      *map[string]string `json:"preview_env,omitempty"`
	ForkPreviews    *bool              `json:"fork_previews,omitempty"`
	ForkSecrets     *bool              `json:"fork_secrets,omitempty"`
}

// Empty reports whether no field is set.
func (in GitSettingsInput) Empty() bool { return in == GitSettingsInput{} }

// GitCredentials authenticate a connect at the provider: a linked GitHub App installation,
// a GitLab / Forgejo access token, or the token of a GitLab sign-in (TokenRef).
type GitCredentials struct {
	ProviderID     string `json:"provider_id"`
	InstallationID string `json:"installation_id,omitempty"`
	AccessToken    string `json:"access_token,omitempty"`
	TokenRef       string `json:"token_ref,omitempty"`
}

// GetGit returns the git remote, settings and connection of a project.
func (c *Client) GetGit(ctx context.Context, projectID string) (*GitInfo, error) {
	return c.gitInfo(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/git"})
}

// UpdateGit changes the git settings of a project.
func (c *Client) UpdateGit(ctx context.Context, projectID string, in GitSettingsInput) (*GitInfo, error) {
	return c.gitInfo(ctx, request{method: http.MethodPut, path: "/projects/" + projectID + "/git", body: in})
}

func (c *Client) gitInfo(ctx context.Context, r request) (*GitInfo, error) {
	var out struct {
		Data GitInfo `json:"data"`
	}
	if err := c.do(ctx, r, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// GitDeploys returns the deploy history, newest first. kind is "", "production" or "preview";
// perPage 0 means the API's default (30), at most 100.
func (c *Client) GitDeploys(ctx context.Context, projectID, kind string, perPage int) ([]GitDeploy, *Page, error) {
	q := url.Values{}
	if kind != "" {
		q.Set("kind", kind)
	}
	if perPage > 0 {
		q.Set("per_page", strconv.Itoa(perPage))
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/git/deploys", query: q}, &raw); err != nil {
		return nil, nil, err
	}
	var list []GitDeploy
	var page Page
	if err := decodeList(raw, &list, &page); err != nil {
		return nil, nil, err
	}
	return list, &page, nil
}

// RedeployGit deploys the head of the deploy branch again (RunR remote).
func (c *Client) RedeployGit(ctx context.Context, projectID string) (*GitDeploy, error) {
	var out struct {
		Data GitDeploy `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/git/redeploy"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// WaitGitDeploy polls the deploy history until the deploy ended. A failed or skipped deploy
// is a *FailedError.
func (c *Client) WaitGitDeploy(ctx context.Context, projectID string, d *GitDeploy, opts WaitOptions) (*GitDeploy, error) {
	opts = opts.withDefaults()
	deadline := time.Now().Add(opts.Timeout)
	cur := d
	last := ""
	for {
		if cur.Final() {
			if cur.Failed() {
				return cur, &FailedError{What: "git deploy", Ref: cur.shortRef(), Status: cur.Status, Message: cur.StatusMessage}
			}
			return cur, nil
		}
		if cur.Status != last && opts.Progress != nil {
			opts.Progress(cur.Status)
		}
		last = cur.Status
		if time.Now().After(deadline) {
			return cur, &TimeoutError{What: "git deploy " + cur.shortRef(), Timeout: opts.Timeout, Last: cur.Status}
		}
		select {
		case <-ctx.Done():
			return cur, ctx.Err()
		case <-time.After(opts.Interval):
		}
		list, _, err := c.GitDeploys(ctx, projectID, "", 100)
		if err != nil {
			return cur, err
		}
		found := false
		for i := range list {
			if list[i].ID == d.ID {
				cur, found = &list[i], true
				break
			}
		}
		if !found {
			return cur, &NotFoundError{Kind: "git deploy", Ref: d.ID}
		}
	}
}

func (d *GitDeploy) shortRef() string {
	if d.ShortSHA != "" {
		return d.ShortSHA
	}
	if len(d.SHA) > 7 {
		return d.SHA[:7]
	}
	if d.SHA != "" {
		return d.SHA
	}
	return d.ID
}

// GitRepositories lists the repositories the credentials can deploy from (search filters by
// name).
func (c *Client) GitRepositories(ctx context.Context, projectID string, cred GitCredentials, search string) ([]GitRepository, error) {
	body := struct {
		GitCredentials
		Search string `json:"search,omitempty"`
	}{cred, search}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/git/repos", body: body}, &raw); err != nil {
		return nil, err
	}
	var list []GitRepository
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, err
	}
	return list, nil
}

// ConnectGit connects a project to a provider repository (full name or id).
func (c *Client) ConnectGit(ctx context.Context, projectID string, cred GitCredentials, repository string) (*GitInfo, error) {
	body := struct {
		GitCredentials
		Repository string `json:"repository"`
	}{cred, repository}
	return c.gitInfo(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/git/connect", body: body})
}

// DisconnectGit removes the connection, its webhook and the previews; the versions stay.
func (c *Client) DisconnectGit(ctx context.Context, projectID string) (*GitInfo, error) {
	return c.gitInfo(ctx, request{method: http.MethodDelete, path: "/projects/" + projectID + "/git/connect"})
}

// GitInstallations lists the GitHub App installations of the organization for a provider.
func (c *Client) GitInstallations(ctx context.Context, projectID, providerID string) ([]GitInstallation, error) {
	q := url.Values{}
	if providerID != "" {
		q.Set("provider_id", providerID)
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/git/installations", query: q}, &raw); err != nil {
		return nil, err
	}
	var list []GitInstallation
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, err
	}
	return list, nil
}

// GitHubInstallURL returns the GitHub page that installs the provider's GitHub App for the
// organization (with RunR's signed state; valid 15 minutes).
func (c *Client) GitHubInstallURL(ctx context.Context, projectID, providerID string) (string, error) {
	return c.gitURL(ctx, "/projects/"+projectID+"/git/github/install", providerID)
}

// GitLabAuthorizeURL returns the GitLab sign-in page of an OAuth connect.
func (c *Client) GitLabAuthorizeURL(ctx context.Context, projectID, providerID string) (string, error) {
	return c.gitURL(ctx, "/projects/"+projectID+"/git/gitlab/authorize", providerID)
}

func (c *Client) gitURL(ctx context.Context, path, providerID string) (string, error) {
	var out struct {
		URL string `json:"url"`
	}
	body := map[string]string{"provider_id": providerID}
	if err := c.do(ctx, request{method: http.MethodPost, path: path, body: body}, &out); err != nil {
		return "", err
	}
	return out.URL, nil
}

// ListPreviews returns the preview environments of a project (all: deleted ones too).
func (c *Client) ListPreviews(ctx context.Context, projectID string, all bool) ([]GitPreview, error) {
	q := url.Values{}
	if all {
		q.Set("all", "1")
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/previews", query: q}, &raw); err != nil {
		return nil, err
	}
	var list []GitPreview
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, err
	}
	return list, nil
}

// FindPreview resolves a preview by id, request number (12, #12, pr-12) or branch. Live
// previews win over deleted ones of the same request.
func (c *Client) FindPreview(ctx context.Context, projectID, ref string) (*GitPreview, error) {
	list, err := c.ListPreviews(ctx, projectID, true)
	if err != nil {
		return nil, err
	}
	if p := PickPreview(list, ref); p != nil {
		return p, nil
	}
	return nil, &NotFoundError{Kind: "preview", Ref: ref}
}

// PickPreview finds a preview of list by id, request number or branch.
func PickPreview(list []GitPreview, ref string) *GitPreview {
	for i := range list {
		if list[i].ID == ref {
			return &list[i]
		}
	}
	num := strings.TrimPrefix(strings.TrimPrefix(strings.ToLower(ref), "#"), "pr-")
	n, numErr := strconv.Atoi(num)
	var match *GitPreview
	for i := range list {
		p := &list[i]
		if (numErr == nil && p.Number == n) || (numErr != nil && p.Branch == ref) {
			if match == nil || (match.Status == PreviewDeleted && p.Status != PreviewDeleted) {
				match = p
			}
		}
	}
	return match
}

// RedeployPreview deploys a ready or failed preview's version again.
func (c *Client) RedeployPreview(ctx context.Context, projectID, previewID string) (*GitPreview, error) {
	return c.preview(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/previews/" + previewID + "/redeploy"})
}

// DeletePreview deletes a preview now (a new commit of the request creates it again).
func (c *Client) DeletePreview(ctx context.Context, projectID, previewID string) (*GitPreview, error) {
	return c.preview(ctx, request{method: http.MethodDelete, path: "/projects/" + projectID + "/previews/" + previewID})
}

func (c *Client) preview(ctx context.Context, r request) (*GitPreview, error) {
	var out struct {
		Data GitPreview `json:"data"`
	}
	if err := c.do(ctx, r, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// WaitPreview polls a preview until it is ready; failed or skipped is a *FailedError.
func (c *Client) WaitPreview(ctx context.Context, projectID string, p *GitPreview, opts WaitOptions) (*GitPreview, error) {
	opts = opts.withDefaults()
	deadline := time.Now().Add(opts.Timeout)
	cur := p
	last := ""
	for {
		switch cur.Status {
		case PreviewReady:
			return cur, nil
		case PreviewFailed, PreviewSkipped, PreviewDeleting, PreviewDeleted:
			return cur, &FailedError{What: "preview", Ref: fmt.Sprintf("#%d", cur.Number), Status: cur.Status, Message: cur.StatusMessage}
		}
		if cur.Status != last && opts.Progress != nil {
			opts.Progress(cur.Status)
		}
		last = cur.Status
		if time.Now().After(deadline) {
			return cur, &TimeoutError{What: fmt.Sprintf("preview #%d", cur.Number), Timeout: opts.Timeout, Last: cur.Status}
		}
		select {
		case <-ctx.Done():
			return cur, ctx.Err()
		case <-time.After(opts.Interval):
		}
		list, err := c.ListPreviews(ctx, projectID, true)
		if err != nil {
			return cur, err
		}
		found := false
		for i := range list {
			if list[i].ID == p.ID {
				cur, found = &list[i], true
				break
			}
		}
		if !found {
			return cur, &NotFoundError{Kind: "preview", Ref: p.ID}
		}
	}
}

// ListGitProviders returns the git providers of the installation (git_providers.manage).
func (c *Client) ListGitProviders(ctx context.Context) ([]GitProviderInfo, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/git-providers"}, &raw); err != nil {
		return nil, err
	}
	var list []GitProviderInfo
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, err
	}
	return list, nil
}

// PickGitProvider finds the provider a connect names: its id, its name, or its type when the
// project offers exactly one provider of that type.
func PickGitProvider(list []GitProviderRef, ref string) (*GitProviderRef, error) {
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
	var match []*GitProviderRef
	for i := range list {
		if list[i].Type == typ {
			match = append(match, &list[i])
		}
	}
	switch len(match) {
	case 1:
		return match[0], nil
	case 0:
		names := make([]string, 0, len(list))
		for _, p := range list {
			names = append(names, p.Name+" ("+p.Type+")")
		}
		if len(names) == 0 {
			return nil, &NotFoundError{Kind: "git provider", Ref: ref + " (no provider is configured: Settings → Git providers)"}
		}
		return nil, &NotFoundError{Kind: "git provider", Ref: ref + " (available: " + strings.Join(names, ", ") + ")"}
	}
	names := make([]string, 0, len(match))
	for _, p := range match {
		names = append(names, p.Name+" ["+p.ID+"]")
	}
	return nil, fmt.Errorf("several %s providers are configured (%s): name one with --provider", typ, strings.Join(names, ", "))
}

// HasMode reports whether the provider connects in mode: "app" (GitHub App), "pat" (access
// token) or "oauth" (GitLab sign-in).
func (p *GitProviderRef) HasMode(mode string) bool {
	for _, m := range p.ConnectModes {
		if m == mode {
			return true
		}
	}
	return false
}
