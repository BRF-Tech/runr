<?php

namespace Modules\AuthProvider\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int|string $user_id
 * @property string $provider
 * @property string $provider_user_id
 * @property string $email
 * @property string|null $name
 * @property string|null $avatar_url
 * @property string $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property Carbon|null $created_at
 * @property-read User|null $user
 */
class SocialAccount extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'email',
        'name',
        'avatar_url',
        'access_token',
        'refresh_token',
        'token_expires_at',
    ];

    /** The provider's OAuth tokens (encrypted at rest) never leave the API. */
    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
