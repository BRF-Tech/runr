<?php

namespace App\Models\ApiProduct;

use App\Models\Observability\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Central: an organization (scope = tenant id, "_" for the central database) with gateway routes
 * behind the gateway-auth service of a region, and the revision of what that service has to know
 * (keys, consumers, plans, routes). The service's sync sends the ETag of the region's rows; the
 * API builds the configuration again only when a revision moved (GatewayAuthConfig).
 *
 * @property int $id
 * @property string $knative_region_id
 * @property string $scope
 * @property int $revision
 * @property Carbon|null $updated_at
 */
class GatewayAuthScope extends Model
{
    use UsesCentralConnection;

    public const CENTRAL = '_';

    protected $table = 'gateway_auth_scopes';

    protected $fillable = ['knative_region_id', 'scope', 'revision'];

    protected $casts = ['revision' => 'integer'];
}
