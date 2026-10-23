package gateway

import (
	"net/http"
	"os"
	"sync"
)

// TokenHeader carries the caller's token on the region's internal network
// (docs/internal-network.md): the sink's ForwardAuth (the region agent in authz mode) admits a
// call with the project's own token as the project's call.
const TokenHeader = "X-Runr-Internal-Token"

// Signer adds the platform's internal-call authentication to every delivery request, just
// before it is sent (each attempt anew). SetSigner replaces it.
type Signer interface {
	Sign(req *http.Request, body []byte) error
}

// TokenSigner is the default Signer: it sends the project's RUNR_INTERNAL_TOKEN (the gateway's
// Deployment reads it from the project's Secret <slug>-secret-env) in TokenHeader.
type TokenSigner struct {
	Token string
}

// Sign sets TokenHeader on req.
func (s TokenSigner) Sign(req *http.Request, _ []byte) error {
	req.Header.Set(TokenHeader, s.Token)
	return nil
}

// SignerFromEnv is the TokenSigner of RUNR_INTERNAL_TOKEN, or nil (no header) when it is not set:
// the authz then knows the gateway by its pod's address (label runr/project-id).
func SignerFromEnv(getenv func(string) string) Signer {
	token := getenv("RUNR_INTERNAL_TOKEN")
	if token == "" {
		return nil
	}
	return TokenSigner{Token: token}
}

var (
	signerMu  sync.RWMutex
	signerSet bool
	signer    Signer
	envSigner = sync.OnceValue(func() Signer { return SignerFromEnv(os.Getenv) })
)

// SetSigner replaces the signer of every delivery request; nil turns the header off. Without a
// call the signer is SignerFromEnv(os.Getenv).
func SetSigner(s Signer) {
	signerMu.Lock()
	defer signerMu.Unlock()
	signerSet, signer = true, s
}

func activeSigner() Signer {
	signerMu.RLock()
	set, s := signerSet, signer
	signerMu.RUnlock()
	if set {
		return s
	}
	return envSigner()
}
