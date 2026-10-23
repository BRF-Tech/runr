package runr

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// Deploy strategies and rollouts. A project deploys directly by default: the new revision
// replaces the live one once it is ready. With the blue-green or canary strategy (HTTP and
// workflow triggers), a deploy while a deployment serves the project opens a rollout instead:
// the new revision starts next to the one that serves, gets a test URL, and takes the traffic
// on promotion (blue-green) or step by step (canary).
//
// Each environment of a project (environments.go) rolls out on its own: it has the project's
// settings unless it sets its own (GetDeployStrategyIn / UpdateDeployStrategyIn with an
// environment), and its rollouts move the traffic of its own workload (ProjectRolloutIn).

// Deploy strategies (DeployStrategy.Strategy, Rollout.Strategy).
const (
	StrategyDirect    = "direct"
	StrategyBlueGreen = "blue_green"
	StrategyCanary    = "canary"
)

// Rollout statuses. Pending, progressing and waiting_promotion are open.
const (
	RolloutPending          = "pending"           // the new revision is being created; 0 % of the traffic
	RolloutProgressing      = "progressing"       // canary steps, or blue-green health checks before auto promotion
	RolloutWaitingPromotion = "waiting_promotion" // blue-green: ready, waiting for a manual promote
	RolloutPromoted         = "promoted"          // the new revision has all the traffic
	RolloutAborted          = "aborted"           // stopped before promotion; the traffic stayed on the previous revision
	RolloutRolledBack       = "rolled_back"       // threshold exceeded or rolled back after promotion
	RolloutFailed           = "failed"            // the new revision never became ready, or health checks did not pass
)

// Parts a deployment plays in the project's current rollout (Deployment.RolloutRole).
const (
	RolloutRoleCandidate = "candidate" // the new deployment
	RolloutRoleStable    = "stable"    // the deployment that serves until promotion
	RolloutRolePrevious  = "previous"  // the deployment that served before, kept warm after a blue-green promotion
)

// NormalizeStrategy maps a strategy name as people write it (direct, blue-green, blue_green,
// bluegreen, canary; any case) to the API's name. ok is false for an unknown name.
func NormalizeStrategy(s string) (string, bool) {
	switch strings.ToLower(strings.TrimSpace(s)) {
	case "direct":
		return StrategyDirect, true
	case "blue_green", "blue-green", "bluegreen":
		return StrategyBlueGreen, true
	case "canary":
		return StrategyCanary, true
	}
	return "", false
}

// StrategyLabel renders a strategy for people: blue_green is "blue-green".
func StrategyLabel(s string) string {
	if s == StrategyBlueGreen {
		return "blue-green"
	}
	if s == "" {
		return StrategyDirect
	}
	return s
}

// BlueGreenOptions are the options of the blue-green strategy.
type BlueGreenOptions struct {
	// AutoPromote promotes once the health checks passed; false waits for a manual promote.
	AutoPromote bool `json:"auto_promote"`
	// HealthPath is the path the health checks call (/healthz); nil relies on readiness alone.
	HealthPath *string `json:"health_path"`
	// HealthChecks is how many consecutive 2xx answers auto promotion needs (1-20, every 10 s).
	HealthChecks int `json:"health_checks"`
	// KeepPreviousMinutes keeps the previous revision warm after promotion for an instant
	// rollback (0-1440).
	KeepPreviousMinutes int `json:"keep_previous_minutes"`
}

// CanaryOptions are the options of the canary strategy.
type CanaryOptions struct {
	// Steps are the strictly increasing traffic percentages of the new revision (1-10 steps,
	// 1-100); reaching 100 promotes it.
	Steps []int `json:"steps"`
	// IntervalMinutes is how long each step lasts (1-1440).
	IntervalMinutes int `json:"interval_minutes"`
	// ErrorRateThreshold rolls back above this error rate in percent; nil is not checked.
	ErrorRateThreshold *float64 `json:"error_rate_threshold"`
	// P95ThresholdMs rolls back above this 95th percentile latency; nil is not checked.
	P95ThresholdMs *int `json:"p95_threshold_ms"`
}

