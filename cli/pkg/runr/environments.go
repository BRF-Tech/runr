package runr

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"regexp"
)

// Environments of a project (docs/environments.md): dev, staging, … next to the project's own
// workload, the primary environment "production". Each runs the project's versions with the
// project's config and its own overrides, secrets, bindings, custom domains and region.
// Promotion moves the version one environment runs to the next one (the same version and
// image, the target's own config); into an environment that requires approval it waits for
// someone else with environments.approve. APIs before environments answer 404:
// ErrUnsupported.

// PrimaryEnvironment is the name of the project's own workload.
const PrimaryEnvironment = "production"

// EnvironmentNamePattern is the API's rule for an environment name: a lowercase DNS label of at
// most 20 characters (ProjectEnvironment::NAME_PATTERN).
var EnvironmentNamePattern = regexp.MustCompile(`^[a-z]([-a-z0-9]{0,18}[a-z0-9])?$`)

// reservedEnvironmentNames are the primary's name and the suffixes of the project's own
// resources and previews (ProjectEnvironment::RESERVED_NAMES).
var reservedEnvironmentNames = map[string]bool{
	"production": true, "trigger": true, "qc": true, "bindings": true, "secret-env": true, "pr": true, "build": true, "preview": true,
}

var (
	previewSuffix = regexp.MustCompile(`(^|-)pr-\d+$`)
	versionName   = regexp.MustCompile(`^v\d+$`)
)

// ReservedEnvironmentName reports whether a new environment may not take name: production (the
// project's own workload), and the names the project's resources, versions and previews use
// (trigger, qc, bindings, pr-<n>, v<n>, …).
func ReservedEnvironmentName(name string) bool {
	return reservedEnvironmentNames[name] || previewSuffix.MatchString(name) || versionName.MatchString(name)
}

// Promotion statuses.
const (
	PromotionPendingApproval = "pending_approval"
	PromotionApproved        = "approved"
	PromotionDeploying       = "deploying"
	PromotionDeployed        = "deployed"
	PromotionFailed          = "failed"
	PromotionRejected        = "rejected"
	PromotionCancelled       = "cancelled"
	PromotionSuperseded      = "superseded"
)

// Config sources of a deployment (Deployment.ConfigSource).
const (
	ConfigCurrent  = "current"  // the project's (environment's) config at deploy time
	ConfigRestored = "restored" // a rollback brought back the config an earlier deployment ran with
)

// EnvironmentOverrides are an environment's overrides of the project config. EnvVars is merged
// by name over the project's variables; a nil value removes the project's variable there.
type EnvironmentOverrides struct {
	EnvVars        map[string]*string `json:"env_vars,omitempty"`
	CPU            *string            `json:"cpu,omitempty"`
	MemoryMB       *int               `json:"memory_mb,omitempty"`
	TimeoutSeconds *int               `json:"timeout_seconds,omitempty"`
	MinScale       *int               `json:"min_scale,omitempty"`
	MaxScale       *int               `json:"max_scale,omitempty"`
	ScaleToZero    *bool              `json:"scale_to_zero,omitempty"`
	Schedule       *string            `json:"schedule,omitempty"`
	QueueStream    *string            `json:"queue_stream,omitempty"`
	// ScaleSchedule is the environment's own timed warmer: rows replace the project's schedule
	// there, an empty list keeps no containers warm there, nil follows the project's schedule.
	ScaleSchedule *[]ScaleScheduleRow `json:"scale_schedule,omitempty"`
	// EnvVarsHidden is set when the caller may not read the variables (no env.view): EnvVars
	// is then empty and must not be written back.
	EnvVarsHidden bool `json:"env_vars_hidden,omitempty"`
}

