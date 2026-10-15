package runrtest

import (
	"fmt"
	"net/mail"
	"net/url"
	"regexp"
	"strings"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// White-label branding (BrandingController, TenantBrandingController): the install's brand
// under /admin/branding and, in the multi-tenant edition (RBACMultiTenant), the organization's
// own brand under /tenant/branding while tenant overrides are enabled.

type brandState struct {
	install          map[string]any
	tenant           map[string]any
	overridesEnabled bool
}

func (s *Server) brand() *brandState {
	return featureState(s, "branding", func() *brandState {
		return &brandState{install: map[string]any{}, tenant: map[string]any{}}
	})
}

var brandDefaults = map[string]any{
	"product_name": "RunR", "company_name": "", "primary_color": "#2563eb", "accent_color": "#7c3aed",
	"login_headline": "Welcome back", "login_text": "", "support_email": "", "support_url": "",
	"email_from_name": nil, "logo_light_url": nil, "logo_dark_url": nil, "logo_mark_url": nil,
	"favicon_url": nil, "email_logo_url": nil, "footer_links": []any{}, "powered_by": true,
}

// BrandingStored returns the explicitly set fields of a brand (runr.BrandingInstall or
// runr.BrandingOrganization).
func (s *Server) BrandingStored(scope string) map[string]any {
	s.mu.Lock()
	defer s.mu.Unlock()
	out := map[string]any{}
	for k, v := range s.brandStore(scope) {
		out[k] = v
	}
	return out
}

// BrandingSeedUpload marks an image (logo_light, favicon, …) of a brand as uploaded in the
// console: its URL field then holds the asset URL.
func (s *Server) BrandingSeedUpload(scope, kind string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.brandStore(scope)
	st[kind+"_url"] = s.URL + "/api/branding/assets/" + kind + "?v=1"
	st[kind+"_uploaded"] = true
}

// BrandingAllowTenants enables organization brands (the platform's tenant-branding switch).
func (s *Server) BrandingAllowTenants(on bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.brand().overridesEnabled = on
}

func (s *Server) brandStore(scope string) map[string]any {
	if scope == runr.BrandingOrganization {
		return s.brand().tenant
	}
	return s.brand().install
}

func (s *Server) brandPayload(scope string) map[string]any {
	st := s.brandStore(scope)
	resolved := map[string]any{}
	for k, v := range brandDefaults {
		resolved[k] = v
	}
	if scope == runr.BrandingOrganization {
		for k, v := range s.brand().install {
			resolved[k] = v
		}
	}
	for k, v := range st {
		if !strings.HasSuffix(k, "_uploaded") {
			resolved[k] = v
		}
	}
	stored := any(map[string]any{})
	if len(st) == 0 {
		stored = []any{} // an empty PHP array
	} else {
		m := map[string]any{}
		for k, v := range st {
			m[k] = v
		}
		stored = m
	}
	out := map[string]any{"branding": resolved, "stored": stored, "defaults": brandDefaults,
		"tenant_overrides_enabled": s.brand().overridesEnabled}
	if scope == runr.BrandingOrganization {
		out["enabled"] = s.brand().overridesEnabled
	}
	return out
}

var brandColor = regexp.MustCompile(`^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$`)
var brandLinkURL = regexp.MustCompile(`(?i)^(https?://|mailto:)\S+$`)

var brandMaxLen = map[string]int{
	"product_name": 80, "company_name": 120, "login_headline": 120, "login_text": 600,
	"support_email": 190, "support_url": 500, "email_from_name": 120,
}

func (s *Server) brandUpdate(scope string, body map[string]any) *apiErr {
	allowed := append([]string{}, runr.BrandingTextFields...)
	allowed = append(allowed, "footer_links")
	if scope == runr.BrandingInstall {
		allowed = append(allowed, "powered_by")
	}
	// Validate everything before changing anything.
	for _, f := range allowed {
		v, ok := body[f]
		if !ok || v == nil {
			continue
		}
		switch f {
		case "powered_by":
			if _, isBool := v.(bool); !isBool {
				return errValidation(f, "The powered by field must be true or false.")
			}
		case "footer_links":
			links, isList := v.([]any)
			if !isList {
				return errValidation(f, "The footer links field must be an array.")
			}
			if len(links) > 8 {
				return errValidation(f, "The footer links field must not have more than 8 items.")
			}
			for i, l := range links {
				m, _ := l.(map[string]any)
				label, _ := m["label"].(string)
				u, _ := m["url"].(string)
				if label == "" {
					return errValidation(fmt.Sprintf("footer_links.%d.label", i), "The label field is required.")
				}
				if !brandLinkURL.MatchString(u) {
					return errValidation(fmt.Sprintf("footer_links.%d.url", i), "The url field format is invalid.")
				}
			}
		default:
			str, isStr := v.(string)
			if !isStr {
				return errValidation(f, "The "+strings.ReplaceAll(f, "_", " ")+" field must be a string.")
			}
			if max, ok := brandMaxLen[f]; ok && len(str) > max {
				return errValidation(f, fmt.Sprintf("The %s field must not be greater than %d characters.", strings.ReplaceAll(f, "_", " "), max))
			}
			switch f {
			case "primary_color", "accent_color":
				if !brandColor.MatchString(str) {
					return errValidation(f, "The "+strings.ReplaceAll(f, "_", " ")+" field format is invalid.")
				}
			case "support_email":
				if _, err := mail.ParseAddress(str); err != nil {
					return errValidation(f, "The support email field must be a valid email address.")
				}
			case "support_url":
				if u, err := url.Parse(str); err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
					return errValidation(f, "The support url field must be a valid URL.")
				}
			}
		}
	}
	if v, ok := body["tenant_overrides_enabled"]; ok && v != nil && scope == runr.BrandingInstall {
		if _, isBool := v.(bool); !isBool {
			return errValidation("tenant_overrides_enabled", "The tenant overrides enabled field must be true or false.")
		}
	}

	st := s.brandStore(scope)
	for _, f := range allowed {
		v, ok := body[f]
		if !ok {
			continue
		}
		if str, isStr := v.(string); v == nil || (isStr && strings.TrimSpace(str) == "") {
			if kind, isAsset := runr.BrandingAssetOfField[f]; isAsset && st[kind+"_uploaded"] == true {
				continue // an uploaded image stays; only an explicit URL replaces it
			}
			delete(st, f)
			continue
		}
		st[f] = v
		if kind, isAsset := runr.BrandingAssetOfField[f]; isAsset {
			delete(st, kind+"_uploaded") // an explicit URL replaces an uploaded image
		}
	}
	if scope == runr.BrandingInstall {
		if v, ok := body["tenant_overrides_enabled"]; ok {
			b, _ := v.(bool)
			s.brand().overridesEnabled = b
		}
	}
	return nil
}

func init() {
	handle("GET /admin/branding", func(s *Server, q *featureReq) (int, any, *apiErr) {
		return 200, s.brandPayload(runr.BrandingInstall), nil
	})
	handle("PUT /admin/branding", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if err := s.brandUpdate(runr.BrandingInstall, q.Body); err != nil {
			return 0, nil, err
		}
		return 200, s.brandPayload(runr.BrandingInstall), nil
	})
	handle("GET /tenant/branding", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if s.rbacEdition() != "multi" {
			return 0, nil, errNotFound()
		}
		return 200, s.brandPayload(runr.BrandingOrganization), nil
	})
	handle("PUT /tenant/branding", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if s.rbacEdition() != "multi" {
			return 0, nil, errNotFound()
		}
		if !s.brand().overridesEnabled {
			return 0, nil, &apiErr{status: 403, message: "Tenant branding is switched off by the platform administrator."}
		}
		if err := s.brandUpdate(runr.BrandingOrganization, q.Body); err != nil {
			return 0, nil, err
		}
		return 200, s.brandPayload(runr.BrandingOrganization), nil
	})
}
