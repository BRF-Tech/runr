package runr

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/url"
)

// API product of the gateways (docs/api-product.md): gateway routes that ask for an API key or a
// JWT, consumers and their keys, plans with rate limits and quotas, and the measured usage. The
// endpoints are newer than the gateways themselves: an API without them answers 404, which the
// methods below turn into ErrUnsupported.

// Authentication modes of a gateway route.
const (
	RouteAuthPublic = "public"
	RouteAuthKey    = "key"
	RouteAuthJWT    = "jwt"
)

// Gateway is GatewayResource.
type Gateway struct {
	ID                     string         `json:"id"`
	Subdomain              string         `json:"subdomain"`
	CustomDomain           string         `json:"custom_domain,omitempty"`
	CustomDomainPending    *PendingDomain `json:"custom_domain_pending,omitempty"`
	CustomDomainVerifiedAt string         `json:"custom_domain_verified_at,omitempty"`
	Status                 string         `json:"status"`
	// StatusMessage says why a gateway is pending / in error (e.g. its key and JWT routes answer
	// 503 auth_unavailable until gateway-auth is ready).
	StatusMessage    string         `json:"status_message,omitempty"`
	RegionID         string         `json:"knative_region_id"`
	Region           *GatewayRegion `json:"region,omitempty"`
	Host             string         `json:"host"`
	RateLimit        *GatewayLimit  `json:"rate_limit,omitempty"`
	DeveloperPage    bool           `json:"developer_page"`
	DeveloperPageKey string         `json:"developer_page_key,omitempty"`
	// DeveloperPageMetrics: the page shows the routes' latency and success rate
	DeveloperPageMetrics bool           `json:"developer_page_metrics,omitempty"`
	Description          string         `json:"description,omitempty"`
	Routes               []GatewayRoute `json:"routes"`
	CreatedAt            string         `json:"created_at,omitempty"`
}

// GatewayRegion names the gateway's region.
type GatewayRegion struct {
	ID   string `json:"id"`
	Name string `json:"name"`
}

// GatewayLimit is the gateway's Traefik rate limit per client IP.
type GatewayLimit struct {
	Average int `json:"average"`
	Burst   int `json:"burst"`
}

// PendingDomain is a custom domain waiting for its DNS records.
type PendingDomain struct {
	Domain       string `json:"domain"`
	Verification struct {
		Type  string `json:"type"`
		Name  string `json:"name"`
		Value string `json:"value"`
	} `json:"verification"`
	DNSTargets []struct {
		Type  string `json:"type"`
		Name  string `json:"name"`
		Value string `json:"value"`
	} `json:"dns_targets"`
}

// GatewayRoute is a route of a gateway.
type GatewayRoute struct {
	ID        string          `json:"id"`
	Path      string          `json:"path"`
	Methods   []string        `json:"methods,omitempty"`
	ProjectID string          `json:"project_id"`
	Project   *GatewayProject `json:"project,omitempty"`
	// Environment is the project environment the route leads to; empty: its own workload
	// (production). EnvironmentID is its id.
	Environment   string    `json:"environment,omitempty"`
	EnvironmentID string    `json:"environment_id,omitempty"`
	URL           string    `json:"url,omitempty"`
	AuthMode      string    `json:"auth_mode,omitempty"`
	JWT           *RouteJWT `json:"jwt,omitempty"`
	GatewayOnly   bool      `json:"gateway_only"`
}

// GatewayProject is the project a route reaches.
type GatewayProject struct {
	ID   string `json:"id"`
	Name string `json:"name"`
	Slug string `json:"slug"`
}

// RouteJWT is how a JWT route verifies tokens.
type RouteJWT struct {
	JWKSURL         string `json:"jwks_url"`
	Issuer          string `json:"issuer,omitempty"`
	Audience        string `json:"audience,omitempty"`
	SubjectClaim    string `json:"subject_claim,omitempty"`
	RequireConsumer bool   `json:"require_consumer"`
}

