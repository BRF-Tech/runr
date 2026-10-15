package runrtest

import (
	"fmt"
	"net/url"
	"regexp"
	"strconv"
	"strings"

	"github.com/runrsh/core/cli/pkg/runr"
)

// Git deploy of the fake (api GitProjectController, GitPreviewController, GitProviderController,
// RepoConnector): every project has a remote under the console URL; providers, installations
// and repositories are seeded; a redeploy and a preview redeploy finish after GitConfig.Polls
// reads of the deploy history / preview list.

// GitConfig changes how the fake's git deploy behaves; get it with Server.Git.
type GitConfig struct {
	// Polls is how many reads a redeploy (or a redeployed preview) stays in progress. Default 1.
	Polls int
	// FailStatus makes redeploys end in that status ("failed", "skipped") with FailMessage.
	FailStatus  string
	FailMessage string
	// Owner is the owner segment of remote URLs. Default "runr".
	Owner string
}

type gitState struct {
	cfg           GitConfig
	projects      map[string]*gitProject
	providers     []*gitProvider
	installations []gitInstallation
	repos         map[string][]runr.GitRepository
	tokens        []string
}

type gitProject struct {
	settings   runr.GitSettings
	connection *runr.GitConnection
	head       string
	deploys    []*gitDeployRow
	previews   []*gitPreviewRow
}

type gitDeployRow struct {
	data      runr.GitDeploy
	pollsLeft int
}

type gitPreviewRow struct {
	data      runr.GitPreview
	versionID string
	pollsLeft int
}

type gitProvider struct {
	data       runr.GitProviderInfo
	slug       string
	oauth      bool
	configured bool
}

type gitInstallation struct {
	id, providerID, installationID, account string
}

func (s *Server) gitSt() *gitState {
	return featureState(s, "git", func() *gitState {
		return &gitState{cfg: GitConfig{Polls: 1, FailMessage: "build failed: requirements.txt could not be installed", Owner: "runr"},
			projects: map[string]*gitProject{}, repos: map[string][]runr.GitRepository{}}
	})
}

func (s *Server) gitProjectOf(p *project) *gitProject {
	st := s.gitSt()
	gp, ok := st.projects[p.data.ID]
	if !ok {
		gp = &gitProject{settings: runr.GitSettings{DeployBranch: "main", AutoDeploy: true, PreviewLimit: 3,
			PreviewTTLHours: 72, PreviewEnv: runr.EnvMap{}}}
		st.projects[p.data.ID] = gp
	}
	return gp
}

// Git returns the git deploy settings of the fake; change them before the requests.
func (s *Server) Git() *GitConfig {
	s.mu.Lock()
	defer s.mu.Unlock()
	return &s.gitSt().cfg
}

// SeedGitProvider adds a configured provider (github, gitlab, forgejo); oauth enables the
// GitLab sign-in.
func (s *Server) SeedGitProvider(typ, name string, oauth bool) runr.GitProviderInfo {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.gitSt()
	pr := &gitProvider{slug: strings.ToLower(strings.ReplaceAll(name, " ", "-")), oauth: oauth && typ == runr.GitProviderGitLab, configured: true}
	web := map[string]string{runr.GitProviderGitHub: "https://github.com", runr.GitProviderGitLab: "https://gitlab.com",
		runr.GitProviderForgejo: "https://forgejo.example.com"}[typ]
	pr.data = runr.GitProviderInfo{ID: s.newID(), Type: typ, Name: name, WebURL: web, IsEnabled: true, Configured: true,
		CreatedAt: nowRFC3339()}
	if typ == runr.GitProviderForgejo {
		pr.data.BaseURL = web
	}
	if typ == runr.GitProviderGitHub {
		pr.data.AppID, pr.data.AppSlug, pr.data.ClientID = "12345", pr.slug, "Iv1.client"
		pr.data.HasClientSecret, pr.data.HasPrivateKey, pr.data.HasWebhookSecret = true, true, true
		pr.data.URLs = map[string]string{"webhook": s.URL + "/api/git/webhooks/github/" + pr.data.ID,
			"setup": s.URL + "/api/git/github/setup", "callback": s.URL + "/api/git/github/setup"}
	}
	if pr.oauth {
		pr.data.ClientID, pr.data.HasClientSecret = "gitlab-app", true
		pr.data.URLs = map[string]string{"callback": s.URL + "/api/git/oauth/gitlab/callback"}
	}
	pr.data.ConnectModes = s.gitModes(pr)
	st.providers = append(st.providers, pr)
	return pr.data
}

