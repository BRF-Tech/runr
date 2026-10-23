package runr

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"strconv"
	"strings"
)

// Triggers (docs/triggers.md): the list of what invokes a project's function (HTTP requests, a
// schedule, a queue, a webhook inbox, a topic, platform events, …), the stored events of the
// webhook / platform_event triggers with their replay, and the organization's topics. A trigger
// is addressed by its id or name, a topic by its id or name. A change of the list reaches the
// cluster with the project's next deploy.

// Trigger types of the trigger list (GET /trigger-types has the authoritative list).
const (
	TriggerWorkflow      = "workflow"
	TriggerWebhook       = "webhook"
	TriggerTopic         = "topic"
	TriggerPlatformEvent = "platform_event"
)

// Statuses of a stored trigger event.
const (
	TriggerEventPending    = "pending"
	TriggerEventDelivering = "delivering"
	TriggerEventDelivered  = "delivered"
	TriggerEventFailed     = "failed"
)

// Trigger is one trigger of a project (TriggerPresenter): its settings, never its secrets (only
// the names of those that are set).
type Trigger struct {
	ID      string `json:"id,omitempty"`
	Name    string `json:"name"`
	Type    string `json:"type"`
	Label   string `json:"label,omitempty"`
	Enabled bool   `json:"enabled"`
	// Position orders the list; the first enabled trigger is the primary one, which the
	// project's config.trigger_type mirrors.
	Position int  `json:"position"`
	Primary  bool `json:"primary"`
	// RunsOn is service, gateway (the project's event gateway), cronjob, disabled or unknown.
	RunsOn   string           `json:"runs_on,omitempty"`
	Config   map[string]any   `json:"config"`
	Secrets  []string         `json:"secrets"`
	Delivery *TriggerDelivery `json:"delivery,omitempty"`
	// RetentionDays of the stored events (webhook, platform_event).
	RetentionDays *int `json:"retention_days,omitempty"`
	// URL is the public inbox URL of a webhook trigger.
	URL              string `json:"url,omitempty"`
	HasSigningSecret *bool  `json:"has_signing_secret,omitempty"`
	// EffectiveTimezone is the zone a schedule trigger runs in: config.timezone, or the
	// organization's time zone when that is not set.
	EffectiveTimezone string `json:"effective_timezone,omitempty"`
	CreatedAt         string `json:"created_at,omitempty"`
	UpdatedAt         string `json:"updated_at,omitempty"`
	// Cleanup is what an update left outside RunR that RunR removes later (UpdateTrigger: the
	// answer's cleanup, e.g. a renamed cdc trigger's old slot); not part of the trigger itself.
	Cleanup []TriggerCleanup `json:"-"`
}

// UnmarshalJSON accepts the config as an object or as [] (an empty PHP array).
func (t *Trigger) UnmarshalJSON(b []byte) error {
	type plain Trigger
	var v struct {
		plain
		Config json.RawMessage `json:"config"`
	}
	if err := json.Unmarshal(b, &v); err != nil {
		return err
	}
	*t = Trigger(v.plain)
	t.Config = map[string]any{}
	if len(v.Config) > 0 && v.Config[0] == '{' {
		if err := json.Unmarshal(v.Config, &t.Config); err != nil {
			return err
		}
	}
	return nil
}

// TriggerDelivery are the delivery settings of a trigger the event gateway runs.
type TriggerDelivery struct {
	Concurrency            int `json:"concurrency"`
	MaxRetries             int `json:"max_retries"`
	RetryBackoffSeconds    int `json:"retry_backoff_seconds"`
	RetryBackoffMaxSeconds int `json:"retry_backoff_max_seconds"`
}

// TriggerPlan says what the trigger list deploys.
type TriggerPlan struct {
	// Workload is service or cronjob (a schedule alone).
	Workload       string `json:"workload"`
	Public         bool   `json:"public"`
	Gateway        bool   `json:"gateway"`
	RuntimeTrigger string `json:"runtime_trigger,omitempty"`
	RequiresRedis  bool   `json:"requires_redis"`
}

// TriggerList is the answer of GET /projects/{project}/triggers and of a replacement.
type TriggerList struct {
	Triggers []Trigger   `json:"triggers"`
	Plan     TriggerPlan `json:"plan"`
	// Cleanup is what a replacement left outside RunR that RunR removes later (a removed cdc
	// trigger's replication slots); empty otherwise.
	Cleanup []TriggerCleanup `json:"cleanup,omitempty"`
}

