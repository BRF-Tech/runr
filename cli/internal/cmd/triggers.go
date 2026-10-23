package cmd

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"sort"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Triggers (docs/triggers.md): what invokes a project's function, the stored events of webhook
// and platform_event triggers with their replay, and the organization's topics.

func init() {
	registerCommand(newTriggersCmd)
	registerCommand(newTopicsCmd)
}

// errNoTriggers is the answer of an API without the trigger list.
var errNoTriggers = errors.New("this RunR API has no trigger list (an older RunR version); use trigger (http, schedule or queue) in runr.yaml")

func noTriggers(err error) error {
	if errors.Is(err, runr.ErrUnsupported) {
		return errNoTriggers
	}
	return err
}

func newTriggersCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "triggers",
		Aliases: []string{"trigger"},
		Short:   "List and change what invokes the function: HTTP, schedule, queue, webhook, topic, …",
		Long: "A project has a list of triggers (docs/triggers.md): http (the public URL), schedule (cron, in\n" +
			"its time zone or the organization's), queue (a Redis stream), workflow, webhook (a stable public\n" +
			"inbox URL that verifies the sender and stores each request), topic (a fan-out topic of the\n" +
			"organization), platform_event (deployments, alerts, …) and the broker sources (kafka, nats,\n" +
			"rabbitmq, mqtt, cdc, s3, email). The first enabled trigger is the primary one. Every type except\n" +
			"http and workflow runs on the project's event gateway, which delivers each event to the\n" +
			"function as a CloudEvent with retries.\n\n" +
			"A change reaches the cluster with the next deploy: unless --no-deploy is given, add, update,\n" +
			"remove, enable and disable redeploy the live version. Config keys are set with --set KEY=VALUE\n" +
			"(dotted keys reach nested settings: hmac.header, delivery.concurrency); secrets (a webhook's\n" +
			"signing_secret) with --secret or --secret-stdin, write-only.\n\n" +
			"runr.yaml can hold the whole list as `triggers:`; runr deploy applies it.",
		Example: "  runr triggers ls\n" +
			"  printf %s \"$GITHUB_SECRET\" | runr triggers add --type webhook --name github --set verify=github --secret-stdin\n" +
			"  runr triggers add --type schedule --name nightly --set cron=\"0 3 * * *\"\n" +
			"  runr triggers add --type schedule --name morning --set cron=\"0 9 * * MON-FRI\" --set timezone=Europe/Berlin\n" +
			"  runr triggers add --type queue --name orders --set stream=orders --set delivery.concurrency=8\n" +
			"  runr triggers events github --status failed\n" +
			"  runr triggers replay github --failed",
		Args: cobra.NoArgs,
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the project's triggers",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			list, err := c.ListTriggers(cmd.Context(), p.ID)
			if err != nil {
				return noTriggers(err)
			}
			return printTriggerList(app, p, list)
		},
	}
	cmd.RunE = ls.RunE
	cmd.AddCommand(ls, newTriggerAddCmd(app), newTriggerUpdateCmd(app), newTriggerRemoveCmd(app),
		newTriggerEnableCmd(app, true), newTriggerEnableCmd(app, false), newTriggerEventsCmd(app),
		newTriggerReplayCmd(app))
	return cmd
}

func printTriggerList(app *App, p *runr.Project, list *runr.TriggerList) error {
	return app.Printer().Print(list, func(t *output.TableWriter) {
		t.Header("NAME", "TYPE", "ENABLED", "PRIMARY", "RUNS ON", "DETAIL")
		for _, tr := range list.Triggers {
			t.Row(tr.Name, tr.Type, yesOrNo(tr.Enabled), yesNo(tr.Primary), output.Dash(tr.RunsOn),
				output.Truncate(triggerDetail(&tr, p), 80))
		}
		t.Note("Deploys: %s.", planSummary(list.Plan))
	})
}

// planSummary renders what the trigger list deploys.
func planSummary(pl runr.TriggerPlan) string {
	parts := []string{output.Dash(pl.Workload)}
	if pl.Public {
		parts = append(parts, "public URL")
	} else {
		parts = append(parts, "no public URL")
	}
	if pl.Gateway {
		parts = append(parts, "event gateway")
	}
	if pl.RequiresRedis {
		parts = append(parts, "uses the region's Redis")
	}
	return strings.Join(parts, ", ")
}

