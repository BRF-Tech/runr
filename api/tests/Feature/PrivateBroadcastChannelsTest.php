<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Events\PlatformEvent;
use App\Models\Notification;
use App\Support\BroadcastChannelResolver;
use App\Support\Broadcasting\ChannelAccess;
use App\Support\TenantAccess;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Every broadcast channel is private and authorized with the user's RBAC permissions
 * (routes/channels.php, App\Support\Broadcasting\ChannelAccess, docs/security.md).
 */
class PrivateBroadcastChannelsTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    protected function tearDown(): void
    {
        TenantAccess::setResolvers(null, null);
        BroadcastChannelResolver::setResolver(null);
        parent::tearDown();
    }

    /** The Reverb (Pusher protocol) broadcaster signs authorized subscriptions. */
    private function useReverb(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
        ]);

        // The channels were registered at boot on the suite's default (null) broadcaster: register
        // them on the Reverb one, as an install with BROADCAST_CONNECTION=reverb does at boot
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');
    }

    private function subscribe(string $channel)
    {
        return $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
    }

    public function test_events_go_to_private_channels(): void
    {
        $this->assertEquals([new PrivateChannel('platform')], (new PlatformEvent('project.updated', []))->broadcastOn());

        $own = new Notification(['type' => 'build.failed', 'level' => 'warning', 'title' => 't']);
        $own->user_id = 7;
        $this->assertEquals([new PrivateChannel('notifications.7')], (new NotificationCreated($own))->broadcastOn());

        $everyone = new Notification(['type' => 'demo.seeded', 'level' => 'info', 'title' => 't']);
        $this->assertEquals([new PrivateChannel('notifications')], (new NotificationCreated($everyone))->broadcastOn());

        $this->assertEquals(
            [new PrivateChannel('tenant.t-1.support-tickets'), new PrivateChannel('support-tickets')],
            (function () {
                BroadcastChannelResolver::setResolver(fn (string $c) => [new PrivateChannel("tenant.t-1.{$c}")]);

                return BroadcastChannelResolver::resolveWithPlatform('support-tickets');
            })(),
        );
    }

    public function test_the_organization_channels(): void
    {
        $member = $this->userWithRole('owner');
        $admin = $this->platformAdmin();

        $this->assertTrue(ChannelAccess::organization($member), 'single-tenant: the install is the organization');
        $this->assertFalse(ChannelAccess::organization(null));

        config(['tenancy.enabled' => true]);
        $this->assertFalse(ChannelAccess::organization($member), 'multi-tenant platform channel: platform admins only');
        $this->assertTrue(ChannelAccess::organization($admin));
    }

    public function test_a_tenant_channel_takes_membership_and_that_tenants_context(): void
    {
        $member = $this->userWithRole('developer');
        $outsider = $this->userWithRole('developer');
        TenantAccess::setResolvers(
            fn () => ['id' => 't-1'],
            fn ($user, string $tenantId) => $tenantId === 't-1' && $user->getAuthIdentifier() === $member->id ? 'developer' : null,
        );

        $this->assertTrue(ChannelAccess::organization($member, 't-1'));
        $this->assertFalse(ChannelAccess::organization($outsider, 't-1'), 'not a member');
        $this->assertFalse(ChannelAccess::organization($member, 't-2'), 'another tenant than the request context');
    }

    public function test_notifications_of_a_user_are_theirs_only(): void
    {
        $jane = $this->userWithRole('developer');
        $john = $this->userWithRole('developer');

        $this->assertTrue(ChannelAccess::ownNotifications($jane, (string) $jane->id));
        $this->assertFalse(ChannelAccess::ownNotifications($john, (string) $jane->id));
    }

    public function test_a_deployment_channel_takes_projects_view(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->caasProject();
        $this->customRole('auditor', ['audit.view']);
        $auditor = $this->userWithRole('auditor');

        $this->assertTrue(ChannelAccess::deployment($owner, $project->id));
        $this->assertFalse(ChannelAccess::deployment($auditor, $project->id), 'no projects.view');
        $this->assertFalse(ChannelAccess::deployment($owner, 'no-such-project'));
    }

    public function test_ticket_channels(): void
    {
        $owner = $this->userWithRole('owner');      // tickets.manage
        $developer = $this->userWithRole('developer');  // own tickets only

        $this->assertTrue(ChannelAccess::ticketList($owner));
        $this->assertFalse(ChannelAccess::ticketList($developer));
        $this->assertTrue(ChannelAccess::ticketInternal($owner));
        $this->assertFalse(ChannelAccess::ticketInternal($developer), 'internal notes are for ticket managers');
        $this->assertTrue(ChannelAccess::ticket($owner, 'any-ticket'));
        $this->assertFalse(ChannelAccess::ticket($developer, 'someone-elses-ticket'));
    }

    public function test_the_auth_endpoint_signs_allowed_subscriptions_only(): void
    {
        $this->useReverb();
        $jane = $this->userWithRole('owner');
        $john = $this->userWithRole('developer');

        $this->signedIn($jane);
        $this->subscribe("private-notifications.{$jane->id}")->assertOk()->assertJsonStructure(['auth']);
        $this->subscribe('private-platform')->assertOk();
        $this->subscribe("private-notifications.{$john->id}")->assertForbidden();

        $this->signedIn($john);
        $this->subscribe('private-support-tickets')->assertForbidden();

        // Not signed in: no subscription at all
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->subscribe('private-platform')->assertUnauthorized();
    }
}
