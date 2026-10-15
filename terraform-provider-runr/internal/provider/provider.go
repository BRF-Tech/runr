// Package provider implements the RunR Terraform provider on terraform-plugin-framework.
// It talks to the API through package runr, the client the runr CLI uses.
package provider

import (
	"context"
	"os"
	"sync"
	"time"

	"github.com/hashicorp/terraform-plugin-framework/datasource"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/provider"
	"github.com/hashicorp/terraform-plugin-framework/provider/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/config"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Client is what resources and data sources get from Configure.
type Client struct {
	API          *runr.Client
	PollInterval time.Duration

	mu    sync.Mutex
	locks map[string]*sync.Mutex
}

// lock serialises changes to one project's config (environment variables are a read-modify-
// write of one map; parallel runr_env_var resources would otherwise lose updates).
func (c *Client) lock(projectID string) func() {
	c.mu.Lock()
	if c.locks == nil {
		c.locks = map[string]*sync.Mutex{}
	}
	m, ok := c.locks[projectID]
	if !ok {
		m = &sync.Mutex{}
		c.locks[projectID] = m
	}
	c.mu.Unlock()
	m.Lock()
	return m.Unlock
}

type runrProvider struct {
	version string
}

type providerModel struct {
	APIURL       types.String `tfsdk:"api_url"`
	Token        types.String `tfsdk:"token"`
	Context      types.String `tfsdk:"context"`
	PollInterval types.String `tfsdk:"poll_interval"`
}

// New returns the provider factory.
func New(version string) func() provider.Provider {
	return func() provider.Provider { return &runrProvider{version: version} }
}

func (p *runrProvider) Metadata(_ context.Context, _ provider.MetadataRequest, resp *provider.MetadataResponse) {
	resp.TypeName = "runr"
	resp.Version = p.version
}

func (p *runrProvider) Schema(_ context.Context, _ provider.SchemaRequest, resp *provider.SchemaResponse) {
	resp.Schema = schema.Schema{
		Description: "Manage RunR projects, environment variables, custom domains, deployments, bindings, runtime plugins, " +
			"workflow triggers, model services, hosted MCP servers, access control and branding.",
		MarkdownDescription: "Manage [RunR](https://github.com/brf-tech/runr) projects, environment variables, custom domains, deployments, " +
			"bindings, runtime plugins, workflow triggers, model services, hosted MCP servers, access control and branding.\n\n" +
			"Credentials come from the provider block, else `RUNR_API_URL` / `RUNR_TOKEN`, else the current context of the `runr` CLI (`runr login`).",
		Attributes: map[string]schema.Attribute{
			"api_url": schema.StringAttribute{
				Optional:            true,
				MarkdownDescription: "RunR console or API URL, e.g. `https://runr.example.com`. Default: `RUNR_API_URL`, else the CLI context.",
			},
			"token": schema.StringAttribute{
				Optional:            true,
				Sensitive:           true,
				MarkdownDescription: "RunR API token (`runr tokens create terraform`). Default: `RUNR_TOKEN`, else the CLI context.",
			},
			"context": schema.StringAttribute{
				Optional:            true,
				MarkdownDescription: "Name of a `runr` CLI context to take the URL and token from (default: the current context).",
			},
			"poll_interval": schema.StringAttribute{
				Optional:            true,
				MarkdownDescription: "How often builds, deployments and domain checks are polled (Go duration). Default `2s`.",
			},
		},
	}
}

func (p *runrProvider) Configure(ctx context.Context, req provider.ConfigureRequest, resp *provider.ConfigureResponse) {
	var m providerModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if m.APIURL.IsUnknown() || m.Token.IsUnknown() {
		// Values known only after apply of other resources: configure on the next run.
		return
	}

	apiURL := m.APIURL.ValueString()
	token := m.Token.ValueString()
	if apiURL == "" {
		apiURL = os.Getenv(config.EnvAPIURL)
	}
	if token == "" {
		token = os.Getenv(config.EnvToken)
	}
	if apiURL == "" || token == "" {
		if f, err := config.Load(); err == nil {
			if r, err := f.Resolve(m.Context.ValueString()); err == nil {
				if apiURL == "" {
					apiURL = r.APIURL
				}
				if token == "" {
					token = r.Token
				}
			}
		}
	}
	if apiURL == "" {
		resp.Diagnostics.AddAttributeError(path.Root("api_url"), "RunR API URL missing",
			"Set api_url in the provider block or RUNR_API_URL, or log in with `runr login`.")
	}
	if token == "" {
		resp.Diagnostics.AddAttributeError(path.Root("token"), "RunR API token missing",
			"Set token in the provider block or RUNR_TOKEN, or log in with `runr login`.")
	}
	if resp.Diagnostics.HasError() {
		return
	}

	interval := 2 * time.Second
	if s := m.PollInterval.ValueString(); s != "" {
		d, err := time.ParseDuration(s)
		if err != nil || d <= 0 {
			resp.Diagnostics.AddAttributeError(path.Root("poll_interval"), "Invalid poll_interval", "Use a Go duration such as 2s.")
			return
		}
		interval = d
	}

	api, err := runr.New(runr.Options{APIURL: apiURL, Token: token, UserAgent: "terraform-provider-runr/" + p.version})
	if err != nil {
		resp.Diagnostics.AddAttributeError(path.Root("api_url"), "Invalid RunR API URL", err.Error())
		return
	}
	c := &Client{API: api, PollInterval: interval}
	resp.ResourceData = c
	resp.DataSourceData = c
}

func (p *runrProvider) Resources(context.Context) []func() resource.Resource {
	return append([]func() resource.Resource{
		NewProjectResource,
		NewEnvVarResource,
		NewDomainResource,
		NewDeploymentResource,
	}, featureResources...)
}

// featureResources are the resources of the feature files (bindings, runtimes, RBAC, …); each
// registers its constructor in init with registerResource.
var featureResources []func() resource.Resource

func registerResource(newResource func() resource.Resource) {
	featureResources = append(featureResources, newResource)
}

func (p *runrProvider) DataSources(context.Context) []func() datasource.DataSource {
	return []func() datasource.DataSource{
		NewRegionDataSource,
		NewProjectDataSource,
	}
}

// clientFrom extracts the Client handed out by Configure.
func clientFrom(data any, diags *diag.Diagnostics) *Client {
	if data == nil {
		return nil
	}
	c, ok := data.(*Client)
	if !ok {
		diags.AddError("Unexpected provider data", "The RunR provider was not configured correctly.")
		return nil
	}
	return c
}

// notConfigured reports (and adds an error) when the provider has no API client: its
// configuration held values known only after other resources are applied.
func notConfigured(c *Client, diags *diag.Diagnostics) bool {
	if c != nil && c.API != nil {
		return false
	}
	diags.AddError("RunR provider not configured",
		"api_url and token must be known when the plan is made; set them from variables or the environment.")
	return true
}

// apiError adds an API error as a diagnostic.
func apiError(diags *diag.Diagnostics, summary string, err error) {
	diags.AddError(summary, err.Error())
}

func (c *Client) wait(timeout time.Duration) runr.WaitOptions {
	return runr.WaitOptions{Timeout: timeout, Interval: c.PollInterval}
}
