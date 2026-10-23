package main

import (
	"context"
	"crypto"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rsa"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math/big"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"
)

// JWT routes: a token signed with a key of the route's JWKS (RS256/384/512, PS256/384/512,
// ES256/384/512 — never "none" or an HMAC algorithm), not expired (exp) and already valid (nbf),
// with the route's issuer and audience when it names them. The JWKS of a URL is cached for
// ten minutes and fetched again early, at most every 30 seconds, when a token names an unknown
// key id (the issuer rotated its keys).

const (
	jwksTTL        = 10 * time.Minute
	jwksMinRefetch = 30 * time.Second
	jwtLeeway      = 30 * time.Second
	jwksMaxBytes   = 1 << 20
)

var (
	errTokenMalformed = errors.New("malformed token")
	errTokenAlgorithm = errors.New("algorithm not accepted")
	errTokenKey       = errors.New("no key of the JWKS verifies the token")
	errTokenExpired   = errors.New("token expired")
	errTokenNotYet    = errors.New("token not valid yet")
	errTokenIssuer    = errors.New("issuer does not match")
	errTokenAudience  = errors.New("audience does not match")
)

type jwk struct {
	Kty string `json:"kty"`
	Kid string `json:"kid"`
	Alg string `json:"alg"`
	Use string `json:"use"`
	N   string `json:"n"`
	E   string `json:"e"`
	Crv string `json:"crv"`
	X   string `json:"x"`
	Y   string `json:"y"`
}

type verifierKey struct {
	kid string
	alg string
	pub crypto.PublicKey
}

type jwksEntry struct {
	keys      []verifierKey
	fetchedAt time.Time
	triedAt   time.Time
}

// JWKSCache fetches and caches the JWKS of the JWT routes.
type JWKSCache struct {
	Client *http.Client
	mu     sync.Mutex
	sets   map[string]*jwksEntry
}

func NewJWKSCache(client *http.Client) *JWKSCache {
	return &JWKSCache{Client: client, sets: map[string]*jwksEntry{}}
}

// Verify checks the token for cfg and returns its claims.
func (c *JWKSCache) Verify(ctx context.Context, token string, cfg *JWTConfig, now time.Time) (map[string]any, error) {
	parts := strings.Split(token, ".")
	if len(parts) != 3 {
		return nil, errTokenMalformed
	}
	var header struct {
		Alg string `json:"alg"`
		Kid string `json:"kid"`
	}
	if err := decodeSegment(parts[0], &header); err != nil {
		return nil, errTokenMalformed
	}
	hash, family, ok := algorithm(header.Alg)
	if !ok {
		return nil, errTokenAlgorithm
	}
	signature, err := base64.RawURLEncoding.DecodeString(parts[2])
	if err != nil {
		return nil, errTokenMalformed
	}
	h := hash.New()
	h.Write([]byte(parts[0] + "." + parts[1]))
	digest := h.Sum(nil)

	keys, err := c.keys(ctx, cfg.JWKSURL, header.Kid, now)
	if err != nil {
		return nil, err
	}
	verified := false
	for _, k := range keys {
		if (header.Kid != "" && k.kid != header.Kid) || (k.alg != "" && k.alg != header.Alg) {
			continue
		}
		if verifySignature(k.pub, family, hash, digest, signature) {
			verified = true
			break
		}
	}
	if !verified {
		return nil, errTokenKey
	}

	var claims map[string]any
	if err := decodeSegment(parts[1], &claims); err != nil {
		return nil, errTokenMalformed
	}
	if exp, ok := numericClaim(claims, "exp"); ok && now.After(time.Unix(exp, 0).Add(jwtLeeway)) {
		return nil, errTokenExpired
	}
	if nbf, ok := numericClaim(claims, "nbf"); ok && now.Add(jwtLeeway).Before(time.Unix(nbf, 0)) {
		return nil, errTokenNotYet
	}
	if cfg.Issuer != nil && *cfg.Issuer != "" {
		if iss, _ := claims["iss"].(string); iss != *cfg.Issuer {
			return nil, errTokenIssuer
		}
	}
	if cfg.Audience != nil && *cfg.Audience != "" && !hasAudience(claims["aud"], *cfg.Audience) {
		return nil, errTokenAudience
	}
	return claims, nil
}

// keys of url; fetched again when kid is unknown (rotation), at most every jwksMinRefetch.
func (c *JWKSCache) keys(ctx context.Context, url, kid string, now time.Time) ([]verifierKey, error) {
	if url == "" {
		return nil, errors.New("route has no JWKS URL")
	}
	c.mu.Lock()
	entry := c.sets[url]
	c.mu.Unlock()

	known := entry != nil && (kid == "" || hasKid(entry.keys, kid))
	if entry != nil && known && now.Sub(entry.fetchedAt) < jwksTTL {
		return entry.keys, nil
	}
	if entry != nil && now.Sub(entry.triedAt) < jwksMinRefetch {
		return entry.keys, nil
	}

	keys, err := c.fetch(ctx, url)
	c.mu.Lock()
	defer c.mu.Unlock()
	if entry == nil {
		entry = &jwksEntry{}
		c.sets[url] = entry
	}
	entry.triedAt = now
	if err != nil {
		if entry.keys != nil {
			return entry.keys, nil // the issuer is down: the keys we had
		}
		return nil, err
	}
	entry.keys = keys
	entry.fetchedAt = now
	return keys, nil
}

