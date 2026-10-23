<?php

namespace App\Triggers;

use App\Models\Project;
use App\Models\ProjectTrigger;
use Illuminate\Validation\Validator;

/**
 * One kind of trigger (docs/triggers.md). A new trigger type is one subclass registered in
 * TriggerTypes (App\Providers\TriggerServiceProvider) plus — when the project's event gateway
 * runs it — one Go source registered under the kind its gatewayEntry() names
 * (runtime/queue-consumer/sources/<kind>, source.Register).
 *
 * Where a trigger runs:
 *  - servesHttp(): the project's Knative Service serves it and is public (http, workflow)
 *  - gatewayEntry() !== null: a source of the project's event gateway (Deployment <slug>-qc),
 *    which delivers each event as a CloudEvent to the function's cluster-local URL with retries,
 *    backoff, a dead letter and per-trigger concurrency
 *  - schedule alone: the project's CronJob (TriggerPlan::cronJob(), single-trigger compatibility)
 */
abstract class TriggerType
{
    /** The `type` value of project_triggers rows. */
    abstract public function key(): string;

    /** Display name (lang/<locale>/triggers.php). */
    public function label(): string
    {
        $key = 'triggers.types.'.$this->key();
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $this->key();
    }

    /** One sentence on what invokes the function (lang triggers.descriptions.<type>), null without one. */
    public function description(): ?string
    {
        $key = 'triggers.descriptions.'.$this->key();
        $text = __($key);

        return is_string($text) && $text !== $key ? $text : null;
    }

    /**
     * Validation rules of the trigger's config, keys relative to the config object (the
     * request prefixes them with "config."); a rule that names another field (required_if,
     * required_with …) names it with its full path, e.g. config.after.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Validation rules of the trigger's write-only secrets ("secrets.<key>").
     *
     * @return array<string, mixed>
     */
    public function secretRules(): array
    {
        return [];
    }

    /**
     * Checks that need the project or other rows (the region's Redis, an existing topic).
     * $config is the normalized config.
     */
    public function validate(Validator $validator, Project $project, array $config, ?ProjectTrigger $existing): void {}

    /** The stored config: defaults filled in, unknown keys dropped. */
    public function normalize(array $config): array
    {
        return $config;
    }

    /** Whether the trigger makes the project's Knative Service public (it is cluster-local otherwise). */
    public function servesHttp(): bool
    {
        return false;
    }

    /**
     * The runtime trigger (runtime.yaml `triggers`: http | queue | schedule) the function needs
     * for this trigger: an event from the gateway arrives like a queue message.
     */
    public function runtimeTrigger(): string
    {
        return 'queue';
    }

    /** Whether the trigger needs the region's Redis. */
    public function requiresRedis(): bool
    {
        return false;
    }

    /** Whether the trigger must be the project's only one (workflow). */
    public function exclusive(): bool
    {
        return false;
    }

    /** Most triggers of this type per project; null: only the project limit. */
    public function maxPerProject(): ?int
    {
        return null;
    }

    /**
     * The event gateway entry of the trigger (gateway.json `triggers[]`, without name / type /
     * delivery settings, which GatewayConfig adds): ['kind' => <Go source kind>, 'source' =>
     * [...], 'dead_letter' => [...]?]. Null: the gateway does not run this trigger.
     */
    public function gatewayEntry(ProjectTrigger $trigger, GatewayContext $context): ?array
    {
        return null;
    }

    /** Whether the gateway delivers this trigger's events with the common delivery settings. */
    public function hasDeliverySettings(): bool
    {
        return true;
    }

    /** Whether the trigger stores its events in trigger_events (inbox, replay). */
    public function storesEvents(): bool
    {
        return false;
    }

    /** The metering entry point of a project whose primary trigger this is (http | schedule | queue). */
    public function entryPoint(): string
    {
        return 'queue';
    }

    /** A new trigger is about to be stored (e.g. a webhook gets its inbox key). */
    public function creating(ProjectTrigger $trigger): void {}

    /** The trigger was removed (its events go with the row). */
    public function deleted(ProjectTrigger $trigger, Project $project): void {}

    /**
     * A stored trigger was changed (name, config, secrets): $before is its state before the
     * change (a copy, its secrets included), $after the saved row.
     */
    public function changed(ProjectTrigger $before, ProjectTrigger $after, Project $project): void {}

    /**
     * Fields the API shows next to the config (a webhook's public URL …).
     *
     * @return array<string, mixed>
     */
    public function present(ProjectTrigger $trigger): array
    {
        return [];
    }

    /**
     * The config fields for clients without a dedicated form (the console renders a generic form
     * from them): [key (dotted for nested config), type (string | text | list | integer | boolean |
     * select | duration | json), required, default, options (select), secret (goes into
     * secrets.<key>, write-only)]. Labels and help come from lang triggers.fields.<type>.<key>
     * (field()). Empty: the client needs a form of its own.
     *
     * @return list<array<string, mixed>>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * One entry of fields(), its label and help translated.
     *
     * @param  list<string>|null  $options
     * @return array<string, mixed>
     */
    protected function field(string $key, string $type = 'string', bool $required = false, mixed $default = null, ?array $options = null, bool $secret = false): array
    {
        $base = 'triggers.fields.'.$this->key().'.'.str_replace('.', '_', $key);
        $label = __($base.'.label');
        $help = __($base.'.help');

        return [
            'key' => $key,
            'type' => $type,
            'label' => is_string($label) && $label !== $base.'.label' ? $label : $key,
            'help' => is_string($help) && $help !== $base.'.help' ? $help : null,
            'required' => $required,
            'default' => $default,
            'options' => $options,
            'secret' => $secret,
        ];
    }

    /**
     * Metadata for clients (console forms, CLI, MCP): key, label, where it runs, its secrets and
     * config fields.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'type' => $this->key(),
            'label' => $this->label(),
            'description' => $this->description(),
            'serves_http' => $this->servesHttp(),
            'gateway' => $this->runsOnGateway(),
            'requires_redis' => $this->requiresRedis(),
            'exclusive' => $this->exclusive(),
            'stores_events' => $this->storesEvents(),
            'secrets' => array_keys($this->secretRules()),
            'max_per_project' => $this->maxPerProject(),
            'fields' => $this->fields(),
        ];
    }

    /** Whether the event gateway runs triggers of this type (schedule: when not alone, TriggerPlan). */
    public function runsOnGateway(): bool
    {
        return ! $this->servesHttp();
    }
}