// UnmarshalJSON accepts an object, an empty array (PHP's empty map) or null, and numbers or
// booleans as env var values.
func (o *EnvironmentOverrides) UnmarshalJSON(b []byte) error {
	trimmed := bytes.TrimSpace(b)
	if bytes.Equal(trimmed, []byte("null")) || (len(trimmed) > 0 && trimmed[0] == '[') {
		*o = EnvironmentOverrides{}
		return nil
	}
	type plain EnvironmentOverrides
	var raw struct {
		plain
		EnvVars json.RawMessage `json:"env_vars"`
	}
	if err := json.Unmarshal(trimmed, &raw); err != nil {
		return err
	}
	*o = EnvironmentOverrides(raw.plain)
	o.EnvVars = nil
	if len(raw.EnvVars) > 0 && raw.EnvVars[0] == '{' {
		var vars map[string]any
		if err := json.Unmarshal(raw.EnvVars, &vars); err != nil {
			return fmt.Errorf("overrides.env_vars: %w", err)
		}
		o.EnvVars = make(map[string]*string, len(vars))
		for k, v := range vars {
			if v == nil {
				o.EnvVars[k] = nil
				continue
			}
			s := fmt.Sprint(v)
			if str, ok := v.(string); ok {
				s = str
			}
			o.EnvVars[k] = &s
		}
	}
	return nil
}

// Empty reports whether nothing is overridden.
func (o EnvironmentOverrides) Empty() bool {
	return len(o.EnvVars) == 0 && o.CPU == nil && o.MemoryMB == nil && o.TimeoutSeconds == nil &&
		o.MinScale == nil && o.MaxScale == nil && o.ScaleToZero == nil && o.Schedule == nil && o.QueueStream == nil
}

// EnvironmentDeployment is the live deployment of an environment.
type EnvironmentDeployment struct {
	ID           string `json:"id"`
	Status       string `json:"status"`
	ConfigSource string `json:"config_source,omitempty"`
	ReadyAt      string `json:"ready_at,omitempty"`
	CreatedAt    string `json:"created_at,omitempty"`
	Version      *struct {
		ID     string `json:"id"`
		Number int    `json:"number"`
		Label  string `json:"label"`
	} `json:"version,omitempty"`
}

// EnvironmentSecret is a secret variable an environment gets: its own (scope "environment") or
// the project's it inherits (scope "project"). Values are write-only.
type EnvironmentSecret struct {
	Name      string `json:"name"`
	Scope     string `json:"scope,omitempty"`
	UpdatedAt string `json:"updated_at,omitempty"`
	// Version counts the writes of an own value (each PUT adds one); 0 for an inherited one or
	// on APIs that do not report it. A version the caller did not write means the value was
	// changed by someone else.
	Version int `json:"version,omitempty"`
}

// Own reports whether the variable is the environment's own, not the project's it inherits.
func (s *EnvironmentSecret) Own() bool { return s.Scope == "" || s.Scope == "environment" }

// Environment is EnvironmentResource.
type Environment struct {
	ID               string               `json:"id"`
	Name             string               `json:"name"`
	IsPrimary        bool                 `json:"is_primary"`
	WorkloadName     string               `json:"workload_name,omitempty"`
	RegionID         string               `json:"knative_region_id,omitempty"`
	OwnRegion        bool                 `json:"own_region,omitempty"`
	Region           *Ref                 `json:"region,omitempty"`
	Position         int                  `json:"position"`
	RequiresApproval bool                 `json:"requires_approval"`
	GitBranch        string               `json:"git_branch,omitempty"`
	AutoDeploy       bool                 `json:"auto_deploy"`
	InheritSecrets   bool                 `json:"inherit_secrets"`
	Overrides        EnvironmentOverrides `json:"overrides"`
	URL              string               `json:"url,omitempty"`
	// DefaultURL is its own URL on the function domain of its region (URL is its gateway URL
	// while it has a gateway route).
	DefaultURL string `json:"default_url,omitempty"`
	// DeployStrategy is how its deploys roll out (direct, blue_green, canary): the project's
	// unless it sets its own (GetDeployStrategyIn has the options).
	DeployStrategy string `json:"deploy_strategy,omitempty"`
	// ScaleSchedule is the timed warmer its workload runs (nil: none) — the project's schedule
	// while ScaleScheduleInherited, else its own; WarmScale is what the warmer last applied there.
	ScaleSchedule          []ScaleScheduleRow     `json:"scale_schedule"`
	ScaleScheduleInherited bool                   `json:"scale_schedule_inherited"`
	WarmScale              *WarmScale             `json:"warm_scale,omitempty"`
	Deployment             *EnvironmentDeployment `json:"deployment,omitempty"`
	Secrets                []EnvironmentSecret    `json:"secrets,omitempty"`
	CreatedAt              string                 `json:"created_at,omitempty"`
	UpdatedAt              string                 `json:"updated_at,omitempty"`
}

