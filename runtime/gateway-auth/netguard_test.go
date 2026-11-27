package main

import (
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"net"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"net/url"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
)

func TestIsPublicAddr(t *testing.T) {
	blocked := []string{
		// IPv4
		"0.0.0.0", "0.1.2.3", "10.0.0.1", "100.64.0.1", "100.127.255.255", "127.0.0.1", "169.254.169.254",
		"172.16.0.1", "172.31.255.255", "192.0.0.170", "192.168.1.1", "198.18.0.1", "224.0.0.1", "240.0.0.1",
		"255.255.255.255",
		// IPv6
		"::", "::1", "fc00::1", "fd00::1", "fe80::1", "fe80::1%eth0", "fec0::1", "ff02::1", "100::1",
		"64:ff9b:1::a9fe:a9fe", "2001::1", "2001:0:4136:e378:8000:63bf:3fff:fdd2", "2001:2::1", "2001:10::1",
		"2001:db8::1", "3fff::1", "::ffff:0:a9fe:a9fe",
		// transition forms carrying a private, loopback or link-local IPv4 address
		"::ffff:127.0.0.1", "::ffff:169.254.169.254", "::ffff:10.0.0.1", "::a9fe:a9fe", "::7f00:1",
		"2002:a9fe:a9fe::", "2002:7f00:1::1", "2002:0a00:0001::", "64:ff9b::a9fe:a9fe", "64:ff9b::7f00:1",
		"64:ff9b::10.0.0.1",
	}
	public := []string{
		"8.8.8.8", "1.1.1.1", "100.128.0.1", "93.184.216.34", "2606:4700:4700::1111", "2a00:1450:4001::200e",
		"::ffff:8.8.8.8", "2002:0808:0808::", "64:ff9b::808:808", "::808:808",
	}
	for _, s := range blocked {
		if IsPublicAddr(netip.MustParseAddr(s)) {
			t.Errorf("%s counts as public", s)
		}
	}
	for _, s := range public {
		if !IsPublicAddr(netip.MustParseAddr(s)) {
			t.Errorf("%s counts as private", s)
		}
	}
	if IsPublicAddr(netip.Addr{}) {
		t.Error("the zero address counts as public")
	}
}

func TestEmbeddedIPv4(t *testing.T) {
	cases := map[string]string{
		"::ffff:169.254.169.254": "169.254.169.254",
		"::a9fe:a9fe":            "169.254.169.254",
		"64:ff9b::a9fe:a9fe":     "169.254.169.254",
		"2002:a9fe:a9fe:1::1":    "169.254.169.254",
		"2002:0808:0808::":       "8.8.8.8",
	}
	for in, want := range cases {
		got, ok := EmbeddedIPv4(netip.MustParseAddr(in))
		if !ok || got.String() != want {
			t.Errorf("EmbeddedIPv4(%s) = %s, %t; want %s", in, got, ok, want)
		}
	}
	for _, in := range []string{"2606:4700:4700::1111", "64:ff9b:1::a9fe:a9fe", "8.8.8.8"} {
		if got, ok := EmbeddedIPv4(netip.MustParseAddr(in)); ok {
			t.Errorf("EmbeddedIPv4(%s) = %s, want none", in, got)
		}
	}
}

func TestDialControlJudgesTheDialedAddress(t *testing.T) {
	for _, addr := range []string{"127.0.0.1:80", "169.254.169.254:80", "[::1]:443", "[::ffff:127.0.0.1]:80", "[64:ff9b::a9fe:a9fe]:80",
		"[2002:a9fe:a9fe::]:80", "[::a9fe:a9fe]:80", "[fec0::1]:80", "[fe80::1%eth0]:80", "not-an-address"} {
		if err := refusePrivate("tcp", addr, nil); err != errPrivateTarget {
			t.Errorf("dial %s: %v, want refused", addr, err)
		}
	}
	for _, addr := range []string{"8.8.8.8:443", "[2606:4700:4700::1111]:443", "[::ffff:8.8.8.8]:443", "[64:ff9b::808:808]:80"} {
		if err := refusePrivate("tcp", addr, nil); err != nil {
			t.Errorf("dial %s refused: %v", addr, err)
		}
	}
}

// countingServer is a target on the loopback interface that counts the requests reaching it.
func countingServer(t *testing.T) (*httptest.Server, *atomic.Int32) {
	var hits atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits.Add(1)
		_, _ = w.Write([]byte("internal secret"))
	}))
	t.Cleanup(srv.Close)
	return srv, &hits
}

// rebindingDNS answers A queries for every name with public first, with private afterwards — a
// name an attacker controls, answering a public address to a check and an internal one to the
// connection. AAAA queries get no answer.
type rebindingDNS struct {
	conn    net.PacketConn
	public  [4]byte
	private [4]byte
	mu      sync.Mutex
	answers int
}

func newRebindingDNS(t *testing.T, public, private string) *rebindingDNS {
	conn, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	d := &rebindingDNS{conn: conn, public: netip.MustParseAddr(public).As4(), private: netip.MustParseAddr(private).As4()}
	t.Cleanup(func() { conn.Close() })
	go d.serve()
	return d
}

