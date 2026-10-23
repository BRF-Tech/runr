package runr

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"strings"
)

// TLS modes of a custom domain.
const (
	TLSNone        = "none"
	TLSCustom      = "custom"
	TLSCertManager = "cert-manager"
)

// ListDomains returns a project's custom domains (of its own workload) and the meta object
// (eligibility, DNS targets).
func (c *Client) ListDomains(ctx context.Context, projectID string) ([]Domain, *DomainsMeta, error) {
	return c.ListEnvironmentDomains(ctx, projectID, "")
}

// ListEnvironmentDomains returns the custom domains of one environment of the project (name or
// id; "" the project's own workload) and the meta object of that environment: its eligibility and
// the DNS targets of the region it runs in (docs/environments.md#domains).
func (c *Client) ListEnvironmentDomains(ctx context.Context, projectID, environment string) ([]Domain, *DomainsMeta, error) {
	var out struct {
		Data []Domain    `json:"data"`
		Meta DomainsMeta `json:"meta"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/domains", query: environmentQuery(environment)}, &out); err != nil {
		if environment != "" {
			return nil, nil, environmentErr(err, environment)
		}
		return nil, nil, err
	}
	return out.Data, &out.Meta, nil
}

// environmentQuery is ?environment= of an environment-scoped request ("": none, the project's own
// workload).
func environmentQuery(environment string) url.Values {
	if environment == "" {
		return nil
	}
	return url.Values{"environment": {environment}}
}

// AddDomain adds a custom domain; it stays pending until VerifyDomain finds the TXT record.
func (c *Client) AddDomain(ctx context.Context, projectID, domain string) (*Domain, error) {
	return c.AddEnvironmentDomain(ctx, projectID, "", domain)
}

// AddEnvironmentDomain adds a custom domain to one environment of the project ("" its own
// workload): the domain routes to that environment's workload.
func (c *Client) AddEnvironmentDomain(ctx context.Context, projectID, environment, domain string) (*Domain, error) {
	body := struct {
		Domain      string `json:"domain"`
		Environment string `json:"environment,omitempty"`
	}{Domain: domain, Environment: environment}
	var out struct {
		Data Domain `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/domains", body: body}, &out); err != nil {
		if environment != "" {
			return nil, environmentErr(err, environment)
		}
		return nil, err
	}
	return &out.Data, nil
}

// VerifyDomain checks the domain's DNS records. The domain is returned in both cases; err is
// a *DomainVerifyError when the API answered 422 (not verified yet).
func (c *Client) VerifyDomain(ctx context.Context, projectID, domainID string) (*Domain, error) {
	return c.VerifyEnvironmentDomain(ctx, projectID, "", domainID)
}

// VerifyEnvironmentDomain is VerifyDomain for a domain of one environment of the project.
func (c *Client) VerifyEnvironmentDomain(ctx context.Context, projectID, environment, domainID string) (*Domain, error) {
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
		d, derr := c.GetEnvironmentDomain(ctx, projectID, environment, domainID)
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
	return c.GetEnvironmentDomain(ctx, projectID, "", domainID)
}

// GetEnvironmentDomain returns one domain of an environment of the project by id.
func (c *Client) GetEnvironmentDomain(ctx context.Context, projectID, environment, domainID string) (*Domain, error) {
	list, _, err := c.ListEnvironmentDomains(ctx, projectID, environment)
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
	return c.FindEnvironmentDomain(ctx, projectID, "", ref)
}

// FindEnvironmentDomain resolves a domain of one environment of the project ("" its own workload)
// by id or host name.
func (c *Client) FindEnvironmentDomain(ctx context.Context, projectID, environment, ref string) (*Domain, error) {
	list, _, err := c.ListEnvironmentDomains(ctx, projectID, environment)
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
