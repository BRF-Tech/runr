<?php

namespace App\Services\Bindings\Drivers;

use App\Models\Binding;
use App\Models\BindingRegionService;
use App\Models\KnativeRegion;
use App\Services\Bindings\BindingException;
use App\Services\Bindings\BindingManifests;
use App\Services\Bindings\DataServices\KvService;
use App\Services\Bindings\Kv\RedisEndpoint;
use App\Services\Bindings\Kv\RedisScripts;
use App\Services\Bindings\Ops\BindingOpsRunner;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * KV binding: an ACL user (the binding's resource name) restricted to the key and channel prefix
 * "<resource name>:" — or, on a region Redis without ACL support, a logical database of its own
 * (credentials = the region Redis password). Memory limit: measured hourly; over it the user may
 * only read and delete until it is below again.
 */
class KvDriver implements BindingDriver
{
    public function __construct(
        private KvService $kv,
        private BindingOpsRunner $ops,
    ) {}

    public function provision(Binding $binding): void
    {
        $endpoint = $this->endpoint($binding);

        if (! $endpoint->acl) {
            $db = $this->kv->allocateDatabase($this->row($binding), $binding->id);
            $binding->credentials = ['mode' => 'db', 'db' => $db];

            return;
        }

        $password = Str::random(32);
        $this->commands($binding, $endpoint, 'kv-provision', [RedisScripts::setUser($binding, [$password])]);

        $binding->credentials = ['mode' => 'acl', 'username' => $binding->resource_name, 'password' => $password];
    }

    public function rotate(Binding $binding): void
    {
        if ($binding->credential('mode') !== 'acl') {
            return; // logical-DB bindings use the region Redis password
        }

        $endpoint = $this->endpoint($binding);
        $new = Str::random(32);

        // The user keeps its current password next to the new one until finishRotation()
        $this->commands($binding, $endpoint, 'kv-rotate', ['ACL SETUSER '.$binding->resource_name.' >'.$new]);

        $binding->previous_credentials = $binding->credentials;
        $binding->credentials = array_merge((array) $binding->credentials, ['password' => $new]);
    }

    public function finishRotation(Binding $binding): void
    {
        $old = (string) ($binding->previous_credentials['password'] ?? '');
        if ($binding->credential('mode') === 'acl' && $old !== '' && $old !== $binding->credential('password')) {
            $this->commands($binding, $this->endpoint($binding), 'kv-rotate-finish', ['ACL SETUSER '.$binding->resource_name.' <'.$old]);
        }

        $binding->previous_credentials = null;
    }

    public function suspend(Binding $binding): void
    {
        if ($binding->credential('mode') !== 'acl') {
            return;
        }

        $this->commands($binding, $this->endpoint($binding), 'kv-suspend', [
            RedisScripts::setUser($binding, [(string) $binding->credential('password')], false, false),
            'CLIENT KILL USER '.$binding->resource_name,
        ]);
    }

    public function resume(Binding $binding): void
    {
        if ($binding->credential('mode') !== 'acl') {
            return;
        }

        $this->commands($binding, $this->endpoint($binding), 'kv-resume', [
            RedisScripts::setUser($binding, [(string) $binding->credential('password')], (bool) $binding->setting('over_quota', false)),
        ]);
    }

    public function purge(Binding $binding): void
    {
        $row = BindingRegionService::forRegion($this->region($binding), 'kv');
        if (! $row) {
            return; // service removed: nothing left to delete
        }

        $endpoint = $this->kv->endpoint($this->region($binding), $row);
        $image = (string) config('bindings.kv.image');

        if ($binding->credential('mode') === 'db') {
            $db = (int) $binding->credential('db');
            $result = $this->ops->run($this->region($binding), RedisScripts::purge($endpoint, $image, '*', $db));
            $this->assertOk($result, 'purge');
            $this->kv->releaseDatabase($row, $binding->id);

            return;
        }

        $result = $this->ops->run($this->region($binding), RedisScripts::purge($endpoint, $image, $binding->kvPrefix().'*', 0, [
            'ACL DELUSER '.$binding->resource_name,
        ]));
        $this->assertOk($result, 'purge');
    }

    public function applySettings(Binding $binding, array $old): void
    {
        $this->enforce($binding, (int) $binding->usage_bytes);
    }

    public function env(Binding $binding): array
    {
        // Connection details only: valid while the service is being reconfigured too
        $endpoint = $this->kv->endpoint($this->region($binding));
        $hostPort = $endpoint->host.':'.$endpoint->port;

        if ($binding->credential('mode') === 'db') {
            return [
                'REDIS_URL' => 'redis://:'.rawurlencode($endpoint->adminPassword).'@'.$hostPort.'/'.(int) $binding->credential('db'),
                'KV_PREFIX' => '',
            ];
        }

        return [
            'REDIS_URL' => 'redis://'.rawurlencode((string) $binding->credential('username')).':'.rawurlencode((string) $binding->credential('password')).'@'.$hostPort,
            'KV_PREFIX' => $binding->kvPrefix(),
        ];
    }

    public function health(Binding $binding): array
    {
        $endpoint = $this->endpoint($binding);
        $details = [
            'mode' => $binding->credential('mode'),
            'memory_mb' => (int) $binding->setting('memory_mb', config('bindings.kv.default_memory_mb')),
            'used_bytes' => (int) $binding->usage_bytes,
            'over_quota' => (bool) $binding->setting('over_quota', false),
        ];

        $command = $binding->credential('mode') === 'acl' ? 'ACL GETUSER '.$binding->resource_name : 'PING';
        $result = $this->ops->run($this->region($binding), RedisScripts::commands($endpoint, (string) config('bindings.kv.image'), 'kv-health', [$command], (int) $binding->credential('db', 0)));

        if (! $result->ok) {
            return ['state' => 'error', 'message' => $result->tail(200), 'details' => $details];
        }

        return ['state' => $details['over_quota'] ? 'degraded' : 'ready', 'message' => $details['over_quota'] ? __('api.bindings.over_quota') : null, 'details' => $details];
    }

    public function measure(KnativeRegion $region, Collection $bindings): array
    {
        $targets = [];
        foreach ($bindings as $binding) {
            if (empty($binding->credentials)) {
                continue;
            }
            $targets[] = $binding->credential('mode') === 'db'
                ? ['id' => $binding->id, 'db' => (int) $binding->credential('db'), 'pattern' => '*']
                : ['id' => $binding->id, 'db' => 0, 'pattern' => $binding->kvPrefix().'*'];
        }
        if (! $targets) {
            return [];
        }

        $result = $this->ops->run($region, RedisScripts::usage($this->kv->endpoint($region), (string) config('bindings.kv.image'), $targets));
        $usage = [];
        foreach ($result->marked('USAGE') as $line) {
            [$id, $bytes] = array_pad(explode(' ', $line), 2, '0');
            $usage[$id] = ['bytes' => (int) $bytes, 'instances' => 0];
        }

        return $usage;
    }

    public function enforce(Binding $binding, int $bytes): void
    {
        if ($binding->credential('mode') !== 'acl' || ! $binding->isActive()) {
            return;
        }

        $limit = (int) $binding->setting('memory_mb', config('bindings.kv.default_memory_mb')) * 1024 * 1024;
        $over = $limit > 0 && $bytes > $limit;

        if ($over === (bool) $binding->setting('over_quota', false)) {
            return;
        }

        $passwords = array_filter([(string) $binding->credential('password'), (string) ($binding->previous_credentials['password'] ?? '')]);
        $this->commands($binding, $this->endpoint($binding), 'kv-quota', [RedisScripts::setUser($binding, $passwords, $over)]);

        $binding->settings = array_merge((array) $binding->settings, ['over_quota' => $over]);
        $binding->status_message = $over ? __('api.bindings.over_quota') : null;
    }

    public function accessTarget(Binding $binding): ?array
    {
        $row = BindingRegionService::forRegion($this->region($binding), 'kv');

        return $row?->mode === 'managed'
            ? ['labels' => ['app.kubernetes.io/name' => BindingManifests::KV_NAME], 'port' => BindingManifests::KV_PORT]
            : null;
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────

    private function region(Binding $binding): KnativeRegion
    {
        return $binding->region ?? throw new BindingException(__('api.bindings.region_missing'));
    }

    private function row(Binding $binding): BindingRegionService
    {
        $row = BindingRegionService::forRegion($this->region($binding), 'kv');
        if (! $row?->isReady()) {
            throw new BindingException(__('api.bindings.service_not_ready', ['service' => 'kv', 'region' => $this->region($binding)->name]));
        }

        return $row;
    }

    private function endpoint(Binding $binding): RedisEndpoint
    {
        return $this->kv->endpoint($this->region($binding), $this->row($binding));
    }

    /** @param list<string> $commands */
    private function commands(Binding $binding, RedisEndpoint $endpoint, string $purpose, array $commands): void
    {
        $result = $this->ops->run($this->region($binding), RedisScripts::commands($endpoint, (string) config('bindings.kv.image'), $purpose, $commands));
        $this->assertOk($result, $purpose);
    }

    private function assertOk($result, string $purpose): void
    {
        if (! $result->ok || ! $result->hasMarker('RUNR_OK')) {
            throw new BindingException(__('api.bindings.kv_operation_failed', ['operation' => $purpose, 'error' => $result->tail()]));
        }
    }
}
