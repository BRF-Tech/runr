package main

import (
	"strings"
	"testing"
	"time"
)

func TestTicketRoundTrip(t *testing.T) {
	tickets := NewTickets("region-token")
	now := time.Date(2026, 10, 22, 10, 59, 59, 0, time.UTC)
	usage := UsageKey{Scope: "_", GatewayID: gwA, RouteID: routeK, ConsumerID: "c-org", KeyID: "k-org", Hour: "2026-10-22T10:00:00Z"}

	value := tickets.Sign(TicketFor(usage, now))
	got, err := tickets.Verify(value, now.Add(2*time.Second))
	if err != nil {
		t.Fatal(err)
	}
	// counted in the hour of the check, also when the answer comes in the next one
	if got.Usage() != usage {
		t.Errorf("usage %+v, want %+v", got.Usage(), usage)
	}
}

func TestTicketsOfAnotherRegionOrTamperedAreRefused(t *testing.T) {
	now := time.Date(2026, 10, 22, 10, 0, 0, 0, time.UTC)
	value := NewTickets("region-token").Sign(Ticket{Route: routeK, Gateway: gwA, Scope: "_", Consumer: "c-org", Issued: now.Unix()})

	if _, err := NewTickets("other-token").Verify(value, now); err != errTicketSignature {
		t.Errorf("another key: %v", err)
	}
	parts := strings.Split(value, ".")
	forged := NewTickets("region-token").Sign(Ticket{Route: routeK, Gateway: gwA, Scope: "_", Consumer: "c-gw", Issued: now.Unix()})
	if _, err := NewTickets("region-token").Verify(parts[0]+"."+strings.Split(forged, ".")[1]+"."+parts[2], now); err != errTicketSignature {
		t.Errorf("payload swapped: %v", err)
	}
	for _, bad := range []string{"", "v1.abc", "v2." + parts[1] + "." + parts[2], "garbage"} {
		if _, err := NewTickets("region-token").Verify(bad, now); err == nil {
			t.Errorf("%q verified", bad)
		}
	}
}

func TestTicketsExpire(t *testing.T) {
	tickets := NewTickets("region-token")
	now := time.Date(2026, 10, 22, 10, 0, 0, 0, time.UTC)
	value := tickets.Sign(Ticket{Route: routeK, Gateway: gwA, Scope: "_", Issued: now.Unix()})

	if _, err := tickets.Verify(value, now.Add(61*time.Second)); err != errTicketExpired {
		t.Errorf("old ticket: %v", err)
	}
	if _, err := tickets.Verify(value, now.Add(-10*time.Second)); err != errTicketExpired {
		t.Errorf("ticket from the future: %v", err)
	}
}
