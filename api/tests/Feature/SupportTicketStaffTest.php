<?php

namespace Tests\Feature;

use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Modules\Ticket\Providers\TicketServiceProvider;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Ticket module: a new ticket notifies the people who work the support queue. users.is_admin
 * comes with the Tenancy module; a single-tenant install has no such column (opening a ticket
 * answered 500 there), so its staff are the members whose role holds tickets.manage.
 */
class SupportTicketStaffTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite may run without the Ticket module enabled: register + boot it the way it
        // boots when enabled (routes, migrations)
        config(['ticket.enabled' => true]);
        $this->app->register(TicketServiceProvider::class, true);
        Artisan::call('migrate', ['--path' => 'Modules/Ticket/Database/Migrations', '--force' => true]);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
    }

    private function openTicket($user): void
    {
        $this->actingAs($user, 'sanctum')->postJson('/api/support-tickets', [
            'subject' => 'Cannot reach my function',
            'description' => 'The endpoint answers 502 since the last deploy.',
            'category' => 'deployment',
            'priority' => 'high',
        ])->assertCreated();
    }

    private function ticketNotifications($user): int
    {
        return Notification::where('user_id', $user->id)->where('type', 'ticket.created')->count();
    }

    public function test_a_single_tenant_install_notifies_the_members_who_manage_tickets(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'is_admin'), 'this suite runs without the Tenancy module');
        $owner = $this->userWithRole('owner');
        $admin = $this->userWithRole('admin');
        $developer = $this->userWithRole('developer');

        $this->openTicket($developer);

        $this->assertSame(1, $this->ticketNotifications($owner));
        $this->assertSame(1, $this->ticketNotifications($admin));
        $this->assertSame(0, $this->ticketNotifications($developer));
    }

    public function test_platform_administrators_are_the_staff_where_users_have_is_admin(): void
    {
        $platformAdmin = $this->platformAdmin();
        $developer = $this->userWithRole('developer');

        $this->openTicket($developer);

        $this->assertSame(1, $this->ticketNotifications($platformAdmin));
        $this->assertSame(0, $this->ticketNotifications($developer));
    }
}
