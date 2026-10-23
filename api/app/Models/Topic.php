<?php

namespace App\Models;

use App\Triggers\Topics\TopicStreams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A fan-out topic of the organization (docs/triggers.md#topics): a Redis Stream on the region's
 * Redis (runr.topic.<name>, suffixed with the tenant in a multi-tenant install). Functions and
 * API clients publish to it; every project with a `topic` trigger on it gets each event — one
 * consumer group per subscribing trigger (a queue trigger is one group on one stream).
 *
 * @property string $id
 * @property string $name
 * @property string $knative_region_id
 * @property string|null $description
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read KnativeRegion|null $knativeRegion
 *
 * @method static Builder<static>|Topic query()
 */
class Topic extends Model
{
    use HasUlids;

    public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/';

    protected $fillable = ['name', 'knative_region_id', 'description', 'created_by'];

    /**
     * created_by is a string column (a user's id as text): the answer to the create and every
     * later read name it the same way.
     */
    protected $casts = ['created_by' => 'string'];

    public function knativeRegion(): BelongsTo
    {
        return $this->belongsTo(KnativeRegion::class);
    }

    /** The Redis Stream key of the topic. */
    public function streamKey(): string
    {
        return TopicStreams::key($this->name);
    }

    /**
     * Enabled `topic` triggers on this topic.
     *
     * @return EloquentCollection<int, ProjectTrigger>
     */
    public function subscriptions(): EloquentCollection
    {
        return ProjectTrigger::query()
            ->where('type', 'topic')
            ->get()
            ->filter(fn (ProjectTrigger $t) => $t->setting('topic') === $this->name)
            ->values();
    }
}
