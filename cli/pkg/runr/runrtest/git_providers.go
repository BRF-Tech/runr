package runrtest

import (
	"strconv"
	"strings"

	"github.com/runrsh/core/cli/pkg/runr"
)

// Create, update and delete of git providers (GitProviderController). The fake keeps the
// secrets so tests can check what was stored; the answers carry has_* flags only.

type gitpSecrets struct {
	clientSecret, privateKey, webhookSecret string
}

func (s *Server) gitpSecretsOf(id string) *gitpSecrets {
	m := featureState(s, "git-provider-secrets", func() *map[string]*gitpSecrets {
		v := map[string]*gitpSecrets{}
		return &v
	})
	sec, ok := (*m)[id]
	if !ok {
		sec = &gitpSecrets{}
		(*m)[id] = sec
	}
	return sec
}

// GitProviderSecrets returns the client secret, private key and webhook secret stored for a
// provider.
func (s *Server) GitProviderSecrets(providerID string) (clientSecret, privateKey, webhookSecret string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	sec := s.gitpSecretsOf(providerID)
	return sec.clientSecret, sec.privateKey, sec.webhookSecret
}

func (s *Server) gitpProvider(id string) *gitProvider {
	for _, pr := range s.gitSt().providers {
		if pr.data.ID == id {
			return pr
		}
	}
	return nil
}

func (s *Server) gitpLinks(id string) int {
	n := 0
	for _, gp := range s.gitSt().projects {
		if gp.connection != nil && gp.connection.ProviderID == id {
			n++
		}
	}
	return n
}

// gitpApply validates and applies a create (pr new) or update body.
func (s *Server) gitpApply(pr *gitProvider, body map[string]any, create bool) *apiErr {
	str := func(k string) (string, bool) {
		v, ok := body[k]
		if !ok || v == nil {
			return "", false
		}
		x, _ := v.(string)
		return x, true
	}
	if create {
		typ, _ := str("type")
		switch typ {
		case runr.GitProviderGitHub, runr.GitProviderGitLab, runr.GitProviderForgejo:
		case "":
			return errValidation("type", "The type field is required.")
		default:
			return errValidation("type", "The selected type is invalid.")
		}
		pr.data.Type = typ
		if n, _ := str("name"); n == "" {
			return errValidation("name", "The name field is required.")
		}
		if typ == runr.GitProviderForgejo {
			if b, _ := str("base_url"); b == "" {
				return errValidation("base_url", "The base url field is required.")
			}
		}
		if typ == runr.GitProviderGitHub {
			for _, k := range []string{"app_id", "app_slug", "client_id", "client_secret", "private_key", "webhook_secret"} {
				if v, _ := str(k); v == "" {
					return errValidation(k, "The "+strings.ReplaceAll(k, "_", " ")+" field is required.")
				}
			}
		}
	} else if _, ok := body["type"]; ok {
		return errValidation("type", "The type field is prohibited.")
	}
	if pk, _ := str("private_key"); pk != "" && !strings.Contains(pk, "PRIVATE KEY") {
		return errValidation("private_key", "The private key is not a valid PEM private key.")
	}
	if ws, _ := str("webhook_secret"); ws != "" && len(ws) < 16 {
		return errValidation("webhook_secret", "The webhook secret field must be at least 16 characters.")
	}
	if v, ok := str("name"); ok && v != "" {
		pr.data.Name = v
	}
	if v, ok := str("base_url"); ok {
		pr.data.BaseURL = strings.TrimRight(v, "/")
	}
	if v, ok := str("api_url"); ok {
		pr.data.APIURL = strings.TrimRight(v, "/")
	}
	if v, ok := str("client_id"); ok {
		pr.data.ClientID = v
	}
	if v, ok := str("app_id"); ok {
		pr.data.AppID = runr.FlexID(v)
	}
	if v, ok := str("app_slug"); ok {
		pr.data.AppSlug = v
	}
	if v, ok := body["is_enabled"].(bool); ok {
		pr.data.IsEnabled = v
	}
	sec := s.gitpSecretsOf(pr.data.ID)
	if !create {
		// A provider seeded with SeedGitProvider has its secrets as flags only.
		if sec.clientSecret == "" && pr.data.HasClientSecret {
			sec.clientSecret = "seeded"
		}
		if sec.privateKey == "" && pr.data.HasPrivateKey {
			sec.privateKey = "seeded"
		}
		if sec.webhookSecret == "" && pr.data.HasWebhookSecret {
			sec.webhookSecret = "seeded"
		}
	}
	// An empty secret keeps the stored one.
	if v, _ := str("client_secret"); v != "" {
		sec.clientSecret = v
	}
	if v, _ := str("private_key"); v != "" {
		sec.privateKey = v
	}
	if v, _ := str("webhook_secret"); v != "" {
		sec.webhookSecret = v
	}
	pr.data.HasClientSecret, pr.data.HasPrivateKey, pr.data.HasWebhookSecret =
		sec.clientSecret != "", sec.privateKey != "", sec.webhookSecret != ""
	switch pr.data.Type {
	case runr.GitProviderGitHub:
		pr.data.WebURL = "https://github.com"
		pr.slug = pr.data.AppSlug
		pr.data.URLs = map[string]string{"webhook": s.URL + "/api/git/webhooks/github/" + pr.data.ID,
			"setup": s.URL + "/api/git/github/setup", "callback": s.URL + "/api/git/github/setup"}
		pr.configured = pr.data.AppID != "" && pr.data.HasPrivateKey && pr.data.HasWebhookSecret
	case runr.GitProviderGitLab:
		pr.data.WebURL = pr.data.BaseURL
		if pr.data.WebURL == "" {
			pr.data.WebURL = "https://gitlab.com"
		}
		pr.oauth = pr.data.ClientID != "" && pr.data.HasClientSecret
		pr.data.URLs = map[string]string{"callback": s.URL + "/api/git/oauth/gitlab/callback"}
		pr.configured = true
	default:
		pr.data.WebURL = pr.data.BaseURL
		pr.data.URLs = nil
		pr.configured = pr.data.BaseURL != ""
	}
	pr.data.Configured = pr.configured
	pr.data.ConnectModes = s.gitModes(pr)
	return nil
}

