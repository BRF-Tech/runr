package main

// What RunR tells the service to enforce (GET /api/internal/gateway-auth/{region}/config,
// App\Services\ApiProduct\GatewayAuthConfig): the key and JWT routes of every organization with
// gateways in the region, their consumers, the hashes of the usable keys and the plans. Every
// route and consumer carries its organization (scope); a key opens only routes of its own
// organization and, for a consumer of one gateway, of that gateway.

// Config is the API's answer.
type Config struct {
	Version   string        `json:"version"`
	Routes    []RouteConfig `json:"routes"`
	Consumers []Consumer    `json:"consumers"`
	Keys      []Key         `json:"keys"`
	Plans     []Plan        `json:"plans"`
	// Partial lists organizations RunR could not read this time: what the previous snapshot
	// had for them is kept.
	Partial []string `json:"partial"`
}

// RouteConfig is a key or JWT route of a gateway.
type RouteConfig struct {
	ID        string     `json:"id"`
	Scope     string     `json:"scope"`
	GatewayID string     `json:"gateway_id"`
	Mode      string     `json:"mode"`
	JWT       *JWTConfig `json:"jwt"`
}

// JWTConfig is how a JWT route verifies tokens.
type JWTConfig struct {
	JWKSURL         string  `json:"jwks_url"`
	Issuer          *string `json:"issuer"`
	Audience        *string `json:"audience"`
	SubjectClaim    string  `json:"subject_claim"`
	RequireConsumer bool    `json:"require_consumer"`
}

// Consumer is a customer of a gateway's API (GatewayID nil: of the organization, every gateway).
type Consumer struct {
	ID         string  `json:"id"`
	Scope      string  `json:"scope"`
	GatewayID  *string `json:"gateway_id"`
	PlanID     *string `json:"plan_id"`
	Suspended  bool    `json:"suspended"`
	ExternalID *string `json:"external_id"`
	Subject    *string `json:"subject"`
}

// Key is a usable API key: its SHA-256 hash, never the key.
type Key struct {
	ID         string  `json:"id"`
	Hash       string  `json:"hash"`
	ConsumerID string  `json:"consumer_id"`
	PlanID     *string `json:"plan_id"`
	ExpiresAt  *int64  `json:"expires_at"`
	// Routes nil: every route the consumer may call; otherwise route id → methods (nil: all).
	Routes map[string][]string `json:"routes"`
}

// Plan holds the limits; a nil limit does not apply.
type Plan struct {
	ID        string `json:"id"`
	PerSecond *int64 `json:"per_second"`
	Burst     *int64 `json:"burst"`
	PerMinute *int64 `json:"per_minute"`
	PerDay    *int64 `json:"per_day"`
	PerMonth  *int64 `json:"per_month"`
}

// Limited reports whether the plan has any limit.
func (p *Plan) Limited() bool {
	return p != nil && (p.PerSecond != nil || p.PerMinute != nil || p.PerDay != nil || p.PerMonth != nil)
}

// Snapshot is Config indexed for lookups; it is never changed once built.
type Snapshot struct {
	Version   string
	routes    map[string]*RouteConfig
	keys      map[string]*Key      // by hash
	consumers map[string]*Consumer // by id
	subjects  map[string][]*Consumer
	plans     map[string]*Plan
	config    *Config
}

func subjectKey(scope, subject string) string {
	return scope + "|" + subject
}

// NewSnapshot indexes cfg. With cfg.Partial, the entries of those organizations come from prev.
func NewSnapshot(cfg *Config, prev *Snapshot) *Snapshot {
	if len(cfg.Partial) > 0 && prev != nil {
		cfg = mergePartial(cfg, prev.config)
	}

	s := &Snapshot{
		Version:   cfg.Version,
		routes:    make(map[string]*RouteConfig, len(cfg.Routes)),
		keys:      make(map[string]*Key, len(cfg.Keys)),
		consumers: make(map[string]*Consumer, len(cfg.Consumers)),
		subjects:  make(map[string][]*Consumer),
		plans:     make(map[string]*Plan, len(cfg.Plans)),
		config:    cfg,
	}
	for i := range cfg.Routes {
		r := &cfg.Routes[i]
		s.routes[r.ID] = r
	}
	for i := range cfg.Consumers {
		c := &cfg.Consumers[i]
		s.consumers[c.ID] = c
		if c.Subject != nil && *c.Subject != "" {
			k := subjectKey(c.Scope, *c.Subject)
			s.subjects[k] = append(s.subjects[k], c)
		}
	}
	for i := range cfg.Keys {
		k := &cfg.Keys[i]
		s.keys[k.Hash] = k
	}
	for i := range cfg.Plans {
		p := &cfg.Plans[i]
		s.plans[p.ID] = p
	}
	return s
}

// mergePartial: the organizations RunR could not read keep their previous routes, consumers,
// keys and plans.
func mergePartial(cfg, prev *Config) *Config {
	if prev == nil {
		return cfg
	}
	stale := make(map[string]bool, len(cfg.Partial))
	for _, scope := range cfg.Partial {
		stale[scope] = true
	}

	out := *cfg
	out.Routes = append([]RouteConfig(nil), cfg.Routes...)
	out.Consumers = append([]Consumer(nil), cfg.Consumers...)
	out.Keys = append([]Key(nil), cfg.Keys...)
	out.Plans = append([]Plan(nil), cfg.Plans...)

	for _, r := range prev.Routes {
		if stale[r.Scope] {
			out.Routes = append(out.Routes, r)
		}
	}
	kept := map[string]bool{}
	for _, c := range prev.Consumers {
		if stale[c.Scope] {
			out.Consumers = append(out.Consumers, c)
			kept[c.ID] = true
		}
	}
	plans := map[string]bool{}
	for _, p := range out.Plans {
		plans[p.ID] = true
	}
	for _, k := range prev.Keys {
		if kept[k.ConsumerID] {
			out.Keys = append(out.Keys, k)
		}
	}
	for _, p := range prev.Plans {
		if !plans[p.ID] {
			out.Plans = append(out.Plans, p)
			plans[p.ID] = true
		}
	}
	return &out
}

// Route returns the key / JWT route, nil when unknown.
func (s *Snapshot) Route(id string) *RouteConfig { return s.routes[id] }

// KeyByHash returns the usable key with this hash, nil when unknown.
func (s *Snapshot) KeyByHash(hash string) *Key { return s.keys[hash] }

// Consumer returns the consumer, nil when unknown.
func (s *Snapshot) Consumer(id string) *Consumer { return s.consumers[id] }

// Plan returns the plan, nil for a nil or unknown id.
func (s *Snapshot) Plan(id *string) *Plan {
	if id == nil {
		return nil
	}
	return s.plans[*id]
}

// ConsumerBySubject returns the consumer a JWT subject names on route r: one of the gateway, or
// one of the organization.
func (s *Snapshot) ConsumerBySubject(r *RouteConfig, subject string) *Consumer {
	var orgWide *Consumer
	for _, c := range s.subjects[subjectKey(r.Scope, subject)] {
		if c.GatewayID == nil {
			orgWide = c
			continue
		}
		if *c.GatewayID == r.GatewayID {
			return c
		}
	}
	return orgWide
}
