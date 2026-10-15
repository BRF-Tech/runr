package runr

import (
	"context"
	"encoding/json"
	"net/http"
	"net/url"
	"strconv"
)

// Audit log (docs/security.md, "Audit log"): append-only events of the organization, newest
// first. Listing needs audit.view, the export audit.export.

// AuditEvent is AuditEvent::toPublicArray.
type AuditEvent struct {
	ID         string          `json:"id"`
	OccurredAt string          `json:"occurred_at"`
	TenantID   string          `json:"tenant_id,omitempty"`
	Actor      AuditActor      `json:"actor"`
	Action     string          `json:"action"`
	Result     string          `json:"result"`
	Target     *AuditTarget    `json:"target,omitempty"`
	IPAddress  string          `json:"ip_address,omitempty"`
	UserAgent  string          `json:"user_agent,omitempty"`
	RequestID  string          `json:"request_id,omitempty"`
	HTTPMethod string          `json:"http_method,omitempty"`
	Route      string          `json:"route,omitempty"`
	StatusCode int             `json:"status_code,omitempty"`
	Metadata   json.RawMessage `json:"metadata,omitempty"`
}

// AuditActor is who acted.
type AuditActor struct {
	Type         string `json:"type"`
	ID           FlexID `json:"id,omitempty"`
	Name         string `json:"name,omitempty"`
	Email        string `json:"email,omitempty"`
	AuthMethod   string `json:"auth_method,omitempty"`
	Impersonator *struct {
		ID   FlexID `json:"id"`
		Name string `json:"name"`
	} `json:"impersonator,omitempty"`
}

// AuditTarget is what the event acted on.
type AuditTarget struct {
	Type string `json:"type"`
	ID   FlexID `json:"id,omitempty"`
	Name string `json:"name,omitempty"`
}

// AuditFilter narrows the audit log; empty fields are not sent.
type AuditFilter struct {
	// Actor is a user id, or part of the name or e-mail.
	Actor string
	// Action is an exact action, "project.*" for a prefix, or a comma-separated list.
	Action     string
	TargetType string
	TargetID   string
	// Target is part of the target's name.
	Target string
	// Result is success, denied or failure.
	Result string
	// From and To are ISO 8601 times.
	From      string
	To        string
	RequestID string
	// Query is free text.
	Query string
	// TenantID filters platform-wide lists (platform administrators; "platform").
	TenantID string
	// PerPage is 1-100 (default 25).
	PerPage int
}

func (f AuditFilter) values() url.Values {
	q := url.Values{}
	set := func(k, v string) {
		if v != "" {
			q.Set(k, v)
		}
	}
	set("actor", f.Actor)
	set("action", f.Action)
	set("target_type", f.TargetType)
	set("target_id", f.TargetID)
	set("target", f.Target)
	set("result", f.Result)
	set("from", f.From)
	set("to", f.To)
	set("request_id", f.RequestID)
	set("q", f.Query)
	set("tenant_id", f.TenantID)
	if f.PerPage > 0 {
		q.Set("per_page", strconv.Itoa(f.PerPage))
	}
	return q
}

// AuditPage is one page of the audit log.
type AuditPage struct {
	Events    []AuditEvent `json:"events"`
	Page      Page         `json:"page"`
	CanExport bool         `json:"can_export"`
	Scope     string       `json:"scope,omitempty"`
}

// ListAuditEvents returns the newest events matching f.
func (c *Client) ListAuditEvents(ctx context.Context, f AuditFilter) (*AuditPage, error) {
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodGet, path: "/audit-events", query: f.values()}, &raw); err != nil {
		return nil, err
	}
	out := &AuditPage{}
	if err := decodeList(raw, &out.Events, &out.Page); err != nil {
		return nil, err
	}
	var meta struct {
		Meta struct {
			CanExport bool   `json:"can_export"`
			Scope     string `json:"scope"`
		} `json:"meta"`
	}
	_ = json.Unmarshal(raw, &meta)
	out.CanExport, out.Scope = meta.Meta.CanExport, meta.Meta.Scope
	if out.Events == nil {
		out.Events = []AuditEvent{}
	}
	return out, nil
}

// GetAuditEvent returns one event.
func (c *Client) GetAuditEvent(ctx context.Context, id string) (*AuditEvent, error) {
	var out struct {
		Data AuditEvent `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/audit-events/" + seg(id)}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// ExportAuditEvents streams the events matching f (up to the API's export limit) as CSV or JSON
// (format "csv" or "json") into d.
func (c *Client) ExportAuditEvents(ctx context.Context, f AuditFilter, format string, d *Download) error {
	q := f.values()
	q.Del("per_page")
	if format != "" {
		q.Set("format", format)
	}
	return c.do(ctx, request{method: http.MethodGet, path: "/audit-events/export", query: q,
		accept: "text/csv, application/json"}, d)
}

// AuditSettings is GET|PUT /audit-settings: how long events are kept (0 = forever, up to
// MaxDays), whether events are forwarded to an external sink, and whether the caller may change
// the retention (install-wide: settings.manage on a single-tenant installation, a platform
// administrator outside any organization otherwise).
type AuditSettings struct {
	RetentionDays  int  `json:"retention_days"`
	MaxDays        int  `json:"max_days"`
	ForwardEnabled bool `json:"forward_enabled"`
	CanUpdate      bool `json:"can_update"`
}

// GetAuditSettings returns the audit log's retention settings.
func (c *Client) GetAuditSettings(ctx context.Context) (*AuditSettings, error) {
	var out AuditSettings
	if err := c.do(ctx, request{method: http.MethodGet, path: "/audit-settings"}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// SetAuditRetention sets how many days audit events are kept (0 keeps them forever).
func (c *Client) SetAuditRetention(ctx context.Context, days int) (*AuditSettings, error) {
	var out AuditSettings
	err := c.do(ctx, request{method: http.MethodPut, path: "/audit-settings", body: map[string]int{"retention_days": days}}, &out)
	if err != nil {
		return nil, err
	}
	return &out, nil
}