// RouteAuthInput changes a route's API product settings; nil fields stay as they are.
type RouteAuthInput struct {
	AuthMode    string    `json:"auth_mode,omitempty"`
	JWT         *RouteJWT `json:"jwt,omitempty"`
	GatewayOnly *bool     `json:"gateway_only,omitempty"`
}

// APIConsumer is ApiConsumerResource.
type APIConsumer struct {
	ID         string         `json:"id"`
	GatewayID  string         `json:"gateway_id,omitempty"`
	Scope      string         `json:"scope"`
	Name       string         `json:"name"`
	Email      string         `json:"email,omitempty"`
	ExternalID string         `json:"external_id,omitempty"`
	JWTSubject string         `json:"jwt_subject,omitempty"`
	PlanID     string         `json:"plan_id,omitempty"`
	Plan       *APIPlanRef    `json:"plan,omitempty"`
	Status     string         `json:"status"`
	Metadata   map[string]any `json:"metadata,omitempty"`
	Keys       *struct {
		Total  int `json:"total"`
		Active int `json:"active"`
	} `json:"keys,omitempty"`
	CanManage bool   `json:"can_manage"`
	CreatedAt string `json:"created_at,omitempty"`
}

// APIPlanRef names a consumer's plan.
type APIPlanRef struct {
	ID   string `json:"id"`
	Name string `json:"name"`
}

// APIConsumerInput creates or changes a consumer; empty strings are not sent.
type APIConsumerInput struct {
	GatewayID  string         `json:"gateway_id,omitempty"`
	Name       string         `json:"name,omitempty"`
	Email      string         `json:"email,omitempty"`
	ExternalID string         `json:"external_id,omitempty"`
	JWTSubject string         `json:"jwt_subject,omitempty"`
	PlanID     string         `json:"plan_id,omitempty"`
	Status     string         `json:"status,omitempty"`
	Metadata   map[string]any `json:"metadata,omitempty"`
}

// APIKey is ApiKeyResource; Key is set only in the answer that created or rotated it.
type APIKey struct {
	ID          string     `json:"id"`
	ConsumerID  string     `json:"consumer_id"`
	Name        string     `json:"name"`
	Prefix      string     `json:"prefix"`
	Scopes      []KeyScope `json:"scopes,omitempty"`
	PlanID      string     `json:"plan_id,omitempty"`
	Status      string     `json:"status"`
	ExpiresAt   string     `json:"expires_at,omitempty"`
	RevokedAt   string     `json:"revoked_at,omitempty"`
	LastUsedAt  string     `json:"last_used_at,omitempty"`
	RotatedFrom string     `json:"rotated_from_id,omitempty"`
	CreatedAt   string     `json:"created_at,omitempty"`
	Key         string     `json:"key,omitempty"`
}

// KeyScope limits a key to one route (Methods empty: every method).
type KeyScope struct {
	RouteID string   `json:"route_id"`
	Methods []string `json:"methods,omitempty"`
}

// APIKeyInput creates a key.
type APIKeyInput struct {
	Name      string     `json:"name"`
	Scopes    []KeyScope `json:"scopes,omitempty"`
	PlanID    string     `json:"plan_id,omitempty"`
	ExpiresAt string     `json:"expires_at,omitempty"`
}

// APIPlan is ApiPlanResource.
type APIPlan struct {
	ID            string `json:"id"`
	GatewayID     string `json:"gateway_id,omitempty"`
	Scope         string `json:"scope"`
	Name          string `json:"name"`
	Description   string `json:"description,omitempty"`
	RatePerSecond *int64 `json:"rate_per_second,omitempty"`
	Burst         *int64 `json:"burst,omitempty"`
	RatePerMinute *int64 `json:"rate_per_minute,omitempty"`
	QuotaPerDay   *int64 `json:"quota_per_day,omitempty"`
	QuotaPerMonth *int64 `json:"quota_per_month,omitempty"`
}

// APIPlanInput creates a plan; nil limits are not set.
type APIPlanInput struct {
	GatewayID     string `json:"gateway_id,omitempty"`
	Name          string `json:"name"`
	Description   string `json:"description,omitempty"`
	RatePerSecond *int64 `json:"rate_per_second,omitempty"`
	Burst         *int64 `json:"burst,omitempty"`
	RatePerMinute *int64 `json:"rate_per_minute,omitempty"`
	QuotaPerDay   *int64 `json:"quota_per_day,omitempty"`
	QuotaPerMonth *int64 `json:"quota_per_month,omitempty"`
}

