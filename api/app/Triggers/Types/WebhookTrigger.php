<?php

namespace App\Triggers\Types;

use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Services\GitDeploy\GitUrls;
use App\Triggers\TriggerContexts;
use App\Triggers\Webhooks\WebhookVerifier;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Webhook inbox (docs/triggers.md#webhook-inbox): a stable public URL per trigger
 * (<base>/api/hooks/<context>/<key>). The API verifies the sender's signature (WebhookVerifier
 * presets), drops a delivery id it already stored, stores the request and answers 200 — the
 * sender is done. The project's event gateway then delivers the stored event to the function
 * (async, retried, dead-lettered as `failed`); stored events stay for retention_days and can be
 * replayed from the console, the API, the CLI or MCP.
 */
class WebhookTrigger extends InboxTriggerType
{
    public const EVENT_TYPE = 'dev.runr.webhook.received';

    public function key(): string
    {
        return 'webhook';
    }

    protected function configRules(): array
    {
        return [
            'verify' => ['required', 'string', Rule::in(WebhookVerifier::PRESETS)],
            'hmac' => 'nullable|array',
            'hmac.header' => 'nullable|string|max:100|regex:/^[A-Za-z0-9-]+$/',
            'hmac.algorithm' => ['nullable', 'string', Rule::in(WebhookVerifier::ALGORITHMS)],
            'hmac.encoding' => 'nullable|string|in:hex,base64',
            'hmac.prefix' => 'nullable|string|max:32',
            'delivery_id_header' => 'nullable|string|max:100|regex:/^[A-Za-z0-9-]+$/',
            'tolerance_seconds' => 'nullable|integer|min:0|max:86400',
            'max_body_kb' => 'nullable|integer|min:1|max:'.max(1, (int) config('triggers.webhook_max_body_kb', 1024)),
        ] + self::retentionRules();
    }

    public function secretRules(): array
    {
        return [
            'signing_secret' => 'nullable|string|min:8|max:512',
        ];
    }

    public function normalize(array $config): array
    {
        $verify = in_array($config['verify'] ?? 'none', WebhookVerifier::PRESETS, true) ? $config['verify'] : 'none';
        $normalized = ['verify' => $verify];

        if ($verify === 'hmac') {
            $hmac = (array) ($config['hmac'] ?? []);
            $normalized['hmac'] = [
                'header' => (string) (($hmac['header'] ?? null) ?: 'X-Signature'),
                'algorithm' => (string) (($hmac['algorithm'] ?? null) ?: 'sha256'),
                'encoding' => (string) (($hmac['encoding'] ?? null) ?: 'hex'),
                'prefix' => (string) ($hmac['prefix'] ?? ''),
            ];
        }
        if (in_array($verify, ['stripe', 'slack'], true) && isset($config['tolerance_seconds']) && $config['tolerance_seconds'] !== '') {
            $normalized['tolerance_seconds'] = (int) $config['tolerance_seconds'];
        }
        foreach (['delivery_id_header' => 'string', 'max_body_kb' => 'int'] as $key => $cast) {
            if (isset($config[$key]) && $config[$key] !== '') {
                $normalized[$key] = $cast === 'int' ? (int) $config[$key] : (string) $config[$key];
            }
        }

        return self::keepDelivery($config, self::keepRetention($config, $normalized));
    }

    public function validate(Validator $validator, Project $project, array $config, ?ProjectTrigger $existing): void
    {
        $needsSecret = ($config['verify'] ?? 'none') !== 'none';
        $given = $validator->getData()['secrets']['signing_secret'] ?? null;
        $hasSecret = (is_string($given) && $given !== '') || ($given === null && $existing?->secret('signing_secret') !== null);
        if ($needsSecret && ! $hasSecret) {
            $validator->errors()->add('secrets.signing_secret', __('triggers.errors.signing_secret_required'));
        }
    }

    public function creating(ProjectTrigger $trigger): void
    {
        if (! $trigger->ingress_key) {
            $trigger->ingress_key = strtolower(Str::random(40));
        }
    }

    public function present(ProjectTrigger $trigger): array
    {
        return [
            'url' => $trigger->ingress_key ? self::url($trigger->ingress_key) : null,
            'has_signing_secret' => $trigger->secret('signing_secret') !== null,
        ];
    }

    /** Public URL of an inbox key in the current organization context. */
    public static function url(string $key): string
    {
        $base = rtrim((string) (config('triggers.webhook_base_url') ?: GitUrls::webhookBase()), '/');

        return $base.'/api/hooks/'.rawurlencode(TriggerContexts::current()).'/'.$key;
    }

    /** Largest body the trigger stores (bytes). */
    public static function maxBodyBytes(ProjectTrigger $trigger): int
    {
        $max = max(1, (int) config('triggers.webhook_max_body_kb', 1024));
        $own = (int) ($trigger->setting('max_body_kb') ?? $max);

        return max(1, min($own, $max)) * 1024;
    }
}