func init() {
	handle("POST /git-providers", func(s *Server, q *featureReq) (int, any, *apiErr) {
		pr := &gitProvider{data: runr.GitProviderInfo{ID: s.newID(), IsEnabled: true, CreatedAt: nowRFC3339()}}
		if err := s.gitpApply(pr, q.Body, true); err != nil {
			return 0, nil, err
		}
		st := s.gitSt()
		st.providers = append(st.providers, pr)
		return 201, map[string]any{"data": pr.data}, nil
	})
	handle("PUT /git-providers/{provider}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		pr := s.gitpProvider(q.Segs[1])
		if pr == nil {
			return 0, nil, errNotFound()
		}
		// Validate on a copy: a refused update changes nothing.
		cp := *pr
		if err := s.gitpApply(&cp, q.Body, false); err != nil {
			return 0, nil, err
		}
		*pr = cp
		d := pr.data
		d.LinksCount = s.gitpLinks(pr.data.ID)
		return 200, map[string]any{"data": d}, nil
	})
	handle("DELETE /git-providers/{provider}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		pr := s.gitpProvider(q.Segs[1])
		if pr == nil {
			return 0, nil, errNotFound()
		}
		if n := s.gitpLinks(pr.data.ID); n > 0 {
			return 0, nil, errValidation("provider", "Projects are connected through this provider ("+strconv.Itoa(n)+"); disconnect them first.")
		}
		st := s.gitSt()
		for i, x := range st.providers {
			if x == pr {
				st.providers = append(st.providers[:i], st.providers[i+1:]...)
				break
			}
		}
		return 204, nil, nil
	})
}