// DeployStrategySettings are a strategy with the options of both strategies.
type DeployStrategySettings struct {
	Strategy  string           `json:"strategy"`
	BlueGreen BlueGreenOptions `json:"blue_green"`
	Canary    CanaryOptions    `json:"canary"`
}

// DeployStrategy is the deploy strategy of a project (GET /projects/{project}/deploy-strategy),
// complete with the defaults of what was never set.
type DeployStrategy struct {
	DeployStrategySettings
	// Eligible is set for the trigger types that can use a strategy (http, workflow); the
	// deploys of other projects are direct.
	Eligible    bool   `json:"eligible"`
	TriggerType string `json:"trigger_type,omitempty"`
	// Tracing is set when the project has tracing and its region accepts spans: a canary is
	// judged by the new revision's span metrics. Without it Knative's request metrics of the
	// revision judge it where the region's Prometheus has them, else it moves on time only.
	Tracing  bool                    `json:"tracing"`
	Defaults *DeployStrategySettings `json:"defaults,omitempty"`
	// Environment names the workload the settings are of: PrimaryEnvironment for the project's
	// own, else one of its environments (empty on APIs without environment strategies).
	Environment string `json:"environment,omitempty"`
	// Own is what the environment sets of its own over the project's settings (nil for the
	// project's own settings); Inherited is set while it sets nothing, Project names the
	// project's strategy then.
	Own       map[string]any `json:"own,omitempty"`
	Inherited bool           `json:"inherited,omitempty"`
	Project   string         `json:"project,omitempty"`
}

// OfEnvironment reports whether the settings are those of one of the project's environments
// (not of its own workload).
func (s *DeployStrategy) OfEnvironment() bool {
	return s.Environment != "" && s.Environment != PrimaryEnvironment
}

// Nullable is a setting of a partial update that can also be cleared: a nil *Nullable is not
// sent, one with Null set sends null, and otherwise Value is sent.
type Nullable[T any] struct {
	Value T
	Null  bool
}

// NullableOf returns a Nullable that sends v.
func NullableOf[T any](v T) *Nullable[T] { return &Nullable[T]{Value: v} }

// NullOf returns a Nullable that sends null.
func NullOf[T any]() *Nullable[T] { return &Nullable[T]{Null: true} }

// MarshalJSON writes null or the value.
func (n Nullable[T]) MarshalJSON() ([]byte, error) {
	if n.Null {
		return []byte("null"), nil
	}
	return json.Marshal(n.Value)
}

// UnmarshalJSON reads null or a value.
func (n *Nullable[T]) UnmarshalJSON(b []byte) error {
	if bytes.Equal(bytes.TrimSpace(b), []byte("null")) {
		var zero T
		*n = Nullable[T]{Value: zero, Null: true}
		return nil
	}
	n.Null = false
	return json.Unmarshal(b, &n.Value)
}

// BlueGreenInput changes blue-green options; nil fields are not sent.
type BlueGreenInput struct {
	AutoPromote         *bool             `json:"auto_promote,omitempty"`
	HealthPath          *Nullable[string] `json:"health_path,omitempty"`
	HealthChecks        *int              `json:"health_checks,omitempty"`
	KeepPreviousMinutes *int              `json:"keep_previous_minutes,omitempty"`
}

// CanaryInput changes canary options; nil fields are not sent.
type CanaryInput struct {
	Steps              []int              `json:"steps,omitempty"`
	IntervalMinutes    *int               `json:"interval_minutes,omitempty"`
	ErrorRateThreshold *Nullable[float64] `json:"error_rate_threshold,omitempty"`
	P95ThresholdMs     *Nullable[int]     `json:"p95_threshold_ms,omitempty"`
}

