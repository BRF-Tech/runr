// Package authz is the ForwardAuth of the region Traefik's private entry point: every request to
// a Knative Service on the region's internal network (its cluster-local hosts, its
// <project>.<organization>.runr.internal host) is checked here before Traefik routes it.
//
// The caller is the project its token names (RUNR_INTERNAL_TOKEN of its workloads, signed by the
// RunR API with the region's key, in X-Runr-Internal-Token) or, without a token, the project
// whose pod the call comes from (Pods). The callee's Knative Service carries its policy in
// annotations (runr.dev/internal-name, -org, -callers). A call is admitted when the caller is the
// callee itself, RunR (runr:system) or on the callee's allow list — a caller of another
// organization only while the platform allows cross-organization grants. A namespace enforces
// unless RunR's ConfigMap says audit, where a call the policy denies is admitted and logged.
package authz

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"strings"
	"time"
)

// TokenPrefix is the version tag of a token: rit1.<payload>.<signature>.
const TokenPrefix = "rit1"

// SystemSubject is RunR itself (the API's calls through the API server).
const SystemSubject = "runr:system"

// Claims of a token.
type Claims struct {
	Subject  string `json:"sub"`
	Region   string `json:"reg"`
	IssuedAt int64  `json:"iat"`
	Expires  int64  `json:"exp,omitempty"`
}

var (
	errMissing   = errors.New("no token")
	errMalformed = errors.New("malformed token")
	errSignature = errors.New("token signed with another key")
	errExpired   = errors.New("token expired")
)

var b64 = base64.RawURLEncoding

// Verify checks a token against the key of the region it names (keys: region id → key) and
// returns its claims.
func Verify(token string, keys map[string][]byte, now time.Time) (*Claims, error) {
	if token == "" {
		return nil, errMissing
	}
	parts := strings.Split(token, ".")
	if len(parts) != 3 || parts[0] != TokenPrefix {
		return nil, errMalformed
	}
	payload, err := b64.DecodeString(parts[1])
	if err != nil {
		return nil, errMalformed
	}
	var c Claims
	if err := json.Unmarshal(payload, &c); err != nil || c.Subject == "" {
		return nil, errMalformed
	}
	key, ok := keys[c.Region]
	if !ok {
		return nil, errSignature
	}
	sig, err := b64.DecodeString(parts[2])
	if err != nil {
		return nil, errMalformed
	}
	mac := hmac.New(sha256.New, key)
	mac.Write([]byte(parts[0] + "." + parts[1]))
	if !hmac.Equal(sig, mac.Sum(nil)) {
		return nil, errSignature
	}
	if c.Expires != 0 && c.Expires < now.Unix() {
		return nil, errExpired
	}
	return &c, nil
}

// Sign makes a token with key; the tests use it, the RunR API signs the same way
// (App\Services\InternalNetwork\InternalNetwork::sign).
func Sign(c Claims, key []byte) string {
	payload, _ := json.Marshal(c)
	signed := TokenPrefix + "." + b64.EncodeToString(payload)
	mac := hmac.New(sha256.New, key)
	mac.Write([]byte(signed))
	return signed + "." + b64.EncodeToString(mac.Sum(nil))
}

// orgOf is the organization part of an internal name <project>.<organization>.
func orgOf(name string) string {
	if i := strings.IndexByte(name, '.'); i >= 0 {
		return name[i+1:]
	}
	return ""
}
