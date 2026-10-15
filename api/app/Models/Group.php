<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A group (team) of members. Roles assigned to a group apply to every member — organization-wide
 * or on single projects. SSO group mappings keep memberships with source "sso" in sync.
 *
 * @property string $id
 * @property string $name
 * @property string|null $description
 */
class Group extends Model
{
    use HasUlids;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SSO = 'sso';

    protected $table = 'rbac_groups';

    protected $fillable = ['name', 'description'];

    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class, 'subject_id')
            ->where('subject_type', RoleAssignment::SUBJECT_GROUP);
    }

    /** The organization-wide role of the group (null = none). */
    public function orgRole(): ?string
    {
        return RoleAssignment::query()
            ->where('subject_type', RoleAssignment::SUBJECT_GROUP)
            ->where('subject_id', $this->id)
            ->whereNull('project_id')
            ->value('role');
    }

    protected static function booted(): void
    {
        static::deleting(function (Group $group) {
            DB::table('rbac_group_user')->where('group_id', $group->id)->delete();
            RoleAssignment::query()
                ->where('subject_type', RoleAssignment::SUBJECT_GROUP)
                ->where('subject_id', $group->id)
                ->delete();
        });
    }
}
