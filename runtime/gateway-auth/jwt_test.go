package main

import (
	"context"
	"crypto"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/rsa"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"math/big"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"
)

var b64 = base64.RawURLEncoding

func rsaJWK(kid string, pub *rsa.PublicKey) map[string]any {
	return map[string]any{
		"kty": "RSA", "kid": kid, "alg": "RS256", "use": "sig",
		"n": b64.EncodeToString(pub.N.Bytes()),
		"e": b64.EncodeToString(big.NewInt(int64(pub.E)).Bytes()),
	}
}

func ecJWK(kid string, pub *ecdsa.PublicKey) map[string]any {
	raw, _ := pub.Bytes() // 0x04 || x || y
	size := (len(raw) - 1) / 2
	return map[string]any{
		"kty": "EC", "kid": kid, "alg": "ES256", "crv": "P-256",
		"x": b64.EncodeToString(raw[1 : 1+size]), "y": b64.EncodeToString(raw[1+size:]),
	}
}

func signRS256(t *testing.T, key *rsa.PrivateKey, kid string, claims map[string]any) string {
	t.Helper()
	input := segment(map[string]any{"alg": "RS256", "typ": "JWT", "kid": kid}) + "." + segment(claims)
	digest := sha256.Sum256([]byte(input))
	sig, err := rsa.SignPKCS1v15(rand.Reader, key, crypto.SHA256, digest[:])
	if err != nil {
		t.Fatal(err)
	}
	return input + "." + b64.EncodeToString(sig)
}

func signES256(t *testing.T, key *ecdsa.PrivateKey, kid string, claims map[string]any) string {
	t.Helper()
	input := segment(map[string]any{"alg": "ES256", "kid": kid}) + "." + segment(claims)
	digest := sha256.Sum256([]byte(input))
	r, s, err := ecdsa.Sign(rand.Reader, key, digest[:])
	if err != nil {
		t.Fatal(err)
	}
	sig := make([]byte, 64)
	r.FillBytes(sig[:32])
	s.FillBytes(sig[32:])
	return input + "." + b64.EncodeToString(sig)
}

func segment(v any) string {
	raw, _ := json.Marshal(v)
	return b64.EncodeToString(raw)
}

// jwksServer serves the given keys; the returned counter counts fetches.
func jwksServer(t *testing.T, keys *atomic.Value) (*httptest.Server, *atomic.Int32) {
	t.Helper()
	var fetches atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		fetches.Add(1)
		_ = json.NewEncoder(w).Encode(map[string]any{"keys": keys.Load()})
	}))
	t.Cleanup(srv.Close)
	return srv, &fetches
}

func str(s string) *string { return &s }

func TestJWTRoundTrip(t *testing.T) {
	rsaKey, _ := rsa.GenerateKey(rand.Reader, 2048)
	ecKey, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	var keys atomic.Value
	keys.Store([]map[string]any{rsaJWK("r1", &rsaKey.PublicKey), ecJWK("e1", &ecKey.PublicKey)})
	srv, _ := jwksServer(t, &keys)

	cache := NewJWKSCache(srv.Client())
	cfg := &JWTConfig{JWKSURL: srv.URL, Issuer: str("https://issuer.test"), Audience: str("orders")}
	now := time.Unix(1_800_000_000, 0)
	good := map[string]any{"iss": "https://issuer.test", "aud": "orders", "sub": "partner-1", "exp": now.Unix() + 300}

	for name, token := range map[string]string{
		"RS256": signRS256(t, rsaKey, "r1", good),
		"ES256": signES256(t, ecKey, "e1", good),
	} {
		claims, err := cache.Verify(context.Background(), token, cfg, now)
		if err != nil {
			t.Fatalf("%s: %v", name, err)
		}
		if claimString(claims, "sub") != "partner-1" {
			t.Errorf("%s: sub %v", name, claims["sub"])
		}
	}

	// aud as a list
	listAud := map[string]any{"iss": "https://issuer.test", "aud": []any{"billing", "orders"}, "sub": "x", "exp": now.Unix() + 60}
	if _, err := cache.Verify(context.Background(), signRS256(t, rsaKey, "r1", listAud), cfg, now); err != nil {
		t.Errorf("aud list: %v", err)
	}
}

