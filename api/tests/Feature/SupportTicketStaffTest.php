<?php

namespace Tests\Feature;

use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Ticket\Models\SupportTicket;
use Modules\Ticket\Models\SupportTicketReply;
use Modules\Ticket\Providers\TicketServiceProvider;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Ticket module: a new ticket notifies the people who work the support queue. users.is_admin
 * comes with the Tenancy module; a single-tenant install has no such column (opening a ticket
 * answered 500 there), so its staff are the members whose role holds tickets.manage — they list,
 * assign, tag and answer tickets through the admin endpoints, and downloads serve only the files
 * recorded on the ticket (internal notes' files to staff only).
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

    public function test_the_staff_list_names_the_members_who_manage_tickets(): void
    {
        $owner = $this->userWithRole('owner', ['name' => 'Olivia Owner']);
        $admin = $this->userWithRole('admin', ['name' => 'Adam Admin']);
        $developer = $this->userWithRole('developer');

        $staff = $this->signedIn($owner)->getJson('/api/admin/support-tickets/staff')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing([$owner->id, $admin->id], array_column($staff, 'id'));
        $this->assertSame(['id', 'name', 'email'], array_keys($staff[0]));
        $this->signedIn($developer)->getJson('/api/admin/support-tickets/staff')->assertForbidden();
    }

    public function test_members_who_manage_tickets_work_the_queue_without_platform_administrators(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'is_admin'));
        $owner = $this->userWithRole('owner');
        $admin = $this->userWithRole('admin');
        $developer = $this->userWithRole('developer');
        $this->openTicket($developer);
        $id = SupportTicket::query()->value('id');

        $this->signedIn($owner)->getJson('/api/admin/support-tickets')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->signedIn($owner)->getJson('/api/admin/support-tickets/stats')->assertOk()->assertJsonPath('open', 1);
        $this->signedIn($owner)->patchJson("/api/admin/support-tickets/{$id}/assign", ['assigned_to' => $admin->id])
            ->assertOk()->assertJsonPath('assignee.id', $admin->id);
        $this->signedIn($owner)->patchJson("/api/admin/support-tickets/{$id}/tags", ['tags' => ['billing', 'urgent']])
            ->assertOk()->assertJsonPath('tags', ['billing', 'urgent']);
        $this->signedIn($owner)->patchJson("/api/admin/support-tickets/{$id}/status", ['status' => 'in_progress'])->assertOk();

        $this->signedIn($developer)->getJson('/api/admin/support-tickets')->assertForbidden();
        $this->signedIn($developer)->patchJson("/api/admin/support-tickets/{$id}/assign", ['assigned_to' => $developer->id])->assertForbidden();
    }

    public function test_downloads_serve_only_files_recorded_on_the_ticket(): void
    {
        Storage::fake(config('filesystems.default'));
        $owner = $this->userWithRole('owner');
        $developer = $this->userWithRole('developer');

        $id = $this->signedIn($developer)->post('/api/support-tickets', [
            'subject' => 'Broken build', 'description' => 'See the screenshot.', 'category' => 'deployment', 'priority' => 'high',
            'attachments' => [UploadedFile::fake()->create('screenshot.png', 4, 'image/png')],
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $path = SupportTicket::find($id)->attachments[0];
        $this->assertStringStartsWith("support-attachments/{$id}/", $path, 'single-tenant installs keep the ticket directory');
        $file = basename($path);

        // Staff note with a file of its own
        $this->signedIn($owner)->post("/api/admin/support-tickets/{$id}/replies", [
            'message' => 'Looks like the cache', 'is_internal' => 1,
            'attachments' => [UploadedFile::fake()->create('trace.pdf', 2, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated();
        $note = basename(SupportTicketReply::query()->where('is_internal', true)->first()->attachments[0]);
        // On the disk next to them, recorded nowhere
        Storage::put("support-attachments/{$id}/planted.png", 'x');

        $this->signedIn($developer)->get("/api/support-tickets/{$id}/attachments/{$file}")->assertOk();
        $this->signedIn($developer)->getJson("/api/support-tickets/{$id}/attachments/planted.png")->assertNotFound();
        $this->signedIn($developer)->getJson("/api/support-tickets/{$id}/attachments/{$note}")->assertNotFound();
        $this->signedIn($owner)->get("/api/admin/support-tickets/{$id}/attachments/{$note}")->assertOk();
        $this->signedIn($owner)->get("/api/admin/support-tickets/{$id}/attachments/{$file}")->assertOk();
        $this->signedIn($owner)->getJson("/api/admin/support-tickets/{$id}/attachments/planted.png")->assertNotFound();
    }
}