// triggerDetail is the one-line summary of a trigger: its URL, schedule, stream, topic or events.
func triggerDetail(t *runr.Trigger, p *runr.Project) string {
	str := func(k string) string { v, _ := t.Config[k].(string); return v }
	switch t.Type {
	case runr.TriggerHTTP, runr.TriggerWorkflow:
		if p != nil && p.EndpointURL != "" {
			return p.EndpointURL
		}
		return "-"
	case runr.TriggerWebhook:
		// the check first: a narrow table cuts the end of a long inbox URL
		d := output.Dash(t.URL)
		if v := str("verify"); v != "" && v != "none" {
			d = "(verify " + v + ") " + d
		}
		return d
	case runr.TriggerSchedule:
		if zone := scheduleZone(t); zone != "" {
			return "cron " + str("cron") + " (" + zone + ")"
		}
		return "cron " + str("cron")
	case runr.TriggerQueue:
		return "stream " + str("stream")
	case runr.TriggerTopic:
		return "topic " + str("topic")
	case runr.TriggerPlatformEvent:
		var events []string
		if list, ok := t.Config["events"].([]any); ok {
			for _, e := range list {
				events = append(events, fmt.Sprint(e))
			}
		}
		return "events " + joinOrDash(events)
	}
	if len(t.Config) == 0 {
		return "-"
	}
	return compactJSON(t.Config)
}

// scheduleZone is the time zone a schedule trigger runs in: its own (config.timezone), or the
// organization's (effective_timezone without config.timezone); "" from an API without zones.
func scheduleZone(t *runr.Trigger) string {
	if zone, _ := t.Config["timezone"].(string); zone != "" {
		return zone
	}
	if t.EffectiveTimezone != "" {
		return t.EffectiveTimezone + ", the organization's"
	}
	return ""
}

func yesOrNo(b bool) string {
	if b {
		return "yes"
	}
	return "no"
}

func compactJSON(v any) string {
	b, err := json.Marshal(v)
	if err != nil {
		return fmt.Sprint(v)
	}
	return string(b)
}

// triggerKV prints one trigger.
func triggerKV(t *output.TableWriter, tr *runr.Trigger, p *runr.Project) {
	t.KV("Trigger", tr.Name)
	label := tr.Type
	if tr.Label != "" && tr.Label != tr.Type {
		label += " (" + tr.Label + ")"
	}
	t.KV("Type", label)
	t.KV("Enabled", yesOrNo(tr.Enabled))
	t.KV("Primary", yesOrNo(tr.Primary))
	t.KV("Runs on", output.Dash(tr.RunsOn))
	if tr.URL != "" {
		t.KV("URL", tr.URL)
	} else if d := triggerDetail(tr, p); d != "-" && tr.Type != runr.TriggerWebhook {
		t.KV("Detail", d)
	}
	if tr.Type == runr.TriggerSchedule {
		if zone := scheduleZone(tr); zone != "" {
			t.KV("Time zone", zone)
		}
	}
	keys := make([]string, 0, len(tr.Config))
	for k := range tr.Config {
		if k != "delivery" {
			keys = append(keys, k)
		}
	}
	sort.Strings(keys)
	for i, k := range keys {
		label := ""
		if i == 0 {
			label = "Config"
		}
		v := tr.Config[k]
		s, ok := v.(string)
		if !ok {
			s = compactJSON(v)
		}
		t.KV(label, k+" = "+s)
	}
	if tr.HasSigningSecret != nil {
		t.KV("Signing secret", map[bool]string{true: "set", false: "not set"}[*tr.HasSigningSecret])
	} else if len(tr.Secrets) > 0 {
		t.KV("Secrets", strings.Join(tr.Secrets, ", ")+" (set)")
	}
	if d := tr.Delivery; d != nil {
		t.KV("Delivery", fmt.Sprintf("concurrency %d, %d retries, backoff %ds up to %ds", d.Concurrency, d.MaxRetries,
			d.RetryBackoffSeconds, d.RetryBackoffMaxSeconds))
	}
	if tr.RetentionDays != nil {
		t.KV("Events kept", countLabel(*tr.RetentionDays, "day"))
	}
}

func printTrigger(app *App, tr *runr.Trigger, p *runr.Project, note string) error {
	return app.Printer().Print(tr, func(t *output.TableWriter) {
		triggerKV(t, tr, p)
		if note != "" {
			t.Note("%s", note)
		}
	})
}

// triggerChangeFlags are the flags of the commands that change a trigger.
type triggerChangeFlags struct {
	set      []string
	unset    []string
	secrets  []string
	secretIn string
	primary  bool
	noDeploy bool
	timeout  time.Duration
}