// UsageCounts are the counters of one usage row: the gateway's decisions, and what the functions
// answered to the calls let through (measured by the region's metering proxy).
type UsageCounts struct {
	Requests      int64 `json:"requests"`
	Unauthorized  int64 `json:"unauthorized"`
	Forbidden     int64 `json:"forbidden"`
	RateLimited   int64 `json:"rate_limited"`
	QuotaExceeded int64 `json:"quota_exceeded"`

	Responses      int64 `json:"responses"`
	Status2xx      int64 `json:"status_2xx"`
	Status3xx      int64 `json:"status_3xx"`
	Status4xx      int64 `json:"status_4xx"`
	Status5xx      int64 `json:"status_5xx"`
	UpstreamErrors int64 `json:"upstream_errors"`
	// Errors: 5xx answers and calls without an answer
	Errors int64 `json:"errors"`
	// latencies until the answer started, milliseconds; nil without measured answers
	LatencyAvgMS *float64 `json:"latency_avg_ms"`
	LatencyP50MS *float64 `json:"latency_p50_ms"`
	LatencyP95MS *float64 `json:"latency_p95_ms"`
	LatencyP99MS *float64 `json:"latency_p99_ms"`
	BytesIn      int64    `json:"bytes_in"`
	BytesOut     int64    `json:"bytes_out"`
}

// Refused sums the calls the gateway refused.
func (u UsageCounts) Refused() int64 {
	return u.Unauthorized + u.Forbidden + u.RateLimited + u.QuotaExceeded
}

// UsageRow is one group of GET /api-usage; which label fields are set depends on GroupBy.
type UsageRow struct {
	UsageCounts
	Period     string `json:"period,omitempty"`
	ConsumerID string `json:"consumer_id,omitempty"`
	Consumer   *struct {
		ID         string `json:"id"`
		Name       string `json:"name"`
		ExternalID string `json:"external_id,omitempty"`
	} `json:"consumer,omitempty"`
	Subject string `json:"subject,omitempty"`
	KeyID   string `json:"key_id,omitempty"`
	Key     *struct {
		ID     string `json:"id"`
		Name   string `json:"name"`
		Prefix string `json:"prefix"`
	} `json:"key,omitempty"`
	RouteID string `json:"route_id,omitempty"`
	Path    string `json:"path,omitempty"`
}

// APIUsage is GET /api-usage.
type APIUsage struct {
	From    string      `json:"from"`
	To      string      `json:"to"`
	GroupBy string      `json:"group_by"`
	Totals  UsageCounts `json:"totals"`
	Data    []UsageRow  `json:"data"`
}

// APIUsageFilter narrows GET /api-usage; empty fields are not sent.
type APIUsageFilter struct {
	GatewayID, ConsumerID, KeyID, RouteID, From, To, GroupBy string
}

func (f APIUsageFilter) values() url.Values {
	q := url.Values{}
	for k, v := range map[string]string{
		"gateway_id": f.GatewayID, "consumer_id": f.ConsumerID, "key_id": f.KeyID, "route_id": f.RouteID,
		"from": f.From, "to": f.To, "group_by": f.GroupBy,
	} {
		if v != "" {
			q.Set(k, v)
		}
	}
	return q
}

// unsupported turns the 404 of an API without the API product into ErrUnsupported. The 404
// stays inside: IsNotFound still tells a consumer, key or plan that is gone (an API without the
// endpoints answers 404 as well).
func unsupported(err error) error {
	if IsNotFound(err) {
		return &unsupportedError{err: err}
	}
	return err
}

// unsupportedError is ErrUnsupported for errors.Is and the 404 it came from for errors.As.
type unsupportedError struct{ err error }

func (e *unsupportedError) Error() string        { return ErrUnsupported.Error() }
func (e *unsupportedError) Is(target error) bool { return target == ErrUnsupported }
func (e *unsupportedError) Unwrap() error        { return e.err }