// TriggerCleanup is one entry of `cleanup` in the answer of a trigger change: what the change
// left on an outside system and RunR removes once the event gateway no longer reads it (a cdc
// trigger's replication slots and the publication RunR created). RunR notifies the outcome.
type TriggerCleanup struct {
	Trigger     string   `json:"trigger"`
	Type        string   `json:"type,omitempty"`
	Slots       []string `json:"slots,omitempty"`
	Publication string   `json:"publication,omitempty"`
	Status      string   `json:"status,omitempty"`
	Message     string   `json:"message"`
}

// Primary returns the primary trigger (nil without one).
func (l *TriggerList) Primary() *Trigger {
	for i := range l.Triggers {
		if l.Triggers[i].Primary {
			return &l.Triggers[i]
		}
	}
	return nil
}

// Find returns the trigger with the id or name ref (nil when there is none).
func (l *TriggerList) Find(ref string) *Trigger {
	for i := range l.Triggers {
		if l.Triggers[i].ID == ref || l.Triggers[i].Name == ref {
			return &l.Triggers[i]
		}
	}
	return nil
}

// TriggerInput is the body of POST …/triggers (Type required) and PATCH …/triggers/{trigger}
// (Type ignored). Config replaces the stored config when given; Secrets are merged into the
// stored ones ("" removes one).
type TriggerInput struct {
	Type     string            `json:"type,omitempty"`
	Name     *string           `json:"name,omitempty"`
	Enabled  *bool             `json:"enabled,omitempty"`
	Position *int              `json:"position,omitempty"`
	Config   map[string]any    `json:"config,omitempty"`
	Secrets  map[string]string `json:"secrets,omitempty"`
}

// TriggerSpec is one entry of a whole list (PUT …/triggers, runr.yaml `triggers:`): a trigger
// whose name and type match an existing one keeps its secrets, inbox URL and stored events.
// Secrets are merged into the stored ones ("" removes one); an entry without them keeps them.
type TriggerSpec struct {
	Type    string            `json:"type"`
	Name    string            `json:"name,omitempty"`
	Enabled *bool             `json:"enabled,omitempty"`
	Config  map[string]any    `json:"config,omitempty"`
	Secrets map[string]string `json:"secrets,omitempty"`
}

// Trigger types of event sources the project's event gateway reads (docs/triggers.md).
const (
	TriggerKafka    = "kafka"
	TriggerNATS     = "nats"
	TriggerRabbitMQ = "rabbitmq"
	TriggerMQTT     = "mqtt"
	TriggerCDC      = "cdc"
	TriggerEmail    = "email"
	TriggerS3       = "s3"
)

// TriggerTypes are the trigger types of the trigger list (GET /trigger-types has the
// authoritative list of an API).
var TriggerTypes = []string{
	TriggerHTTP, TriggerSchedule, TriggerQueue, TriggerWorkflow, TriggerWebhook, TriggerTopic,
	TriggerPlatformEvent, TriggerKafka, TriggerNATS, TriggerRabbitMQ, TriggerMQTT, TriggerCDC,
	TriggerEmail, TriggerS3,
}

// TriggerSecretKeys are the write-only secrets each trigger type takes (TriggerType::secretRules).
var TriggerSecretKeys = map[string][]string{
	TriggerWebhook:  {"signing_secret"},
	TriggerKafka:    {"sasl_password"},
	TriggerNATS:     {"password", "token", "nkey_seed", "creds"},
	TriggerRabbitMQ: {"url"},
	TriggerMQTT:     {"password"},
	TriggerCDC:      {"dsn"},
	TriggerEmail:    {"password"},
	TriggerS3:       {"access_key", "secret_key"},
}