// DeployStrategyInput is the body of PUT /projects/{project}/deploy-strategy: what is set is
// merged onto the stored settings. Changing the strategy does not create a version.
type DeployStrategyInput struct {
	// Strategy is StrategyDirect, StrategyBlueGreen or StrategyCanary ("" keeps it).
	Strategy  string          `json:"strategy,omitempty"`
	BlueGreen *BlueGreenInput `json:"blue_green,omitempty"`
	Canary    *CanaryInput    `json:"canary,omitempty"`
	// Environment is set by UpdateDeployStrategyIn / ResetDeployStrategy: the environment whose
	// settings change ("" the project's).
	Environment string `json:"environment,omitempty"`
	// Reset drops what the environment sets of its own (ResetDeployStrategy).
	Reset bool `json:"reset,omitempty"`
}

// Empty reports whether nothing is set.
func (in DeployStrategyInput) Empty() bool {
	bgEmpty := in.BlueGreen == nil || *in.BlueGreen == BlueGreenInput{}
	cEmpty := in.Canary == nil || (in.Canary.Steps == nil && in.Canary.IntervalMinutes == nil &&
		in.Canary.ErrorRateThreshold == nil && in.Canary.P95ThresholdMs == nil)
	return in.Strategy == "" && bgEmpty && cEmpty
}

// RolloutTraffic is the split of the traffic in percent.
type RolloutTraffic struct {
	// Candidate is the share of the new revision, Stable that of the revision that served before.
	Candidate int `json:"candidate"`
	Stable    int `json:"stable"`
}

// RolloutStep is the current step of a canary rollout.
type RolloutStep struct {
	// Index counts from 0; Count is the number of steps.
	Index   int `json:"index"`
	Count   int `json:"count"`
	Percent int `json:"percent"`
	// NextPercent is the share of the next step; nil at the last step.
	NextPercent *int   `json:"next_percent"`
	StartedAt   string `json:"started_at,omitempty"`
	EndsAt      string `json:"ends_at,omitempty"`
}

// RolloutHealth are the health checks of a blue-green rollout that promotes automatically.
type RolloutHealth struct {
	Path string `json:"path"`
	// Passes counts the consecutive 2xx answers; Required is how many promote.
	Passes     int    `json:"passes"`
	Required   int    `json:"required"`
	LastStatus *int   `json:"last_status"`
	LastError  string `json:"last_error,omitempty"`
	CheckedAt  string `json:"checked_at,omitempty"`
	// DeadlineAt is when the rollout fails unless the checks passed.
	DeadlineAt string `json:"deadline_at,omitempty"`
}

// RolloutMetrics are what a canary rollout measured of the new revision (span metrics of the
// project's tracing) and the thresholds it is held to.
type RolloutMetrics struct {
	Tracing bool `json:"tracing"`
	// Source is where the new revision's numbers come from: MetricsFromSpans (its span metrics,
	// with tracing), MetricsFromKnative (Knative's request metrics of the revision) or "" — no
	// requests of the revision measured, the canary moves on time only.
	Source string `json:"source"`
	// Revision is the Knative revision measured: the canary's new one.
	Revision string `json:"revision,omitempty"`
	// ErrorRate is in percent; nil before enough requests were measured.
	ErrorRate *FlexFloat `json:"error_rate"`
	P95Ms     *FlexFloat `json:"p95_ms"`
	// Volume is the number of requests in the window (10 are needed to judge a threshold).
	Volume             int        `json:"volume"`
	WindowMinutes      FlexFloat  `json:"window_minutes"`
	MeasuredAt         string     `json:"measured_at,omitempty"`
	ErrorRateThreshold *FlexFloat `json:"error_rate_threshold"`
	P95ThresholdMs     *FlexFloat `json:"p95_threshold_ms"`
}

