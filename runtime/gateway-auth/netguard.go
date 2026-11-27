package main

import (
	"errors"
	"net"
	"net/http"
	"net/netip"
	"syscall"
	"time"
)

// The outbound guard of the JWKS fetches: a JWT route's jwks_url is set by the gateway's owner, and
// this service runs inside the region's cluster. A copy of the region agent's guard
// (runtime/agent/internal/uptime/netguard.go, a module of its own) with the same test table.

// errPrivateTarget: a fetch would have connected to an address of the region's own network.
var errPrivateTarget = errors.New("the JWKS URL resolves to a private, loopback or cluster-internal address")

// The ranges of RunR's outbound guard (api: OutboundUrlGuard::isPublicIp), kept in step with it.
var (
	// IPv4 that is not public: this network, private, CGNAT, loopback, link-local (cloud
	// metadata 169.254.169.254), IETF protocol assignments, benchmarking, multicast, reserved.
	blockedIPv4 = prefixes("0.0.0.0/8", "10.0.0.0/8", "100.64.0.0/10", "127.0.0.0/8", "169.254.0.0/16",
		"172.16.0.0/12", "192.0.0.0/24", "192.168.0.0/16", "198.18.0.0/15", "224.0.0.0/4", "240.0.0.0/4")

	// IPv6: only global unicast is public (no loopback, unique local, link-local, site-local,
	// multicast, discard, NAT64 local-use …), and inside it not Teredo (its client address is
	// obfuscated), benchmarking, ORCHID or documentation.
	globalIPv6  = netip.MustParsePrefix("2000::/3")
	blockedIPv6 = prefixes("2001::/32", "2001:2::/48", "2001:10::/28", "2001:20::/28", "2001:db8::/32", "3fff::/20")

	ipv4Compatible = netip.MustParsePrefix("::/96")
	nat64WellKnown = netip.MustParsePrefix("64:ff9b::/96")
	sixToFour      = netip.MustParsePrefix("2002::/16")
)

func prefixes(cidrs ...string) []netip.Prefix {
	out := make([]netip.Prefix, len(cidrs))
	for i, c := range cidrs {
		out[i] = netip.MustParsePrefix(c)
	}
	return out
}

// IsPublicAddr reports whether a fetch may connect to ip when the route does not allow private
// targets. IPv4-mapped, IPv4-compatible, NAT64 (64:ff9b::/96) and 6to4 addresses are judged by
// the IPv4 address they carry; an address with a zone is link-local, so never public.
func IsPublicAddr(ip netip.Addr) bool {
	if !ip.IsValid() || ip.Zone() != "" {
		return false
	}
	if v4, ok := EmbeddedIPv4(ip); ok {
		ip = v4
	}
	if ip.Is4() {
		for _, p := range blockedIPv4 {
			if p.Contains(ip) {
				return false
			}
		}
		return true
	}
	if !globalIPv6.Contains(ip) {
		return false
	}
	for _, p := range blockedIPv6 {
		if p.Contains(ip) {
			return false
		}
	}
	return true
}

// EmbeddedIPv4 is the IPv4 address an IPv6 address stands for: IPv4-mapped (::ffff:a.b.c.d),
// IPv4-compatible (::a.b.c.d, :: and ::1 included), NAT64 well-known prefix (64:ff9b::a.b.c.d)
// and 6to4 (2002:aabb:ccdd::/48). A connection to one of them reaches that IPv4 address.
func EmbeddedIPv4(ip netip.Addr) (netip.Addr, bool) {
	if !ip.Is6() {
		return netip.Addr{}, false
	}
	if ip.Is4In6() {
		return ip.Unmap(), true
	}
	ip = ip.WithZone("")
	b := ip.As16()
	switch {
	case ipv4Compatible.Contains(ip), nat64WellKnown.Contains(ip):
		return netip.AddrFrom4([4]byte{b[12], b[13], b[14], b[15]}), true
	case sixToFour.Contains(ip):
		return netip.AddrFrom4([4]byte{b[2], b[3], b[4], b[5]}), true
	}
	return netip.Addr{}, false
}

// refusePrivate is the dialer's Control: it runs for every address the dialer connects to, after
// name resolution, so it judges the address actually dialed — a name cannot answer a public
// address to one lookup and an internal one to the next (DNS rebinding).
func refusePrivate(_, address string, _ syscall.RawConn) error {
	ap, err := netip.ParseAddrPort(address)
	if err != nil || !IsPublicAddr(ap.Addr()) {
		return errPrivateTarget
	}
	return nil
}

// newGuardedTransport is Go's default transport whose dialer refuses private addresses
// (refusePrivate), resolving names with resolver (nil: the system's). It uses no proxy —
// HTTP_PROXY / HTTPS_PROXY are ignored: through a proxy the dialer would judge the proxy's
// address, and the proxy would connect to the target unchecked.
func newGuardedTransport(resolver *net.Resolver) *http.Transport {
	t := http.DefaultTransport.(*http.Transport).Clone()
	t.Proxy = nil
	t.DialContext = (&net.Dialer{Timeout: 10 * time.Second, KeepAlive: 30 * time.Second, Resolver: resolver, Control: refusePrivate}).DialContext
	return t
}

// newGuardedClient fetches JWKS of routes that do not allow private targets: the guarded
// transport, no redirects (a redirect would lead to a URL nobody checked).
func newGuardedClient(resolver *net.Resolver) *http.Client {
	return &http.Client{
		Timeout:       10 * time.Second,
		Transport:     newGuardedTransport(resolver),
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
}