func (f *triggerChangeFlags) register(cmd *cobra.Command, update bool) {
	fl := cmd.Flags()
	fl.StringArrayVar(&f.set, "set", nil, "config KEY=VALUE (repeatable; dotted keys like hmac.header or delivery.concurrency; JSON values like 8, true or [\"a\"])")
	if update {
		fl.StringArrayVar(&f.unset, "unset", nil, "remove a config KEY (repeatable; dotted keys)")
	}
	fl.StringArrayVar(&f.secrets, "secret", nil, "write-only secret KEY=VALUE, e.g. signing_secret=… (repeatable; KEY= removes it)")
	fl.StringVar(&f.secretIn, "secret-stdin", "", "read the value of a secret (default signing_secret) from a hidden prompt or stdin")
	fl.Lookup("secret-stdin").NoOptDefVal = "signing_secret"
	fl.BoolVar(&f.primary, "primary", false, "make it the primary trigger (first in the list)")
	fl.BoolVar(&f.noDeploy, "no-deploy", false, "only save; the next deployment applies the change")
	fl.DurationVar(&f.timeout, "timeout", 10*time.Minute, "how long to wait for the redeploy")
}

// secretValues reads --secret and --secret-stdin.
func (f *triggerChangeFlags) secretValues(app *App) (map[string]string, error) {
	out, err := parseKeyValues("--secret", f.secrets)
	if err != nil {
		return nil, err
	}
	if f.secretIn != "" {
		v, err := readSecretValue(app, f.secretIn)
		if err != nil {
			return nil, err
		}
		out[f.secretIn] = v
	}
	if len(out) == 0 {
		return nil, nil
	}
	return out, nil
}

func newTriggerAddCmd(app *App) *cobra.Command {
	var f triggerChangeFlags
	var typ, name string
	cmd := &cobra.Command{
		Use:   "add --type TYPE",
		Short: "Add a trigger (http, schedule, queue, workflow, webhook, topic, platform_event)",
		Long: "Add a trigger to the project. --set sets its config: cron and timezone of a schedule (an IANA\n" +
			"zone such as Europe/Berlin; without one it runs in the organization's time zone), stream and start_from\n" +
			"of a queue, verify (none, stripe, github, slack, hmac), hmac.* and retention_days of a webhook,\n" +
			"topic and start_from of a topic, events and projects of a platform_event, delivery.* of every\n" +
			"type the event gateway runs. A value that reads as JSON (8, true, [\"a\",\"b\"]) is sent as such;\n" +
			"quote it to keep a string (--set 'stream=\"123\"'). events and projects also take a comma list.",
		Example: "  printf %s \"$SECRET\" | runr triggers add --type webhook --name github --set verify=github --secret-stdin\n" +
			"  runr triggers add --type webhook --name billing --set verify=hmac --set hmac.header=X-Hub-Signature \\\n" +
			"      --set hmac.prefix=sha256= --secret signing_secret=…\n" +
			"  runr triggers add --type schedule --name nightly --set cron=\"0 3 * * *\" --set timezone=UTC\n" +
			"  runr triggers add --type topic --set topic=orders --set delivery.concurrency=8\n" +
			"  runr triggers add --type platform_event --set events=deployment.failed,alert.fired",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if strings.TrimSpace(typ) == "" {
				return usageErrorf("--type is required (http, schedule, queue, workflow, webhook, topic, platform_event, kafka, nats, rabbitmq, mqtt, cdc, s3 or email)")
			}
			cfg, err := applySets(map[string]any{}, f.set, nil)
			if err != nil {
				return err
			}
			secrets, err := f.secretValues(app)
			if err != nil {
				return err
			}
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			in := runr.TriggerInput{Type: strings.ToLower(strings.TrimSpace(typ)), Config: cfg, Secrets: secrets}
			if name != "" {
				in.Name = &name
			}
			if f.primary {
				zero := 0
				in.Position = &zero
			}
			tr, err := c.CreateTrigger(cmd.Context(), p.ID, in)
			if err != nil {
				return noTriggers(err)
			}
			return triggerChanged(cmd.Context(), app, c, p, tr, "Trigger "+tr.Name+" added.", f)
		},
	}
	cmd.Flags().StringVar(&typ, "type", "", "trigger type: http, schedule, queue, workflow, webhook, topic, platform_event or a broker source (kafka, nats, rabbitmq, mqtt, cdc, s3, email)")
	cmd.Flags().StringVar(&name, "name", "", "trigger name (lower-case letters, digits, dashes; default: the type)")
	f.register(cmd, false)
	return cmd
}