// SeedGitInstallation links a GitHub App installation to the organization.
func (s *Server) SeedGitInstallation(providerID, installationID, account string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.gitSt()
	st.installations = append(st.installations, gitInstallation{id: s.newID(), providerID: providerID,
		installationID: installationID, account: account})
}

// SeedGitRepository makes a repository readable through a provider.
func (s *Server) SeedGitRepository(providerID, fullName, defaultBranch string, private bool) runr.GitRepository {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.gitSt()
	s.idCounter++
	r := runr.GitRepository{ID: strconv.Itoa(1000 + s.idCounter), FullName: fullName, URL: "https://example.com/" + fullName,
		DefaultBranch: defaultBranch, Private: private}
	st.repos[providerID] = append(st.repos[providerID], r)
	return r
}

// SeedGitPush records a push to the project's remote: the deploy branch points to sha and
// the deploy history gets an active production deploy.
func (s *Server) SeedGitPush(projectName, sha, message string) runr.GitDeploy {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	gp := s.gitProjectOf(p)
	gp.head = sha
	d := &gitDeployRow{data: runr.GitDeploy{ID: s.newID(), Kind: "production", Trigger: "push", Provider: "runr", SHA: sha,
		ShortSHA: gitShortSHA(sha), Branch: gp.settings.DeployBranch, AuthorName: s.User.Name, Message: message,
		URL: p.data.EndpointURL, Status: runr.GitDeployActive, Warnings: []any{}, Skipped: []any{},
		CreatedAt: nowRFC3339(), UpdatedAt: nowRFC3339()}}
	if n := len(p.versions); n > 0 {
		v := p.versions[n-1].data
		d.data.Version = &runr.GitDeployVersion{ID: v.ID, Number: v.Number, Label: v.Label}
	}
	gp.deploys = append(gp.deploys, d)
	return d.data
}

// SeedPreview adds a preview environment of request number to a project.
func (s *Server) SeedPreview(projectName string, number int, branch, status string) runr.GitPreview {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	gp := s.gitProjectOf(p)
	sha := fmt.Sprintf("%040x", 0xabc000+number)
	svc := fmt.Sprintf("%s-pr-%d", p.data.Slug, number)
	row := &gitPreviewRow{versionID: s.newID(), data: runr.GitPreview{ID: s.newID(), Number: number, Provider: "github",
		Title: "Change " + branch, Branch: branch, BaseBranch: gp.settings.DeployBranch, HeadSHA: sha, ShortSHA: gitShortSHA(sha),
		Author: "ada", PRURL: fmt.Sprintf("https://github.com/acme/app/pull/%d", number), ServiceName: svc,
		URL: "https://" + svc + "." + s.regionName(p) + ".runr.test", Status: status, CreatedAt: nowRFC3339(),
		LastDeployedAt: nowRFC3339(), ExpiresAt: nowRFC3339()}}
	if status == runr.PreviewDeleted {
		row.data.ClosedAt = nowRFC3339()
	}
	gp.previews = append(gp.previews, row)
	return row.data
}

// GitSettingsOf returns the git settings of a project.
func (s *Server) GitSettingsOf(projectName string) runr.GitSettings {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return runr.GitSettings{}
	}
	return s.gitProjectOf(p).settings
}

// GitConnectionOf returns the repository a project is connected to (nil: none).
func (s *Server) GitConnectionOf(projectName string) *runr.GitConnection {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return nil
	}
	if c := s.gitProjectOf(p).connection; c != nil {
		cc := *c
		return &cc
	}
	return nil
}

// PreviewsOf returns the previews of a project, deleted ones included.
func (s *Server) PreviewsOf(projectName string) []runr.GitPreview {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return nil
	}
	var out []runr.GitPreview
	for _, r := range s.gitProjectOf(p).previews {
		out = append(out, r.data)
	}
	return out
}

// GitAccessTokens returns the provider access tokens the fake received (repos, connect).
func (s *Server) GitAccessTokens() []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]string(nil), s.gitSt().tokens...)
}