// ListGateways returns the caller's gateways (one per region) with their routes.
func (c *Client) ListGateways(ctx context.Context) ([]Gateway, error) {
	var out struct {
		Data []Gateway `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/gateways"}, &out); err != nil {
		return nil, err
	}
	return out.Data, nil
}

// GetGateway returns one gateway.
func (c *Client) GetGateway(ctx context.Context, id string) (*Gateway, error) {
	var out struct {
		Data Gateway `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/gateways/" + url.PathEscape(id)}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// UpdateGatewayPage switches the gateway's public developer page and sets its description and,
// with metrics, whether the page shows the routes' latency and success rate.
func (c *Client) UpdateGatewayPage(ctx context.Context, id string, enabled bool, description *string, metrics *bool) (*Gateway, error) {
	body := map[string]any{"developer_page": enabled}
	if description != nil {
		body["description"] = *description
	}
	if metrics != nil {
		body["developer_page_metrics"] = *metrics
	}
	return c.UpdateGateway(ctx, id, body)
}

// UpdateGateway changes the given settings of a gateway: "rate_limit" (a *GatewayLimit, nil:
// none; a Burst of 0 is left out and the API takes the average), "developer_page",
// "description" (nil clears it) and "developer_page_metrics".
func (c *Client) UpdateGateway(ctx context.Context, id string, fields map[string]any) (*Gateway, error) {
	body := make(map[string]any, len(fields))
	for k, v := range fields {
		if l, ok := v.(*GatewayLimit); ok {
			v = nil
			if l != nil {
				limit := map[string]any{"average": l.Average}
				if l.Burst > 0 {
					limit["burst"] = l.Burst
				}
				v = limit
			}
		}
		body[k] = v
	}
	var out struct {
		Data Gateway `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPatch, path: "/gateways/" + url.PathEscape(id), body: body}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// EnsureGateway returns the caller's gateway in a region, created when there is none (one per
// member and region): the gateway for routes to workloads that run there — a project, or an
// environment in another region than the caller's other projects.
func (c *Client) EnsureGateway(ctx context.Context, regionID string) (*Gateway, error) {
	var out struct {
		Data Gateway `json:"data"`
	}
	body := map[string]any{"knative_region_id": regionID}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/gateways", body: body}, &out); err != nil {
		return nil, unsupported(err)
	}
	return &out.Data, nil
}

// GatewayRouteInput is a new route of a gateway: a path that leads to a project's own workload,
// or with Environment (a name or id) to one of its environments (docs/environments.md). The
// gateway must be in the region the workload runs in. AuthMode, JWT and GatewayOnly are its API
// product settings (empty / nil: a public route, its workload's own URL kept).
type GatewayRouteInput struct {
	ProjectID   string    `json:"project_id"`
	Environment string    `json:"environment,omitempty"`
	Path        string    `json:"path"`
	Methods     []string  `json:"methods,omitempty"`
	AuthMode    string    `json:"auth_mode,omitempty"`
	JWT         *RouteJWT `json:"jwt,omitempty"`
	GatewayOnly *bool     `json:"gateway_only,omitempty"`
}

// settings are the API product settings of the input (nil: none).
func (in GatewayRouteInput) settings() *RouteAuthInput {
	if in.AuthMode == "" && in.JWT == nil && in.GatewayOnly == nil {
		return nil
	}
	return &RouteAuthInput{AuthMode: in.AuthMode, JWT: in.JWT, GatewayOnly: in.GatewayOnly}
}

// AddGatewayRoute adds a route to a gateway with its authentication in the same call: a key or
// JWT route reaches the region with its check in front of it. The answer is the route as the
// gateway shows it (URL, JWT settings). An API before 0.6.0 creates every route public and its
// answer has no auth_mode: the settings follow right after (UpdateRouteAuth) — the route is
// public until then — and the URL is read from the gateway.
func (c *Client) AddGatewayRoute(ctx context.Context, gatewayID string, in GatewayRouteInput) (*GatewayRoute, error) {
	var out struct {
		Data GatewayRoute `json:"data"`
	}
	path := "/gateways/" + url.PathEscape(gatewayID) + "/routes"
	if err := c.do(ctx, request{method: http.MethodPost, path: path, body: in}, &out); err != nil {
		if in.Environment != "" {
			return nil, environmentErr(err, in.Environment)
		}
		return nil, err
	}
	route := &out.Data
	if route.AuthMode != "" {
		return route, nil
	}
	// an earlier API: the stored route, without its settings, URL and JWT
	if s := in.settings(); s != nil {
		if _, err := c.UpdateRouteAuth(ctx, gatewayID, route.ID, *s); err != nil {
			return route, fmt.Errorf("route %s was created public; setting its authentication failed (runr gateway routes set): %w", route.Path, err)
		}
	}
	if shown, err := c.gatewayRoute(ctx, gatewayID, route.ID); err == nil && shown != nil {
		return shown, nil
	}
	return route, nil
}

// gatewayRoute is the route as its gateway shows it (nil: not there).
func (c *Client) gatewayRoute(ctx context.Context, gatewayID, routeID string) (*GatewayRoute, error) {
	g, err := c.GetGateway(ctx, gatewayID)
	if err != nil {
		return nil, err
	}
	for i := range g.Routes {
		if g.Routes[i].ID == routeID {
			return &g.Routes[i], nil
		}
	}
	return nil, nil
}

// RemoveGatewayRoute removes a route; a project's own workload goes back to its own URL.
func (c *Client) RemoveGatewayRoute(ctx context.Context, gatewayID, routeID string) error {
	path := "/gateways/" + url.PathEscape(gatewayID) + "/routes/" + url.PathEscape(routeID)
	return c.do(ctx, request{method: http.MethodDelete, path: path}, nil)
}

// UpdateGatewayRoute changes the given fields of a route: "path", "methods" (nil: every
// method), "auth_mode", "jwt" (a *RouteJWT, nil: none) and "gateway_only". The answer is the
// route as the gateway shows it (URL, JWT settings); an API before 0.6.0 answers the stored
// route, without its URL and JWT settings (GetGateway has them). A path another route of the
// gateway has is a validation error (422).
func (c *Client) UpdateGatewayRoute(ctx context.Context, gatewayID, routeID string, fields map[string]any) (*GatewayRoute, error) {
	var out struct {
		Data GatewayRoute `json:"data"`
	}
	path := "/gateways/" + url.PathEscape(gatewayID) + "/routes/" + url.PathEscape(routeID)
	if err := c.do(ctx, request{method: http.MethodPatch, path: path, body: fields}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// UpdateRouteAuth sets a route's authentication and gateway-only setting.
func (c *Client) UpdateRouteAuth(ctx context.Context, gatewayID, routeID string, in RouteAuthInput) (*GatewayRoute, error) {
	var out struct {
		Data GatewayRoute `json:"data"`
	}
	path := "/gateways/" + url.PathEscape(gatewayID) + "/routes/" + url.PathEscape(routeID)
	if err := c.do(ctx, request{method: http.MethodPatch, path: path, body: in}, &out); err != nil {
		return nil, unsupported(err)
	}
	return &out.Data, nil
}

// SetGatewayDomain requests a custom domain; it waits in CustomDomainPending until verified.
func (c *Client) SetGatewayDomain(ctx context.Context, gatewayID, domain string) (*Gateway, error) {
	var out struct {
		Data Gateway `json:"data"`
	}
	body := map[string]string{"domain": domain}
	if err := c.do(ctx, request{method: http.MethodPut, path: "/gateways/" + url.PathEscape(gatewayID) + "/custom-domain", body: body}, &out); err != nil {
		return nil, unsupported(err)
	}
	return &out.Data, nil
}

// GatewayDomainError means the domain's DNS records were not found (yet).
type GatewayDomainError struct {
	Gateway *Gateway
	Message string
}

func (e *GatewayDomainError) Error() string { return "domain not verified: " + e.Message }

// VerifyGatewayDomain checks the pending domain's DNS; a *GatewayDomainError when the API
// answered 422.
func (c *Client) VerifyGatewayDomain(ctx context.Context, gatewayID string) (*Gateway, error) {
	var out struct {
		Data Gateway `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: "/gateways/" + url.PathEscape(gatewayID) + "/custom-domain/verify"}, &out)
	if err == nil {
		return &out.Data, nil
	}
	var ae *APIError
	if errors.As(err, &ae) && ae.StatusCode == http.StatusUnprocessableEntity {
		g, gerr := c.GetGateway(ctx, gatewayID)
		if gerr != nil {
			return nil, err
		}
		return g, &GatewayDomainError{Gateway: g, Message: ae.Message}
	}
	return nil, unsupported(err)
}

// RemoveGatewayDomain stops serving the gateway's custom domain.
func (c *Client) RemoveGatewayDomain(ctx context.Context, gatewayID string) (*Gateway, error) {
	var out struct {
		Data Gateway `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodDelete, path: "/gateways/" + url.PathEscape(gatewayID) + "/custom-domain"}, &out); err != nil {
		return nil, unsupported(err)
	}
	return &out.Data, nil
}

// ListAPIConsumers returns consumers (gatewayID: the gateway's and the organization's).
func (c *Client) ListAPIConsumers(ctx context.Context, gatewayID string) ([]APIConsumer, error) {
	q := url.Values{}
	if gatewayID != "" {
		q.Set("gateway_id", gatewayID)
	}
	var out struct {
		Data []APIConsumer `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/api-consumers", query: q}, &out); err != nil {
		return nil, unsupported(err)
	}
	return out.Data, nil
}

// GetAPIConsumer returns one consumer.
func (c *Client) GetAPIConsumer(ctx context.Context, id string) (*APIConsumer, error) {
	var out struct {
		Data APIConsumer `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/api-consumers/" + url.PathEscape(id)}, &out); err != nil {
		return nil, unsupported(err)
	}
	return &out.Data, nil
}

// CreateAPIConsumer creates a consumer (GatewayID empty: of the organization).
func (c *Client) CreateAPIConsumer(ctx context.Context, in APIConsumerInput) (*APIConsumer, error) {
	var out struct {
		Data APIConsumer `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/api-consumers", body: in}, &out); err != nil {
		return nil, unsupported(err)
	}
	return &out.Data, nil
}

// UpdateAPIConsumer changes the given fields of a consumer.
func (c *Client) UpdateAPIConsumer(ctx context.Context, id string, fields map[string]any) (*APIConsumer, error) {
	var out struct {
		Data APIConsumer `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPatch, path: "/api-consumers/" + url.PathEscape(id), body: fields}, &out); err != nil {
		return nil, unsupported(err)
	}
	return &out.Data, nil
}

// DeleteAPIConsumer deletes a consumer and its keys.
func (c *Client) DeleteAPIConsumer(ctx context.Context, id string) error {
	return unsupported(c.do(ctx, request{method: http.MethodDelete, path: "/api-consumers/" + url.PathEscape(id)}, nil))
}

// ListAPIKeys returns a consumer's keys (never the keys themselves).
func (c *Client) ListAPIKeys(ctx context.Context, consumerID string) ([]APIKey, error) {
	var out struct {
		Data []APIKey `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/api-consumers/" + url.PathEscape(consumerID) + "/keys"}, &out); err != nil {
		return nil, unsupported(err)
	}
	return out.Data, nil
}

// CreateAPIKey creates a key; the result's Key is the only copy of it.
func (c *Client) CreateAPIKey(ctx context.Context, consumerID string, in APIKeyInput) (*APIKey, error) {
	var out struct {
		Data APIKey `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/api-consumers/" + url.PathEscape(consumerID) + "/keys", body: in}, &out); err != nil {
		return nil, unsupported(err)
	}
	return &out.Data, nil
}

// UpdateAPIKey changes the given fields of a key: "name", "scopes" ([]KeyScope, nil: every
// route the consumer may call), "plan_id" and "expires_at" (nil: never). The key itself stays.
func (c *Client) UpdateAPIKey(ctx context.Context, id string, fields map[string]any) (*APIKey, error) {
	var out struct {
		Data APIKey `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPatch, path: "/api-keys/" + url.PathEscape(id), body: fields}, &out); err != nil {
		return nil, unsupported(err)
	}
	return &out.Data, nil
}

