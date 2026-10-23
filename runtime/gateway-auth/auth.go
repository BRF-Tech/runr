package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"log/slog"
	"net/http"
	"strconv"
	"strings"
	"time"
)

// GET /auth/<route id> — the Traefik forwardAuth address of a key / JWT route
// (App\Services\Manifests\GatewayAuthManifests). Traefik sends the caller's Authorization and
// X-Api-Key headers and X-Forwarded-Method / -Uri / -Host; a 200 lets the call through with
// X-Runr-Consumer, X-Runr-Consumer-External, X-Runr-Key and X-Runr-Subject copied to the upstream
// request, any other answer goes back to the caller as it is:
//
//	401  no key / token, unknown or expired key, invalid token     (WWW-Authenticate: Bearer)
//	403  key of another gateway, route or method outside the key's scopes, consumer suspended,
//	     JWT subject that is no consumer on a route that requires one
//	429  a plan limit (RateLimit-Limit / -Remaining / -Reset / -Policy, Retry-After)
//	503  no configuration yet, or the route is not known yet (Retry-After)
//
// With ?meter=1 (a route whose calls go through the metering proxy, proxy.go) a 200 also carries
// X-Runr-Gateway-Ticket: what was decided, signed for the proxy (ticket.go).
//
// The key is never logged nor echoed; only its SHA-256 hash is looked up.

// Authorizer answers the forwardAuth checks.
type Authorizer struct {
	Snapshots interface{ Snapshot() *Snapshot }
	Limiter   Limiter
	JWKS      *JWKSCache
	Usage     *Recorder
	Logger    *slog.Logger
	Now       func() time.Time
	// Tickets signs the ticket of an allowed call of a metered route (nil: none is issued)
	Tickets *Tickets
}

func (a *Authorizer) now() time.Time {
	if a.Now != nil {
		return a.Now()
	}
	return time.Now()
}

type denial struct {
	Status  int
	Code    string
	Message string
}

func (a *Authorizer) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	routeID := strings.Trim(strings.TrimPrefix(r.URL.Path, "/auth/"), "/")
	snap := a.Snapshots.Snapshot()
	if snap == nil {
		unavailable(w, "not_ready", "The gateway is starting; try again in a moment.")
		return
	}
	route := snap.Route(routeID)
	if route == nil {
		unavailable(w, "route_unknown", "The gateway is updating this route; try again in a moment.")
		return
	}

	method := r.Header.Get("X-Forwarded-Method")
	if method == "" {
		method = r.Method
	}
	now := a.now()
	usage := UsageKey{Scope: route.Scope, GatewayID: route.GatewayID, RouteID: route.ID, Hour: now.UTC().Truncate(time.Hour).Format(time.RFC3339)}

	switch route.Mode {
	case "key":
		a.checkKey(r.Context(), w, r, snap, route, method, usage, now)
	case "jwt":
		a.checkJWT(r.Context(), w, r, snap, route, usage, now)
	default:
		w.WriteHeader(http.StatusOK)
	}
}

// allow lets the call through: counted, the consumer's headers for the function and, for a
// metered route, the ticket for the proxy.
func (a *Authorizer) allow(w http.ResponseWriter, r *http.Request, usage UsageKey, c *Consumer, keyID, subject string, now time.Time) {
	a.Usage.Add(usage, CounterRequests)
	setUpstream(w, c, keyID, subject)
	if a.Tickets != nil && r.URL.Query().Get("meter") == "1" {
		w.Header().Set(TicketHeader, a.Tickets.Sign(TicketFor(usage, now)))
	}
	w.WriteHeader(http.StatusOK)
}

func (a *Authorizer) checkKey(ctx context.Context, w http.ResponseWriter, r *http.Request, snap *Snapshot, route *RouteConfig, method string, usage UsageKey, now time.Time) {
	key := extractKey(r)
	if key == "" {
		a.deny(w, usage, CounterUnauthorized, denial{http.StatusUnauthorized, "missing_key", "An API key is required: send it as X-Api-Key or Authorization: Bearer."})
		return
	}
	sum := sha256.Sum256([]byte(key))
	k := snap.KeyByHash(hex.EncodeToString(sum[:]))
	var c *Consumer
	if k != nil {
		c = snap.Consumer(k.ConsumerID)
	}
	// a key of another organization is unknown here
	if k == nil || c == nil || c.Scope != route.Scope {
		a.deny(w, usage, CounterUnauthorized, denial{http.StatusUnauthorized, "invalid_key", "The API key is not valid."})
		return
	}
	usage.ConsumerID, usage.KeyID = c.ID, k.ID
	if k.ExpiresAt != nil && now.Unix() >= *k.ExpiresAt {
		a.deny(w, usage, CounterUnauthorized, denial{http.StatusUnauthorized, "key_expired", "The API key has expired."})
		return
	}
	if c.GatewayID != nil && *c.GatewayID != route.GatewayID {
		a.deny(w, usage, CounterForbidden, denial{http.StatusForbidden, "wrong_gateway", "The API key is not valid for this API."})
		return
	}
	if c.Suspended {
		a.deny(w, usage, CounterForbidden, denial{http.StatusForbidden, "consumer_suspended", "Access of this consumer is suspended."})
		return
	}
	if k.Routes != nil {
		methods, ok := k.Routes[route.ID]
		if !ok {
			a.deny(w, usage, CounterForbidden, denial{http.StatusForbidden, "route_not_allowed", "The API key does not open this route."})
			return
		}
		if methods != nil && !containsFold(methods, method) {
			a.deny(w, usage, CounterForbidden, denial{http.StatusForbidden, "method_not_allowed", "The API key does not allow " + method + " on this route."})
			return
		}
	}

	subject, plan := "c:"+c.ID, snap.Plan(c.PlanID)
	if k.PlanID != nil {
		subject, plan = "k:"+k.ID, snap.Plan(k.PlanID)
	}
	if !a.limit(ctx, w, usage, subject, plan, now) {
		return
	}

	a.allow(w, r, usage, c, k.ID, "", now)
}

