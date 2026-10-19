<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A billing setting of the organization (webhook URL, encrypted webhook secret, the day billing
 * started). Local to the organization's database: a tenant never falls back to the platform's
 * values.
 *
 * @property string $key
 * @property string|null $value
 */
class BillingSetting extends Model
{
    protected $table = 'billing_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];
}