func gitShortSHA(sha string) string {
	if len(sha) > 7 {
		return sha[:7]
	}
	return sha
}

func (s *Server) gitModes(pr *gitProvider) []string {
	switch pr.data.Type {
	case runr.GitProviderGitHub:
		return []string{"app"}
	case runr.GitProviderGitLab:
		if pr.oauth {
			return []string{"oauth", "pat"}
		}
	}
	return []string{"pat"}
}

func gitForbidden(permission string) *apiErr {
	return &apiErr{status: 403, message: "This action needs the " + permission + " permission on the project."}
}

func (s *Server) gitInfo(p *project) map[string]any {
	gp := s.gitProjectOf(p)
	st := s.gitSt()
	providers := []map[string]any{}
	for _, pr := range st.providers {
		if !pr.data.IsEnabled || !pr.configured {
			continue
		}
		providers = append(providers, map[string]any{"id": pr.data.ID, "type": pr.data.Type, "name": pr.data.Name,
			"base_url": pr.data.WebURL, "connect_modes": s.gitModes(pr)})
	}
	var head any
	if gp.head != "" {
		head = gp.head
	}
	var conn any
	if gp.connection != nil {
		conn = gp.connection
	}
	env := map[string]string{}
	for k, v := range gp.settings.PreviewEnv {
		env[k] = v
	}
	var rootDir any
	if gp.settings.RootDir != "" {
		rootDir = gp.settings.RootDir
	}
	return map[string]any{
		"remote_url":   s.URL + "/git/" + st.cfg.Owner + "/" + p.data.Slug + ".git",
		"remote_ready": gp.head != "",
		"remote_head":  head,
		"read_only":    gp.connection != nil,
		"settings": map[string]any{
			"deploy_branch": gp.settings.DeployBranch, "root_dir": rootDir, "auto_deploy": gp.settings.AutoDeploy,
			"allow_force_push": gp.settings.AllowForcePush, "previews_enabled": gp.settings.PreviewsEnabled,
			"preview_limit": gp.settings.PreviewLimit, "preview_ttl_hours": gp.settings.PreviewTTLHours,
			"preview_env": env, "fork_previews": gp.settings.ForkPreviews, "fork_secrets": gp.settings.ForkSecrets,
		},
		"runr_yaml":  nil,
		"connection": conn,
		"providers":  providers,
		"limits":     map[string]any{"preview_limit_max": 20, "preview_ttl_max": 720, "max_push_mb": 50},
		"abilities": map[string]any{"pull": s.holds("git.pull"), "push": s.holds("git.push"),
			"manage": s.holds("git.manage"), "manage_previews": s.holds("previews.manage")},
		"username_hint": s.User.Username,
	}
}

var gitBranchRe = regexp.MustCompile(`^[A-Za-z0-9._/-]+$`)

func (s *Server) gitProvider(id, typ string) (*gitProvider, *apiErr) {
	for _, pr := range s.gitSt().providers {
		if pr.data.ID == id && pr.data.IsEnabled && pr.configured && (typ == "" || pr.data.Type == typ) {
			return pr, nil
		}
	}
	return nil, errValidation("provider_id", "The git provider is unknown or not configured.")
}

// gitCredentials checks the credentials of a repos / connect request (RepoConnector::auth).
func (s *Server) gitCredentials(q *featureReq) (*gitProvider, string, *apiErr) {
	pr, err := s.gitProvider(q.str("provider_id"), "")
	if err != nil {
		return nil, "", err
	}
	st := s.gitSt()
	if pr.data.Type == runr.GitProviderGitHub {
		inst := q.str("installation_id")
		for _, i := range st.installations {
			if inst != "" && i.providerID == pr.data.ID && i.installationID == inst {
				return pr, inst, nil
			}
		}
		return nil, "", errValidation("installation_id", "This GitHub App installation is not linked to your organization.")
	}
	if ref := q.str("token_ref"); ref != "" {
		return nil, "", errValidation("token_ref", "The GitLab sign-in expired; sign in again.")
	}
	tok := strings.TrimSpace(q.str("access_token"))
	if tok == "" {
		return nil, "", errValidation("access_token", "An access token is required.")
	}
	st.tokens = append(st.tokens, tok)
	return pr, "", nil
}

