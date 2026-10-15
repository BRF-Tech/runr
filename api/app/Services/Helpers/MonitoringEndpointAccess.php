<?php

namespace App\Services\Helpers;

use App\Rules\InClusterServiceUrl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

/**
 * Who may point a region at an operator-supplied Prometheus/Loki (prometheus_url, loki_url,
 * loki_org_id). Single-tenant installs have no roles: every user manages the platform, so the
 * default allows. The Tenancy module registers its resolver (platform admin, or the owner of the
 * tenant the new tenant-owned region belongs to).
 */
final class MonitoringEndpointAccess
{
    /** @var (Closure(Request): bool)|null */
    private static ?Closure $resolver = null;

    public const FIELDS = ['prometheus_url', 'loki_url', 'loki_org_id'];

    /**
     * @param  (Closure(Request): bool)|null  $resolver
     */
    public static function setResolver(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function allows(Request $request): bool
    {
        return self::$resolver ? (bool) (self::$resolver)($request) : true;
    }

    /**
     * Validation rules for the monitoring fields (region create + setup wizard).
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'prometheus_url' => ['sometimes', 'nullable', 'string', 'max:'.MonitoringEndpoint::MAX_LENGTH, new InClusterServiceUrl],
            'loki_url' => ['sometimes', 'nullable', 'string', 'max:'.MonitoringEndpoint::MAX_LENGTH, new InClusterServiceUrl],
            // Loki tenant ID (X-Scope-OrgID): Loki's tenant charset, "|" for multi-tenant queries
            'loki_org_id' => ['sometimes', 'nullable', 'string', 'max:255', "regex:/^[A-Za-z0-9!._*'()|-]+$/D"],
        ];
    }

    /**
     * After-validation hook: a field set by someone who may not set it is a validation error.
     */
    public static function guard(Validator $validator, Request $request): void
    {
        $validator->after(function ($validator) use ($request) {
            if (self::allows($request)) {
                return;
            }

            foreach (self::FIELDS as $field) {
                if (filled($request->input($field))) {
                    $validator->errors()->add($field, __('api.region.monitoring_endpoint_forbidden'));
                }
            }
        });
    }

    /**
     * Region attributes for the validated monitoring input: a supplied URL makes that component
     * operator-managed (RunR installs neither kube-prometheus-stack nor loki-stack for it).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function regionAttributes(array $input): array
    {
        $attributes = [];

        if (! empty($input['prometheus_url'])) {
            $attributes['prometheus_url'] = $input['prometheus_url'];
            $attributes['prometheus_managed'] = false;
        }

        if (! empty($input['loki_url'])) {
            $attributes['loki_url'] = $input['loki_url'];
            $attributes['loki_managed'] = false;
            // No RunR-owned Loki pods to watch for readiness — the operator vouches it is up
            $attributes['loki_status'] = 'ready';
        }

        if (! empty($input['loki_org_id'])) {
            $attributes['loki_org_id'] = $input['loki_org_id'];
        }

        return $attributes;
    }
}
