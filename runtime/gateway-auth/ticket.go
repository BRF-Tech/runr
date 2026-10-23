package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"strings"
	"time"
)

// Tickets carry an allowed call from the forwardAuth check to the metering proxy (proxy.go).
//
// The check answers 200 with X-Runr-Gateway-Ticket when Traefik asks with ?meter=1 (a route whose
// IngressRoute sends its calls through the proxy); Traefik copies the header to the request it
// then hands to the proxy (authResponseHeaders, which also removes one a caller sent). The ticket
// names the route, gateway, organization, consumer, key and JWT subject the check decided on and
// when, signed with a key every pod of the region derives from its RunR token — the pod that
// checked and the pod that proxies need not be the same. The proxy serves nothing without a valid,
// fresh ticket: a pod of the namespace that calls the proxy directly can neither reach a route's
// function past its check nor count calls for a consumer.

// TicketHeader is the request header that carries a ticket from the check to the proxy.
const TicketHeader = "X-Runr-Gateway-Ticket"

// ticketTTL: a ticket is used within milliseconds; the margin covers slow pods and clock skew.
const ticketTTL = 60 * time.Second

// Ticket is what the check decided on one call.
type Ticket struct {
	Route    string `json:"r"`
	Gateway  string `json:"g"`
	Scope    string `json:"o"`
	Consumer string `json:"c,omitempty"`
	Key      string `json:"k,omitempty"`
	Subject  string `json:"s,omitempty"`
	Issued   int64  `json:"t"`
}

// Usage is the usage row of the call: the hour it was checked in.
func (t Ticket) Usage() UsageKey {
	return UsageKey{
		Scope: t.Scope, GatewayID: t.Gateway, RouteID: t.Route, ConsumerID: t.Consumer, KeyID: t.Key, Subject: t.Subject,
		Hour: time.Unix(t.Issued, 0).UTC().Truncate(time.Hour).Format(time.RFC3339),
	}
}

// TicketFor is the ticket of a call counted under usage at now.
func TicketFor(usage UsageKey, now time.Time) Ticket {
	return Ticket{
		Route: usage.RouteID, Gateway: usage.GatewayID, Scope: usage.Scope,
		Consumer: usage.ConsumerID, Key: usage.KeyID, Subject: usage.Subject, Issued: now.Unix(),
	}
}

// Tickets signs and verifies tickets.
type Tickets struct{ key []byte }

// NewTickets derives the signing key from the region's gateway-auth token (shared by its pods).
func NewTickets(token string) *Tickets {
	mac := hmac.New(sha256.New, []byte(token))
	mac.Write([]byte("runr-gateway-auth/ticket/v1"))
	return &Tickets{key: mac.Sum(nil)}
}

var (
	errTicketMissing   = errors.New("no ticket")
	errTicketMalformed = errors.New("malformed ticket")
	errTicketSignature = errors.New("ticket signature does not match")
	errTicketExpired   = errors.New("ticket expired")
)

func (t *Tickets) mac(payload string) string {
	m := hmac.New(sha256.New, t.key)
	m.Write([]byte("v1." + payload))
	return base64.RawURLEncoding.EncodeToString(m.Sum(nil))
}

// Sign returns v1.<payload>.<signature>.
func (t *Tickets) Sign(ticket Ticket) string {
	body, _ := json.Marshal(ticket)
	payload := base64.RawURLEncoding.EncodeToString(body)
	return "v1." + payload + "." + t.mac(payload)
}

// Verify checks the signature and the age of a ticket.
func (t *Tickets) Verify(value string, now time.Time) (Ticket, error) {
	var ticket Ticket
	if value == "" {
		return ticket, errTicketMissing
	}
	parts := strings.Split(value, ".")
	if len(parts) != 3 || parts[0] != "v1" || len(parts[1]) > 4096 {
		return ticket, errTicketMalformed
	}
	if !hmac.Equal([]byte(t.mac(parts[1])), []byte(parts[2])) {
		return ticket, errTicketSignature
	}
	body, err := base64.RawURLEncoding.DecodeString(parts[1])
	if err != nil || json.Unmarshal(body, &ticket) != nil || ticket.Route == "" || ticket.Gateway == "" {
		return Ticket{}, errTicketMalformed
	}
	issued := time.Unix(ticket.Issued, 0)
	if now.Sub(issued) > ticketTTL || issued.Sub(now) > 5*time.Second {
		return Ticket{}, errTicketExpired
	}
	return ticket, nil
}
