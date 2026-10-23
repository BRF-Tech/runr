<?php

namespace App\Services\OrgTransfer\Sections;

use App\Models\Observability\AlertChannel;
use App\Models\Observability\AlertRule;
use App\Models\Observability\StatusPage;
use App\Services\Observability\StatusPageService;
use App\Services\OrgTransfer\ExportContext;
use App\Services\OrgTransfer\ImportContext;
use App\Services\OrgTransfer\ImportPlan;
use App\Services\OrgTransfer\Package\PackageFormat;

/**
 * organization/observability.json (docs/observability.md): alert channels, alert rules and the
 * public status page. A channel's configuration is its secret (webhook URLs with tokens, bot
 * tokens, signing secrets): the package keeps the type and what the UI may show; the
 * configuration itself goes to secrets/secrets.enc when the export includes secrets — except the
 * e-mail addresses of an e-mail channel, which are no secret.
 *
 * Import: channels without their secrets are created disabled and listed as a manual step;
 * rules point at the imported projects and channels (a rule of a project left out is skipped);
 * the status page takes the package's settings — with another slug when its slug is another
 * organization's on this installation, its custom domain a manual DNS step.
 */
class ObservabilitySection implements TransferSection
{
    public function __construct(private StatusPageService $pages) {}

    public function key(): string
    {
        return 'observability';
    }

    public function export(ExportContext $context): void
    {
        $projectIds = $context->projects()->pluck('id')->all();

        $channels = AlertChannel::query()->orderBy('name')->get()->map(function (AlertChannel $c) use ($context) {
            $config = (array) ($c->config ?? []);
            if ($c->type !== 'email') {
                $context->addSecret('alert_channels', (string) $c->id, $config);
            }

            return [
                'id' => (string) $c->id,
                'name' => $c->name,
                'type' => $c->type,
                'is_enabled' => (bool) $c->is_enabled,
                // e-mail addresses travel as they are; every other config is a secret
                'config' => $c->type === 'email' ? ['emails' => array_values((array) ($config['emails'] ?? []))] : null,
                'masked' => $c->maskedConfig(),
            ];
        })->all();

        $rules = AlertRule::query()->orderBy('name')->get()
            ->filter(fn (AlertRule $r) => $r->project_id === null || in_array($r->project_id, $projectIds, true))
            ->map(fn (AlertRule $r) => [
                'id' => (string) $r->id,
                'name' => $r->name,
                'type' => $r->type,
                'project_id' => $r->project_id,
                'region_id' => $r->region_id,
                'params' => $r->params,
                'severity' => $r->severity,
                'cooldown_minutes' => $r->cooldown_minutes,
                'channel_ids' => array_values((array) ($r->channel_ids ?? [])),
                'is_enabled' => (bool) $r->is_enabled,
            ])->values()->all();

        $page = StatusPage::query()->first();

        $context->putJson(PackageFormat::OBSERVABILITY, [
            'alert_channels' => $channels,
            'alert_rules' => $rules,
            'status_page' => $page ? array_diff_key($page->toApi(), ['id' => true]) : null,
        ]);
        $context->count('alert_channels', count($channels));
        $context->count('alert_rules', count($rules));
        $context->contents['status_page'] = $page !== null;
    }

    public function plan(ImportContext $context, ImportPlan $plan): void
    {
        $data = (array) $context->json(PackageFormat::OBSERVABILITY, []);
        $unlocked = $context->secretsState() === 'unlocked';

        foreach ((array) ($data['alert_channels'] ?? []) as $channel) {
            $needsSecrets = ($channel['type'] ?? null) !== 'email';
            $exists = AlertChannel::query()->where('name', (string) ($channel['name'] ?? ''))->exists();
            $plan->organization['alert_channels'][] = [
                'name' => $channel['name'] ?? null,
                'type' => $channel['type'] ?? null,
                'status' => $exists ? 'exists' : 'create',
                'secrets' => ! $needsSecrets ? 'none' : ($unlocked ? 'included' : 'missing'),
            ];
            if ($needsSecrets && ! $unlocked && ! $exists) {
                $plan->manual('channel_secrets', __('org_transfer.manual.channel_secrets'), [(string) ($channel['name'] ?? '?')]);
            }
        }

        $page = $data['status_page'] ?? null;
        if (is_array($page)) {
            $current = StatusPage::query()->first();
            $plan->organization['status_page'] = $current ? 'update' : 'create';
            if ($this->pages->conflicts((string) ($page['slug'] ?? ''), null, $current?->id)) {
                $plan->organization['status_page_slug'] = $this->freeSlug((string) ($page['slug'] ?? 'status'), $current?->id);
            }
            if (! empty($page['custom_domain'])) {
                $plan->manual('dns', __('org_transfer.manual.dns'), [(string) $page['custom_domain']]);
            }
        }
    }