func (s *Server) advanceGitDeploy(gp *gitProject, d *gitDeployRow) {
	if d.data.Final() {
		return
	}
	if d.pollsLeft > 0 {
		d.pollsLeft--
		d.data.Status = runr.GitDeployBuilding
		return
	}
	st := s.gitSt()
	if st.cfg.FailStatus != "" {
		d.data.Status = st.cfg.FailStatus
		d.data.StatusMessage = st.cfg.FailMessage
		return
	}
	d.data.Status = runr.GitDeployActive
}

func (s *Server) advancePreview(r *gitPreviewRow) {
	if r.data.Status != runr.PreviewDeploying {
		return
	}
	if r.pollsLeft > 0 {
		r.pollsLeft--
		return
	}
	r.data.Status = runr.PreviewReady
	r.data.LastDeployedAt = nowRFC3339()
}

func (s *Server) gitPreviewByID(gp *gitProject, id string) *gitPreviewRow {
	for _, r := range gp.previews {
		if r.data.ID == id {
			return r
		}
	}
	return nil
}

func init() {
	handle("GET /projects/{project}/git", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"data": s.gitInfo(p)}, nil
	})

	handle("PUT /projects/{project}/git", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		gp := s.gitProjectOf(p)
		next := gp.settings
		for _, k := range []string{"previews_enabled", "preview_limit", "preview_ttl_hours", "preview_env", "fork_previews", "fork_secrets"} {
			if _, ok := q.Body[k]; ok && !s.holds("previews.manage") {
				return 0, nil, gitForbidden("previews.manage")
			}
		}
		if v, ok := q.Body["deploy_branch"]; ok {
			b, _ := v.(string)
			if b == "" || !gitBranchRe.MatchString(b) || strings.Contains(b, "..") || strings.HasSuffix(b, ".lock") {
				return 0, nil, errValidation("deploy_branch", "The deploy branch format is invalid.")
			}
			next.DeployBranch = b
		}
		if v, ok := q.Body["root_dir"]; ok {
			d, _ := v.(string)
			d = strings.Trim(d, "/ ")
			if strings.HasPrefix(d, ".") || strings.Contains(d, "/.") {
				return 0, nil, errValidation("root_dir", "The root dir format is invalid.")
			}
			next.RootDir = d
		}
		boolField := func(key string, dst *bool) *apiErr {
			if v, ok := q.Body[key]; ok {
				b, isBool := v.(bool)
				if !isBool {
					return errValidation(key, "The "+strings.ReplaceAll(key, "_", " ")+" field must be true or false.")
				}
				*dst = b
			}
			return nil
		}
		for key, dst := range map[string]*bool{"auto_deploy": &next.AutoDeploy, "allow_force_push": &next.AllowForcePush,
			"previews_enabled": &next.PreviewsEnabled, "fork_previews": &next.ForkPreviews, "fork_secrets": &next.ForkSecrets} {
			if e := boolField(key, dst); e != nil {
				return 0, nil, e
			}
		}
		if v, ok := q.Body["preview_limit"]; ok {
			n, isNum := v.(float64)
			if !isNum || n < 0 || n > 20 || n != float64(int(n)) {
				return 0, nil, errValidation("preview_limit", "The preview limit field must be between 0 and 20.")
			}
			next.PreviewLimit = int(n)
		}
		if v, ok := q.Body["preview_ttl_hours"]; ok {
			n, isNum := v.(float64)
			if !isNum || n < 1 || n > 720 || n != float64(int(n)) {
				return 0, nil, errValidation("preview_ttl_hours", "The preview ttl hours field must be between 1 and 720.")
			}
			next.PreviewTTLHours = int(n)
		}
		if v, ok := q.Body["preview_env"]; ok {
			env := runr.EnvMap{}
			if m, isMap := v.(map[string]any); isMap {
				for k, val := range m {
					if e := runr.ValidateEnvName(k); e != nil {
						return 0, nil, errValidation("preview_env", e.Error())
					}
					env[k] = fmt.Sprint(val)
				}
			}
			next.PreviewEnv = env
		}
		if gp.settings.PreviewsEnabled && !next.PreviewsEnabled {
			for _, r := range gp.previews {
				if r.data.Status != runr.PreviewDeleted {
					r.data.Status, r.data.StatusMessage, r.data.ClosedAt = runr.PreviewDeleted, "Previews turned off", nowRFC3339()
				}
			}
		}
		gp.settings = next
		return 200, map[string]any{"data": s.gitInfo(p)}, nil
	})

	handle("GET /projects/{project}/git/deploys", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		gp := s.gitProjectOf(p)
		per := 30
		if v := q.query("per_page"); v != "" {
			if n, e := strconv.Atoi(v); e == nil {
				per = n
			}
		}
		if per < 1 {
			per = 1
		}
		if per > 100 {
			per = 100
		}
		kind := q.query("kind")
		list := []runr.GitDeploy{}
		total := 0
		for i := len(gp.deploys) - 1; i >= 0; i-- {
			d := gp.deploys[i]
			s.advanceGitDeploy(gp, d)
			if kind != "" && d.data.Kind != kind {
				continue
			}
			total++
			if len(list) < per {
				list = append(list, d.data)
			}
		}
		last := (total + per - 1) / per
		if last < 1 {
			last = 1
		}
		return 200, map[string]any{"data": list, "meta": map[string]any{"total": total, "current_page": 1, "last_page": last}}, nil
	})

	handle("POST /projects/{project}/git/redeploy", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		gp := s.gitProjectOf(p)
		if gp.head == "" {
			return 0, nil, errValidation("deploy_branch", "The deploy branch has no commit to deploy.")
		}
		d := &gitDeployRow{pollsLeft: s.gitSt().cfg.Polls, data: runr.GitDeploy{ID: s.newID(), Kind: "production",
			Trigger: "redeploy", Provider: "runr", SHA: gp.head, ShortSHA: gitShortSHA(gp.head), Branch: gp.settings.DeployBranch,
			AuthorName: s.User.Name, URL: p.data.EndpointURL, Status: runr.GitDeployQueued, Warnings: []any{},
			Skipped: []any{}, CreatedAt: nowRFC3339(), UpdatedAt: nowRFC3339()}}
		gp.deploys = append(gp.deploys, d)
		return 202, map[string]any{"data": d.data}, nil
	})

	handle("POST /projects/{project}/git/repos", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if _, err := s.projectOf(q); err != nil {
			return 0, nil, err
		}
		pr, _, err := s.gitCredentials(q)
		if err != nil {
			return 0, nil, err
		}
		search := strings.ToLower(q.str("search"))
		list := []runr.GitRepository{}
		for _, r := range s.gitSt().repos[pr.data.ID] {
			if search == "" || strings.Contains(strings.ToLower(r.FullName), search) {
				list = append(list, r)
			}
		}
		return 200, map[string]any{"data": list}, nil
	})

	handle("POST /projects/{project}/git/connect", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		repo := q.str("repository")
		if repo == "" {
			return 0, nil, errValidation("repository", "The repository field is required.")
		}
		gp := s.gitProjectOf(p)
		if gp.connection != nil {
			return 0, nil, errValidation("repository", "The project is already connected to a repository.")
		}
		pr, inst, err := s.gitCredentials(q)
		if err != nil {
			return 0, nil, err
		}
		var found *runr.GitRepository
		for _, r := range s.gitSt().repos[pr.data.ID] {
			if r.FullName == repo || r.ID == repo {
				rr := r
				found = &rr
			}
		}
		if found == nil {
			return 0, nil, errValidation("repository", "The repository cannot be read: Not Found")
		}
		mode := "pat"
		if pr.data.Type == runr.GitProviderGitHub {
			mode = "app"
		}
		gp.connection = &runr.GitConnection{ID: s.newID(), ProviderID: pr.data.ID, ProviderType: pr.data.Type,
			ProviderName: pr.data.Name, RepoID: found.ID, RepoFullName: found.FullName, RepoURL: found.URL,
			DefaultBranch: found.DefaultBranch, AuthMode: mode, InstallationID: runr.FlexID(inst),
			WebhookRegistered: true, CreatedAt: nowRFC3339()}
		return 201, map[string]any{"data": s.gitInfo(p)}, nil
	})

	handle("DELETE /projects/{project}/git/connect", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		gp := s.gitProjectOf(p)
		if gp.connection != nil {
			gp.connection = nil
			for _, r := range gp.previews {
				if r.data.Status != runr.PreviewDeleted {
					r.data.Status, r.data.StatusMessage, r.data.ClosedAt = runr.PreviewDeleted, "Repository disconnected", nowRFC3339()
				}
			}
		}
		return 200, map[string]any{"data": s.gitInfo(p)}, nil
	})

	handle("GET /projects/{project}/git/installations", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if _, err := s.projectOf(q); err != nil {
			return 0, nil, err
		}
		pr, err := s.gitProvider(q.query("provider_id"), runr.GitProviderGitHub)
		if err != nil {
			return 0, nil, err
		}
		list := []map[string]any{}
		for _, i := range s.gitSt().installations {
			if i.providerID == pr.data.ID {
				list = append(list, map[string]any{"id": i.id, "installation_id": i.installationID,
					"account_login": i.account, "account_type": "Organization"})
			}
		}
		return 200, map[string]any{"data": list}, nil
	})

	handle("POST /projects/{project}/git/github/install", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if _, err := s.projectOf(q); err != nil {
			return 0, nil, err
		}
		pr, err := s.gitProvider(q.str("provider_id"), runr.GitProviderGitHub)
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"url": pr.data.WebURL + "/apps/" + pr.data.AppSlug + "/installations/new?state=" +
			url.QueryEscape("signed-state")}, nil
	})

	handle("POST /projects/{project}/git/gitlab/authorize", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if _, err := s.projectOf(q); err != nil {
			return 0, nil, err
		}
		pr, err := s.gitProvider(q.str("provider_id"), runr.GitProviderGitLab)
		if err != nil {
			return 0, nil, err
		}
		if !pr.oauth {
			return 0, nil, errValidation("provider_id", "This GitLab provider has no OAuth application.")
		}
		return 200, map[string]any{"url": pr.data.WebURL + "/oauth/authorize?client_id=" + pr.data.ClientID +
			"&redirect_uri=" + url.QueryEscape(s.URL+"/api/git/oauth/gitlab/callback") + "&response_type=code&scope=api&state=signed-state"}, nil
	})

	handle("GET /projects/{project}/previews", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		all := q.query("all") == "1" || q.query("all") == "true"
		list := []runr.GitPreview{}
		gp := s.gitProjectOf(p)
		for i := len(gp.previews) - 1; i >= 0; i-- {
			r := gp.previews[i]
			s.advancePreview(r)
			if !all && r.data.Status == runr.PreviewDeleted {
				continue
			}
			list = append(list, r.data)
		}
		return 200, map[string]any{"data": list}, nil
	})

	handle("POST /projects/{project}/previews/{preview}/redeploy", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		r := s.gitPreviewByID(s.gitProjectOf(p), q.Segs[3])
		if r == nil {
			return 0, nil, errNotFound()
		}
		if r.versionID == "" || (r.data.Status != runr.PreviewReady && r.data.Status != runr.PreviewFailed) {
			return 0, nil, errValidation("preview", "Only a ready or failed preview can be deployed again.")
		}
		r.data.Status, r.data.StatusMessage = runr.PreviewDeploying, ""
		r.pollsLeft = s.gitSt().cfg.Polls
		return 202, map[string]any{"data": r.data}, nil
	})

	handle("DELETE /projects/{project}/previews/{preview}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		r := s.gitPreviewByID(s.gitProjectOf(p), q.Segs[3])
		if r == nil {
			return 0, nil, errNotFound()
		}
		if r.data.Status != runr.PreviewDeleted {
			r.data.Status, r.data.StatusMessage, r.data.ClosedAt = runr.PreviewDeleted, "Deleted in RunR", nowRFC3339()
		}
		return 200, map[string]any{"data": r.data}, nil
	})

	handle("GET /git-providers", func(s *Server, q *featureReq) (int, any, *apiErr) {
		list := []runr.GitProviderInfo{}
		st := s.gitSt()
		for _, pr := range st.providers {
			d := pr.data
			d.LinksCount = 0
			for _, gp := range st.projects {
				if gp.connection != nil && gp.connection.ProviderID == d.ID {
					d.LinksCount++
				}
			}
			list = append(list, d)
		}
		return 200, map[string]any{"data": list}, nil
	})
}