// RegionName returns the environment's region name, or its id when the name is not loaded.
func (e *Environment) RegionName() string {
	if e.Region != nil && e.Region.Name != "" {
		return e.Region.Name
	}
	return e.RegionID
}

// EnvironmentAbilities are what the caller may do with a project's environments.
type EnvironmentAbilities struct {
	Manage  bool `json:"manage"`
	Promote bool `json:"promote"`
	Approve bool `json:"approve"`
}

// EnvironmentList is the answer of GET /projects/{project}/environments: the environments in
// pipeline order (the primary last unless moved).
type EnvironmentList struct {
	Environments []Environment        `json:"environments"`
	Abilities    EnvironmentAbilities `json:"abilities"`
}

// EnvironmentInput creates or changes an environment. Nil fields are left out (on an update:
// unchanged). Overrides replace the environment's overrides as a whole.
type EnvironmentInput struct {
	Name             string                `json:"name,omitempty"`
	RegionID         *string               `json:"knative_region_id,omitempty"`
	Position         *int                  `json:"position,omitempty"`
	RequiresApproval *bool                 `json:"requires_approval,omitempty"`
	GitBranch        *string               `json:"git_branch,omitempty"`
	AutoDeploy       *bool                 `json:"auto_deploy,omitempty"`
	InheritSecrets   *bool                 `json:"inherit_secrets,omitempty"`
	Overrides        *EnvironmentOverrides `json:"overrides,omitempty"`
}

// MarshalJSON writes the overrides without env_vars_hidden (an answer field).
func (in EnvironmentInput) MarshalJSON() ([]byte, error) {
	type plain EnvironmentInput
	out := struct {
		plain
		Overrides *overridesInput `json:"overrides,omitempty"`
	}{plain: plain(in)}
	if in.Overrides != nil {
		o := overridesInput(*in.Overrides)
		out.Overrides = &o
	}
	return json.Marshal(out)
}

type overridesInput EnvironmentOverrides

// MarshalJSON leaves env_vars_hidden out and writes a removed variable as null.
func (o overridesInput) MarshalJSON() ([]byte, error) {
	m := map[string]any{}
	if o.EnvVars != nil {
		vars := make(map[string]any, len(o.EnvVars))
		for k, v := range o.EnvVars {
			if v == nil {
				vars[k] = nil
			} else {
				vars[k] = *v
			}
		}
		m["env_vars"] = vars
	}
	set := func(key string, v any, present bool) {
		if present {
			m[key] = v
		}
	}
	set("cpu", o.CPU, o.CPU != nil)
	set("memory_mb", o.MemoryMB, o.MemoryMB != nil)
	set("timeout_seconds", o.TimeoutSeconds, o.TimeoutSeconds != nil)
	set("min_scale", o.MinScale, o.MinScale != nil)
	set("max_scale", o.MaxScale, o.MaxScale != nil)
	set("scale_to_zero", o.ScaleToZero, o.ScaleToZero != nil)
	set("schedule", o.Schedule, o.Schedule != nil)
	set("queue_stream", o.QueueStream, o.QueueStream != nil)
	// the environment's own timed warmer: rows, or [] for none; nil follows the project's
	if o.ScaleSchedule != nil {
		rows := *o.ScaleSchedule
		if rows == nil {
			rows = []ScaleScheduleRow{}
		}
		m["scale_schedule"] = rows
	}
	return json.Marshal(m)
}