// TriggerEvent is one stored event of a webhook or platform_event trigger. Headers and the body
// are filled in the detail view (GetTriggerEvent).
type TriggerEvent struct {
	ID        string `json:"id"`
	TriggerID string `json:"trigger_id"`
	// Kind is webhook or platform.
	Kind string `json:"kind"`
	// Type is the CloudEvents type (dev.runr.webhook.received, dev.runr.deployment.finished).
	Type        string `json:"type"`
	Subject     string `json:"subject,omitempty"`
	DeliveryID  string `json:"delivery_id,omitempty"`
	ContentType string `json:"content_type,omitempty"`
	Size        int64  `json:"size"`
	// Status is pending, delivering, delivered or failed.
	Status      string `json:"status"`
	Attempts    int    `json:"attempts"`
	Replays     int    `json:"replays"`
	LastError   string `json:"last_error,omitempty"`
	ReceivedAt  string `json:"received_at,omitempty"`
	DeliveredAt string `json:"delivered_at,omitempty"`
	ExpiresAt   string `json:"expires_at,omitempty"`

	Headers    map[string]string `json:"headers,omitempty"`
	BodyBase64 string            `json:"body_base64,omitempty"`
	// BodyText is the body as text (UTF-8 bodies up to 64 KiB); nil otherwise.
	BodyText *string `json:"body_text,omitempty"`
}

// UnmarshalJSON accepts headers as an object or as [] (an empty PHP array) and header values
// given as lists.
func (e *TriggerEvent) UnmarshalJSON(b []byte) error {
	type plain TriggerEvent
	var v struct {
		plain
		Headers json.RawMessage `json:"headers"`
	}
	if err := json.Unmarshal(b, &v); err != nil {
		return err
	}
	*e = TriggerEvent(v.plain)
	e.Headers = nil
	if len(v.Headers) > 0 && v.Headers[0] == '{' {
		var raw map[string]any
		if err := json.Unmarshal(v.Headers, &raw); err != nil {
			return err
		}
		e.Headers = map[string]string{}
		for k, val := range raw {
			switch x := val.(type) {
			case string:
				e.Headers[k] = x
			case []any:
				parts := make([]string, 0, len(x))
				for _, p := range x {
					parts = append(parts, fmt.Sprint(p))
				}
				e.Headers[k] = strings.Join(parts, ", ")
			default:
				e.Headers[k] = fmt.Sprint(x)
			}
		}
	}
	return nil
}

// Topic is one fan-out topic of the organization; publishing to it delivers the event to every
// project with a topic trigger on it.
type Topic struct {
	ID          string `json:"id"`
	Name        string `json:"name"`
	Description string `json:"description,omitempty"`
	RegionID    string `json:"knative_region_id"`
	RegionName  string `json:"region_name,omitempty"`
	Stream      string `json:"stream,omitempty"`
	// CreatedBy is the creator's user id as a string (null: unknown).
	CreatedBy string `json:"created_by,omitempty"`
	CreatedAt string `json:"created_at,omitempty"`
	// Subscriptions is the number of topic triggers on it (list).
	Subscriptions int `json:"subscriptions"`
}

// UnmarshalJSON accepts subscriptions as a count (list) or as the list of subscribing
// triggers (detail).
func (t *Topic) UnmarshalJSON(b []byte) error {
	type plain Topic
	var v struct {
		plain
		Subscriptions json.RawMessage `json:"subscriptions"`
	}
	if err := json.Unmarshal(b, &v); err != nil {
		return err
	}
	*t = Topic(v.plain)
	t.Subscriptions = 0
	switch {
	case len(v.Subscriptions) == 0:
	case v.Subscriptions[0] == '[':
		var list []json.RawMessage
		if err := json.Unmarshal(v.Subscriptions, &list); err == nil {
			t.Subscriptions = len(list)
		}
	default:
		_ = json.Unmarshal(v.Subscriptions, &t.Subscriptions)
	}
	return nil
}

// PublishInput is the body of POST /topics/{topic}/publish. Data is any JSON value; with a
// ContentType other than application/json a JSON string is sent as is (text, XML, …).
type PublishInput struct {
	Data        json.RawMessage `json:"data"`
	ContentType string          `json:"content_type,omitempty"`
	Type        string          `json:"type,omitempty"`
	Subject     string          `json:"subject,omitempty"`
	ID          string          `json:"id,omitempty"`
}

// Published is the answer of a publish: the event id and its entry in the topic's stream.
type Published struct {
	ID       string `json:"id"`
	StreamID string `json:"stream_id"`
}

// ---------------------------------------------------------------------------------------
// Project triggers

func triggersPath(projectID string) string { return "/projects/" + projectID + "/triggers" }

func triggerPath(projectID, ref string) string { return triggersPath(projectID) + "/" + seg(ref) }