// Sources of a canary's metrics (RolloutMetrics.Source).
const (
	MetricsFromSpans   = "span_metrics"
	MetricsFromKnative = "knative"
)

// RolloutEvent is one entry of a rollout's history.
type RolloutEvent struct {
	At string `json:"at"`
	// Type is created, ready, step, health, promoted, aborted, rolled_back, failed, released or
	// traffic_failed.
	Type    string `json:"type"`
	Message string `json:"message"`
	// Traffic is the new revision's share after the event.
	Traffic int `json:"traffic"`
	// Actor is a username, or "system" for automatic actions.
	Actor string `json:"actor,omitempty"`
}

// RolloutActions say what the caller can do with a rollout now.
type RolloutActions struct {
	Promote  bool `json:"promote"`
	Abort    bool `json:"abort"`
	Rollback bool `json:"rollback"`
}

// Rollout is one blue-green or canary rollout of a new deployment.
type Rollout struct {
	ID        string `json:"id"`
	ProjectID string `json:"project_id,omitempty"`
	// DeploymentID is the new deployment, StableDeploymentID the one that served before (the
	// previous one after promotion).
	DeploymentID       string `json:"deployment_id"`
	StableDeploymentID string `json:"stable_deployment_id,omitempty"`
	// Environment is the environment whose workload the rollout moves the traffic of:
	// PrimaryEnvironment for the project's own (empty on APIs without environment rollouts).
	Environment   string `json:"environment,omitempty"`
	EnvironmentID string `json:"environment_id,omitempty"`
	// Strategy is StrategyBlueGreen or StrategyCanary.
	Strategy string `json:"strategy"`
	Status   string `json:"status"`
	// Open is set while pending, progressing or waiting for promotion.
	Open           bool           `json:"open"`
	Traffic        RolloutTraffic `json:"traffic"`
	Revision       string         `json:"revision,omitempty"`
	StableRevision string         `json:"stable_revision,omitempty"`
	// Tag is the traffic tag of the new revision (green, canary).
	Tag string `json:"tag,omitempty"`
	// TestURL reaches the new revision alone; empty without a function domain or once finished.
	TestURL string `json:"test_url,omitempty"`
	// PreviousURL reaches the previous revision while a promoted rollout keeps it.
	PreviousURL string `json:"previous_url,omitempty"`
	// Options are the strategy's options at deploy time.
	Options json.RawMessage `json:"options,omitempty"`
	Step    *RolloutStep    `json:"step,omitempty"`
	Health  *RolloutHealth  `json:"health,omitempty"`
	Metrics *RolloutMetrics `json:"metrics,omitempty"`
	// KeepUntil is when a promoted blue-green rollout releases the previous revision.
	KeepUntil   string `json:"keep_until,omitempty"`
	PromotedAt  string `json:"promoted_at,omitempty"`
	FinishedAt  string `json:"finished_at,omitempty"`
	ReleasedAt  string `json:"released_at,omitempty"`
	NextCheckAt string `json:"next_check_at,omitempty"`
	// Reason says why it was aborted, rolled back or failed.
	Reason    string         `json:"reason,omitempty"`
	Events    []RolloutEvent `json:"events,omitempty"`
	Can       RolloutActions `json:"can"`
	CreatedBy string         `json:"created_by,omitempty"`
	CreatedAt string         `json:"created_at,omitempty"`
	UpdatedAt string         `json:"updated_at,omitempty"`
}

// Ended reports whether the rollout ended without promotion (aborted, rolled back or failed).
func (r *Rollout) Ended() bool {
	switch r.Status {
	case RolloutAborted, RolloutRolledBack, RolloutFailed:
		return true
	}
	return false
}

// ---------------------------------------------------------------------------------------
// Client methods

// GetDeployStrategy returns the deploy strategy of a project with its options; ErrUnsupported
// on APIs without deploy strategies.
func (c *Client) GetDeployStrategy(ctx context.Context, projectID string) (*DeployStrategy, error) {
	return c.GetDeployStrategyIn(ctx, projectID, "")
}