// RotateAPIKey replaces a key; the old one works on for overlapMinutes (nil: the API's default).
func (c *Client) RotateAPIKey(ctx context.Context, keyID string, overlapMinutes *int) (*APIKey, error) {
	body := map[string]any{}
	if overlapMinutes != nil {
		body["overlap_minutes"] = *overlapMinutes
	}
	var out struct {
		Data APIKey `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/api-keys/" + url.PathEscape(keyID) + "/rotate", body: body}, &out); err != nil {
		return nil, unsupported(err)
	}
	return &out.Data, nil
}

// RevokeAPIKey revokes a key.
func (c *Client) RevokeAPIKey(ctx context.Context, keyID string) (*APIKey, error) {
	var out struct {
		Data APIKey `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/api-keys/" + url.PathEscape(keyID) + "/revoke"}, &out); err != nil {
		return nil, unsupported(err)
	}
	return &out.Data, nil
}

// ListAPIPlans returns plans (gatewayID: the gateway's and the organization's).
func (c *Client) ListAPIPlans(ctx context.Context, gatewayID string) ([]APIPlan, error) {
	q := url.Values{}
	if gatewayID != "" {
		q.Set("gateway_id", gatewayID)
	}
	var out struct {
		Data []APIPlan `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/api-plans", query: q}, &out); err != nil {
		return nil, unsupported(err)
	}
	return out.Data, nil
}

// CreateAPIPlan creates a plan (GatewayID empty: of the organization).
func (c *Client) CreateAPIPlan(ctx context.Context, in APIPlanInput) (*APIPlan, error) {
	var out struct {
		Data APIPlan `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/api-plans", body: in}, &out); err != nil {
		return nil, unsupported(err)
	}
	return &out.Data, nil
}