func (d *rebindingDNS) serve() {
	buf := make([]byte, 512)
	for {
		n, from, err := d.conn.ReadFrom(buf)
		if err != nil {
			return
		}
		if n < 12 {
			continue
		}
		q := buf[:n]
		// the question: name labels, then type and class
		end := 12
		for end < n && q[end] != 0 {
			end += int(q[end]) + 1
		}
		end++
		if end+4 > n {
			continue
		}
		qtype := binary.BigEndian.Uint16(q[end : end+2])
		question := q[12 : end+4]

		resp := make([]byte, 12, 64)
		copy(resp, q[:2])                            // id
		binary.BigEndian.PutUint16(resp[2:], 0x8180) // response, recursion desired + available, no error
		binary.BigEndian.PutUint16(resp[4:], 1)      // one question
		resp = append(resp, question...)
		if qtype == 1 { // A
			d.mu.Lock()
			ip := d.public
			if d.answers > 0 {
				ip = d.private
			}
			d.answers++
			d.mu.Unlock()
			binary.BigEndian.PutUint16(resp[6:], 1) // one answer
			resp = append(resp, 0xc0, 0x0c, 0, 1, 0, 1, 0, 0, 0, 0, 0, 4)
			resp = append(resp, ip[:]...)
		}
		_, _ = d.conn.WriteTo(resp, from)
	}
}

func (d *rebindingDNS) count() int {
	d.mu.Lock()
	defer d.mu.Unlock()
	return d.answers
}

func (d *rebindingDNS) resolver() *net.Resolver {
	return &net.Resolver{PreferGo: true, Dial: func(ctx context.Context, _, _ string) (net.Conn, error) {
		return (&net.Dialer{}).DialContext(ctx, "udp", d.conn.LocalAddr().String())
	}}
}

// A JWT route whose JWKS URL names a private address: the fetch is refused when it connects, and
// nothing reaches the address — literal, by name, or in an IPv6 form carrying it.
func TestJWKSOfAPrivateAddressIsRefused(t *testing.T) {
	srv, hits := countingServer(t)
	port := srv.URL[strings.LastIndex(srv.URL, ":")+1:]
	cache := NewJWKSCache(srv.Client())

	for _, u := range []string{srv.URL + "/jwks", "http://localhost:" + port + "/jwks", "http://[::ffff:127.0.0.1]:" + port + "/jwks"} {
		_, err := cache.fetch(context.Background(), u, false)
		if !errors.Is(err, errPrivateTarget) {
			t.Errorf("%s: %v, want refused", u, err)
		}
	}
	if n := hits.Load(); n != 0 {
		t.Errorf("%d request(s) reached the private address", n)
	}

	// The platform allows private targets: the route's own client, as before
	if _, err := cache.fetch(context.Background(), srv.URL+"/jwks", true); err == nil || errors.Is(err, errPrivateTarget) {
		t.Errorf("allowed private JWKS: %v, want a fetch (and a JWKS error)", err)
	}
	if n := hits.Load(); n != 1 {
		t.Errorf("requests reaching the allowed private JWKS: %d, want 1", n)
	}
}

func TestJWKSFetchIsRebindingProof(t *testing.T) {
	srv, hits := countingServer(t)
	port := srv.URL[strings.LastIndex(srv.URL, ":")+1:]
	dns := newRebindingDNS(t, "93.184.216.34", "127.0.0.1")
	cache := NewJWKSCache(srv.Client())
	cache.Guarded = newGuardedClient(dns.resolver())

	addrs, err := dns.resolver().LookupHost(context.Background(), "idp.example.com")
	if err != nil || len(addrs) != 1 || addrs[0] != "93.184.216.34" {
		t.Fatalf("first answer: %v %v", addrs, err)
	}
	if _, err := cache.fetch(context.Background(), "http://idp.example.com:"+port+"/jwks", false); !errors.Is(err, errPrivateTarget) {
		t.Errorf("rebinding name: %v, want refused", err)
	}
	if hits.Load() != 0 {
		t.Error("the request reached the private address")
	}
	if n := dns.count(); n < 2 {
		t.Errorf("the fetch did not resolve the name itself (%d answers)", n)
	}
}

// A snapshot of a RunR that does not send allow_private_targets: the route's JWKS is guarded.
func TestJWTRoutesWithoutTheFieldAreGuarded(t *testing.T) {
	var cfg JWTConfig
	if err := json.Unmarshal([]byte(`{"jwks_url":"https://idp.example.com/jwks"}`), &cfg); err != nil {
		t.Fatal(err)
	}
	if cfg.AllowPrivateTargets {
		t.Fatal("a route without allow_private_targets allows private targets")
	}
}

func TestGuardedClientUsesNoProxyAndFollowsNoRedirect(t *testing.T) {
	t.Setenv("HTTP_PROXY", "http://proxy.example.com:3128")
	t.Setenv("HTTPS_PROXY", "http://proxy.example.com:3128")
	c := newGuardedClient(nil)
	tr := c.Transport.(*http.Transport)
	if tr.Proxy != nil {
		req := &http.Request{URL: &url.URL{Scheme: "https", Host: "example.com"}}
		if p, _ := tr.Proxy(req); p != nil {
			t.Errorf("guarded fetches go through %s", p)
		}
	}
	if c.CheckRedirect == nil || c.CheckRedirect(nil, nil) != http.ErrUseLastResponse {
		t.Error("the guarded client follows redirects")
	}
}
