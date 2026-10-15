package runr

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"strings"
)

// TLS modes of a custom domain.
const (
	TLSNone        = "none"
	TLSCustom      = "custom"
	TLSCertManager = "cert-manager"
)

// ListDomains returns a project's custom domains and the meta object (eligibility, DNS targets).
func (c *Client) ListDomains(ctx context.Context, projectID string) ([]Domain, *DomainsMeta, error) {
	var out struct {
		Data []Domain    `json:"data"`
		Meta DomainsMeta `json:"meta"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/domains"}, &out); err != nil {
		return nil, nil, err
	}
	return out.Data, &out.Meta, nil
}

// AddDomain adds a custom domain; it stays pending until VerifyDomain finds the TXT record.
func (c *Client) AddDomain(ctx context.Context, projectID, domain string) (*Domain, error) {
	body := struct {
		Domain string `json:"domain"`
	}{Domain: domain}
	var out struct {
		Data Domain `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/domains", body: body}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// VerifyDomain checks the domain's DNS records. The domain is returned in both cases; err is
// a *DomainVerifyError when the API answered 422 (not verified yet).
func (c *Client) VerifyDomain(ctx context.Context, projectID, domainID string) (*Domain, error) {
	var out struct {
		Message string `json:"message"`
		Data    Domain `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/domains/" + domainID + "/verify"}, &out)
	if err == nil {
		return &out.Data, nil
	}
	var ae *APIError
	if errors.As(err, &ae) && ae.StatusCode == http.StatusUnprocessableEntity {
		d, derr := c.GetDomain(ctx, projectID, domainID)
		if derr != nil {
			return nil, err
		}
		return d, &DomainVerifyError{Domain: d, Message: ae.Message}
	}
	return nil, err
}

// DomainVerifyError means the DNS records were not found (yet).
type DomainVerifyError struct {
	Domain  *Domain
	Message string
}

func (e *DomainVerifyError) Error() string {
	return fmt.Sprintf("domain %s is not verified: %s", e.Domain.Domain, e.Message)
}

// SetDomainTLS sets the HTTPS certificate of a domain: none, cert-manager, or custom with a
// PEM certificate and private key.
func (c *Client) SetDomainTLS(ctx context.Context, projectID, domainID, mode, certificate, privateKey string) (*Domain, error) {
	body := map[string]any{"mode": mode}
	if mode == TLSCustom {
		body["certificate"] = certificate
		body["privateKey"] = privateKey
	}
	var out struct {
		Data Domain `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPut, path: "/projects/" + projectID + "/domains/" + domainID + "/tls", body: body}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// DeleteDomain removes a custom domain from the project and its region.
func (c *Client) DeleteDomain(ctx context.Context, projectID, domainID string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/projects/" + projectID + "/domains/" + domainID}, nil)
}

// GetDomain returns one domain of a project by id.
func (c *Client) GetDomain(ctx context.Context, projectID, domainID string) (*Domain, error) {
	list, _, err := c.ListDomains(ctx, projectID)
	if err != nil {
		return nil, err
	}
	for i := range list {
		if list[i].ID == domainID {
			return &list[i], nil
		}
	}
	return nil, &NotFoundError{Kind: "domain", Ref: domainID}
}

// FindDomain resolves a domain of a project by id or host name.
func (c *Client) FindDomain(ctx context.Context, projectID, ref string) (*Domain, error) {
	list, _, err := c.ListDomains(ctx, projectID)
	if err != nil {
		return nil, err
	}
	ref = strings.TrimSuffix(strings.ToLower(strings.TrimSpace(ref)), ".")
	for i := range list {
		if list[i].ID == ref || strings.EqualFold(list[i].Domain, ref) {
			return &list[i], nil
		}
	}
	return nil, &NotFoundError{Kind: "domain", Ref: ref}
}
