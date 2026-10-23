<?php

namespace App\Services\Secrets;

use RuntimeException;

/**
 * A secret backend could not do what was asked. The message is for the user (translated) and
 * never carries a value; status() is the HTTP status the API answers with.
 */
class SecretBackendException extends RuntimeException
{
    /** The project has no cluster to keep the value in (no region, a demo region). */
    public const NO_CLUSTER = 'no_cluster';

    /** The region's cluster did not answer, or refused the request. */
    public const UNREACHABLE = 'region_unreachable';

    /** The value is not in the region (gone with its cluster, removed by hand). */
    public const MISSING = 'value_missing';

    /** The backend does not do this (e.g. reading a reference back). */
    public const UNSUPPORTED = 'unsupported';

    /** External Secrets Operator does not run in the region. */
    public const NOT_INSTALLED = 'eso_not_installed';

    /**
     * A reference to a secret store while the upgrade from 0.5.10 is not finalized: 0.5.10, which
     * a rollback installs again, could not give its value to the workload (RollbackCopies).
     */
    public const NOT_FINALIZED = 'upgrade_not_finalized';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function noCluster(?string $region): self
    {
        return new self(self::NO_CLUSTER, __('api.secret.no_cluster', ['region' => $region ?? '—']));
    }

    public static function unreachable(?string $region, \Throwable $previous): self
    {
        return new self(self::UNREACHABLE, __('api.secret.region_unreachable', ['region' => $region ?? '—']), $previous);
    }

    /** @param list<string> $names */
    public static function missing(?string $region, array $names): self
    {
        return new self(self::MISSING, __('api.secret.value_missing', ['region' => $region ?? '—', 'names' => implode(', ', $names)]));
    }

    public function status(): int
    {
        return match ($this->reason) {
            self::UNREACHABLE => 503,
            self::MISSING, self::NOT_FINALIZED => 409,
            default => 422,
        };
    }
}
