<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Tenancy\Models\Tenant;
use Stancl\Tenancy\Database\TenantCollection;

/**
 * @property-read Collection<int, Gateway> $gateways
 * @property-read int|null $gateways_count
 * @property-read string|null $avatar_url
 * @property-read Collection<int, NotificationPreference> $notificationPreferences
 * @property-read int|null $notification_preferences_count
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read TenantCollection<int, Tenant> $tenants
 * @property-read int|null $tenants_count
 * @property-read Collection<int, PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantAwareUser newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantAwareUser newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantAwareUser query()
 *
 * @mixin Eloquent
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'tutorial_completed_at',
        'avatar_path',
        'bio',
        'phone',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'is_disabled',
        'disabled_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected $appends = ['avatar_url'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'tutorial_completed_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'is_disabled' => 'boolean',
            'disabled_at' => 'datetime',
        ];
    }

    public function gateways(): HasMany
    {
        return $this->hasMany(Gateway::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        return Storage::disk('public')->url($this->avatar_path);
    }
}