func newTriggerUpdateCmd(app *App) *cobra.Command {
	var f triggerChangeFlags
	var rename string
	cmd := &cobra.Command{
		Use:   "update TRIGGER",
		Short: "Change a trigger's config, secrets, name or position (name or id)",
		Long: "Change a trigger. --set and --unset change single config keys and keep the others; --secret\n" +
			"and --secret-stdin replace a secret (KEY= removes it); --primary moves the trigger to the top\n" +
			"of the list. The type of a trigger cannot change: remove it and add another.",
		Example: "  runr triggers update github --set retention_days=14\n" +
			"  runr triggers update orders --set delivery.max_retries=10 --unset delivery.concurrency\n" +
			"  printf %s \"$NEW_SECRET\" | runr triggers update github --secret-stdin\n" +
			"  runr triggers update nightly --primary --rename nightly-report",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(f.set) == 0 && len(f.unset) == 0 && len(f.secrets) == 0 && f.secretIn == "" && !f.primary && rename == "" {
				return usageErrorf("nothing to change: give --set, --unset, --secret, --secret-stdin, --primary or --rename")
			}
			secrets, err := f.secretValues(app)
			if err != nil {
				return err
			}
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			in := runr.TriggerInput{Secrets: secrets}
			if len(f.set) > 0 || len(f.unset) > 0 {
				// The API replaces the config: start from the stored one
				cur, err := c.FindTrigger(cmd.Context(), p.ID, args[0])
				if err != nil {
					return noTriggers(err)
				}
				cfg, err := applySets(copyMap(cur.Config), f.set, f.unset)
				if err != nil {
					return err
				}
				in.Config = cfg
			}
			if rename != "" {
				in.Name = &rename
			}
			if f.primary {
				zero := 0
				in.Position = &zero
			}
			tr, err := c.UpdateTrigger(cmd.Context(), p.ID, args[0], in)
			if err != nil {
				return noTriggers(err)
			}
			return triggerChanged(cmd.Context(), app, c, p, tr, "Trigger "+tr.Name+" updated.", f)
		},
	}
	cmd.Flags().StringVar(&rename, "rename", "", "new name of the trigger")
	f.register(cmd, true)
	return cmd
}

func newTriggerEnableCmd(app *App, enable bool) *cobra.Command {
	var f triggerChangeFlags
	use, short, verb := "enable TRIGGER", "Enable a trigger (name or id)", "enabled"
	if !enable {
		use, short, verb = "disable TRIGGER", "Disable a trigger; it stays in the list (name or id)", "disabled"
	}
	cmd := &cobra.Command{
		Use:   use,
		Short: short,
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			tr, err := c.UpdateTrigger(cmd.Context(), p.ID, args[0], runr.TriggerInput{Enabled: &enable})
			if err != nil {
				return noTriggers(err)
			}
			return triggerChanged(cmd.Context(), app, c, p, tr, "Trigger "+tr.Name+" "+verb+".", f)
		},
	}
	fl := cmd.Flags()
	fl.BoolVar(&f.noDeploy, "no-deploy", false, "only save; the next deployment applies the change")
	fl.DurationVar(&f.timeout, "timeout", 10*time.Minute, "how long to wait for the redeploy")
	return cmd
}

func newTriggerRemoveCmd(app *App) *cobra.Command {
	var f triggerChangeFlags
	var yes bool
	cmd := &cobra.Command{
		Use:     "remove TRIGGER",
		Aliases: []string{"rm", "delete"},
		Short:   "Remove a trigger and its stored events (name or id); the last one cannot go",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			if err := app.confirm(fmt.Sprintf("Remove trigger %s of %s?", args[0], p.Name), yes); err != nil {
				return err
			}
			cleanup, err := c.DeleteTrigger(cmd.Context(), p.ID, args[0])
			if err != nil {
				return noTriggers(err)
			}
			result := map[string]any{"project": p.Name, "removed": args[0]}
			if len(cleanup) > 0 {
				result["cleanup"] = cleanup
			}
			err = app.Printer().Print(result, func(t *output.TableWriter) {
				t.Note("Trigger %s removed from %s.", args[0], p.Name)
			})
			if err != nil {
				return err
			}
			printTriggerCleanup(app, cleanup)
			return redeployForTriggers(cmd.Context(), app, c, p, f.noDeploy, f.timeout)
		},
	}
	fl := cmd.Flags()
	fl.BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	fl.BoolVar(&f.noDeploy, "no-deploy", false, "only save; the next deployment applies the change")
	fl.DurationVar(&f.timeout, "timeout", 10*time.Minute, "how long to wait for the redeploy")
	return cmd
}

// triggerChanged prints the changed trigger and redeploys the live version.
func triggerChanged(ctx context.Context, app *App, c *runr.Client, p *runr.Project, tr *runr.Trigger, note string, f triggerChangeFlags) error {
	if err := printTrigger(app, tr, p, note); err != nil {
		return err
	}
	printTriggerCleanup(app, tr.Cleanup)
	return redeployForTriggers(ctx, app, c, p, f.noDeploy, f.timeout)
}

// printTriggerCleanup says what a change left outside RunR that RunR removes once the event
// gateway no longer reads it (a cdc trigger's replication slots); RunR notifies the outcome.
func printTriggerCleanup(app *App, cleanup []runr.TriggerCleanup) {
	for _, c := range cleanup {
		if c.Message != "" {
			app.Progress("%s: %s", c.Trigger, c.Message)
		}
	}
}