func (a *Authorizer) checkJWT(ctx context.Context, w http.ResponseWriter, r *http.Request, snap *Snapshot, route *RouteConfig, usage UsageKey, now time.Time) {
	token := bearer(r)
	if token == "" || route.JWT == nil {
		a.deny(w, usage, CounterUnauthorized, denial{http.StatusUnauthorized, "missing_token", "A bearer token is required."})
		return
	}
	claims, err := a.JWKS.Verify(ctx, token, route.JWT, now)
	if err != nil {
		if a.Logger != nil {
			a.Logger.Debug("token refused", "route", route.ID, "reason", err.Error())
		}
		a.deny(w, usage, CounterUnauthorized, denial{http.StatusUnauthorized, "invalid_token", "The token is not valid."})
		return
	}

	claim := route.JWT.SubjectClaim
	if claim == "" {
		claim = "sub"
	}
	subject := sanitize(claimString(claims, claim))
	c := (*Consumer)(nil)
	if subject != "" {
		c = snap.ConsumerBySubject(route, subject)
	}
	if c == nil {
		usage.Subject = subject
		if route.JWT.RequireConsumer {
			a.deny(w, usage, CounterForbidden, denial{http.StatusForbidden, "unknown_subject", "The token's subject is no consumer of this API."})
			return
		}
		a.allow(w, r, usage, nil, "", subject, now)
		return
	}

	usage.ConsumerID = c.ID
	if c.Suspended {
		a.deny(w, usage, CounterForbidden, denial{http.StatusForbidden, "consumer_suspended", "Access of this consumer is suspended."})
		return
	}
	if !a.limit(ctx, w, usage, "c:"+c.ID, snap.Plan(c.PlanID), now) {
		return
	}
	a.allow(w, r, usage, c, "", subject, now)
}

// limit applies the plan; false when the call was refused (the answer is written).
func (a *Authorizer) limit(ctx context.Context, w http.ResponseWriter, usage UsageKey, subject string, plan *Plan, now time.Time) bool {
	if !plan.Limited() {
		return true
	}
	d, err := a.Limiter.Allow(ctx, subject, plan, now)
	if err != nil || d.Allowed {
		return true
	}

	retry := strconv.FormatInt(d.RetryAfter(), 10)
	h := w.Header()
	h.Set("RateLimit-Limit", strconv.FormatInt(d.Limit, 10))
	h.Set("RateLimit-Remaining", "0")
	h.Set("RateLimit-Reset", retry)
	h.Set("RateLimit-Policy", strconv.FormatInt(d.Limit, 10)+";w="+strconv.FormatInt(d.Window, 10))
	h.Set("Retry-After", retry)

	counter, message := CounterRateLimited, "Too many requests: the plan's rate limit is reached."
	if d.Reason == ReasonQuotaExceeded {
		counter, message = CounterQuotaExceeded, "The plan's quota is used up."
	}
	a.deny(w, usage, counter, denial{http.StatusTooManyRequests, d.Reason, message})
	return false
}

func (a *Authorizer) deny(w http.ResponseWriter, usage UsageKey, counter string, d denial) {
	a.Usage.Add(usage, counter)
	if d.Status == http.StatusUnauthorized {
		w.Header().Set("WWW-Authenticate", `Bearer realm="runr"`)
	}
	writeJSON(w, d.Status, map[string]string{"error": d.Code, "message": d.Message})
}

func unavailable(w http.ResponseWriter, code, message string) {
	w.Header().Set("Retry-After", "5")
	writeJSON(w, http.StatusServiceUnavailable, map[string]string{"error": code, "message": message})
}

func writeJSON(w http.ResponseWriter, status int, body any) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(body)
}

// setUpstream sets the headers Traefik copies to the upstream request (authResponseHeaders).
func setUpstream(w http.ResponseWriter, c *Consumer, keyID, subject string) {
	h := w.Header()
	if c != nil {
		h.Set("X-Runr-Consumer", c.ID)
		if c.ExternalID != nil && *c.ExternalID != "" {
			h.Set("X-Runr-Consumer-External", sanitize(*c.ExternalID))
		}
	}
	if keyID != "" {
		h.Set("X-Runr-Key", keyID)
	}
	if subject != "" {
		h.Set("X-Runr-Subject", subject)
	}
}

// extractKey: X-Api-Key, else the bearer token of Authorization.
func extractKey(r *http.Request) string {
	if k := strings.TrimSpace(r.Header.Get("X-Api-Key")); k != "" {
		return k
	}
	return bearer(r)
}

func bearer(r *http.Request) string {
	h := strings.TrimSpace(r.Header.Get("Authorization"))
	if len(h) > 7 && strings.EqualFold(h[:7], "bearer ") {
		return strings.TrimSpace(h[7:])
	}
	return ""
}

func containsFold(list []string, v string) bool {
	for _, s := range list {
		if strings.EqualFold(s, v) {
			return true
		}
	}
	return false
}

// sanitize keeps a claim value fit for a header: printable ASCII, at most 191 characters.
func sanitize(v string) string {
	var b strings.Builder
	for _, r := range v {
		if r >= 0x20 && r < 0x7f {
			b.WriteRune(r)
		}
		if b.Len() >= 191 {
			break
		}
	}
	return b.String()
}
