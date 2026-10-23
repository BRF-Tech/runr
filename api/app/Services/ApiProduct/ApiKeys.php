<?php

namespace App\Services\ApiProduct;

use App\Models\ApiProduct\ApiConsumer;
use App\Models\ApiProduct\ApiKey;
use App\Models\GatewayRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * API keys of consumers (docs/api-product.md#api-keys): "rk_" and 43 random letters and digits
 * (about 256 bits). The key is returned once; RunR stores its SHA-256 hash — the region's
 * gateway-auth hashes what a caller sends and looks the hash up — and the first 11 characters
 * to tell keys apart. A key is never written to a log.
 */
class ApiKeys
{
    public const PREFIX = 'rk_';

    /** Characters of the stored, visible prefix ("rk_" + 8). */
    public const VISIBLE = 11;

    public static function generate(): string
    {
        return self::PREFIX.Str::random(43);
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function visiblePrefix(string $key): string
    {
        return substr($key, 0, self::VISIBLE);
    }

    /**
     * A new key for $consumer. $data: name, scopes, plan_id, expires_at (validated by the caller,
     * scopes checked here against the routes the consumer may call).
     *
     * @param  array{name: string, scopes?: array|null, plan_id?: string|null, expires_at?: string|null}  $data
     * @return array{0: ApiKey, 1: string} the key and its plaintext
     */
    public function issue(ApiConsumer $consumer, array $data, ?int $userId, ?string $rotatedFrom = null): array
    {
        $plain = self::generate();

        /** @var ApiKey $key */
        $key = $consumer->keys()->create([
            'name' => $data['name'],
            'prefix' => self::visiblePrefix($plain),
            'key_hash' => self::hash($plain),
            'scopes' => $this->normalizeScopes($consumer, $data['scopes'] ?? null),
            'plan_id' => $data['plan_id'] ?? null,
            'expires_at' => isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            'rotated_from_id' => $rotatedFrom,
            'created_by' => $userId,
        ]);

        return [$key->setRelation('consumer', $consumer), $plain];
    }

    /**
     * Rotation with overlap: a new key with the same name, scopes, plan and expiry; the old one
     * keeps working for $overlapMinutes (0: revoked at once) so callers can switch without an outage.
     *
     * @return array{0: ApiKey, 1: string} the new key and its plaintext
     */
    public function rotate(ApiKey $key, int $overlapMinutes, ?int $userId): array
    {
        $consumer = $key->consumer;

        return DB::transaction(function () use ($key, $consumer, $overlapMinutes, $userId) {
            [$new, $plain] = $this->issue($consumer, [
                'name' => $key->name,
                'scopes' => $key->scopes,
                'plan_id' => $key->plan_id,
                'expires_at' => $key->expires_at?->toIso8601String(),
            ], $userId, $key->id);

            if ($overlapMinutes <= 0) {
                $key->update(['revoked_at' => now()]);
            } else {
                $until = now()->addMinutes($overlapMinutes);
                if ($key->expires_at === null || $key->expires_at->greaterThan($until)) {
                    $key->update(['expires_at' => $until]);
                }
            }

            return [$new, $plain];
        });
    }

    public function revoke(ApiKey $key): ApiKey
    {
        if ($key->revoked_at === null) {
            $key->update(['revoked_at' => now()]);
        }

        return $key;
    }

    /**
     * Scopes as stored: [{route_id, methods}] with known routes of the consumer's gateway (any
     * gateway of the organization for an organization consumer) and upper-case methods; null =
     * every route.
     *
     * @return list<array{route_id: string, methods: list<string>|null}>|null
     */
    public function normalizeScopes(ApiConsumer $consumer, ?array $scopes): ?array
    {
        if ($scopes === null) {
            return null;
        }

        $routeIds = array_values(array_unique(array_map(fn ($s) => (string) ($s['route_id'] ?? ''), $scopes)));
        $known = GatewayRoute::query()
            ->whereIn('id', $routeIds)
            ->when($consumer->gateway_id !== null, fn ($q) => $q->where('gateway_id', $consumer->gateway_id))
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $out = [];
        foreach ($scopes as $i => $scope) {
            $routeId = (string) ($scope['route_id'] ?? '');
            if (! in_array($routeId, $known, true)) {
                throw ValidationException::withMessages(["scopes.{$i}.route_id" => __('api_product.key.unknown_route')]);
            }
            $methods = $scope['methods'] ?? null;
            $out[] = [
                'route_id' => $routeId,
                'methods' => $methods === null ? null : array_values(array_unique(array_map(fn ($m) => strtoupper((string) $m), $methods))),
            ];
        }

        return $out;
    }
}