// environmentErr maps the API's "unknown environment" refusal (422 environment_not_found) to
// a NotFoundError and a 404 of an API before environments to ErrUnsupported.
func environmentErr(err error, ref string) error {
	var ae *APIError
	if errors.As(err, &ae) && ae.Code == "environment_not_found" {
		return &NotFoundError{Kind: "environment", Ref: ref}
	}
	if IsNotFound(err) {
		return ErrUnsupported
	}
	return err
}

func environmentPath(projectID, environment string) string {
	return "/projects/" + projectID + "/environments/" + seg(environment)
}

// ListEnvironments returns a project's environments in pipeline order; ErrUnsupported on APIs
// without environments.
func (c *Client) ListEnvironments(ctx context.Context, projectID string) (*EnvironmentList, error) {
	var out struct {
		Data      []Environment        `json:"data"`
		Abilities EnvironmentAbilities `json:"abilities"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/environments"}, &out); err != nil {
		if IsNotFound(err) {
			return nil, ErrUnsupported
		}
		return nil, err
	}
	if out.Data == nil {
		out.Data = []Environment{}
	}
	return &EnvironmentList{Environments: out.Data, Abilities: out.Abilities}, nil
}

// GetEnvironment returns one environment by name or id ("production": the project's own).
func (c *Client) GetEnvironment(ctx context.Context, projectID, environment string) (*Environment, error) {
	var out struct {
		Data Environment `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: environmentPath(projectID, environment)}, &out); err != nil {
		// An API with environments answers an unknown one with 422: a 404 is an older API
		return nil, environmentErr(err, environment)
	}
	return &out.Data, nil
}

// CreateEnvironment adds an environment (environments.manage; env vars need env.edit). It must
// fit its region's capacity and the organization quota with its size.
func (c *Client) CreateEnvironment(ctx context.Context, projectID string, in EnvironmentInput) (*Environment, error) {
	var out struct {
		Data Environment `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/environments", body: in}, &out); err != nil {
		if IsNotFound(err) {
			return nil, ErrUnsupported
		}
		return nil, err
	}
	return &out.Data, nil
}

// UpdateEnvironment changes an environment; overrides reach its workload with the next deploy.
func (c *Client) UpdateEnvironment(ctx context.Context, projectID, environment string, in EnvironmentInput) (*Environment, error) {
	in.Name = ""
	var out struct {
		Data Environment `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPatch, path: environmentPath(projectID, environment), body: in}, &out); err != nil {
		return nil, environmentErr(err, environment)
	}
	return &out.Data, nil
}

// DeleteEnvironment removes an environment with its workload, deployments and secrets; its
// bindings and custom domains are detached. Incomplete reports what the region kept (logged).
func (c *Client) DeleteEnvironment(ctx context.Context, projectID, environment string) (incomplete bool, err error) {
	var out struct {
		Incomplete bool `json:"incomplete"`
	}
	if err := c.do(ctx, request{method: http.MethodDelete, path: environmentPath(projectID, environment)}, &out); err != nil {
		return false, environmentErr(err, environment)
	}
	return out.Incomplete, nil
}

// EnvironmentSecretList is the answer of GET …/environments/{environment}/secrets.
type EnvironmentSecretList struct {
	Secrets        []EnvironmentSecret `json:"secrets"`
	InheritSecrets bool                `json:"inherit_secrets"`
}