// redeployForTriggers redeploys the live version so a changed trigger list reaches the cluster
// (like runr env set and runr scale); --no-deploy leaves it to the next deploy.
func redeployForTriggers(ctx context.Context, app *App, c *runr.Client, p *runr.Project, noDeploy bool, timeout time.Duration) error {
	if noDeploy {
		app.Progress("Saved; the next deployment applies it.")
		return nil
	}
	d, err := c.CurrentDeployment(ctx, p.ID)
	if err != nil {
		return err
	}
	if d == nil || !d.Live() || d.Version == nil {
		app.Progress("No live deployment; the next `runr deploy` applies it.")
		return nil
	}
	v, err := c.GetVersion(ctx, p.ID, d.Version.ID)
	if err != nil {
		return err
	}
	_, err = finishDeploy(ctx, app, c, &DeployResult{Project: p, Version: v}, v, deployWait{timeout: timeout})
	return err
}

// ---------------------------------------------------------------------------------------
// Config values

// listKeys take a comma-separated list where the value is not JSON (platform_event).
var listKeys = map[string]bool{"events": true, "projects": true}

// applySets sets KEY=VALUE pairs (dotted keys reach nested objects) and removes keys in cfg.
func applySets(cfg map[string]any, sets, unsets []string) (map[string]any, error) {
	for _, s := range sets {
		k, v, ok := strings.Cut(s, "=")
		path, err := configPath(k)
		if err != nil || !ok {
			return nil, usageErrorf("--set %q is not KEY=VALUE", s)
		}
		setPath(cfg, path, parseConfigValue(path[len(path)-1], v))
	}
	for _, k := range unsets {
		path, err := configPath(k)
		if err != nil {
			return nil, usageErrorf("--unset %q is not a config key", k)
		}
		unsetPath(cfg, path)
	}
	return cfg, nil
}

func configPath(key string) ([]string, error) {
	key = strings.TrimSpace(key)
	if key == "" {
		return nil, errors.New("empty key")
	}
	path := strings.Split(key, ".")
	for _, p := range path {
		if p == "" {
			return nil, errors.New("empty segment")
		}
	}
	return path, nil
}

// parseConfigValue reads a --set value: JSON (numbers, true/false, null, "quoted", [lists],
// {objects}) as such, a comma list for the list keys, anything else as a string.
func parseConfigValue(key, raw string) any {
	if trimmed := strings.TrimSpace(raw); trimmed != "" && json.Valid([]byte(trimmed)) {
		var v any
		dec := json.NewDecoder(strings.NewReader(trimmed))
		dec.UseNumber()
		if err := dec.Decode(&v); err == nil {
			return normalizeNumbers(v)
		}
	}
	if listKeys[key] {
		var out []any
		for _, part := range strings.Split(raw, ",") {
			if part = strings.TrimSpace(part); part != "" {
				out = append(out, part)
			}
		}
		if out == nil {
			out = []any{}
		}
		return out
	}
	return raw
}

// normalizeNumbers turns json.Number values into int64 or float64.
func normalizeNumbers(v any) any {
	switch t := v.(type) {
	case json.Number:
		if i, err := strconv.ParseInt(t.String(), 10, 64); err == nil {
			return i
		}
		f, _ := t.Float64()
		return f
	case map[string]any:
		for k, val := range t {
			t[k] = normalizeNumbers(val)
		}
		return t
	case []any:
		for i, val := range t {
			t[i] = normalizeNumbers(val)
		}
		return t
	}
	return v
}

func setPath(m map[string]any, path []string, v any) {
	for _, k := range path[:len(path)-1] {
		next, ok := m[k].(map[string]any)
		if !ok {
			next = map[string]any{}
			m[k] = next
		}
		m = next
	}
	m[path[len(path)-1]] = v
}

func unsetPath(m map[string]any, path []string) {
	for _, k := range path[:len(path)-1] {
		next, ok := m[k].(map[string]any)
		if !ok {
			return
		}
		m = next
	}
	delete(m, path[len(path)-1])
}

// copyMap returns a deep copy of a decoded JSON object.
func copyMap(m map[string]any) map[string]any {
	out := make(map[string]any, len(m))
	for k, v := range m {
		out[k] = copyValue(v)
	}
	return out
}

func copyValue(v any) any {
	switch t := v.(type) {
	case map[string]any:
		return copyMap(t)
	case []any:
		out := make([]any, len(t))
		for i, x := range t {
			out[i] = copyValue(x)
		}
		return out
	}
	return v
}

// ---------------------------------------------------------------------------------------
// Stored events and replay

