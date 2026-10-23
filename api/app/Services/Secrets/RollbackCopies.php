<?php

namespace App\Services\Secrets;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * The downgrade guard of pass-through (docs/upgrade.md, "Rolling back"; docs/security.md,
 * "Pass-through").
 *
 * RunR 0.5.10 builds a project's Secret <slug>-secret-env from project_secrets.value on every
 * deploy: a variable whose database value is gone would be written into the region as an empty
 * string once 0.5.10 is installed again (helm rollback, or the previous chart installed again on
 * the same database). Until the operator finalizes the upgrade, RunR therefore:
 *
 *  - keeps an encrypted copy of every value it writes into a region or its OpenBao in
 *    project_secrets.value, marked rollback_copy — the column 0.5.10 reads; RunR itself reads the
 *    value from the region and never from the copy (ProjectSecret::hasRollbackCopy());
 *  - lets `secrets:move-to-region` move values out of the database the same way: written into the
 *    region and verified there, the database value kept as the copy;
 *  - refuses references to secret stores, whose values 0.5.10 could not hand to a workload.
 *
 * Finalizing (`php artisan secrets:move-to-region --finalize`, or the Helm value
 * projectSecrets.finalize, which runs it in the upgrade's bootstrap Job) checks every value in its
 * region against its fingerprint, drops the copies and records the time: from then on no value
 * reaches the database and references can be set — and 0.5.10 can no longer be installed again
 * without losing values. An installation that never ran a release before pass-through starts
 * finalized (migration 2026_10_22_500001).
 *
 * The state is the installation's (central settings), also inside a tenant.
 */
final class RollbackCopies
{
    /** Central setting: when the upgrade was finalized (ISO 8601); absent while copies are kept. */
    public const SETTING = 'secrets_pass_through_finalized_at';

    /** Copies are kept and references refused: the upgrade from 0.5.10 is not finalized. */
    public static function kept(): bool
    {
        return self::finalizedAt() === null;
    }

    public static function finalizedAt(): ?Carbon
    {
        $value = Setting::platform(self::SETTING);
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            // Unreadable: finalized at an unknown time — never open the window again by accident
            return Carbon::createFromTimestamp(0);
        }
    }

    /** Record the finalization (central settings). */
    public static function markFinalized(): void
    {
        Setting::set(self::SETTING, now()->toIso8601String());
    }

    /**
     * Refuse a reference while copies are kept.
     *
     * @throws SecretBackendException
     */
    public static function assertReferencesAllowed(): void
    {
        if (self::kept()) {
            throw new SecretBackendException(SecretBackendException::NOT_FINALIZED, __('api.secret.not_finalized'));
        }
    }
}