// decodeTriggerList decodes {data: [Trigger], plan}.
func decodeTriggerList(raw []byte) (*TriggerList, error) {
	var out struct {
		Data    []Trigger        `json:"data"`
		Plan    TriggerPlan      `json:"plan"`
		Cleanup []TriggerCleanup `json:"cleanup"`
	}
	if err := json.Unmarshal(raw, &out); err != nil {
		return nil, fmt.Errorf("decode triggers: %w", err)
	}
	if out.Data == nil {
		out.Data = []Trigger{}
	}
	return &TriggerList{Triggers: out.Data, Plan: out.Plan, Cleanup: out.Cleanup}, nil
}

// ListTriggers returns a project's triggers in order and what they deploy; ErrUnsupported for
// an API without the trigger list.
func (c *Client) ListTriggers(ctx context.Context, projectID string) (*TriggerList, error) {
	var raw []byte
	err := c.do(ctx, request{method: http.MethodGet, path: triggersPath(projectID)}, &raw)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	return decodeTriggerList(raw)
}

// FindTrigger returns one trigger of a project by id or name.
func (c *Client) FindTrigger(ctx context.Context, projectID, ref string) (*Trigger, error) {
	list, err := c.ListTriggers(ctx, projectID)
	if err != nil {
		return nil, err
	}
	t := list.Find(strings.TrimSpace(ref))
	if t == nil {
		return nil, &NotFoundError{Kind: "trigger", Ref: ref}
	}
	return t, nil
}

// CreateTrigger adds a trigger (in.Type required).
func (c *Client) CreateTrigger(ctx context.Context, projectID string, in TriggerInput) (*Trigger, error) {
	var out struct {
		Data Trigger `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: triggersPath(projectID), body: in}, &out)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// UpdateTrigger changes a trigger (by id or name); the type cannot change. Position 0 makes it
// the primary trigger.
func (c *Client) UpdateTrigger(ctx context.Context, projectID, ref string, in TriggerInput) (*Trigger, error) {
	in.Type = ""
	var out struct {
		Data    Trigger          `json:"data"`
		Cleanup []TriggerCleanup `json:"cleanup"`
	}
	err := c.do(ctx, request{method: http.MethodPatch, path: triggerPath(projectID, ref), body: in}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "trigger", Ref: ref}
	}
	if err != nil {
		return nil, err
	}
	out.Data.Cleanup = out.Cleanup
	return &out.Data, nil
}

// DeleteTrigger removes a trigger (by id or name); the last one cannot go (422). It returns what
// the removal left that RunR cleans up later (a cdc trigger's replication slots).
func (c *Client) DeleteTrigger(ctx context.Context, projectID, ref string) ([]TriggerCleanup, error) {
	var out struct {
		Cleanup []TriggerCleanup `json:"cleanup"`
	}
	err := c.do(ctx, request{method: http.MethodDelete, path: triggerPath(projectID, ref)}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "trigger", Ref: ref}
	}
	return out.Cleanup, err
}

// ReplaceTriggers sets the whole list (runr.yaml `triggers:`). Entries are matched to the
// existing triggers by name and type; the others are created or removed.
func (c *Client) ReplaceTriggers(ctx context.Context, projectID string, list []TriggerSpec) (*TriggerList, error) {
	if list == nil {
		list = []TriggerSpec{}
	}
	var raw []byte
	err := c.do(ctx, request{method: http.MethodPut, path: triggersPath(projectID), body: map[string]any{"triggers": list}}, &raw)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	return decodeTriggerList(raw)
}

// ---------------------------------------------------------------------------------------
// Stored events (webhook and platform_event triggers)

// TriggerEventFilter selects stored events of a trigger.
type TriggerEventFilter struct {
	// Status is pending, delivering, delivered or failed ("" = all).
	Status string
	// PerPage is the page size (1-100, the API's default 25).
	PerPage int
}

func (f TriggerEventFilter) values() url.Values {
	q := url.Values{}
	if f.Status != "" {
		q.Set("status", f.Status)
	}
	if f.PerPage > 0 {
		q.Set("per_page", strconv.Itoa(f.PerPage))
	}
	return q
}

// ListTriggerEvents returns the newest stored events of a webhook or platform_event trigger
// (first page). Other trigger types store no events (not found).
func (c *Client) ListTriggerEvents(ctx context.Context, projectID, trigger string, f TriggerEventFilter) ([]TriggerEvent, *Page, error) {
	var raw []byte
	err := c.do(ctx, request{method: http.MethodGet, path: triggerPath(projectID, trigger) + "/events", query: f.values()}, &raw)
	if IsNotFound(err) {
		return nil, nil, &NotFoundError{Kind: "trigger with stored events", Ref: trigger}
	}
	if err != nil {
		return nil, nil, err
	}
	var list []TriggerEvent
	var page Page
	if err := decodeList(raw, &list, &page); err != nil {
		return nil, nil, fmt.Errorf("decode trigger events: %w", err)
	}
	if list == nil {
		list = []TriggerEvent{}
	}
	return list, &page, nil
}

// GetTriggerEvent returns a stored event with the sender's headers and the body.
func (c *Client) GetTriggerEvent(ctx context.Context, projectID, trigger, eventID string) (*TriggerEvent, error) {
	var out struct {
		Data TriggerEvent `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: triggerPath(projectID, trigger) + "/events/" + seg(eventID)}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "trigger event", Ref: eventID}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// ReplayTriggerEvent delivers a stored event again (its status goes back to pending).