    public function import(ImportContext $context): void
    {
        $data = (array) $context->json(PackageFormat::OBSERVABILITY, []);

        foreach ((array) ($data['alert_channels'] ?? []) as $channel) {
            $sourceId = (string) ($channel['id'] ?? '');
            $name = (string) ($channel['name'] ?? '');
            if ($sourceId === '' || $context->settled('alert_channel', $sourceId)) {
                continue;
            }
            if ($existing = AlertChannel::query()->where('name', $name)->first()) {
                $context->record('alert_channel', $sourceId, $name, 'exists', (string) $existing->id);

                continue;
            }

            $config = ($channel['type'] ?? null) === 'email'
                ? (array) ($channel['config'] ?? [])
                : $context->secret('alert_channels', $sourceId);
            $missing = ! is_array($config);
            try {
                $created = AlertChannel::query()->create([
                    'name' => $name,
                    'type' => (string) $channel['type'],
                    'config' => is_array($config) ? $config : [],
                    // A channel without its webhook / token would only fail: off until it is set
                    'is_enabled' => (bool) ($channel['is_enabled'] ?? true) && ! $missing,
                    'created_by' => $context->userId(),
                ]);
                $context->record('alert_channel', $sourceId, $name, $missing ? 'manual' : 'created', (string) $created->id, $missing ? __('org_transfer.manual.channel_secrets') : null);
                if ($missing) {
                    $context->manual('channel_secrets', __('org_transfer.manual.channel_secrets'), [$name]);
                }
            } catch (\Throwable $e) {
                $context->record('alert_channel', $sourceId, $name, 'failed', null, $e->getMessage());
            }
        }

        foreach ((array) ($data['alert_rules'] ?? []) as $rule) {
            $sourceId = (string) ($rule['id'] ?? '');
            if ($sourceId === '' || $context->settled('alert_rule', $sourceId)) {
                continue;
            }
            $projectId = null;
            if (! empty($rule['project_id'])) {
                $projectId = $context->mapped('project', (string) $rule['project_id']);
                if ($projectId === null) {
                    $context->record('alert_rule', $sourceId, $rule['name'] ?? $sourceId, 'skipped', null, __('org_transfer.import.project_not_imported'));

                    continue;
                }
            }
            $channels = array_values(array_filter(array_map(fn ($id) => $context->mapped('alert_channel', (string) $id), (array) ($rule['channel_ids'] ?? []))));
            try {
                $created = AlertRule::query()->create([
                    'name' => (string) ($rule['name'] ?? $sourceId),
                    'type' => (string) $rule['type'],
                    'project_id' => $projectId,
                    'region_id' => ! empty($rule['region_id']) ? $context->regionFor((string) $rule['region_id'])?->id : null,
                    'params' => $rule['params'] ?? null,
                    'severity' => (string) ($rule['severity'] ?? 'warning'),
                    'cooldown_minutes' => (int) ($rule['cooldown_minutes'] ?? 30),
                    'channel_ids' => $channels,
                    'is_enabled' => (bool) ($rule['is_enabled'] ?? true),
                    'state' => AlertRule::STATE_OK,
                ]);
                $context->record('alert_rule', $sourceId, $created->name, 'created', (string) $created->id);
            } catch (\Throwable $e) {
                $context->record('alert_rule', $sourceId, $rule['name'] ?? $sourceId, 'failed', null, $e->getMessage());
            }
        }

        $page = $data['status_page'] ?? null;
        if (is_array($page) && ! $context->settled('status_page', 'page')) {
            try {
                $current = StatusPage::query()->first();
                $slug = (string) ($page['slug'] ?? 'status');
                if ($this->pages->conflicts($slug, null, $current?->id)) {
                    $slug = $this->freeSlug($slug, $current?->id);
                }
                $domain = ! empty($page['custom_domain']) && ! $this->pages->conflicts($slug, (string) $page['custom_domain'], $current?->id)
                    ? (string) $page['custom_domain'] : null;
                $saved = $this->pages->save(array_merge(
                    array_intersect_key($page, array_flip(['title', 'description', 'logo_url', 'is_public', 'show_incident_history', 'history_days'])),
                    ['slug' => $slug, 'custom_domain' => $domain],
                ));
                $context->record('status_page', 'page', $saved->slug, 'created', (string) $saved->id);
                if (! empty($page['custom_domain'])) {
                    $context->manual('dns', __('org_transfer.manual.dns'), [(string) $page['custom_domain']]);
                }
            } catch (\Throwable $e) {
                $context->record('status_page', 'page', (string) ($page['slug'] ?? ''), 'failed', null, $e->getMessage());
            }
        }
    }

    private function freeSlug(string $slug, ?string $ownPageId): string
    {
        for ($i = 2; $i < 100; $i++) {
            $candidate = mb_substr($slug, 0, 60)."-{$i}";
            if (! $this->pages->conflicts($candidate, null, $ownPageId)) {
                return $candidate;
            }
        }

        return mb_substr($slug, 0, 55).'-'.substr(bin2hex(random_bytes(3)), 0, 6);
    }
}
