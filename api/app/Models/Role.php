<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A custom role of the organization (built-in roles live in config/permissions.php).
 * Stored in the organization's own database: the tenant schema in multi-tenant installs.
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property list<string> $permissions
 */
class Role extends Model
{
    use HasUlids;

    protected $table = 'rbac_roles';

    protected $fillable = ['key', 'name', 'description', 'permissions'];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'key';
    }
}
