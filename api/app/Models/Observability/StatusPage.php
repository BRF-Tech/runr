<?php

namespace App\Models\Observability;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The public status page of a tenant (one per tenant; one per installation without Tenancy).
 * Components are the uptime checks marked show_on_status_page. The public lookup by slug /
 * custom domain is StatusPageRoute (central).
 *
 * @property string $id
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property string|null $logo_url
 * @property string|null $custom_domain
 * @property bool $is_public
 * @property bool $show_incident_history
 * @property int $history_days
 */
class StatusPage extends Model
{
    use HasUlids;

    protected $table = 'status_pages';

    protected $fillable = ['slug', 'title', 'description', 'logo_url', 'custom_domain', 'is_public', 'show_incident_history', 'history_days'];

    protected $casts = [
        'is_public' => 'boolean',
        'show_incident_history' => 'boolean',
        'history_days' => 'integer',
    ];

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'logo_url' => $this->logo_url,
            'custom_domain' => $this->custom_domain,
            'is_public' => $this->is_public,
            'show_incident_history' => $this->show_incident_history,
            'history_days' => $this->history_days,
        ];
    }
}