func (c *Client) ReplayTriggerEvent(ctx context.Context, projectID, trigger, eventID string) (*TriggerEvent, error) {
	var out struct {
		Data TriggerEvent `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: triggerPath(projectID, trigger) + "/events/" + seg(eventID) + "/replay"}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "trigger event", Ref: eventID}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// ReplayTriggerEvents delivers the stored events of a status again (failed, delivered or all;
// at most 500 per call) and returns how many were replayed.
func (c *Client) ReplayTriggerEvents(ctx context.Context, projectID, trigger, status string) (int, error) {
	var out struct {
		Replayed int `json:"replayed"`
	}
	body := map[string]any{"status": status}
	err := c.do(ctx, request{method: http.MethodPost, path: triggerPath(projectID, trigger) + "/events/replay", body: body}, &out)
	if IsNotFound(err) {
		return 0, &NotFoundError{Kind: "trigger with stored events", Ref: trigger}
	}
	if err != nil {
		return 0, err
	}
	return out.Replayed, nil
}

// ---------------------------------------------------------------------------------------
// Topics

// ListTopics returns the organization's topics with their number of subscriptions;
// ErrUnsupported for an API without topics.
func (c *Client) ListTopics(ctx context.Context) ([]Topic, error) {
	var raw []byte
	err := c.do(ctx, request{method: http.MethodGet, path: "/topics"}, &raw)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	var list []Topic
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, fmt.Errorf("decode topics: %w", err)
	}
	if list == nil {
		list = []Topic{}
	}
	return list, nil
}

// CreateTopic creates a topic in a region (the region needs Redis).
func (c *Client) CreateTopic(ctx context.Context, name, regionID, description string) (*Topic, error) {
	body := map[string]any{"name": name, "knative_region_id": regionID}
	if description != "" {
		body["description"] = description
	}
	var out struct {
		Data Topic `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: "/topics", body: body}, &out)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// GetTopic returns one topic (by id or name) with the number of triggers subscribing to it.
func (c *Client) GetTopic(ctx context.Context, ref string) (*Topic, error) {
	var out struct {
		Data Topic `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: "/topics/" + seg(ref)}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "topic", Ref: ref}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// UpdateTopicDescription changes a topic's description ("" removes it); its name and region
// cannot change.
func (c *Client) UpdateTopicDescription(ctx context.Context, ref, description string) (*Topic, error) {
	body := map[string]any{"description": nil}
	if description != "" {
		body["description"] = description
	}
	var out struct {
		Data Topic `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPatch, path: "/topics/" + seg(ref), body: body}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "topic", Ref: ref}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// DeleteTopic deletes a topic (by id or name); refused (409) while triggers subscribe to it.
func (c *Client) DeleteTopic(ctx context.Context, ref string) error {
	err := c.do(ctx, request{method: http.MethodDelete, path: "/topics/" + seg(ref)}, nil)
	if IsNotFound(err) {
		return &NotFoundError{Kind: "topic", Ref: ref}
	}
	return err
}

// PublishTopic publishes one event to a topic (by id or name).
func (c *Client) PublishTopic(ctx context.Context, ref string, in PublishInput) (*Published, error) {
	if len(in.Data) == 0 {
		in.Data = json.RawMessage("null")
	}
	var out struct {
		Data Published `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: "/topics/" + seg(ref) + "/publish", body: in}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "topic", Ref: ref}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}