func (c *JWKSCache) fetch(ctx context.Context, url string) ([]verifierKey, error) {
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Accept", "application/json")
	resp, err := c.Client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("JWKS: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("JWKS answered %d", resp.StatusCode)
	}
	var set struct {
		Keys []jwk `json:"keys"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, jwksMaxBytes)).Decode(&set); err != nil {
		return nil, fmt.Errorf("JWKS: %w", err)
	}

	var keys []verifierKey
	for _, k := range set.Keys {
		if k.Use != "" && k.Use != "sig" {
			continue
		}
		pub, err := k.publicKey()
		if err != nil {
			continue
		}
		keys = append(keys, verifierKey{kid: k.Kid, alg: k.Alg, pub: pub})
	}
	if len(keys) == 0 {
		return nil, errors.New("JWKS has no usable signing key")
	}
	return keys, nil
}

func (k jwk) publicKey() (crypto.PublicKey, error) {
	switch k.Kty {
	case "RSA":
		n, err := base64.RawURLEncoding.DecodeString(k.N)
		if err != nil {
			return nil, err
		}
		e, err := base64.RawURLEncoding.DecodeString(k.E)
		if err != nil || len(e) == 0 || len(e) > 4 {
			return nil, errors.New("bad RSA exponent")
		}
		return &rsa.PublicKey{N: new(big.Int).SetBytes(n), E: int(new(big.Int).SetBytes(e).Int64())}, nil
	case "EC":
		var curve elliptic.Curve
		switch k.Crv {
		case "P-256":
			curve = elliptic.P256()
		case "P-384":
			curve = elliptic.P384()
		case "P-521":
			curve = elliptic.P521()
		default:
			return nil, errors.New("unsupported curve")
		}
		x, errX := base64.RawURLEncoding.DecodeString(k.X)
		y, errY := base64.RawURLEncoding.DecodeString(k.Y)
		if errX != nil || errY != nil {
			return nil, errors.New("bad EC point")
		}
		size := (curve.Params().BitSize + 7) / 8
		if len(x) > size || len(y) > size {
			return nil, errors.New("bad EC point")
		}
		point := make([]byte, 1+2*size)
		point[0] = 4
		copy(point[1+size-len(x):1+size], x)
		copy(point[1+2*size-len(y):], y)
		return ecdsa.ParseUncompressedPublicKey(curve, point)
	}
	return nil, errors.New("unsupported key type")
}

func algorithm(alg string) (crypto.Hash, string, bool) {
	switch alg {
	case "RS256":
		return crypto.SHA256, "RS", true
	case "RS384":
		return crypto.SHA384, "RS", true
	case "RS512":
		return crypto.SHA512, "RS", true
	case "PS256":
		return crypto.SHA256, "PS", true
	case "PS384":
		return crypto.SHA384, "PS", true
	case "PS512":
		return crypto.SHA512, "PS", true
	case "ES256":
		return crypto.SHA256, "ES", true
	case "ES384":
		return crypto.SHA384, "ES", true
	case "ES512":
		return crypto.SHA512, "ES", true
	}
	return 0, "", false
}

func verifySignature(pub crypto.PublicKey, family string, hash crypto.Hash, digest, signature []byte) bool {
	switch key := pub.(type) {
	case *rsa.PublicKey:
		switch family {
		case "RS":
			return rsa.VerifyPKCS1v15(key, hash, digest, signature) == nil
		case "PS":
			return rsa.VerifyPSS(key, hash, digest, signature, &rsa.PSSOptions{SaltLength: rsa.PSSSaltLengthEqualsHash}) == nil
		}
	case *ecdsa.PublicKey:
		if family != "ES" {
			return false
		}
		size := (key.Curve.Params().BitSize + 7) / 8
		if len(signature) != 2*size {
			return false
		}
		r := new(big.Int).SetBytes(signature[:size])
		s := new(big.Int).SetBytes(signature[size:])
		return ecdsa.Verify(key, digest, r, s)
	}
	return false
}

func decodeSegment(segment string, v any) error {
	raw, err := base64.RawURLEncoding.DecodeString(segment)
	if err != nil {
		return err
	}
	return json.Unmarshal(raw, v)
}

func numericClaim(claims map[string]any, name string) (int64, bool) {
	switch v := claims[name].(type) {
	case float64:
		return int64(v), true
	case json.Number:
		n, err := v.Int64()
		return n, err == nil
	}
	return 0, false
}

func hasAudience(aud any, want string) bool {
	switch v := aud.(type) {
	case string:
		return v == want
	case []any:
		for _, a := range v {
			if s, ok := a.(string); ok && s == want {
				return true
			}
		}
	}
	return false
}

func hasKid(keys []verifierKey, kid string) bool {
	for _, k := range keys {
		if k.kid == kid {
			return true
		}
	}
	return false
}

// claimString is the claim as a string (numbers too), "" when missing.
func claimString(claims map[string]any, name string) string {
	switch v := claims[name].(type) {
	case string:
		return v
	case float64:
		return strconv.FormatFloat(v, 'f', -1, 64)
	}
	return ""
}
