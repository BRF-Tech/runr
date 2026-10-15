<?php

namespace App\Models\Observability;

use App\Models\Observability\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Central lookup of public status pages: slug / custom domain → the tenant whose schema holds
 * the page (tenant_id null: the central database).
 *
 * @property string $id
 * @property string $slug
 * @property string|null $custom_domain
 * @property string|null $tenant_id
 * @property string $status_page_id
 * @property bool $is_public
 */
class StatusPageRoute extends Model
{
    use HasUlids, UsesCentralConnection;

    protected $table = 'status_page_routes';

    protected $fillable = ['slug', 'custom_domain', 'tenant_id', 'status_page_id', 'is_public'];

    protected $casts = ['is_public' => 'boolean'];
}
