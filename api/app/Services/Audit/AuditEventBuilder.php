<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Query builder of AuditEvent: bulk update / delete / truncate are refused, except the delete the
 * retention prune issues (AuditRetention, inside AuditEvent::whilePruning()).
 *
 * @extends Builder<AuditEvent>
 */
class AuditEventBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException('Audit events are append-only.');
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new LogicException('Audit events are append-only.');
    }

    public function touch($column = null)
    {
        throw new LogicException('Audit events are append-only.');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('Audit events are append-only.');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('Audit events are append-only.');
    }

    public function delete()
    {
        if (! AuditEvent::isPruning()) {
            throw new LogicException('Audit events are removed only by the retention prune.');
        }

        return parent::delete();
    }

    public function forceDelete()
    {
        return $this->delete();
    }
}
