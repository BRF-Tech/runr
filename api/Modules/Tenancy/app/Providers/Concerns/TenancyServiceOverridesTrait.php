<?php

namespace Modules\Tenancy\Providers\Concerns;

use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DemoController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\EnsureRegionMutable;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\KnativeRegion\StoreKnativeRegionRequest;
use App\Http\Requests\KnativeRegion\UpdateKnativeRegionRequest;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Services\AgentMessageHandler;
use App\Services\Ai\Gpu\GpuQuota;
use App\Services\Ai\Models\ModelUsageMeter;
use App\Services\GitService;
use App\Services\Helpers\MonitoringEndpointAccess;
use App\Services\Manifests\KnativeManifests;
use App\Services\Manifests\QueueConsumerManifests;
use App\Services\ResourceThresholdService;
use App\Support\BroadcastChannelResolver;
use App\Support\DeployGate;
use App\Support\TenantAccess;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Http\Request;
use Modules\AuthProvider\Controllers\AuthProviderController;
use Modules\Tenancy\Http\Controllers\TenancyAdminSupportTicketController;
use Modules\Tenancy\Http\Controllers\TenancyApiTokenController;
use Modules\Tenancy\Http\Controllers\TenancyAuthController;
use Modules\Tenancy\Http\Controllers\TenancyAuthProviderController;
use Modules\Tenancy\Http\Controllers\TenancyDemoController;
use Modules\Tenancy\Http\Controllers\TenancyNotificationController;
use Modules\Tenancy\Http\Controllers\TenancySettingsController;
use Modules\Tenancy\Http\Controllers\TenancySupportTicketController;
use Modules\Tenancy\Http\Controllers\TenancyUserController;
use Modules\Tenancy\Http\Middleware\EnsureTenantRegionMutable;
use Modules\Tenancy\Http\Requests\TenancyStoreKnativeRegionRequest;
use Modules\Tenancy\Http\Requests\TenancyUpdateKnativeRegionRequest;
use Modules\Tenancy\Http\Requests\TenantRegisterRequest;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantAwareKnativeRegion;
use Modules\Tenancy\Models\TenantAwareUser;
use Modules\Tenancy\Models\TenantUser;
use Modules\Tenancy\Services\TenantAwareAgentMessageHandler;
use Modules\Tenancy\Services\TenantAwareGitService;
use Modules\Tenancy\Services\TenantGpuQuota;
use Modules\Tenancy\Services\TenantModelUsageMeter;
use Modules\Tenancy\Services\TenantResourceThresholdService;
use Modules\Ticket\Http\Controllers\AdminSupportTicketController;
use Modules\Ticket\Http\Controllers\SupportTicketController;

trait TenancyServiceOverridesTrait
{
    /** Bind main app classes to tenant-aware implementations. */
    protected function registerServiceOverrides(): void
    {
        $this->registerServiceBindings();
        $this->registerManifestLabels();
        $this->registerBroadcastChannelResolver();
        $this->registerQueueStreamResolver();
        $this->registerProjectSlugResolver();
        $this->registerMonitoringEndpointAccess();
        $this->registerDeployGate();
        $this->registerTenantAccess();
    }

    /**
     * Current tenant + a user's role in it for the audit log and the secrets / audit abilities
     * (App\Support\TenantAccess).
     */
    private function registerTenantAccess(): void
    {
        TenantAccess::setResolvers(
            function () {
                $tenant = tenancy()->initialized ? tenancy()->tenant : null;

                return $tenant ? ['id' => (string) $tenant->getTenantKey(), 'name' => $tenant->name ?? null, 'slug' => $tenant->slug ?? null] : null;
            },
            fn ($user, string $tenantId) => TenantUser::where('tenant_id', $tenantId)
                ->where('user_id', $user->getAuthIdentifier())
                ->value('role'),
        );
    }

    /**
     * prometheus_url / loki_url / loki_org_id of a new region: platform admins, or the owner of the
     * tenant the (tenant-owned) region is created in. Tenant members/admins cannot point a region
     * at a monitoring endpoint.
     */
    private function registerMonitoringEndpointAccess(): void
    {
        MonitoringEndpointAccess::setResolver(
            fn (Request $request) => self::mayConfigureMonitoring($request)
        );
    }

