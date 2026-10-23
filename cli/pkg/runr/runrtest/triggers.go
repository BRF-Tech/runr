package runrtest

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Triggers (routes/triggers.php, TriggerService): a project's trigger list with the rules of
// the real API that matter to clients (validation per type, one http trigger, workflow alone,
// the last trigger cannot go, secrets write-only and merged, replace by name and type), the
// stored events of webhook / platform_event triggers with replay, and the organization's topics.
// The primary trigger is mirrored into the project's config.trigger_type like
// ProjectTriggers::syncLegacyFields. Nothing is delivered: replayed events stay pending.

type trigState struct {
	lists map[string][]*fakeTrigger // project id → triggers in position order
	// events per trigger id, oldest first
	events    map[string][]*fakeTriggerEvent
	topics    []*runr.Topic
	published []TopicEvent
}

type fakeTrigger struct {
	id, name, typ string
	enabled       bool
	config        map[string]any
	secrets       map[string]string
	key           string // webhook inbox key
	created       string
	updated       string
}

type fakeTriggerEvent struct {
	data    runr.TriggerEvent
	headers map[string]string
	body    []byte
}

// TopicEvent is one event published to a topic through the API.
type TopicEvent struct {
	Topic       string
	Data        json.RawMessage
	ContentType string
	Type        string
	Subject     string
	ID          string
}

func (s *Server) trig() *trigState {
	return featureState(s, "triggers", func() *trigState {
		return &trigState{lists: map[string][]*fakeTrigger{}, events: map[string][]*fakeTriggerEvent{}}
	})
}