// GetDeployStrategyIn returns the deploy strategy of one environment of the project (a name or
// id; "" the project's own): the project's settings with what the environment sets of its own
// over them.
func (c *Client) GetDeployStrategyIn(ctx context.Context, projectID, environment string) (*DeployStrategy, error) {
	r := request{method: http.MethodGet, path: "/projects/" + projectID + "/deploy-strategy"}
	if environment != "" {
		r.query = url.Values{"environment": {environment}}
	}
	return c.deployStrategy(ctx, r)
}

// UpdateDeployStrategy changes the strategy and options that are set in in (a partial update)
// and returns the complete settings. A strategy other than direct on a project whose trigger
// cannot use one is a 422 (errors.strategy).
func (c *Client) UpdateDeployStrategy(ctx context.Context, projectID string, in DeployStrategyInput) (*DeployStrategy, error) {
	return c.deployStrategy(ctx, request{method: http.MethodPut, path: "/projects/" + projectID + "/deploy-strategy", body: in})
}

// UpdateDeployStrategyIn is UpdateDeployStrategy for one environment of the project ("" the
// project's own): what in sets becomes the environment's own, over the project's settings.
func (c *Client) UpdateDeployStrategyIn(ctx context.Context, projectID, environment string, in DeployStrategyInput) (*DeployStrategy, error) {
	in.Environment = environment
	return c.UpdateDeployStrategy(ctx, projectID, in)
}

// ResetDeployStrategy drops what an environment sets of its own: it follows the project's
// settings again.
func (c *Client) ResetDeployStrategy(ctx context.Context, projectID, environment string) (*DeployStrategy, error) {
	return c.UpdateDeployStrategy(ctx, projectID, DeployStrategyInput{Environment: environment, Reset: true})
}

func (c *Client) deployStrategy(ctx context.Context, r request) (*DeployStrategy, error) {
	var raw []byte
	err := c.do(ctx, r, &raw)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	var s DeployStrategy
	if err := decodeData(raw, &s); err != nil {
		return nil, fmt.Errorf("decode the deploy strategy: %w", err)
	}
	return &s, nil
}

// ProjectRollout returns the project's newest rollout (open, kept or finished); nil when it
// never had one. ErrUnsupported on APIs without deploy strategies.
func (c *Client) ProjectRollout(ctx context.Context, projectID string) (*Rollout, error) {
	return c.ProjectRolloutIn(ctx, projectID, "")
}

// ProjectRolloutIn returns the newest rollout of one environment of the project (a name or id;
// "" the project's own workload); nil when it never had one.
func (c *Client) ProjectRolloutIn(ctx context.Context, projectID, environment string) (*Rollout, error) {
	var raw []byte
	r := request{method: http.MethodGet, path: "/projects/" + projectID + "/rollout"}
	if environment != "" {
		r.query = url.Values{"environment": {environment}}
	}
	err := c.do(ctx, r, &raw)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	var probe map[string]json.RawMessage
	if err := json.Unmarshal(raw, &probe); err != nil {
		return nil, fmt.Errorf("decode the rollout: %w", err)
	}
	if d, ok := probe["data"]; ok && (len(d) == 0 || bytes.Equal(bytes.TrimSpace(d), []byte("null"))) {
		return nil, nil
	}
	return decodeRollout(raw)
}

// DeploymentRollout returns the rollout that deploys a deployment; a 404 means the deployment
// is not the new deployment of a rollout.
func (c *Client) DeploymentRollout(ctx context.Context, projectID, deploymentID string) (*Rollout, error) {
	return c.rollout(ctx, request{method: http.MethodGet, path: rolloutPath(projectID, deploymentID)})
}