func TestJWTRefusals(t *testing.T) {
	key, _ := rsa.GenerateKey(rand.Reader, 2048)
	other, _ := rsa.GenerateKey(rand.Reader, 2048)
	var keys atomic.Value
	keys.Store([]map[string]any{rsaJWK("k", &key.PublicKey)})
	srv, _ := jwksServer(t, &keys)
	cache := NewJWKSCache(srv.Client())
	cfg := &JWTConfig{JWKSURL: srv.URL, Issuer: str("iss"), Audience: str("aud")}
	now := time.Unix(1_800_000_000, 0)
	base := func() map[string]any {
		return map[string]any{"iss": "iss", "aud": "aud", "sub": "s", "exp": now.Unix() + 60}
	}
	with := func(k string, v any) map[string]any { c := base(); c[k] = v; return c }

	unsigned := segment(map[string]any{"alg": "none"}) + "." + segment(base()) + "."
	hmac := segment(map[string]any{"alg": "HS256", "kid": "k"}) + "." + segment(base()) + "." + b64.EncodeToString([]byte("sig"))

	cases := map[string]struct {
		token string
		want  error
	}{
		"another key":   {signRS256(t, other, "k", base()), errTokenKey},
		"expired":       {signRS256(t, key, "k", with("exp", now.Unix()-120)), errTokenExpired},
		"not yet valid": {signRS256(t, key, "k", with("nbf", now.Unix()+120)), errTokenNotYet},
		"issuer":        {signRS256(t, key, "k", with("iss", "evil")), errTokenIssuer},
		"audience":      {signRS256(t, key, "k", with("aud", "other")), errTokenAudience},
		"alg none":      {unsigned, errTokenAlgorithm},
		"HS256":         {hmac, errTokenAlgorithm},
		"garbage":       {"not-a-token", errTokenMalformed},
	}
	for name, c := range cases {
		if _, err := cache.Verify(context.Background(), c.token, cfg, now); err != c.want {
			t.Errorf("%s: got %v, want %v", name, err, c.want)
		}
	}

	// within the 30 s leeway an expired token still passes
	if _, err := cache.Verify(context.Background(), signRS256(t, key, "k", with("exp", now.Unix()-10)), cfg, now); err != nil {
		t.Errorf("leeway: %v", err)
	}
}

func TestJWKSRotationFetchesAgainForAnUnknownKid(t *testing.T) {
	first, _ := rsa.GenerateKey(rand.Reader, 2048)
	second, _ := rsa.GenerateKey(rand.Reader, 2048)
	var keys atomic.Value
	keys.Store([]map[string]any{rsaJWK("one", &first.PublicKey)})
	srv, fetches := jwksServer(t, &keys)
	cache := NewJWKSCache(srv.Client())
	cfg := &JWTConfig{JWKSURL: srv.URL}
	now := time.Unix(1_800_000_000, 0)
	claims := map[string]any{"sub": "s", "exp": now.Unix() + 600}

	if _, err := cache.Verify(context.Background(), signRS256(t, first, "one", claims), cfg, now); err != nil {
		t.Fatal(err)
	}
	if _, err := cache.Verify(context.Background(), signRS256(t, first, "one", claims), cfg, now.Add(time.Minute)); err != nil {
		t.Fatal(err)
	}
	if fetches.Load() != 1 {
		t.Fatalf("cached JWKS fetched %d times", fetches.Load())
	}

	keys.Store([]map[string]any{rsaJWK("one", &first.PublicKey), rsaJWK("two", &second.PublicKey)})
	if _, err := cache.Verify(context.Background(), signRS256(t, second, "two", claims), cfg, now.Add(2*time.Minute)); err != nil {
		t.Fatalf("rotated key: %v", err)
	}
	if fetches.Load() != 2 {
		t.Errorf("the unknown kid should fetch the JWKS again (fetches: %d)", fetches.Load())
	}

	// an unknown kid again within 30 s: no new fetch (a flood of bad tokens cannot hammer the issuer)
	_, _ = cache.Verify(context.Background(), signRS256(t, second, "three", claims), cfg, now.Add(2*time.Minute+time.Second))
	if fetches.Load() != 2 {
		t.Errorf("refetched within 30 s (fetches: %d)", fetches.Load())
	}
}

func TestJWKSDownKeepsTheKeysItHad(t *testing.T) {
	key, _ := rsa.GenerateKey(rand.Reader, 2048)
	var keys atomic.Value
	keys.Store([]map[string]any{rsaJWK("k", &key.PublicKey)})
	srv, _ := jwksServer(t, &keys)
	cache := NewJWKSCache(srv.Client())
	cfg := &JWTConfig{JWKSURL: srv.URL}
	now := time.Unix(1_800_000_000, 0)
	token := signRS256(t, key, "k", map[string]any{"sub": "s", "exp": now.Unix() + 7200})

	if _, err := cache.Verify(context.Background(), token, cfg, now); err != nil {
		t.Fatal(err)
	}
	srv.Close()
	if _, err := cache.Verify(context.Background(), token, cfg, now.Add(20*time.Minute)); err != nil {
		t.Errorf("issuer down after the TTL: %v", err)
	}
}
