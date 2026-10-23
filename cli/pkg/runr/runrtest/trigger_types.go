package runrtest

import (
	"fmt"
	"regexp"
	"strings"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// What each trigger type checks and stores (App\Triggers\Types\*): the config fields it needs,
// its write-only secrets (some of them required), and the normalization of its config — the
// type's keys only, defaults filled in, empty values dropped (TriggerType::normalize). The event
// source types (kafka, nats, rabbitmq, mqtt, cdc, email, s3) run in the project's event gateway.

var (
	kafkaBrokerPattern = regexp.MustCompile(`^[A-Za-z0-9._-]+:\d{1,5}$`)
	cdcOperations      = []string{"insert", "update", "delete"}
	s3Events           = []string{"created", "updated", "deleted"}
)

// trigSecretKeys are the secrets a trigger type accepts (TriggerType::secretRules).
func trigSecretKeys(typ string) []string { return runr.TriggerSecretKeys[typ] }

// trigCheckConfig checks the type's config fields and the secrets it requires (the merged
// secrets: given now or stored already).
func trigCheckConfig(typ string, cfg map[string]any, secrets map[string]string) *apiErr {
	str := func(path string) string {
		v, _ := dig(cfg, path).(string)
		return strings.TrimSpace(v)
	}
	need := func(path string) *apiErr {
		if str(path) == "" {
			return errValidation("config."+path, "The config."+path+" field is required.")
		}
		return nil
	}
	needSecret := func(key string) *apiErr {
		if secrets[key] == "" {
			return errValidation("secrets."+key, "The secret "+key+" is required.")
		}
		return nil
	}
	first := func(errs ...*apiErr) *apiErr {
		for _, e := range errs {
			if e != nil {
				return e
			}
		}
		return nil
	}
	switch typ {
	case runr.TriggerKafka:
		brokers, _ := cfg["brokers"].([]any)
		if len(brokers) == 0 {
			return errValidation("config.brokers", "The config.brokers field is required.")
		}
		for i, b := range brokers {
			if bs, _ := b.(string); !kafkaBrokerPattern.MatchString(bs) {
				return errValidation(fmt.Sprintf("config.brokers.%d", i), fmt.Sprintf("The config.brokers.%d field format is invalid.", i))
			}
		}
		if err := need("topic"); err != nil {
			return err
		}
		if str("sasl.mechanism") != "" {
			return first(need("sasl.username"), needSecret("sasl_password"))
		}
	case runr.TriggerNATS:
		if err := first(need("url"), need("subject")); err != nil {
			return err
		}
		mode := str("auth.mode")
		if key := map[string]string{"user": "password", "token": "token", "nkey": "nkey_seed", "creds": "creds"}[mode]; key != "" {
			return needSecret(key)
		}
	case runr.TriggerRabbitMQ:
		return first(need("queue"), needSecret("url"))
	case runr.TriggerMQTT:
		return first(need("broker"), need("topic"))
	case runr.TriggerCDC:
		if err := first(need("publication"), needSecret("dsn")); err != nil {
			return err
		}
		if b, _ := cfg["create_publication"].(bool); b {
			if tables, _ := cfg["tables"].([]any); len(tables) == 0 {
				return errValidation("config.tables", "Name the tables of a publication RunR creates.")
			}
		}
	case runr.TriggerEmail:
		if err := first(need("host"), need("username"), needSecret("password")); err != nil {
			return err
		}
		if str("after") == "move" {
			return need("move_to")
		}
	case runr.TriggerS3:
		return first(need("endpoint"), need("bucket"), needSecret("access_key"), needSecret("secret_key"))
	}
	return nil
}

// trigNormalize is the stored config of a trigger of type typ.
func trigNormalize(typ string, cfg map[string]any) map[string]any {
	if cfg == nil {
		cfg = map[string]any{}
	}
	out := map[string]any{}
	get := func(k string) any { return dig(cfg, k) }
	switch typ {
	case runr.TriggerHTTP:
		return out
	case runr.TriggerSchedule:
		cron, _ := cfg["cron"].(string)
		out["cron"] = strings.TrimSpace(cron)
		if tz, _ := cfg["timezone"].(string); strings.TrimSpace(tz) != "" {
			out["timezone"] = strings.TrimSpace(tz)
		}
	case runr.TriggerQueue:
		stream, _ := cfg["stream"].(string)
		out["stream"] = stream
		if sf, _ := cfg["start_from"].(string); sf == "latest" {
			out["start_from"] = "latest"
		}
	case runr.TriggerTopic:
		topic, _ := cfg["topic"].(string)
		out["topic"] = topic
		out["start_from"] = oneOf(get("start_from"), "latest", "earliest")
	case runr.TriggerWorkflow:
		if qs, _ := cfg["queue_stream"].(string); qs != "" {
			out["queue_stream"] = qs
		}
		return out
	case runr.TriggerWebhook:
		verify := oneOf(get("verify"), "none", webhookPresets...)
		out["verify"] = verify
		if verify == "hmac" {
			h, _ := cfg["hmac"].(map[string]any)
			def := func(k, d string) string {
				if v, _ := h[k].(string); v != "" {
					return v
				}
				return d
			}
			out["hmac"] = map[string]any{"header": def("header", "X-Signature"), "algorithm": def("algorithm", "sha256"),
				"encoding": def("encoding", "hex"), "prefix": def("prefix", "")}
		}
		if (verify == "stripe" || verify == "slack") && !emptyValue(get("tolerance_seconds")) {
			out["tolerance_seconds"] = toInt(get("tolerance_seconds"))
		}
		if v := get("delivery_id_header"); !emptyValue(v) {
			out["delivery_id_header"] = fmt.Sprint(v)
		}
		if v := get("max_body_kb"); !emptyValue(v) {
			out["max_body_kb"] = toInt(v)
		}
		keepRetention(cfg, out)
	case runr.TriggerPlatformEvent:
		events := uniqueStrings(get("events"))
		for _, e := range events {
			if e == "*" {
				events = []any{"*"}
				break
			}
		}
		out["events"] = events
		if projects := uniqueStrings(get("projects")); len(projects) > 0 {
			out["projects"] = projects
		}
		keepRetention(cfg, out)
	case runr.TriggerKafka:
		only(cfg, out, "brokers:list", "topic", "group_id", "sasl.mechanism", "sasl.username", "max_wait")
		out["start_from"] = oneOf(get("start_from"), "latest", "earliest")
		keepTLS(cfg, out)
	case runr.TriggerNATS:
		only(cfg, out, "url", "subject", "stream", "durable", "queue_group", "ack_wait", "auth.mode", "auth.user")
		out["deliver"] = oneOf(get("deliver"), "new", "all")
		keepTLS(cfg, out)
	case runr.TriggerRabbitMQ:
		only(cfg, out, "queue", "declare:bool", "prefetch:int")
		if truthy(get("declare")) {
			if bindings, _ := cfg["bindings"].([]any); len(bindings) > 0 {
				list := []any{}
				for _, b := range bindings {
					bm, _ := b.(map[string]any)
					row := map[string]any{}
					for _, k := range []string{"exchange", "routing_key"} {
						if v := fmt.Sprint(nonNil(bm[k])); v != "" {
							row[k] = v
						}
					}
					list = append(list, row)
				}
				out["bindings"] = list
			}
		}
		keepTLS(cfg, out)
	case runr.TriggerMQTT:
		only(cfg, out, "broker", "topic", "client_id", "username", "shared_group")
		qos := 1
		if v := get("qos"); v != nil {
			if n := toInt(v); n >= 0 && n <= 2 {
				qos = n
			}
		}
		out["qos"] = float64(qos)
		out["clean_session"] = truthy(get("clean_session"))
		keepTLS(cfg, out)
	case runr.TriggerCDC:
		only(cfg, out, "publication", "tables:list", "slot", "status_interval")
		out["create_publication"] = truthy(get("create_publication"))
		out["create_slot"] = get("create_slot") == nil || truthy(get("create_slot"))
		out["operations"] = pickOr(cdcOperations, get("operations"), cdcOperations)
		// one delivery at a time keeps the commit order
		d, _ := cfg["delivery"].(map[string]any)
		nd := map[string]any{}
		for k, v := range d {
			nd[k] = v
		}
		nd["concurrency"] = float64(1)
		cfg = map[string]any{"delivery": nd}
	case runr.TriggerEmail:
		tls := oneOf(get("tls"), "implicit", "starttls", "none")
		only(cfg, out, "host", "username", "poll_interval", "move_to", "max_message_kb:int")
		port := 993
		if tls != "implicit" {
			port = 143
		}
		if v := get("port"); !emptyValue(v) {
			port = toInt(v)
		}
		out["port"] = float64(port)
		out["tls"] = tls
		mailbox, _ := cfg["mailbox"].(string)
		if mailbox == "" {
			mailbox = "INBOX"
		}
		out["mailbox"] = mailbox
		out["after"] = oneOf(get("after"), "mark_seen", "move", "delete")
		out["include_raw"] = truthy(get("include_raw"))
	case runr.TriggerS3:
		only(cfg, out, "endpoint", "region", "bucket", "prefix", "poll_interval", "checkpoint_key")
		out["secure"] = get("secure") == nil || truthy(get("secure"))
		out["path_style"] = get("path_style") == nil || truthy(get("path_style"))
		out["events"] = pickOr(s3Events, get("events"), []string{"created", "updated"})
		out["start_from"] = oneOf(get("start_from"), "latest", "earliest")
	default:
		return cfg
	}
	keepDelivery(cfg, out)
	return out
}

// only copies the keys that are set (not null, "" or []) with a cast: "key" (string),
// "key:int", "key:bool", "key:list" (a list of non-empty strings); dotted keys are paths.
func only(cfg, out map[string]any, keys ...string) {
	for _, spec := range keys {
		key, cast, _ := strings.Cut(spec, ":")
		v := dig(cfg, key)
		if emptyValue(v) {
			continue
		}
		var val any
		switch cast {
		case "int":
			val = float64(toInt(v))
		case "bool":
			val = truthy(v)
		case "list":
			list := []any{}
			items, ok := v.([]any)
			if !ok {
				items = []any{v}
			}
			for _, it := range items {
				if s := fmt.Sprint(nonNil(it)); s != "" {
					list = append(list, s)
				}
			}
			val = list
		default:
			val = fmt.Sprint(v)
		}
		setPath(out, key, val)
	}
}

// keepTLS stores tls when it is enabled or names a CA (BrokerTriggerType::tlsConfig).
func keepTLS(cfg, out map[string]any) {
	t, _ := cfg["tls"].(map[string]any)
	ca, _ := t["ca_pem"].(string)
	if !truthy(t["enabled"]) && ca == "" {
		return
	}
	tls := map[string]any{"enabled": true, "insecure_skip_verify": truthy(t["insecure_skip_verify"])}
	if ca != "" {
		tls["ca_pem"] = ca
	}
	out["tls"] = tls
}

// keepDelivery keeps the delivery settings that are set, as integers.
func keepDelivery(cfg, out map[string]any) {
	d, _ := cfg["delivery"].(map[string]any)
	kept := map[string]any{}
	for _, k := range []string{"concurrency", "max_retries", "retry_backoff_seconds", "retry_backoff_max_seconds"} {
		if v, ok := d[k]; ok && !emptyValue(v) {
			kept[k] = float64(toInt(v))
		}
	}
	if len(kept) > 0 {
		out["delivery"] = kept
	}
}

func keepRetention(cfg, out map[string]any) {
	if v, ok := cfg["retention_days"]; ok && !emptyValue(v) {
		out["retention_days"] = float64(toInt(v))
	}
}

func dig(m map[string]any, path string) any {
	var cur any = m
	for _, part := range strings.Split(path, ".") {
		mm, ok := cur.(map[string]any)
		if !ok {
			return nil
		}
		cur = mm[part]
	}
	return cur
}

func setPath(m map[string]any, path string, v any) {
	parts := strings.Split(path, ".")
	for _, p := range parts[:len(parts)-1] {
		next, ok := m[p].(map[string]any)
		if !ok {
			next = map[string]any{}
			m[p] = next
		}
		m = next
	}
	m[parts[len(parts)-1]] = v
}

func emptyValue(v any) bool {
	switch x := v.(type) {
	case nil:
		return true
	case string:
		return x == ""
	case []any:
		return len(x) == 0
	}
	return false
}

func truthy(v any) bool {
	switch x := v.(type) {
	case bool:
		return x
	case float64:
		return x != 0
	case string:
		return x != "" && x != "0" && x != "false"
	}
	return false
}

func toInt(v any) int {
	switch x := v.(type) {
	case float64:
		return int(x)
	case string:
		var n int
		_, _ = fmt.Sscanf(x, "%d", &n)
		return n
	case bool:
		if x {
			return 1
		}
	}
	return 0
}

func nonNil(v any) any {
	if v == nil {
		return ""
	}
	return v
}

// oneOf is v when it is one of the allowed values, else def.
func oneOf(v any, def string, allowed ...string) string {
	s, _ := v.(string)
	if s == def {
		return def
	}
	for _, a := range allowed {
		if s == a {
			return s
		}
	}
	return def
}

func uniqueStrings(v any) []any {
	items, _ := v.([]any)
	seen := map[string]bool{}
	out := []any{}
	for _, it := range items {
		s := fmt.Sprint(nonNil(it))
		if s == "" || seen[s] {
			continue
		}
		seen[s] = true
		out = append(out, s)
	}
	return out
}

// pickOr is the allowed values (in their order) the list v names (array_intersect); def when v
// is no list or names none of them.
func pickOr(allowed []string, v any, def []string) []any {
	items, _ := v.([]any)
	want := map[string]bool{}
	for _, it := range items {
		want[fmt.Sprint(it)] = true
	}
	out := []any{}
	for _, a := range allowed {
		if want[a] {
			out = append(out, a)
		}
	}
	if len(out) == 0 {
		for _, d := range def {
			out = append(out, d)
		}
	}
	return out
}
