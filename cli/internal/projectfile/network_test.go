package projectfile

import (
	"strings"
	"testing"
)

func TestNetworkSection(t *testing.T) {
	f, err := Parse([]byte(`
name: orders
type: faas
runtime: python
network:
  access: Allowlist
  allow_cidrs: [203.0.113.0/24, 198.51.100.7]
  callers: [shop, billing.acme]
`))
	if err != nil {
		t.Fatal(err)
	}
	if f.Network.Access != "allowlist" || len(f.Network.AllowCIDRs) != 2 || f.Network.Callers[1] != "billing.acme" {
		t.Fatalf("network: %+v", f.Network)
	}
}

func TestNetworkSectionRejects(t *testing.T) {
	cases := map[string]string{
		"network:\n  access: private\n":                               "unknown network.access",
		"network:\n  access: allowlist\n":                             "needs network.allow_cidrs",
		"network:\n  access: internal\n  allow_cidrs: [10.0.0.0/8]\n": "applies to network.access allowlist",
		"network:\n  access: allowlist\n  allow_cidrs: [not-an-ip]\n": "no IP address or CIDR range",
		"network:\n  callers: [\"shop.acme.extra\"]\n":                "no project name or <project>.<organization>",
		"network:\n  callers: [\"-shop\"]\n":                          "no project name",
		"network:\n  unknown: true\n":                                 "unknown",
	}
	for yaml, want := range cases {
		_, err := Parse([]byte("name: orders\ntype: faas\nruntime: python\n" + yaml))
		if err == nil || !strings.Contains(err.Error(), want) {
			t.Errorf("%q: %v, want %q", yaml, err, want)
		}
	}
}