func newTriggerEventsCmd(app *App) *cobra.Command {
	var status string
	var limit int
	var body bool
	cmd := &cobra.Command{
		Use:   "events TRIGGER [EVENT]",
		Short: "List the stored events of a webhook or platform_event trigger, or show one",
		Long: "Webhook and platform_event triggers store each event (for retention_days) and the event\n" +
			"gateway delivers it to the function; an event that kept failing ends as failed and can be\n" +
			"replayed (runr triggers replay). With an EVENT id the command shows the event with the\n" +
			"sender's headers and the body; --body writes only the body. Needs replay.manage.",
		Example: "  runr triggers events github\n" +
			"  runr triggers events github --status failed\n" +
			"  runr triggers events github 01J… --body > payload.json",
		Args: cobra.RangeArgs(1, 2),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			if len(args) == 2 {
				e, err := c.GetTriggerEvent(cmd.Context(), p.ID, args[0], args[1])
				if err != nil {
					return err
				}
				if body {
					data, err := base64.StdEncoding.DecodeString(e.BodyBase64)
					if err != nil {
						return fmt.Errorf("decode the body: %w", err)
					}
					_, err = app.Out.Write(data)
					return err
				}
				return app.Printer().Print(e, func(t *output.TableWriter) { triggerEventDetail(t, e) })
			}
			if body {
				return usageErrorf("--body needs an EVENT id")
			}
			if status != "" && !oneOf(status, runr.TriggerEventPending, runr.TriggerEventDelivering, runr.TriggerEventDelivered, runr.TriggerEventFailed) {
				return usageErrorf("--status must be pending, delivering, delivered or failed")
			}
			if limit < 1 || limit > 100 {
				return usageErrorf("--limit must be between 1 and 100")
			}
			events, page, err := c.ListTriggerEvents(cmd.Context(), p.ID, args[0], runr.TriggerEventFilter{Status: status, PerPage: limit})
			if err != nil {
				return err
			}
			return app.Printer().Print(events, func(t *output.TableWriter) {
				t.Header("EVENT", "TYPE", "STATUS", "ATTEMPTS", "REPLAYS", "SIZE", "RECEIVED", "ERROR")
				for _, e := range events {
					t.Row(e.ID, e.Type, e.Status, strconv.Itoa(e.Attempts), strconv.Itoa(e.Replays), byteSize(e.Size),
						wfAge(e.ReceivedAt), output.Truncate(output.Dash(e.LastError), 50))
				}
				if page != nil && page.Total > len(events) {
					t.Note("%d of %d events; narrow the list with --status or raise --limit.", len(events), page.Total)
				}
				if len(events) == 0 {
					t.Note("No events.")
				}
			})
		},
	}
	fl := cmd.Flags()
	fl.StringVar(&status, "status", "", "only events in this status: pending, delivering, delivered or failed")
	fl.IntVar(&limit, "limit", 25, "how many events to show (1-100)")
	fl.BoolVar(&body, "body", false, "with EVENT: write only the body (as received) to stdout")
	return cmd
}