// ListEnvironmentSecrets returns the secret variables an environment gets (names only).
func (c *Client) ListEnvironmentSecrets(ctx context.Context, projectID, environment string) (*EnvironmentSecretList, error) {
	var out struct {
		Data           []EnvironmentSecret `json:"data"`
		InheritSecrets bool                `json:"inherit_secrets"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: environmentPath(projectID, environment) + "/secrets"}, &out); err != nil {
		return nil, environmentErr(err, environment)
	}
	if out.Data == nil {
		out.Data = []EnvironmentSecret{}
	}
	return &EnvironmentSecretList{Secrets: out.Data, InheritSecrets: out.InheritSecrets}, nil
}

// SetEnvironmentSecret creates or replaces a secret variable of an environment (over the
// project's of the same name). It reaches the workload with the next deploy.
func (c *Client) SetEnvironmentSecret(ctx context.Context, projectID, environment, name, value string) (*EnvironmentSecret, error) {
	var out struct {
		Data EnvironmentSecret `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPut, path: environmentPath(projectID, environment) + "/secrets/" + seg(name),
		body: map[string]string{"value": value}}, &out)
	if err != nil {
		return nil, environmentErr(err, environment)
	}
	return &out.Data, nil
}

// DeleteEnvironmentSecret removes a secret variable of an environment.
func (c *Client) DeleteEnvironmentSecret(ctx context.Context, projectID, environment, name string) error {
	err := c.do(ctx, request{method: http.MethodDelete, path: environmentPath(projectID, environment) + "/secrets/" + seg(name)}, nil)
	return environmentErr(err, environment)
}

// ConfigChange is one entry of a "what will change" diff: a field From → To, or — env vars,
// secrets, bindings — the names Added, Removed and Changed (never a value).
type ConfigChange struct {
	Field   string   `json:"field"`
	From    any      `json:"from,omitempty"`
	To      any      `json:"to,omitempty"`
	Added   []string `json:"added,omitempty"`
	Removed []string `json:"removed,omitempty"`
	Changed []string `json:"changed,omitempty"`
}

// PromotionVersion is a version in a promotion: its number and the image it runs.
type PromotionVersion struct {
	ID            string `json:"id"`
	Number        int    `json:"number"`
	Label         string `json:"label"`
	CommitMessage string `json:"commit_message,omitempty"`
	Image         string `json:"image,omitempty"`
	CreatedAt     string `json:"created_at,omitempty"`
}

// PromotionSide is the source or the target environment of a promotion preview.
type PromotionSide struct {
	Environment   string `json:"environment"`
	EnvironmentID string `json:"environment_id,omitempty"`
	RegionID      string `json:"region_id,omitempty"`
	DeploymentID  string `json:"deployment_id,omitempty"`
	Status        string `json:"status,omitempty"`
}

// PromotionPreview is what a promotion would change in its target (GET …/promote).
type PromotionPreview struct {
	Source  PromotionSide     `json:"source"`
	Target  PromotionSide     `json:"target"`
	Version *PromotionVersion `json:"version,omitempty"`
	Code    struct {
		From    *PromotionVersion `json:"from,omitempty"`
		To      *PromotionVersion `json:"to,omitempty"`
		Changes bool              `json:"changes"`
	} `json:"code"`
	// Config are the differences between what the target runs now and what it will run.
	Config []ConfigChange `json:"config"`
	// EnvironmentDifferences are how the source's config differs from the target's (information).
	EnvironmentDifferences []ConfigChange `json:"environment_differences"`
	RequiresApproval       bool           `json:"requires_approval"`
	CanPromote             bool           `json:"can_promote"`
	CanApprove             bool           `json:"can_approve"`
	Problem                string         `json:"problem,omitempty"`
}

// Promotion is one promotion (PromotionController::present).
type Promotion struct {
	ID              string            `json:"id"`
	ProjectID       string            `json:"project_id"`
	Project         *Ref              `json:"project,omitempty"`
	Source          string            `json:"source"`
	Target          string            `json:"target"`
	Version         *PromotionVersion `json:"version,omitempty"`
	Image           string            `json:"image,omitempty"`
	Status          string            `json:"status"`
	StatusMessage   string            `json:"status_message,omitempty"`
	Message         string            `json:"message,omitempty"`
	RequestedBy     string            `json:"requested_by,omitempty"`
	DecidedBy       string            `json:"decided_by,omitempty"`
	DecidedAt       string            `json:"decided_at,omitempty"`
	DecisionComment string            `json:"decision_comment,omitempty"`
	DeploymentID    string            `json:"deployment_id,omitempty"`
	CreatedAt       string            `json:"created_at,omitempty"`
	CanApprove      bool              `json:"can_approve"`
	CanCancel       bool              `json:"can_cancel"`
	Diff            *struct {
		Config []ConfigChange `json:"config,omitempty"`
	} `json:"diff,omitempty"`
}