    public static function mayConfigureMonitoring(Request $request): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }
        if ($user->is_admin) {
            return true;
        }

        $tenant = $request->attributes->get('tenant');
        if (! $tenant) {
            return false;
        }

        return TenantUser::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->exists();
    }

    /**
     * While a tenant is being deleted (DeleteTenantJob), deploys and builds queued before the
     * delete started must not recreate workloads behind the teardown. Read fresh: the job flips
     * the status while other workers already hold the tenant model.
     */
    private function registerDeployGate(): void
    {
        DeployGate::setResolver(function () {
            $tenant = tenancy()->tenant;
            if (! $tenant) {
                return null;
            }

            $status = Tenant::query()->whereKey($tenant->getTenantKey())->value('status');

            return $status && $status !== Tenant::STATUS_ACTIVE ? "Tenant {$tenant->slug} is being deleted" : null;
        });
    }

    private function registerServiceBindings(): void
    {
        $bindings = [
            GitService::class => TenantAwareGitService::class,
            SettingsController::class => TenancySettingsController::class,
            AuthController::class => TenancyAuthController::class,
            User::class => TenantAwareUser::class,
            KnativeRegion::class => TenantAwareKnativeRegion::class,
            DemoController::class => TenancyDemoController::class,
            NotificationController::class => TenancyNotificationController::class,
            ResourceThresholdService::class => TenantResourceThresholdService::class,
            UserController::class => TenancyUserController::class,
            StoreKnativeRegionRequest::class => TenancyStoreKnativeRegionRequest::class,
            UpdateKnativeRegionRequest::class => TenancyUpdateKnativeRegionRequest::class,
            RegisterRequest::class => TenantRegisterRequest::class,
            ApiTokenController::class => TenancyApiTokenController::class,
            SupportTicketController::class => TenancySupportTicketController::class,
            AdminSupportTicketController::class => TenancyAdminSupportTicketController::class,
            AuthProviderController::class => TenancyAuthProviderController::class,
            AgentMessageHandler::class => TenantAwareAgentMessageHandler::class,
            // region.mutable: platform regions read-only for tenant users
            EnsureRegionMutable::class => EnsureTenantRegionMutable::class,
            // GPU quota per tenant and region; model services metered per tenant (docs/ai.md)
            GpuQuota::class => TenantGpuQuota::class,
            ModelUsageMeter::class => TenantModelUsageMeter::class,
        ];

        foreach ($bindings as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }
    }

    /** Scope broadcast channels to tenant when in tenant context (private: routes/channels.php). */
    private function registerBroadcastChannelResolver(): void
    {
        BroadcastChannelResolver::setResolver(function (string $channel) {
            $tenant = tenancy()->tenant;
            if ($tenant) {
                return [new PrivateChannel("tenant.{$tenant->id}.{$channel}")];
            }

            return [new PrivateChannel($channel)];
        });
    }

    /** Suffix queue stream names with tenant slug for Redis isolation. */
    private function registerQueueStreamResolver(): void
    {
        QueueConsumerManifests::setStreamNameResolver(function (string $stream) {
            $tenant = tenancy()->tenant;

            return $tenant ? "{$stream}-{$tenant->slug}" : $stream;
        });
    }

    /**
     * Suffix project slugs with the tenant slug: tenants share the region namespace, so the
     * Knative Service `<name>-<tenant>` stays unique across tenants. Used by the ProjectObserver
     * and by the project name validation (Project::slugFor).
     */
    private function registerProjectSlugResolver(): void
    {
        Project::setSlugResolver(function (string $slug) {
            $tenant = tenancy()->tenant;

            return $tenant ? "{$slug}-{$tenant->slug}" : $slug;
        });
    }

    /**
     * Add the runr/tenant label to every workload manifest (Knative Service, CronJob, code
     * ConfigMap, queue consumer) in tenant context — tenant deletion sweeps by this label.
     */
    private function registerManifestLabels(): void
    {
        KnativeManifests::setExtraLabelsResolver(function ($project) {
            $tenant = tenancy()->tenant;

            return $tenant ? ['runr/tenant' => $tenant->slug] : [];
        });
    }
}