func triggerEventDetail(t *output.TableWriter, e *runr.TriggerEvent) {
	t.KV("Event", e.ID)
	t.KV("Type", e.Type)
	if e.Subject != "" {
		t.KV("Subject", e.Subject)
	}
	t.KV("Status", e.Status)
	t.KV("Attempts", strconv.Itoa(e.Attempts))
	t.KV("Replays", strconv.Itoa(e.Replays))
	if e.DeliveryID != "" {
		t.KV("Delivery id", e.DeliveryID)
	}
	if e.LastError != "" {
		t.KV("Last error", e.LastError)
	}
	t.KV("Received", output.Dash(e.ReceivedAt))
	if e.DeliveredAt != "" {
		t.KV("Delivered", e.DeliveredAt)
	}
	if e.ExpiresAt != "" {
		t.KV("Expires", e.ExpiresAt)
	}
	keys := make([]string, 0, len(e.Headers))
	for k := range e.Headers {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for i, k := range keys {
		label := ""
		if i == 0 {
			label = "Headers"
		}
		t.KV(label, k+": "+e.Headers[k])
	}
	t.KV("Content type", output.Dash(e.ContentType))
	switch {
	case e.BodyText != nil && *e.BodyText != "":
		t.Note("%s", *e.BodyText)
	case e.Size > 0:
		t.Note("(%s body that is not shown as text: --body writes it, -o json has body_base64)", byteSize(e.Size))
	}
}

func newTriggerReplayCmd(app *App) *cobra.Command {
	var failed, all bool
	cmd := &cobra.Command{
		Use:   "replay TRIGGER [EVENT]",
		Short: "Deliver stored events again: one event, --failed or --all",
		Long: "Replay sets stored events back to pending; the event gateway delivers them to the function\n" +
			"again (with the CloudEvents extension runrreplay). --failed replays every failed event,\n" +
			"--all every event that is not waiting (at most 500 per call). Needs replay.manage.",
		Example: "  runr triggers replay github 01J…\n" +
			"  runr triggers replay github --failed",
		Args: cobra.RangeArgs(1, 2),
		RunE: func(cmd *cobra.Command, args []string) error {
			switch {
			case len(args) == 2 && (failed || all):
				return usageErrorf("give an EVENT id or --failed / --all, not both")
			case len(args) == 1 && !failed && !all:
				return usageErrorf("give an EVENT id, --failed or --all")
			case failed && all:
				return usageErrorf("--failed and --all exclude each other")
			}
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			if len(args) == 2 {
				e, err := c.ReplayTriggerEvent(cmd.Context(), p.ID, args[0], args[1])
				if err != nil {
					return err
				}
				return app.Printer().Print(e, func(t *output.TableWriter) {
					t.Note("Event %s of %s is queued for delivery again (status %s).", e.ID, args[0], e.Status)
				})
			}
			status := "failed"
			if all {
				status = "all"
			}
			n, err := c.ReplayTriggerEvents(cmd.Context(), p.ID, args[0], status)
			if err != nil {
				return err
			}
			return app.Printer().Print(map[string]any{"replayed": n}, func(t *output.TableWriter) {
				what := "failed "
				if all {
					what = ""
				}
				t.Note("%s of %s queued for delivery again.", countLabel(n, what+"event"), args[0])
			})
		},
	}
	cmd.Flags().BoolVar(&failed, "failed", false, "replay every failed event")
	cmd.Flags().BoolVar(&all, "all", false, "replay every delivered and failed event")
	return cmd
}

func oneOf(v string, list ...string) bool {
	for _, x := range list {
		if v == x {
			return true
		}
	}
	return false
}

// byteSize renders a size: 512 B, 3.4 KiB, 1.2 MiB.
func byteSize(n int64) string {
	switch {
	case n < 1024:
		return fmt.Sprintf("%d B", n)
	case n < 1024*1024:
		return fmt.Sprintf("%.1f KiB", float64(n)/1024)
	}
	return fmt.Sprintf("%.1f MiB", float64(n)/(1024*1024))
}

// ---------------------------------------------------------------------------------------
// Topics

func newTopicsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "topics",
		Aliases: []string{"topic"},
		Short:   "Fan-out topics of the organization: list, create, delete, publish",
		Long: "A topic delivers each published event to every project with a topic trigger on it\n" +
			"(runr triggers add --type topic --set topic=NAME). Topics live in a region with Redis.\n" +
			"Creating and deleting needs topics.manage, publishing events.send. Functions publish with\n" +
			"the SDKs' publish helper.",
		Example: "  runr topics create orders --region eu-1\n" +
			"  runr topics publish orders --data '{\"order_id\":\"o-1\"}' --type order.created\n" +
			"  runr topics ls",
		Args: cobra.NoArgs,
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the organization's topics",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			topics, err := c.ListTopics(cmd.Context())
			if errors.Is(err, runr.ErrUnsupported) {
				return errors.New("this RunR API has no topics (an older RunR version)")
			}
			if err != nil {
				return err
			}
			return app.Printer().Print(topics, func(t *output.TableWriter) {
				t.Header("NAME", "REGION", "SUBSCRIPTIONS", "STREAM", "DESCRIPTION")
				for _, tp := range topics {
					t.Row(tp.Name, output.Dash(tp.RegionName), strconv.Itoa(tp.Subscriptions), output.Dash(tp.Stream),
						output.Truncate(output.Dash(tp.Description), 50))
				}
				if len(topics) == 0 {
					t.Note("No topics. Create one with `runr topics create NAME --region REGION`.")
				}
			})
		},
	}
	cmd.RunE = ls.RunE
	cmd.AddCommand(ls, newTopicCreateCmd(app), newTopicDeleteCmd(app), newTopicPublishCmd(app))
	return cmd
}

func newTopicCreateCmd(app *App) *cobra.Command {
	var region, description string
	cmd := &cobra.Command{
		Use:     "create NAME",
		Short:   "Create a topic in a region (the region needs Redis)",
		Example: "  runr topics create orders --region eu-1 --description \"Order lifecycle events\"",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			reg, err := c.FindRegion(cmd.Context(), region)
			if err != nil {
				return err
			}
			tp, err := c.CreateTopic(cmd.Context(), args[0], reg.ID, description)
			if errors.Is(err, runr.ErrUnsupported) {
				return errors.New("this RunR API has no topics (an older RunR version)")
			}
			if err != nil {
				return err
			}
			return app.Printer().Print(tp, func(t *output.TableWriter) {
				t.KV("Topic", tp.Name)
				t.KV("Region", output.Dash(tp.RegionName))
				t.KV("Stream", output.Dash(tp.Stream))
				if tp.Description != "" {
					t.KV("Description", tp.Description)
				}
				t.Note("Subscribe a project with `runr triggers add --type topic --set topic=%s`.", tp.Name)
			})
		},
	}
	cmd.Flags().StringVar(&region, "region", "", "region name or id (default: the only active region)")
	cmd.Flags().StringVar(&description, "description", "", "what the topic carries")
	return cmd
}