// PromoteRollout gives the new revision of an open rollout all the traffic (progressing or
// waiting for promotion). A rollout that cannot be promoted is a 409 (rollout_not_promotable);
// a traffic change the cluster refused a 502 (rollout_traffic_failed).
func (c *Client) PromoteRollout(ctx context.Context, projectID, deploymentID string) (*Rollout, error) {
	return c.rollout(ctx, request{method: http.MethodPost, path: rolloutPath(projectID, deploymentID) + "/promote"})
}

// AbortRollout stops an open rollout: the traffic stays on (or goes back to) the previous
// revision and the new deployment shuts down. reason (up to 500 characters) is optional. A
// rollout that is not open is a 409 (rollout_not_abortable).
func (c *Client) AbortRollout(ctx context.Context, projectID, deploymentID, reason string) (*Rollout, error) {
	var body any
	if reason != "" {
		body = map[string]string{"reason": reason}
	}
	return c.rollout(ctx, request{method: http.MethodPost, path: rolloutPath(projectID, deploymentID) + "/abort", body: body})
}

// RollbackRollout gives a promoted rollout's previous revision the traffic back while it is
// still kept (before keep_until); the new deployment shuts down. Otherwise a 409
// (rollout_not_rollbackable).
func (c *Client) RollbackRollout(ctx context.Context, projectID, deploymentID string) (*Rollout, error) {
	return c.rollout(ctx, request{method: http.MethodPost, path: rolloutPath(projectID, deploymentID) + "/rollback"})
}

func rolloutPath(projectID, deploymentID string) string {
	return "/projects/" + projectID + "/deployments/" + deploymentID + "/rollout"
}

func (c *Client) rollout(ctx context.Context, r request) (*Rollout, error) {
	var raw []byte
	if err := c.do(ctx, r, &raw); err != nil {
		return nil, err
	}
	return decodeRollout(raw)
}

func decodeRollout(raw []byte) (*Rollout, error) {
	var r Rollout
	if err := decodeData(raw, &r); err != nil {
		return nil, fmt.Errorf("decode the rollout: %w", err)
	}
	return &r, nil
}

// WaitRollout polls the rollout of a deployment until it is no longer open and returns it.
// Progress gets the status with the new revision's share of the traffic whenever it changes,
// and each new event. A rollout that ended aborted, rolled back or failed is a *FailedError
// with its reason.
func (c *Client) WaitRollout(ctx context.Context, projectID, deploymentID string, opts WaitOptions) (*Rollout, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	what := "rollout of deployment " + deploymentID
	last := ""
	seen := -1
	var cur *Rollout
	for {
		r, err := c.DeploymentRollout(ctx, projectID, deploymentID)
		if err != nil {
			if ctx.Err() != nil {
				return cur, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return cur, err
		}
		cur = r
		if seen < 0 {
			// The history before the wait is not reported again.
			seen = len(r.Events)
		}
		if opts.Progress != nil {
			for ; seen < len(r.Events); seen++ {
				e := r.Events[seen]
				opts.Progress(e.Type + ": " + e.Message)
			}
		}
		seen = max(seen, len(r.Events))
		status := RolloutStatusLine(r)
		if status != last {
			last = status
			if opts.Progress != nil {
				opts.Progress(status)
			}
		}
		if !r.Open {
			if r.Ended() {
				return r, rolloutEndedError(r)
			}
			return r, nil
		}
		select {
		case <-ctx.Done():
			return r, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
	}
}

// RolloutStatusLine is a one-line status: "progressing, 10 % of the traffic on the new revision".
func RolloutStatusLine(r *Rollout) string {
	return fmt.Sprintf("%s, %d %% of the traffic on the new revision", r.Status, r.Traffic.Candidate)
}

func rolloutEndedError(r *Rollout) error {
	msg := strings.ReplaceAll(r.Status, "_", " ")
	if r.Reason != "" {
		msg += ": " + r.Reason
	}
	return &FailedError{What: "rollout", Ref: r.ID, Status: r.Status, Message: msg}
}
