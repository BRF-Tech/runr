<?php

namespace Modules\Billing\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Price and markup versions are unique per scope (region, or the default: region_id null) and
 * effective date (BillingSchema::harden()). put() is the upsert behind "a new version for the
 * same date replaces it": the row of that date is updated, else one is inserted — and when a
 * concurrent request inserted it first, that row is updated instead.
 */
final class Versions
{
    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @param  array<string, mixed>  $values
     * @return T
     */
    public static function put(string $model, ?string $regionId, string $date, array $values): Model
    {
        $scoped = function () use ($model, $regionId, $date): Builder {
            /** @var Builder<T> $query */
            $query = $model::query();

            return $query->when($regionId === null, fn ($q) => $q->whereNull('region_id'), fn ($q) => $q->where('region_id', $regionId))
                ->whereDate('effective_from', $date);
        };

        return DB::transaction(function () use ($model, $regionId, $date, $values, $scoped) {
            $row = $scoped()->lockForUpdate()->first();
            if ($row === null) {
                try {
                    // A savepoint: a concurrent insert of the same version does not abort the transaction
                    return DB::transaction(fn () => tap(new $model(['region_id' => $regionId, 'effective_from' => $date]), fn (Model $m) => $m->fill($values)->save()));
                } catch (UniqueConstraintViolationException) {
                    $row = $scoped()->lockForUpdate()->firstOrFail();
                }
            }
            $row->fill($values)->save();

            return $row;
        });
    }
}