// PreviewPromotion shows what promoting from's live version to to (empty: the next environment
// of the pipeline) would change.
func (c *Client) PreviewPromotion(ctx context.Context, projectID, from, to string) (*PromotionPreview, error) {
	q := url.Values{}
	if to != "" {
		q.Set("to", to)
	}
	var out struct {
		Data PromotionPreview `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: environmentPath(projectID, from) + "/promote", query: q}, &out); err != nil {
		return nil, environmentErr(err, from)
	}
	return &out.Data, nil
}

// Promote requests a promotion of from's live version to to (empty: the next environment).
// Into an environment that requires approval it waits (status pending_approval); otherwise
// its deployment starts at once (status deploying, DeploymentID).
func (c *Client) Promote(ctx context.Context, projectID, from, to, message string) (*Promotion, error) {
	body := map[string]string{}
	if to != "" {
		body["to"] = to
	}
	if message != "" {
		body["message"] = message
	}
	var out struct {
		Data Promotion `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: environmentPath(projectID, from) + "/promote", body: body}, &out); err != nil {
		return nil, environmentErr(err, from)
	}
	return &out.Data, nil
}

// ListPromotions returns a project's promotions, newest first (status "" for all).
func (c *Client) ListPromotions(ctx context.Context, projectID, status string) ([]Promotion, error) {
	q := url.Values{}
	if status != "" {
		q.Set("status", status)
	}
	var out struct {
		Data []Promotion `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/promotions", query: q}, &out); err != nil {
		if IsNotFound(err) {
			return nil, ErrUnsupported
		}
		return nil, err
	}
	if out.Data == nil {
		out.Data = []Promotion{}
	}
	return out.Data, nil
}

// ApprovalInbox returns the promotions of every project the caller may see, by status
// (default pending_approval; "all" for every status); CanApprove says which the caller may decide.
func (c *Client) ApprovalInbox(ctx context.Context, status string) ([]Promotion, error) {
	q := url.Values{}
	if status != "" {
		q.Set("status", status)
	}
	var out struct {
		Data []Promotion `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/environment-promotions", query: q}, &out); err != nil {
		if IsNotFound(err) {
			return nil, ErrUnsupported
		}
		return nil, err
	}
	if out.Data == nil {
		out.Data = []Promotion{}
	}
	return out.Data, nil
}

func (c *Client) decidePromotion(ctx context.Context, projectID, promotionID, decision, comment string) (*Promotion, error) {
	body := map[string]string{}
	if comment != "" {
		body["comment"] = comment
	}
	var out struct {
		Data Promotion `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/promotions/" + seg(promotionID) + "/" + decision, body: body}, &out)
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// ApprovePromotion approves a promotion that waits and deploys it (environments.approve; the
// requester cannot approve their own: 403 self_approval).
func (c *Client) ApprovePromotion(ctx context.Context, projectID, promotionID, comment string) (*Promotion, error) {
	return c.decidePromotion(ctx, projectID, promotionID, "approve", comment)
}

// RejectPromotion rejects a promotion that waits (environments.approve).
func (c *Client) RejectPromotion(ctx context.Context, projectID, promotionID, comment string) (*Promotion, error) {
	return c.decidePromotion(ctx, projectID, promotionID, "reject", comment)
}

// CancelPromotion withdraws a promotion that waits (its requester, or an approver).
func (c *Client) CancelPromotion(ctx context.Context, projectID, promotionID string) (*Promotion, error) {
	var out struct {
		Data Promotion `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/promotions/" + seg(promotionID) + "/cancel"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}
