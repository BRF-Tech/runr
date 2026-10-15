<?php

namespace App\Models;

use App\Casts\Encrypted;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A one-time code an SSO callback hands to the console instead of the API token
 * (App\Services\Auth\SignInHandoffService, docs/security.md).
 *
 * @property string $id
 * @property string $code_hash
 * @property string $user_id
 * @property string $token
 * @property Carbon $expires_at
 */
class SignInHandoff extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['code_hash', 'user_id', 'token', 'expires_at'];

    protected $hidden = ['token', 'code_hash'];

    protected $casts = [
        'token' => Encrypted::class,
        'expires_at' => 'datetime',
    ];

    /** The exchange request has no tenant context: the central schema whenever there is one. */
    public function getConnectionName()
    {
        return config('database.connections.central') ? 'central' : parent::getConnectionName();
    }
}
