<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The monthly cost limit of a pay-as-you-go project (docs/billing.md#monthly-cost-limit), set by
 * its owner: at WARNING_PERCENT of the amount the owner is notified, at 100 % notified again and —
 * with action "stop" — the project is stopped (CostLimits). The state columns belong to `period`:
 * when the warning and the notice went out at the current amount, the month-to-date cost of the
 * last check. A new month starts them over.
 *
 * @property string $project_id
 * @property float $amount
 * @property string $currency
 * @property string $action
 * @property string|null $period
 * @property Carbon|null $warned_at
 * @property Carbon|null $reached_at
 * @property float|null $month_cost
 * @property Carbon|null $checked_at
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CostLimit extends Model
{
    public const NOTIFY = 'notify';

    public const STOP = 'stop';

    public const ACTIONS = [self::NOTIFY, self::STOP];

    /** Share of the limit (percent) at which the owner is warned. */
    public const WARNING_PERCENT = 80;

    protected $table = 'billing_cost_limits';

    protected $primaryKey = 'project_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['project_id', 'amount', 'currency', 'action', 'period', 'warned_at', 'reached_at', 'month_cost', 'checked_at', 'updated_by'];

    protected $casts = [
        'amount' => 'float',
        'month_cost' => 'float',
        'warned_at' => 'datetime',
        'reached_at' => 'datetime',
        'checked_at' => 'datetime',
    ];
}
