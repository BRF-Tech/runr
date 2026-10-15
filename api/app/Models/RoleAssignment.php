<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role bound to a user or a group — organization-wide (project_id null) or on one project.
 * One role per subject and scope. In multi-tenant installs a member's own organization-wide role
 * is the tenant membership role (tenant_users.role); rows here carry groups and project access.
 *
 * @property string $id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $role
 * @property string|null $project_id
 */
class RoleAssignment extends Model
{
    use HasUlids;

    public const SUBJECT_USER = 'user';

    public const SUBJECT_GROUP = 'group';

    protected $table = 'rbac_role_assignments';

    protected $fillable = ['subject_type', 'subject_id', 'role', 'project_id'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