func newTopicDeleteCmd(app *App) *cobra.Command {
	var yes bool
	cmd := &cobra.Command{
		Use:     "delete NAME",
		Aliases: []string{"rm", "remove"},
		Short:   "Delete a topic (refused while triggers subscribe to it)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			if err := app.confirm("Delete topic "+args[0]+" and the events in its stream?", yes); err != nil {
				return err
			}
			if err := c.DeleteTopic(cmd.Context(), args[0]); err != nil {
				return err
			}
			return app.Printer().Print(map[string]any{"deleted": args[0]}, func(t *output.TableWriter) {
				t.Note("Topic %s deleted.", args[0])
			})
		},
	}
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}

func newTopicPublishCmd(app *App) *cobra.Command {
	var data, file, contentType string
	var in runr.PublishInput
	cmd := &cobra.Command{
		Use:   "publish TOPIC",
		Short: "Publish an event to a topic (JSON data from --data, --file or stdin)",
		Long: "Publish one event: every project with a topic trigger on the topic receives it as a\n" +
			"CloudEvent (type from --type, default dev.runr.topic.published). The data is JSON unless\n" +
			"--content-type names another type (text/plain, application/xml, …): it is then sent as is.\n" +
			"Needs events.send.",
		Example: "  runr topics publish orders --data '{\"order_id\":\"o-1\"}' --type order.created --subject o-1\n" +
			"  runr topics publish orders --file event.json\n" +
			"  echo '{\"a\":1}' | runr topics publish orders\n" +
			"  runr topics publish logs --data 'plain text' --content-type text/plain",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			raw, err := readPublishData(app, data, file)
			if err != nil {
				return err
			}
			ct := strings.TrimSpace(contentType)
			if ct == "" || strings.EqualFold(ct, "application/json") {
				if !json.Valid(raw) {
					return usageErrorf("the event data is not valid JSON; pass --content-type (e.g. text/plain) to publish it as is")
				}
				in.Data = json.RawMessage(raw)
				ct = ""
			} else {
				if !utf8.Valid(raw) {
					return usageErrorf("binary data cannot be published through the API; publish it from a function with the SDK")
				}
				in.Data, _ = json.Marshal(string(raw))
			}
			in.ContentType = ct
			c, err := app.Client()
			if err != nil {
				return err
			}
			res, err := c.PublishTopic(cmd.Context(), args[0], in)
			if errors.Is(err, runr.ErrUnsupported) {
				return errors.New("this RunR API has no topics (an older RunR version)")
			}
			if err != nil {
				return err
			}
			return app.Printer().Print(res, func(t *output.TableWriter) {
				t.KV("Event", res.ID)
				t.KV("Stream id", output.Dash(res.StreamID))
				t.Note("Published to topic %s.", args[0])
			})
		},
	}
	fl := cmd.Flags()
	fl.StringVar(&data, "data", "", "event data: JSON inline, @file or - (stdin)")
	fl.StringVar(&file, "file", "", "read the event data from a file (- = stdin)")
	fl.StringVar(&contentType, "content-type", "", "content type of the data (default application/json)")
	fl.StringVar(&in.Type, "type", "", "CloudEvents type of the event (e.g. order.created)")
	fl.StringVar(&in.Subject, "subject", "", "CloudEvents subject of the event")
	fl.StringVar(&in.ID, "id", "", "event id (default: generated)")
	return cmd
}

// readPublishData returns the event data of --data, --file or stdin.
func readPublishData(app *App, data, file string) ([]byte, error) {
	var raw []byte
	var err error
	switch {
	case data != "" && file != "":
		return nil, usageErrorf("give --data or --file, not both")
	case data != "":
		raw, err = readArg(app, data)
	case file != "":
		raw, err = readFileArg(app, file)
	case app.interactive():
		return nil, usageErrorf("no event data: pass --data or --file, or pipe the data to stdin")
	default:
		raw, err = io.ReadAll(app.In)
	}
	if err != nil {
		return nil, err
	}
	if len(strings.TrimSpace(string(raw))) == 0 {
		return nil, usageErrorf("no event data: pass --data, --file or pipe it to stdin (--data null publishes an event without data)")
	}
	return raw, nil
}
