package runr

import (
	"context"
	"errors"
	"net/http"
	"time"
)

// Me returns the user the token belongs to.
func (c *Client) Me(ctx context.Context) (*User, error) {
	var u User
	if err := c.do(ctx, request{method: http.MethodGet, path: "/auth/me"}, &u); err != nil {
		return nil, err
	}
	return &u, nil
}

// ErrTwoFactorRequired is returned by Login when the account needs a 2FA code.
var ErrTwoFactorRequired = errors.New("two-factor authentication code required")

// Login exchanges a username (or e-mail) and password for a session token. twoFactorCode is
// a TOTP or recovery code, "" on the first attempt.
func (c *Client) Login(ctx context.Context, login, password, twoFactorCode string) (token string, user *User, err error) {
	body := map[string]string{"login": login, "password": password}
	if twoFactorCode != "" {
		body["two_factor_code"] = twoFactorCode
	}
	var out struct {
		Token       string `json:"token"`
		User        *User  `json:"user"`
		Requires2FA bool   `json:"requires_2fa"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/auth/login", body: body, noAuth: true}, &out); err != nil {
		return "", nil, err
	}
	if out.Requires2FA && out.Token == "" {
		return "", nil, ErrTwoFactorRequired
	}
	return out.Token, out.User, nil
}

// Logout revokes the token the client authenticates with.
func (c *Client) Logout(ctx context.Context) error {
	return c.do(ctx, request{method: http.MethodPost, path: "/auth/logout"}, nil)
}

// ServerVersion returns the API's version string (GET /version, no authentication).
func (c *Client) ServerVersion(ctx context.Context) (string, error) {
	var out struct {
		Version string `json:"version"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/version", noAuth: true}, &out); err != nil {
		return "", err
	}
	return out.Version, nil
}

// ListTokens returns the user's API tokens (without the one in use) and the abilities a
// token can have.
func (c *Client) ListTokens(ctx context.Context) ([]Token, []string, error) {
	var out struct {
		Tokens    []Token  `json:"tokens"`
		Abilities []string `json:"available_abilities"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/tokens"}, &out); err != nil {
		return nil, nil, err
	}
	return out.Tokens, out.Abilities, nil
}

// CreateTokenInput is the body of POST /tokens.
type CreateTokenInput struct {
	Name      string     `json:"name"`
	Abilities []string   `json:"abilities,omitempty"`
	ExpiresAt *time.Time `json:"expires_at,omitempty"`
}

// CreateToken creates an API token and returns its plain-text value (shown once) and record.
func (c *Client) CreateToken(ctx context.Context, in CreateTokenInput) (plain string, tok *Token, err error) {
	var out struct {
		PlainToken string `json:"plain_token"`
		Token      Token  `json:"token"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/tokens", body: in}, &out); err != nil {
		return "", nil, err
	}
	return out.PlainToken, &out.Token, nil
}

// DeleteToken revokes an API token by id.
func (c *Client) DeleteToken(ctx context.Context, id string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/tokens/" + id}, nil)
}

// ExecutorImages returns the runtime versions the platform offers (GET /settings).
func (c *Client) ExecutorImages(ctx context.Context) ([]ExecutorImage, error) {
	var out struct {
		ExecutorImages []ExecutorImage `json:"executor_images"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/settings"}, &out); err != nil {
		return nil, err
	}
	return out.ExecutorImages, nil
}