var (
	trigNamePattern   = regexp.MustCompile(`^[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])?$`)
	trigStreamPattern = regexp.MustCompile(`^[a-zA-Z0-9._-]{1,255}$`)
	topicNamePattern  = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$`)
	trigTypes         = runr.TriggerTypes
	webhookPresets    = []string{"none", "stripe", "github", "slack", "hmac"}
	platformEvents    = []string{"deployment.finished", "deployment.failed", "alert.fired", "alert.resolved",
		"runtime.patch_available", "cost.limit_reached", "environment.promotion.pending_approval",
		"environment.promotion.approved", "environment.promotion.rejected", "environment.promotion.deployed",
		"environment.promotion.failed", "*"}
)

const maxTriggers = 20

func init() {
	handle("GET /projects/{project}/triggers", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"data": s.trigListJSON(p), "plan": s.trigPlan(p)}, nil
	})
	handle("POST /projects/{project}/triggers", (*Server).trigCreate)
	handle("PUT /projects/{project}/triggers", (*Server).trigReplace)
	handle("PATCH /projects/{project}/triggers/{trigger}", (*Server).trigUpdate)
	handle("DELETE /projects/{project}/triggers/{trigger}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, t, err := s.trigFind(q)
		if err != nil {
			return 0, nil, err
		}
		list := s.trigList(p)
		if len(list) == 1 {
			return 0, nil, errValidation("trigger", "A project needs at least one trigger; disable it instead.")
		}
		var rest []*fakeTrigger
		for _, x := range list {
			if x != t {
				rest = append(rest, x)
			}
		}
		s.trig().lists[p.data.ID] = rest
		delete(s.trig().events, t.id)
		s.trigSync(p)
		out := map[string]any{"requires_deploy": true}
		// a cdc trigger's replication slot is dropped later (CdcSlotCleanup on the API)
		if t.typ == "cdc" {
			slot, _ := t.config["slot"].(string)
			if slot == "" {
				slot = "runr_" + strings.ReplaceAll(p.data.Slug+"_"+t.name, "-", "_")
			}
			out["cleanup"] = []map[string]any{{
				"trigger": t.name, "type": "cdc", "slots": []string{slot}, "status": "scheduled",
				"message": "RunR drops the replication slot(s) " + slot + " on the database once no event gateway reads them any more (after the project's next deploy) and notifies you of the outcome.",
			}}
		}
		return 200, out, nil
	})

	handle("GET /projects/{project}/triggers/{trigger}/events", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, t, err := s.trigFindInbox(q)
		if err != nil {
			return 0, nil, err
		}
		status := q.query("status")
		if status != "" && !trigOneOf(status, []string{"pending", "delivering", "delivered", "failed"}) {
			return 0, nil, errValidation("status", "The selected status is invalid.")
		}
		events := s.trig().events[t.id]
		items := []any{}
		for i := len(events) - 1; i >= 0; i-- {
			if status == "" || events[i].data.Status == status {
				items = append(items, events[i].data)
			}
		}
		per := 25
		if n, err := strconv.Atoi(q.query("per_page")); err == nil && n > 0 {
			per = n
		}
		total := len(items)
		if len(items) > per {
			items = items[:per]
		}
		last := (total + per - 1) / per
		if last < 1 {
			last = 1
		}
		return 200, map[string]any{"data": items, "meta": map[string]any{"current_page": 1, "last_page": last, "per_page": per, "total": total}}, nil
	})
	handle("GET /projects/{project}/triggers/{trigger}/events/{event}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		e, err := s.trigFindEvent(q)
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"data": trigEventDetail(e)}, nil
	})
	handle("POST /projects/{project}/triggers/{trigger}/events/{event}/replay", func(s *Server, q *featureReq) (int, any, *apiErr) {
		e, err := s.trigFindEvent(q)
		if err != nil {
			return 0, nil, err
		}
		trigReplay(e)
		return 200, map[string]any{"data": e.data}, nil
	})
	handle("POST /projects/{project}/triggers/{trigger}/events/replay", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, t, err := s.trigFindInbox(q)
		if err != nil {
			return 0, nil, err
		}
		status := q.str("status")
		if status == "" {
			status = "failed"
		}
		if !trigOneOf(status, []string{"failed", "delivered", "all"}) {
			return 0, nil, errValidation("status", "The selected status is invalid.")
		}
		n := 0
		for _, e := range s.trig().events[t.id] {
			st := e.data.Status
			if st == runr.TriggerEventPending || st == runr.TriggerEventDelivering || (status != "all" && st != status) {
				continue
			}
			trigReplay(e)
			n++
		}
		return 200, map[string]any{"replayed": n}, nil
	})

	handle("GET /topics", func(s *Server, q *featureReq) (int, any, *apiErr) {
		list := []any{}
		for _, t := range s.trigTopicsSorted() {
			list = append(list, s.topicJSON(t))
		}
		return 200, map[string]any{"data": list}, nil
	})
	handle("POST /topics", func(s *Server, q *featureReq) (int, any, *apiErr) {
		name := q.str("name")
		if !topicNamePattern.MatchString(name) {
			return 0, nil, errValidation("name", "The name field format is invalid.")
		}
		if s.trigTopic(name) != nil {
			return 0, nil, errValidation("name", "The name has already been taken.")
		}
		reg := s.regionByID(q.str("knative_region_id"))
		if reg == nil {
			return 0, nil, errValidation("knative_region_id", "The selected knative region id is invalid.")
		}
		if !reg.HasRedis {
			return 0, nil, &apiErr{status: 422, message: "Topics need Redis in the region."}
		}
		t := &runr.Topic{ID: s.newID(), Name: name, Description: q.str("description"), RegionID: reg.ID, RegionName: reg.Name,
			Stream: "runr.topic." + name, CreatedBy: s.User.ID.String(), CreatedAt: nowRFC3339()}
		st := s.trig()
		st.topics = append(st.topics, t)
		return 201, map[string]any{"data": s.topicJSON(t)}, nil
	})
	handle("DELETE /topics/{topic}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		t := s.trigTopic(q.Segs[1])
		if t == nil {
			return 0, nil, errNotFound()
		}
		if subs := s.trigSubscriptions(t.Name); len(subs) > 0 {
			return 0, nil, &apiErr{status: 409, message: fmt.Sprintf("The topic has %d subscriptions; remove those triggers first.", len(subs)),
				extra: map[string]any{"subscriptions": subs}}
		}
		st := s.trig()
		for i, x := range st.topics {
			if x == t {
				st.topics = append(st.topics[:i], st.topics[i+1:]...)
				break
			}
		}
		return 204, nil, nil
	})
	handle("POST /topics/{topic}/publish", func(s *Server, q *featureReq) (int, any, *apiErr) {
		t := s.trigTopic(q.Segs[1])
		if t == nil {
			return 0, nil, errNotFound()
		}
		var raw struct {
			Data json.RawMessage `json:"data"`
		}
		_ = json.Unmarshal(q.Raw, &raw)
		if len(raw.Data) == 0 {
			return 0, nil, errValidation("data", "The data field must be present.")
		}
		ev := TopicEvent{Topic: t.Name, Data: raw.Data, ContentType: q.str("content_type"), Type: q.str("type"),
			Subject: q.str("subject"), ID: q.str("id")}
		if ev.ID == "" {
			ev.ID = s.newID()
		}
		st := s.trig()
		st.published = append(st.published, ev)
		return 202, map[string]any{"data": map[string]any{"id": ev.ID, "stream_id": fmt.Sprintf("1700000000000-%d", len(st.published))}}, nil
	})
}

// ---------------------------------------------------------------------------------------
// Seed helpers and inspection (they lock s.mu)

// SeedTrigger adds a trigger to a project as the API would store it (no validation); secrets
// are write-only values (a webhook's signing_secret).
func (s *Server) SeedTrigger(projectName, typ, name string, config map[string]any, secrets map[string]string) runr.Trigger {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.bearer = ""
	p := s.mustProject(projectName)
	list := s.trigList(p)
	// Stored as the API decodes JSON (numbers are float64)
	var cfg map[string]any
	raw, _ := json.Marshal(config)
	_ = json.Unmarshal(raw, &cfg)
	t := s.trigNew(typ, name, cfg, secrets)
	s.trig().lists[p.data.ID] = append(list, t)
	s.trigSync(p)
	return s.trigPresent(p, t)
}

// SeedTriggerEvent stores an event of a webhook or platform_event trigger with the given
// status and body.
func (s *Server) SeedTriggerEvent(projectName, trigger, status, body string) runr.TriggerEvent {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.mustProject(projectName)
	t := s.trigByRef(p, trigger)
	if t == nil {
		s.t.Fatalf("runrtest: trigger %q of %q does not exist", trigger, projectName)
	}
	kind, typ := "webhook", "dev.runr.webhook.received"
	if t.typ == runr.TriggerPlatformEvent {
		kind, typ = "platform", "dev.runr.deployment.finished"
	}
	now := nowRFC3339()
	e := &fakeTriggerEvent{
		data: runr.TriggerEvent{ID: s.newID(), TriggerID: t.id, Kind: kind, Type: typ, DeliveryID: "dlv-" + s.newID(),
			ContentType: "application/json", Size: int64(len(body)), Status: status, Attempts: 1, ReceivedAt: now, ExpiresAt: now},
		headers: map[string]string{"content-type": "application/json", "user-agent": "GitHub-Hookshot/abc"},
		body:    []byte(body),
	}
	switch status {
	case runr.TriggerEventFailed:
		e.data.LastError = "function answered 500"
		e.data.Attempts = 6
	case runr.TriggerEventDelivered:
		e.data.DeliveredAt = now
	}
	st := s.trig()
	st.events[t.id] = append(st.events[t.id], e)
	return e.data
}

// SeedTopic creates a topic of the organization in a region.
func (s *Server) SeedTopic(name, regionName string) runr.Topic {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		s.t.Fatalf("runrtest: region %q does not exist", regionName)
	}
	t := &runr.Topic{ID: s.newID(), Name: name, RegionID: reg.ID, RegionName: reg.Name, Stream: "runr.topic." + name, CreatedAt: nowRFC3339()}
	st := s.trig()
	st.topics = append(st.topics, t)
	return *t
}

// Triggers returns a project's triggers as the API presents them.
func (s *Server) Triggers(projectName string) []runr.Trigger {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.bearer = ""
	p := s.projectByName(projectName)
	if p == nil {
		return nil
	}
	var out []runr.Trigger
	for _, t := range s.trigList(p) {
		out = append(out, s.trigPresent(p, t))
	}
	return out
}

// TriggerSecrets returns the stored secrets of a trigger.
func (s *Server) TriggerSecrets(projectName, trigger string) map[string]string {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return nil
	}
	t := s.trigByRef(p, trigger)
	if t == nil {
		return nil
	}
	out := map[string]string{}
	for k, v := range t.secrets {
		out[k] = v
	}
	return out
}

// TriggerEvents returns the stored events of a trigger, oldest first.
func (s *Server) TriggerEvents(projectName, trigger string) []runr.TriggerEvent {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return nil
	}
	t := s.trigByRef(p, trigger)
	if t == nil {
		return nil
	}
	var out []runr.TriggerEvent
	for _, e := range s.trig().events[t.id] {
		out = append(out, e.data)
	}
	return out
}

// Topics returns the organization's topics by name.
func (s *Server) Topics() []runr.Topic {
	s.mu.Lock()
	defer s.mu.Unlock()
	var out []runr.Topic
	for _, t := range s.trigTopicsSorted() {
		out = append(out, *t)
	}
	return out
}

// TopicEvents returns the events published to topics through the API, oldest first.
func (s *Server) TopicEvents() []TopicEvent {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]TopicEvent(nil), s.trig().published...)
}

func (s *Server) mustProject(name string) *project {
	p := s.projectByName(name)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", name)
	}
	return p
}

// ---------------------------------------------------------------------------------------
// Behaviour

// trigList returns the stored list; a project without one gets the trigger its config
// describes (TriggerService::ensureStored).
func (s *Server) trigList(p *project) []*fakeTrigger {
	st := s.trig()
	if list, ok := st.lists[p.data.ID]; ok && len(list) > 0 {
		return list
	}
	typ, _ := p.config["trigger_type"].(string)
	if typ == "" {
		typ = runr.TriggerHTTP
	}
	cfg := trigLegacySettings(typ, p.config)
	if typ == runr.TriggerSchedule {
		if zone, _ := p.config["schedule_timezone"].(string); zone != "" {
			cfg["timezone"] = zone
		}
	}
	t := s.trigNew(typ, typ, trigNormalize(typ, cfg), nil)
	st.lists[p.data.ID] = []*fakeTrigger{t}
	return st.lists[p.data.ID]
}

func (s *Server) trigNew(typ, name string, config map[string]any, secrets map[string]string) *fakeTrigger {
	if config == nil {
		config = map[string]any{}
	}
	if secrets == nil {
		secrets = map[string]string{}
	}
	now := nowRFC3339()
	t := &fakeTrigger{id: s.newID(), name: name, typ: typ, enabled: true, config: config, secrets: secrets, created: now, updated: now}
	if typ == runr.TriggerWebhook {
		t.key = fmt.Sprintf("%040d", s.idCounter)
	}
	return t
}

func (s *Server) trigByRef(p *project, ref string) *fakeTrigger {
	for _, t := range s.trigList(p) {
		if t.id == ref || t.name == ref {
			return t
		}
	}
	return nil
}

func (s *Server) trigFind(q *featureReq) (*project, *fakeTrigger, *apiErr) {
	p, err := s.projectOf(q)
	if err != nil {
		return nil, nil, err
	}
	t := s.trigByRef(p, q.Segs[3])
	if t == nil {
		return nil, nil, errNotFound()
	}
	return p, t, nil
}

// trigFindInbox resolves a trigger that stores its events (webhook, platform_event).
func (s *Server) trigFindInbox(q *featureReq) (*project, *fakeTrigger, *apiErr) {
	p, t, err := s.trigFind(q)
	if err != nil {
		return nil, nil, err
	}
	if t.typ != runr.TriggerWebhook && t.typ != runr.TriggerPlatformEvent {
		return nil, nil, errNotFound()
	}
	return p, t, nil
}

func (s *Server) trigFindEvent(q *featureReq) (*fakeTriggerEvent, *apiErr) {
	_, t, err := s.trigFindInbox(q)
	if err != nil {
		return nil, err
	}
	for _, e := range s.trig().events[t.id] {
		if e.data.ID == q.Segs[5] {
			return e, nil
		}
	}
	return nil, errNotFound()
}

func trigReplay(e *fakeTriggerEvent) {
	e.data.Status = runr.TriggerEventPending
	e.data.Replays++
	e.data.LastError = ""
	e.data.DeliveredAt = ""
}

func trigEventDetail(e *fakeTriggerEvent) map[string]any {
	raw, _ := json.Marshal(e.data)
	out := map[string]any{}
	_ = json.Unmarshal(raw, &out)
	out["headers"] = e.headers
	out["body_base64"] = base64.StdEncoding.EncodeToString(e.body)
	if utf8.Valid(e.body) && len(e.body) <= 65536 {
		out["body_text"] = string(e.body)
	} else {
		out["body_text"] = nil
	}
	return out
}

// trigInput reads {type, name, enabled, position, config, secrets} of a request body.
type trigInput struct {
	typ      string
	name     *string
	enabled  *bool
	position *int
	config   map[string]any
	secrets  map[string]any
	// hasConfig tells whether config was sent (PATCH replaces it then).
	hasConfig bool
}

func readTrigInput(body map[string]any) (trigInput, *apiErr) {
	in := trigInput{}
	in.typ, _ = body["type"].(string)
	if v, ok := body["name"]; ok && v != nil {
		n, ok := v.(string)
		if !ok {
			return in, errValidation("name", "The name field must be a string.")
		}
		in.name = &n
	}
	if v, ok := body["enabled"]; ok && v != nil {
		b, ok := v.(bool)
		if !ok {
			return in, errValidation("enabled", "The enabled field must be true or false.")
		}
		in.enabled = &b
	}
	if v, ok := body["position"]; ok && v != nil {
		f, ok := v.(float64)
		if !ok || f < 0 {
			return in, errValidation("position", "The position field must be an integer of at least 0.")
		}
		n := int(f)
		in.position = &n
	}
	if v, ok := body["config"]; ok && v != nil {
		m, ok := v.(map[string]any)
		if !ok {
			return in, errValidation("config", "The config field must be an array.")
		}
		in.config, in.hasConfig = m, true
	}
	if v, ok := body["secrets"]; ok && v != nil {
		m, ok := v.(map[string]any)
		if !ok {
			return in, errValidation("secrets", "The secrets field must be an array.")
		}
		in.secrets = m
	}
	return in, nil
}

// trigValidate checks one trigger of type typ: its name, config and secrets (merged into
// existing ones). It returns the secrets to store.
func (s *Server) trigValidate(typ, name string, cfg map[string]any, given map[string]any, existing map[string]string) (map[string]string, *apiErr) {
	if !trigOneOf(typ, trigTypes) {
		return nil, errValidation("type", "Unknown trigger type "+typ+".")
	}
	if !trigNamePattern.MatchString(name) {
		return nil, errValidation("name", "The name field format is invalid.")
	}
	str := func(k string) string { v, _ := cfg[k].(string); return v }
	switch typ {
	case runr.TriggerSchedule:
		if c := str("cron"); c == "" || (!strings.HasPrefix(c, "@") && len(strings.Fields(c)) != 5) {
			return nil, errValidation("config.cron", "The config.cron field must be a cron expression.")
		}
	case runr.TriggerQueue:
		if !trigStreamPattern.MatchString(str("stream")) || strings.HasPrefix(str("stream"), "runr.") {
			return nil, errValidation("config.stream", "The config.stream field format is invalid.")
		}
	case runr.TriggerTopic:
		if s.trigTopic(str("topic")) == nil {
			return nil, errValidation("config.topic", "The topic "+str("topic")+" does not exist.")
		}
	case runr.TriggerWebhook:
		if !trigOneOf(str("verify"), webhookPresets) {
			return nil, errValidation("config.verify", "The selected config.verify is invalid.")
		}
	case runr.TriggerPlatformEvent:
		events, _ := cfg["events"].([]any)
		if len(events) == 0 {
			return nil, errValidation("config.events", "The config.events field is required.")
		}
		for _, e := range events {
			if es, _ := e.(string); !trigOneOf(es, platformEvents) {
				return nil, errValidation("config.events", fmt.Sprintf("Unknown platform event %v.", e))
			}
		}
	}
	if d, ok := cfg["delivery"].(map[string]any); ok {
		if c, ok := d["concurrency"].(float64); ok && (c < 1 || c > 32) {
			return nil, errValidation("config.delivery.concurrency", "The config.delivery.concurrency field must be between 1 and 32.")
		}
	}
	secrets := map[string]string{}
	for k, v := range existing {
		secrets[k] = v
	}
	for k, v := range given {
		if !trigOneOf(k, trigSecretKeys(typ)) {
			return nil, errValidation("secrets", "Unknown secrets: "+k+".")
		}
		sv, _ := v.(string)
		if sv == "" {
			delete(secrets, k)
			continue
		}
		if typ == runr.TriggerWebhook && len(sv) < 8 {
			return nil, errValidation("secrets.signing_secret", "The secrets.signing_secret field must be at least 8 characters.")
		}
		secrets[k] = sv
	}
	if typ == runr.TriggerWebhook && str("verify") != "none" && secrets["signing_secret"] == "" {
		return nil, errValidation("secrets.signing_secret", "A signing secret is required to verify the sender.")
	}
	if err := trigCheckConfig(typ, cfg, secrets); err != nil {
		return nil, err
	}
	return secrets, nil
}

// trigAssertList checks the rules of the list as a whole.
func trigAssertList(list []*fakeTrigger) *apiErr {
	if len(list) > maxTriggers {
		return errValidation("triggers", fmt.Sprintf("A project has at most %d triggers.", maxTriggers))
	}
	http := 0
	for _, t := range list {
		if t.typ == runr.TriggerHTTP {
			http++
		}
		if t.typ == runr.TriggerWorkflow && len(list) > 1 {
			return errValidation("type", "A workflow trigger must be the project's only trigger.")
		}
	}
	if http > 1 {
		return errValidation("type", "A project has at most 1 http trigger.")
	}
	return nil
}

func (s *Server) trigCreate(q *featureReq) (int, any, *apiErr) {
	p, err := s.projectOf(q)
	if err != nil {
		return 0, nil, err
	}
	in, err := readTrigInput(q.Body)
	if err != nil {
		return 0, nil, err
	}
	list := s.trigList(p)
	name := in.typ
	if in.name != nil && *in.name != "" {
		name = *in.name
		for _, x := range list {
			if x.name == name {
				return 0, nil, errValidation("name", "A trigger named "+name+" exists already.")
			}
		}
	} else {
		name = trigUniqueName(list, strings.ReplaceAll(in.typ, "_", "-"))
	}
	if in.config == nil {
		in.config = map[string]any{}
	}
	secrets, err := s.trigValidate(in.typ, name, in.config, in.secrets, nil)
	if err != nil {
		return 0, nil, err
	}
	t := s.trigNew(in.typ, name, trigNormalize(in.typ, in.config), secrets)
	if in.enabled != nil {
		t.enabled = *in.enabled
	}
	next := append([]*fakeTrigger{}, list...)
	pos := len(next)
	if in.position != nil && *in.position < pos {
		pos = *in.position
	}
	next = append(next[:pos], append([]*fakeTrigger{t}, next[pos:]...)...)
	if err := trigAssertList(next); err != nil {
		return 0, nil, err
	}
	s.trig().lists[p.data.ID] = next
	s.trigSync(p)
	return 201, map[string]any{"data": s.trigPresent(p, t), "requires_deploy": true}, nil
}

func (s *Server) trigUpdate(q *featureReq) (int, any, *apiErr) {
	p, t, err := s.trigFind(q)
	if err != nil {
		return 0, nil, err
	}
	in, err := readTrigInput(q.Body)
	if err != nil {
		return 0, nil, err
	}
	cfg := t.config
	if in.hasConfig {
		cfg = in.config
	}
	name := t.name
	if in.name != nil && *in.name != t.name {
		for _, x := range s.trigList(p) {
			if x.name == *in.name {
				return 0, nil, errValidation("name", "A trigger named "+*in.name+" exists already.")
			}
		}
		name = *in.name
	}
	secrets, err := s.trigValidate(t.typ, name, cfg, in.secrets, t.secrets)
	if err != nil {
		return 0, nil, err
	}
	t.name, t.config, t.secrets = name, trigNormalize(t.typ, cfg), secrets
	if in.enabled != nil {
		t.enabled = *in.enabled
	}
	if in.position != nil {
		var rest []*fakeTrigger
		for _, x := range s.trigList(p) {
			if x != t {
				rest = append(rest, x)
			}
		}
		pos := *in.position
		if pos > len(rest) {
			pos = len(rest)
		}
		s.trig().lists[p.data.ID] = append(rest[:pos], append([]*fakeTrigger{t}, rest[pos:]...)...)
	}
	t.updated = nowRFC3339()
	s.trigSync(p)
	return 200, map[string]any{"data": s.trigPresent(p, t), "requires_deploy": true}, nil
}

func (s *Server) trigReplace(q *featureReq) (int, any, *apiErr) {
	p, err := s.projectOf(q)
	if err != nil {
		return 0, nil, err
	}
	raw, _ := q.Body["triggers"].([]any)
	if len(raw) == 0 {
		return 0, nil, errValidation("triggers", "The triggers field is required.")
	}
	existing := map[string]*fakeTrigger{}
	for _, t := range s.trigList(p) {
		existing[t.name] = t
	}
	var planned []*fakeTrigger
	type change struct {
		t       *fakeTrigger
		name    string
		cfg     map[string]any
		secrets map[string]string
		enabled bool
	}
	var changes []change
	names := map[string]bool{}
	for i, item := range raw {
		m, _ := item.(map[string]any)
		in, err := readTrigInput(m)
		if err != nil {
			return 0, nil, trigPrefixErr(err, i)
		}
		if !trigOneOf(in.typ, trigTypes) {
			return 0, nil, errValidation(fmt.Sprintf("triggers.%d.type", i), "Unknown trigger type "+in.typ+".")
		}
		name := ""
		if in.name != nil {
			name = *in.name
		}
		if name == "" {
			var seen []*fakeTrigger
			for n := range names {
				seen = append(seen, &fakeTrigger{name: n})
			}
			name = trigUniqueName(seen, strings.ReplaceAll(in.typ, "_", "-"))
		}
		if names[name] {
			return 0, nil, errValidation(fmt.Sprintf("triggers.%d.name", i), "A trigger named "+name+" exists already.")
		}
		names[name] = true
		t := existing[name]
		if t == nil || t.typ != in.typ {
			t = s.trigNew(in.typ, name, nil, nil)
		}
		if in.config == nil {
			in.config = map[string]any{}
		}
		secrets, err := s.trigValidate(in.typ, name, in.config, in.secrets, t.secrets)
		if err != nil {
			return 0, nil, trigPrefixErr(err, i)
		}
		enabled := true
		if in.enabled != nil {
			enabled = *in.enabled
		}
		changes = append(changes, change{t: t, name: name, cfg: in.config, secrets: secrets, enabled: enabled})
		planned = append(planned, &fakeTrigger{typ: in.typ, name: name})
	}
	if err := trigAssertList(planned); err != nil {
		return 0, nil, err
	}
	st := s.trig()
	var next []*fakeTrigger
	kept := map[*fakeTrigger]bool{}
	for _, c := range changes {
		c.t.name, c.t.config, c.t.secrets, c.t.enabled = c.name, trigNormalize(c.t.typ, c.cfg), c.secrets, c.enabled
		c.t.updated = nowRFC3339()
		next = append(next, c.t)
		kept[c.t] = true
	}
	for _, old := range s.trigList(p) {
		if !kept[old] {
			delete(st.events, old.id)
		}
	}
	st.lists[p.data.ID] = next
	s.trigSync(p)
	return 200, map[string]any{"data": s.trigListJSON(p), "plan": s.trigPlan(p), "requires_deploy": true}, nil
}

func trigPrefixErr(err *apiErr, i int) *apiErr {
	out := &apiErr{status: err.status, message: err.message, errors: map[string][]string{}}
	for k, v := range err.errors {
		out.errors[fmt.Sprintf("triggers.%d.%s", i, k)] = v
	}
	return out
}

func trigUniqueName(list []*fakeTrigger, base string) string {
	taken := map[string]bool{}
	for _, t := range list {
		taken[t.name] = true
	}
	name := base
	for i := 2; taken[name]; i++ {
		name = base + "-" + strconv.Itoa(i)
	}
	return name
}

// trigSync mirrors the primary trigger into the project's config (syncLegacyFields).
func (s *Server) trigSync(p *project) {
	list := s.trigList(p)
	primary := s.trigPrimary(list)
	if primary == nil {
		primary = list[0]
	}
	p.config["trigger_type"] = primary.typ
	delete(p.config, "schedule")
	delete(p.config, "queue_stream")
	for _, t := range list {
		if t.typ == runr.TriggerSchedule && p.config["schedule"] == nil {
			p.config["schedule"] = t.config["cron"]
		}
		if t.typ == runr.TriggerQueue && p.config["queue_stream"] == nil {
			p.config["queue_stream"] = t.config["stream"]
		}
	}
	if primary.typ == runr.TriggerWorkflow && p.config["queue_stream"] == nil {
		if qs, ok := primary.config["queue_stream"]; ok {
			p.config["queue_stream"] = qs
		}
	}
	s.trigScheduleZoneFields(p)
}

// trigScheduleTrigger is the schedule trigger config.schedule and config.schedule_timezone
// describe (ProjectTriggers::scheduleTrigger): the primary trigger when it is a schedule, else
// the first schedule trigger.
func (s *Server) trigScheduleTrigger(list []*fakeTrigger) *fakeTrigger {
	primary := s.trigPrimary(list)
	if primary == nil && len(list) > 0 {
		primary = list[0]
	}
	if primary != nil && primary.typ == runr.TriggerSchedule {
		return primary
	}
	for _, t := range list {
		if t.typ == runr.TriggerSchedule {
			return t
		}
	}
	return nil
}

// trigScheduleZoneFields writes config.schedule_timezone / schedule_effective_timezone from the
// stored list (ProjectTriggers::scheduleTimezoneFields): the schedule trigger's own zone (null:
// the organization's) and the zone it runs in; both null without a schedule trigger.
func (s *Server) trigScheduleZoneFields(p *project) {
	list := s.trig().lists[p.data.ID]
	if len(list) == 0 {
		return
	}
	t := s.trigScheduleTrigger(list)
	if t == nil {
		p.config["schedule_timezone"], p.config["schedule_effective_timezone"] = nil, nil
		return
	}
	zone, _ := t.config["timezone"].(string)
	p.config["schedule_timezone"] = nil
	effective := s.OrgTimezone
	if effective == "" {
		effective = OrgTimezone
	}
	if zone != "" {
		p.config["schedule_timezone"], effective = zone, zone
	}
	p.config["schedule_effective_timezone"] = effective
}

// trigSyncFromConfig keeps the stored list in step with a project config change of
// trigger_type, schedule or queue_stream (ProjectTriggers::syncFromConfig), and applies
// config.schedule_timezone to the schedule trigger (ProjectTriggers::setScheduleTimezone). A
// project whose list was never stored gets it from the config when it is first read.
func (s *Server) trigSyncFromConfig(p *project, sent map[string]any) {
	st := s.trig()
	list := st.lists[p.data.ID]
	if len(list) == 0 {
		return
	}
	_, typeSent := sent["trigger_type"]
	_, scheduleSent := sent["schedule"]
	_, queueSent := sent["queue_stream"]
	if typeSent || scheduleSent || queueSent {
		typ, _ := p.config["trigger_type"].(string)
		if typ == "" {
			typ = runr.TriggerHTTP
		}
		if settings := trigLegacySettings(typ, p.config); settings != nil {
			primary := list[0]
			if primary.typ == typ {
				merged := map[string]any{}
				for k, v := range primary.config {
					merged[k] = v
				}
				for k, v := range settings {
					merged[k] = v
				}
				primary.config, primary.enabled = trigNormalize(typ, merged), true
			} else {
				var keep []*fakeTrigger
				for _, o := range list[1:] {
					if typ == runr.TriggerWorkflow || (o.typ == typ && typ == runr.TriggerHTTP) {
						delete(st.events, o.id)
						continue
					}
					keep = append(keep, o)
				}
				name := primary.name
				if primary.name == primary.typ {
					taken := false
					for _, o := range keep {
						taken = taken || o.name == typ
					}
					if !taken {
						name = typ
					}
				}
				primary.typ, primary.name, primary.config, primary.enabled = typ, name, trigNormalize(typ, settings), true
				primary.secrets = map[string]string{}
				list = append([]*fakeTrigger{primary}, keep...)
				st.lists[p.data.ID] = list
			}
			primary.updated = nowRFC3339()
		}
	}
	if zone, ok := sent["schedule_timezone"]; ok {
		if t := s.trigScheduleTrigger(list); t != nil {
			z, _ := zone.(string)
			if z = strings.TrimSpace(z); z == "" {
				delete(t.config, "timezone")
			} else {
				t.config["timezone"] = z
			}
		}
	}
	// The config row keeps the fields it was saved with (syncLegacyFields runs on list changes);
	// the schedule's zone is always the schedule trigger's
	s.trigScheduleZoneFields(p)
}

// trigLegacySettings are the settings the single-trigger config fields give a trigger of typ
// (ProjectTriggers::legacySettings); nil for a type they cannot describe.
func trigLegacySettings(typ string, cfg map[string]any) map[string]any {
	str := func(k, def string) string {
		if v, _ := cfg[k].(string); v != "" {
			return v
		}
		return def
	}
	switch typ {
	case runr.TriggerHTTP:
		return map[string]any{}
	case runr.TriggerSchedule:
		return map[string]any{"cron": str("schedule", "*/5 * * * *")}
	case runr.TriggerQueue:
		return map[string]any{"stream": str("queue_stream", "default-stream")}
	case runr.TriggerWorkflow:
		if qs := str("queue_stream", ""); qs != "" {
			return map[string]any{"queue_stream": qs}
		}
		return map[string]any{}
	}
	return nil
}

func (s *Server) trigPrimary(list []*fakeTrigger) *fakeTrigger {
	for _, t := range list {
		if t.enabled {
			return t
		}
	}
	return nil
}

// trigCronJob reports whether the enabled list is a schedule alone (the project's CronJob).
func (s *Server) trigCronJob(list []*fakeTrigger) bool {
	var enabled []*fakeTrigger
	for _, t := range list {
		if t.enabled {
			enabled = append(enabled, t)
		}
	}
	return len(enabled) == 1 && enabled[0].typ == runr.TriggerSchedule
}

func (s *Server) trigRunsOn(list []*fakeTrigger, t *fakeTrigger) string {
	switch {
	case !t.enabled:
		return "disabled"
	case t.typ == runr.TriggerHTTP || t.typ == runr.TriggerWorkflow:
		return "service"
	case t.typ == runr.TriggerSchedule && s.trigCronJob(list):
		return "cronjob"
	}
	return "gateway"
}

func (s *Server) trigPresent(p *project, t *fakeTrigger) runr.Trigger {
	list := s.trigList(p)
	pos := 0
	for i, x := range list {
		if x == t {
			pos = i
		}
	}
	cfg := map[string]any{}
	for k, v := range t.config {
		cfg[k] = v
	}
	secrets := []string{}
	for k, v := range t.secrets {
		if v != "" {
			secrets = append(secrets, k)
		}
	}
	sort.Strings(secrets)
	out := runr.Trigger{ID: t.id, Name: t.name, Type: t.typ, Label: t.typ, Enabled: t.enabled, Position: pos,
		Primary: s.trigPrimary(list) == t, RunsOn: s.trigRunsOn(list, t), Config: cfg, Secrets: secrets,
		CreatedAt: t.created, UpdatedAt: t.updated}
	if t.typ != runr.TriggerHTTP && t.typ != runr.TriggerWorkflow {
		d := runr.TriggerDelivery{Concurrency: 4, MaxRetries: 5, RetryBackoffSeconds: 2, RetryBackoffMaxSeconds: 300}
		if m, ok := t.config["delivery"].(map[string]any); ok {
			num := func(k string, dst *int) {
				if f, ok := m[k].(float64); ok {
					*dst = int(f)
				}
			}
			num("concurrency", &d.Concurrency)
			num("max_retries", &d.MaxRetries)
			num("retry_backoff_seconds", &d.RetryBackoffSeconds)
			num("retry_backoff_max_seconds", &d.RetryBackoffMaxSeconds)
		}
		out.Delivery = &d
	}
	if t.typ == runr.TriggerWebhook || t.typ == runr.TriggerPlatformEvent {
		days := 7
		if f, ok := t.config["retention_days"].(float64); ok {
			days = int(f)
		}
		out.RetentionDays = &days
	}
	if t.typ == runr.TriggerWebhook {
		out.URL = s.URL + "/api/hooks/org/" + t.key
		has := t.secrets["signing_secret"] != ""
		out.HasSigningSecret = &has
	}
	if t.typ == runr.TriggerSchedule {
		// ScheduleTrigger::present: its own zone, else the organization's
		out.EffectiveTimezone = OrgTimezone
		if zone, ok := t.config["timezone"].(string); ok && zone != "" {
			out.EffectiveTimezone = zone
		}
	}
	return out
}

// OrgTimezone is the fake organization's time zone (OrgTimezone::DEFAULT of the API).
const OrgTimezone = "Europe/Istanbul"

func (s *Server) trigListJSON(p *project) []runr.Trigger {
	out := []runr.Trigger{}
	for _, t := range s.trigList(p) {
		out = append(out, s.trigPresent(p, t))
	}
	return out
}

func (s *Server) trigPlan(p *project) runr.TriggerPlan {
	list := s.trigList(p)
	plan := runr.TriggerPlan{Workload: "service", RuntimeTrigger: "queue"}
	if s.trigCronJob(list) {
		plan.Workload, plan.RuntimeTrigger = "cronjob", "schedule"
	}
	for _, t := range list {
		if !t.enabled {
			continue
		}
		switch s.trigRunsOn(list, t) {
		case "service":
			plan.Public = true
			plan.RuntimeTrigger = "http"
		case "gateway":
			plan.Gateway = true
		}
		if t.typ == runr.TriggerQueue || t.typ == runr.TriggerTopic {
			plan.RequiresRedis = true
		}
	}
	return plan
}

func (s *Server) trigTopic(ref string) *runr.Topic {
	for _, t := range s.trig().topics {
		if t.ID == ref || t.Name == ref {
			return t
		}
	}
	return nil
}

func (s *Server) trigTopicsSorted() []*runr.Topic {
	list := append([]*runr.Topic(nil), s.trig().topics...)
	sort.Slice(list, func(i, j int) bool { return list[i].Name < list[j].Name })
	return list
}

// trigSubscriptions returns the topic triggers on a topic ({project_id, trigger}).
func (s *Server) trigSubscriptions(topic string) []map[string]any {
	var out []map[string]any
	ids := make([]string, 0, len(s.trig().lists))
	for id := range s.trig().lists {
		ids = append(ids, id)
	}
	sort.Strings(ids)
	for _, id := range ids {
		for _, t := range s.trig().lists[id] {
			if t.typ == runr.TriggerTopic && t.config["topic"] == topic {
				out = append(out, map[string]any{"project_id": id, "trigger": t.name})
			}
		}
	}
	return out
}

func (s *Server) topicJSON(t *runr.Topic) map[string]any {
	return map[string]any{"id": t.ID, "name": t.Name, "description": wfNil(t.Description), "knative_region_id": t.RegionID,
		"region_name": t.RegionName, "stream": t.Stream, "created_by": wfNil(t.CreatedBy), "created_at": t.CreatedAt,
		"subscriptions": len(s.trigSubscriptions(t.Name))}
}

func trigOneOf(v string, list []string) bool {
	for _, x := range list {
		if x == v {
			return true
		}
	}
	return false
}
