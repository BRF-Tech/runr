package runr

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"net/http"
)

// White-label branding (docs/white-label.md). The install's brand lives under /admin/branding
// (single-tenant: branding.manage; multi-tenant: platform administrators); in the multi-tenant
// edition an organization's own brand lives under /tenant/branding (branding.manage in the
// tenant, while the platform allows tenant branding).

// Branding scopes.
const (
	BrandingInstall      = "install"
	BrandingOrganization = "organization"
)

// BrandingTextFields are the text settings of a brand (BrandingService::FIELDS without the
// colour scale, the footer links and powered_by). The *_url fields take an https URL or a
// data: URI of an image.
var BrandingTextFields = []string{
	"product_name", "company_name", "primary_color", "accent_color", "login_headline", "login_text",
	"support_email", "support_url", "email_from_name", "logo_light_url", "logo_dark_url",
	"logo_mark_url", "favicon_url", "email_logo_url",
}

// BrandingAssetOfField maps an image URL field to its asset kind (an upload in the console
// replaces the URL).
var BrandingAssetOfField = map[string]string{
	"logo_light_url": "logo_light", "logo_dark_url": "logo_dark", "logo_mark_url": "logo_mark",
	"favicon_url": "favicon", "email_logo_url": "email_logo",
}

// FooterLink is one link of the console footer.
type FooterLink struct {
	Label string `json:"label"`
	URL   string `json:"url"`
}

// BrandingSettings is a brand as the API answers it: Stored holds what was set explicitly
// (unset fields use the defaults), Branding the resolved brand.
type BrandingSettings struct {
	Scope string `json:"scope"`
	// Stored are the explicitly set values: field → value (strings, footer_links, powered_by,
	// and <asset>_uploaded markers for images uploaded in the console).
	Stored map[string]any `json:"stored"`
	// Branding is the resolved brand (stored values over the defaults).
	Branding map[string]any `json:"branding"`
	// TenantOverridesEnabled: organizations may set their own brand (install scope).
	TenantOverridesEnabled bool `json:"tenant_overrides_enabled"`
	// Enabled is sent for the organization scope: whether tenant branding is allowed.
	Enabled *bool `json:"enabled,omitempty"`
}

// UnmarshalJSON tolerates stored / branding encoded as [] (an empty PHP array).
func (b *BrandingSettings) UnmarshalJSON(data []byte) error {
	type plain struct {
		Stored                 json.RawMessage `json:"stored"`
		Branding               json.RawMessage `json:"branding"`
		TenantOverridesEnabled bool            `json:"tenant_overrides_enabled"`
		Enabled                *bool           `json:"enabled"`
	}
	var p plain
	if err := json.Unmarshal(data, &p); err != nil {
		return err
	}
	*b = BrandingSettings{TenantOverridesEnabled: p.TenantOverridesEnabled, Enabled: p.Enabled,
		Stored: map[string]any{}, Branding: map[string]any{}}
	if s := bytes.TrimSpace(p.Stored); len(s) > 0 && s[0] == '{' {
		_ = json.Unmarshal(s, &b.Stored)
	}
	if s := bytes.TrimSpace(p.Branding); len(s) > 0 && s[0] == '{' {
		_ = json.Unmarshal(s, &b.Branding)
	}
	return nil
}

// StoredString returns a stored text field ("" when unset).
func (b *BrandingSettings) StoredString(field string) (string, bool) {
	v, ok := b.Stored[field].(string)
	return v, ok && v != ""
}

// Uploaded reports whether the image of an URL field was uploaded in the console.
func (b *BrandingSettings) Uploaded(field string) bool {
	kind, ok := BrandingAssetOfField[field]
	if !ok {
		return false
	}
	v, _ := b.Stored[kind+"_uploaded"].(bool)
	return v
}

// FooterLinks returns the stored footer links.
func (b *BrandingSettings) FooterLinks() []FooterLink {
	raw, ok := b.Stored["footer_links"]
	if !ok || raw == nil {
		return nil
	}
	data, _ := json.Marshal(raw)
	var links []FooterLink
	_ = json.Unmarshal(data, &links)
	return links
}

func brandingPath(scope string) string {
	if scope == BrandingOrganization {
		return "/tenant/branding"
	}
	return "/admin/branding"
}

// BrandingScope picks the brand the caller manages: the organization's own brand for members
// of a multi-tenant installation who are not platform administrators, else the install's.
func (c *Client) BrandingScope(ctx context.Context) (string, error) {
	p, err := c.Permissions(ctx)
	if errors.Is(err, ErrUnsupported) {
		return BrandingInstall, nil
	}
	if err != nil {
		return "", err
	}
	if p.Edition == "multi" && !p.PlatformAdmin {
		return BrandingOrganization, nil
	}
	return BrandingInstall, nil
}

// GetBranding returns the brand of scope (BrandingInstall or BrandingOrganization).
func (c *Client) GetBranding(ctx context.Context, scope string) (*BrandingSettings, error) {
	var out BrandingSettings
	if err := c.do(ctx, request{method: http.MethodGet, path: brandingPath(scope)}, &out); err != nil {
		return nil, err
	}
	out.Scope = scope
	return &out, nil
}

// UpdateBranding changes fields of a brand: field → value, nil resets a field to its default.
// Fields not in the map stay as they are.
func (c *Client) UpdateBranding(ctx context.Context, scope string, fields map[string]any) (*BrandingSettings, error) {
	var out BrandingSettings
	if err := c.do(ctx, request{method: http.MethodPut, path: brandingPath(scope), body: fields}, &out); err != nil {
		return nil, err
	}
	out.Scope = scope
	return &out, nil
}
