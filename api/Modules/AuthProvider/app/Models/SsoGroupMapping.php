<?php

namespace Modules\AuthProvider\Models;

use App\Models\Group;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An external group (OIDC groups claim value / SAML group attribute value) → a group of the
 * organization and / or the user's organization-wide role. auth_provider_id null = every provider.
 *
 * @property string $id
 * @property string|null $auth_provider_id
 * @property string $external_group
 * @property string|null $group_id
 * @property string|null $role
 */
class SsoGroupMapping extends Model
{
    use HasUlids;

    protected $table = 'sso_group_mappings';

    protected $fillable = ['auth_provider_id', 'external_group', 'group_id', 'role'];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AuthProvider::class, 'auth_provider_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