// UpdateAPIPlan changes the given fields of a plan: "name", "description" and the limits
// ("rate_per_second", "burst", "rate_per_minute", "quota_per_day", "quota_per_month"; nil: no
// limit of that kind — without a per-second rate the API clears the burst too).
func (c *Client) UpdateAPIPlan(ctx context.Context, id string, fields map[string]any) (*APIPlan, error) {
	var out struct {
		Data APIPlan `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPatch, path: "/api-plans/" + url.PathEscape(id), body: fields}, &out); err != nil {
		return nil, unsupported(err)
	}
	return &out.Data, nil
}

// DeleteAPIPlan deletes a plan nobody uses.
func (c *Client) DeleteAPIPlan(ctx context.Context, id string) error {
	return unsupported(c.do(ctx, request{method: http.MethodDelete, path: "/api-plans/" + url.PathEscape(id)}, nil))
}

// APIUsage reads the usage.
func (c *Client) APIUsage(ctx context.Context, f APIUsageFilter) (*APIUsage, error) {
	var out APIUsage
	if err := c.do(ctx, request{method: http.MethodGet, path: "/api-usage", query: f.values()}, &out); err != nil {
		return nil, unsupported(err)
	}
	return &out, nil
}

// String labels a plan's limits: "10/s (burst 20), 1000/day".
func (p APIPlan) String() string {
	out := ""
	add := func(s string) {
		if out != "" {
			out += ", "
		}
		out += s
	}
	if p.RatePerSecond != nil {
		s := fmt.Sprintf("%d/s", *p.RatePerSecond)
		if p.Burst != nil && *p.Burst != *p.RatePerSecond {
			s += fmt.Sprintf(" (burst %d)", *p.Burst)
		}
		add(s)
	}
	if p.RatePerMinute != nil {
		add(fmt.Sprintf("%d/min", *p.RatePerMinute))
	}
	if p.QuotaPerDay != nil {
		add(fmt.Sprintf("%d/day", *p.QuotaPerDay))
	}
	if p.QuotaPerMonth != nil {
		add(fmt.Sprintf("%d/month", *p.QuotaPerMonth))
	}
	if out == "" {
		return "no limits"
	}
	return out
}
