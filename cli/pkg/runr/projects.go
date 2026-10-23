package runr

import (
	"context"
	"fmt"
	"net/http"
	"strings"
)

// ConfigInput is the config object of POST /projects and PUT /projects/{project}.
// Nil fields are not sent, so an update changes only what is set.
type ConfigInput struct {
	Filename        *string            `json:"filename,omitempty"`
	Handler         *string            `json:"handler,omitempty"`
	Image           *string            `json:"image,omitempty"`
	Command         *[]string          `json:"command,omitempty"`
	Args            *[]string          `json:"args,omitempty"`
	Ports           *[]int             `json:"ports,omitempty"`
	EnvVars         *map[string]string `json:"env_vars,omitempty"`
	MemoryMB        *int               `json:"memory_mb,omitempty"`
	CPU             *string            `json:"cpu,omitempty"`
	TimeoutSeconds  *int               `json:"timeout_seconds,omitempty"`
	ImagePullPolicy *string            `json:"image_pull_policy,omitempty"`
	ImagePullSecret *string            `json:"image_pull_secret,omitempty"`
	TriggerType     *string            `json:"trigger_type,omitempty"`
	Schedule        *string            `json:"schedule,omitempty"`
	// ScheduleTimezone sets the schedule trigger's IANA zone; a pointer to "" lets the schedule
	// follow the organization's time zone.
	ScheduleTimezone *string `json:"schedule_timezone,omitempty"`
	QueueStream      *string `json:"queue_stream,omitempty"`
	ScaleToZero      *bool   `json:"scale_to_zero,omitempty"`
	MinScale         *int    `json:"min_scale,omitempty"`
	MaxScale         *int    `json:"max_scale,omitempty"`
	// ScaleSchedule sets the timed warmer: the rows replace the whole schedule, a pointer to an
	// empty list turns it off (scale_schedule.go).
	ScaleSchedule *[]ScaleScheduleRow `json:"scale_schedule,omitempty"`
	// SecretEnvVars are write-only environment variables (see env.go). Sent only when the
	// API reports secret support.
	SecretEnvVars *map[string]string `json:"secret_env_vars,omitempty"`
}

// Empty reports whether no field is set.
func (c *ConfigInput) Empty() bool {
	if c == nil {
		return true
	}
	return *c == ConfigInput{}
}

// CreateProjectInput is the body of POST /projects.
type CreateProjectInput struct {
	Name           string      `json:"name"`
	ServiceType    string      `json:"service_type"`
	Runtime        string      `json:"runtime,omitempty"`
	RuntimeVersion string      `json:"runtime_version,omitempty"`
	Description    string      `json:"description,omitempty"`
	RegionID       string      `json:"knative_region_id"`
	Config         ConfigInput `json:"config"`
	EndpointType   string      `json:"endpoint_type,omitempty"`
	// Placement "global" creates a global project (global.go): RegionIDs are all its regions,
	// RegionID among them the primary; GlobalMode is ha (default) or proximity. Sent only for a
	// global project.
	Placement  string   `json:"placement,omitempty"`
	GlobalMode string   `json:"global_mode,omitempty"`
	RegionIDs  []string `json:"region_ids,omitempty"`
}

// UpdateProjectInput is the body of PUT /projects/{project}.
type UpdateProjectInput struct {
	Description   *string      `json:"description,omitempty"`
	RegionID      *string      `json:"knative_region_id,omitempty"`
	Config        *ConfigInput `json:"config,omitempty"`
	ConfigMessage string       `json:"config_message,omitempty"`
}

// ListProjects returns every project the token can see.
func (c *Client) ListProjects(ctx context.Context) ([]Project, error) {
	var out struct {
		Data []Project `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects"}, &out); err != nil {
		return nil, err
	}
	return out.Data, nil
}

// GetProject returns one project by id.
func (c *Client) GetProject(ctx context.Context, id string) (*Project, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + id}, &raw); err != nil {
		return nil, err
	}
	var p Project
	if err := decodeData(raw, &p); err != nil {
		return nil, fmt.Errorf("decode project: %w", err)
	}
	return &p, nil
}

// CreateProject creates a project. The answer has the project with its first version (caas).
func (c *Client) CreateProject(ctx context.Context, in CreateProjectInput) (*Project, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects", body: in}, &raw); err != nil {
		return nil, err
	}
	var p Project
	if err := decodeData(raw, &p); err != nil {
		return nil, fmt.Errorf("decode project: %w", err)
	}
	return &p, nil
}

// UpdateProject changes a project's description, region or config. A config change saves
// a new version (code projects: same code, new config).
func (c *Client) UpdateProject(ctx context.Context, id string, in UpdateProjectInput) (*Project, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPut, path: "/projects/" + id, body: in}, &raw); err != nil {
		return nil, err
	}
	var p Project
	if err := decodeData(raw, &p); err != nil {
		return nil, fmt.Errorf("decode project: %w", err)
	}
	return &p, nil
}

// DeleteProject deletes a project with its deployments, versions and domains.
func (c *Client) DeleteProject(ctx context.Context, id string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/projects/" + id}, nil)
}

// FindProject resolves a project by id, name or slug.
func (c *Client) FindProject(ctx context.Context, ref string) (*Project, error) {
	ref = strings.TrimSpace(ref)
	if ref == "" {
		return nil, fmt.Errorf("no project given")
	}
	if LooksLikeULID(ref) {
		p, err := c.GetProject(ctx, ref)
		if err == nil {
			return p, nil
		}
		if !IsNotFound(err) {
			return nil, err
		}
	}
	projects, err := c.ListProjects(ctx)
	if err != nil {
		return nil, err
	}
	for i := range projects {
		if projects[i].Name == ref || projects[i].Slug == ref || projects[i].ID == ref {
			return &projects[i], nil
		}
	}
	for i := range projects {
		if strings.EqualFold(projects[i].Name, ref) {
			return &projects[i], nil
		}
	}
	return nil, &NotFoundError{Kind: "project", Ref: ref}
}

// NotFoundError means a name did not resolve to an object.
type NotFoundError struct {
	Kind string
	Ref  string
}

func (e *NotFoundError) Error() string { return fmt.Sprintf("%s %q not found", e.Kind, e.Ref) }

// LooksLikeULID reports whether s has the shape of a ULID (26 Crockford base32 characters).
func LooksLikeULID(s string) bool {
	if len(s) != 26 {
		return false
	}
	for _, r := range strings.ToUpper(s) {
		if !strings.ContainsRune("0123456789ABCDEFGHJKMNPQRSTVWXYZ", r) {
			return false
		}
	}
	return true
}
