<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What an import did with one entry of the package (docs/org-export.md): the section, the
 * entry's key in the package (the source id) and the row it created in the organization. An
 * import applied again after a failure skips the entries that are created already, so every
 * entry is created once.
 *
 * @property int $id
 * @property string $org_transfer_id
 * @property string $section
 * @property string $source_key
 * @property string|null $name
 * @property string|null $target_id
 * @property string $status created | exists | skipped | failed | manual
 * @property string|null $message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrgTransferItem extends Model
{
    use UsesCentralConnection;

    public const CREATED = 'created';

    public const EXISTS = 'exists';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public const MANUAL = 'manual';

    /** Statuses after which the entry is not attempted again. */
    public const SETTLED = [self::CREATED, self::EXISTS, self::SKIPPED, self::MANUAL];

    protected $table = 'org_transfer_items';

    protected $fillable = ['org_transfer_id', 'section', 'source_key', 'name', 'target_id', 'status', 'message'];

    /** @return BelongsTo<OrgTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(OrgTransfer::class, 'org_transfer_id');
    }

    public function toApi(): array
    {
        return [
            'section' => $this->section,
            'source' => $this->source_key,
            'name' => $this->name,
            'target_id' => $this->target_id,
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}
