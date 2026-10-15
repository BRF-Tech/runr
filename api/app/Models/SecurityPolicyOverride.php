<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A deploy the supply-chain policy gate blocked and a user with security.override_policy let
 * through anyway: who, when, why, and which rules were violated. Kept with the project (tenant
 * schema for tenant projects) and shown on its Security tab.
 *
 * @property string $id
 * @property string $project_id
 * @property string $version_id
 * @property string|null $deployment_id
 * @property string|null $report_id
 * @property string|null $user_id
 * @property string|null $username
 * @property string $reason
 * @property array $violations
 */
class SecurityPolicyOverride extends Model
{
    use HasUlids;

    protected $fillable = ['project_id', 'version_id', 'deployment_id', 'report_id', 'user_id', 'username', 'reason', 'violations'];

    protected $casts = ['violations' => 'array'];
}
