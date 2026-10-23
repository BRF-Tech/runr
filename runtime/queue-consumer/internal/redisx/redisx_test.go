package redisx

import (
	"crypto/tls"
	"testing"
)

func TestNewClientStripsURLParts(t *testing.T) {
	cases := map[string]string{
		"redis:6379":                      "redis:6379",
		"redis://redis.runr:6379":         "redis.runr:6379",
		"rediss://cache.example:6380":     "cache.example:6380",
		"redis://:s3cret@10.0.0.5:6379":   "10.0.0.5:6379",
		"redis://user:p@ss@10.0.0.5:6379": "10.0.0.5:6379",
	}
	for in, want := range cases {
		rdb := NewClient(in, "pw")
		if got := rdb.Options().Addr; got != want {
			t.Errorf("NewClient(%q).Addr = %q, want %q", in, got, want)
		}
		if rdb.Options().Password != "pw" {
			t.Errorf("password argument not used for %q", in)
		}
		rdb.Close()
	}
}

func TestNewClientUsesTLSForRediss(t *testing.T) {
	rdb := NewClient("rediss://user:pw@cache.example:6380", "")
	defer rdb.Close()
	tlsCfg := rdb.Options().TLSConfig
	if tlsCfg == nil {
		t.Fatal("rediss:// connected without TLS")
	}
	if tlsCfg.ServerName != "cache.example" {
		t.Errorf("TLS ServerName = %q, want the address host", tlsCfg.ServerName)
	}
	if tlsCfg.InsecureSkipVerify {
		t.Error("the server certificate is not verified")
	}
	if tlsCfg.MinVersion < tls.VersionTLS12 {
		t.Errorf("TLS MinVersion = %#x, want at least TLS 1.2", tlsCfg.MinVersion)
	}

	for _, in := range []string{"redis://redis.runr:6379", "redis:6379"} {
		plain := NewClient(in, "")
		if plain.Options().TLSConfig != nil {
			t.Errorf("NewClient(%q) uses TLS", in)
		}
		plain.Close()
	}
}

func TestSameServer(t *testing.T) {
	same := [][2]string{
		{"redis:6379", "redis://redis:6379"},
		{"redis://:pw@10.0.0.5:6379", "10.0.0.5:6379"},
		{"rediss://Cache.example:6380", "rediss://user:x@cache.example:6380"},
	}
	for _, c := range same {
		if !SameServer(c[0], c[1]) {
			t.Errorf("SameServer(%q, %q) = false", c[0], c[1])
		}
	}
	differ := [][2]string{
		{"redis:6379", "redis:6380"},
		{"redis://cache:6380", "rediss://cache:6380"},
		{"", ""},
		{"a:6379", "b:6379"},
	}
	for _, c := range differ {
		if SameServer(c[0], c[1]) {
			t.Errorf("SameServer(%q, %q) = true", c[0], c[1])
		}
	}
}
