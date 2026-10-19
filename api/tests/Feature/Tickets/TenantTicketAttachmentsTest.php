<?php

namespace Tests\Feature\Tickets;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Tenancy\Http\Controllers\TenancyAdminSupportTicketController;
use Modules\Tenancy\Http\Controllers\TenancySupportTicketController;
use Modules\Tenancy\Models\Tenant;
use Modules\Ticket\Models\SupportTicket;
use Modules\Ticket\Models\SupportTicketReply;
use Modules\Ticket\Support\TicketAttachments;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Ticket ids are per tenant schema: every tenant has a ticket 1. Attachments of a tenant's
 * tickets live in support-attachments/<tenant id>/<ticket id>/, a download serves only a file
 * recorded on that ticket of the requesting tenant, and the files earlier releases stored in the
 * shared support-attachments/<ticket id>/ move into each tenant's own directory (only the files
 * recorded on its rows).
 */
class TenantTicketAttachmentsTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    /** The support tables of a tenant schema (with the Ticket module's tenant migration) */
    private const SUPPORT_MIGRATIONS = [
        'Modules/Tenancy/Database/TenantMigrations/0003_create_users_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0012_create_support_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0017_add_admin_fields_to_support_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0019_drop_user_id_fk_from_support_tables.php',
        'Modules/Ticket/Database/TenantMigrations/0021_add_read_tracking_to_support_tickets.php',
    ];

    private Tenant $acme;

    private Tenant $globex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        Storage::fake(config('filesystems.default'));

        $this->acme = $this->createTenantWithSchema('acme', self::SUPPORT_MIGRATIONS);
        $this->globex = $this->createTenantWithSchema('globex', self::SUPPORT_MIGRATIONS);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();

        parent::tearDown();
    }

    /** A ticket (id 1 in a fresh schema) with the given recorded attachments, files written. */
    private function ticket(Tenant $tenant, array $paths, bool $writeFiles = true): SupportTicket
    {
        return $tenant->run(function () use ($paths, $writeFiles) {
            foreach ($writeFiles ? $paths : [] as $path) {
                Storage::put($path, 'content of '.basename($path));
            }

            return SupportTicket::create([
                'user_id' => 1, 'subject' => 'Help', 'description' => 'Something broke',
                'category' => 'other', 'priority' => 'low', 'attachments' => $paths ?: null,
            ]);
        });
    }

    private function reply(Tenant $tenant, SupportTicket $ticket, array $paths, bool $internal = false): void
    {
        $tenant->run(function () use ($ticket, $paths, $internal) {
            foreach ($paths as $path) {
                Storage::put($path, 'reply file');
            }
            SupportTicketReply::create([
                'support_ticket_id' => $ticket->id, 'user_id' => 1, 'message' => 'more',
                'is_staff' => $internal, 'is_internal' => $internal, 'attachments' => $paths,
            ]);
        });
    }

    /** HTTP status of a member's (or $admin: staff) download inside the tenant. */
    private function download(Tenant $tenant, int $ticketId, string $filename, bool $admin = false): int
    {
        return $tenant->run(function () use ($ticketId, $filename, $admin) {
            try {
                $response = $admin
                    ? app(TenancyAdminSupportTicketController::class)->downloadAttachment(Request::create('/'), $ticketId, $filename)
                    : app(TenancySupportTicketController::class)->downloadAttachment(Request::create('/'), SupportTicket::findOrFail($ticketId), $filename);

                return $response->getStatusCode();
            } catch (HttpException $e) {
                return $e->getStatusCode();
            }
        });
    }

    public function test_new_attachments_are_stored_in_the_tenants_directory(): void
    {
        $ticket = $this->ticket($this->acme, []);
        $request = Request::create('/', 'POST', [], [], ['attachments' => [
            UploadedFile::fake()->create('screenshot.png', 4, 'image/png'),
            UploadedFile::fake()->create('script.sh', 1, 'text/x-shellscript'),
        ]]);

        $paths = $this->acme->run(fn () => TicketAttachments::store($request, $ticket->id));

        $this->assertCount(1, $paths, 'only the allowed types are stored');
        $this->assertStringStartsWith("support-attachments/{$this->acme->id}/{$ticket->id}/", $paths[0]);
        Storage::assertExists($paths[0]);
        $this->assertSame("support-attachments/{$ticket->id}", TicketAttachments::directory($ticket->id), 'outside a tenant: the single-tenant directory');
    }

    public function test_a_tenant_downloads_only_files_recorded_on_its_own_ticket(): void
    {
        $acmeTicket = $this->ticket($this->acme, ["support-attachments/{$this->acme->id}/1/acme.png"]);
        $globexTicket = $this->ticket($this->globex, ["support-attachments/{$this->globex->id}/1/globex.png"]);
        $this->assertSame($acmeTicket->id, $globexTicket->id, 'both tenants have a ticket with the same id');

        $this->assertSame(200, $this->download($this->acme, $acmeTicket->id, 'acme.png'));
        $this->assertSame(404, $this->download($this->acme, $acmeTicket->id, 'globex.png'));
        $this->assertSame(404, $this->download($this->acme, $acmeTicket->id, '../../'.$this->globex->id.'/1/globex.png'));
        $this->assertSame(200, $this->download($this->globex, $globexTicket->id, 'globex.png'));
    }

    public function test_files_in_the_shared_directory_of_earlier_releases_stay_apart(): void
    {
        // Before tenant directories both tenants' ticket 1 stored into support-attachments/1/
        $acmeTicket = $this->ticket($this->acme, ['support-attachments/1/acme.png']);
        $this->ticket($this->globex, ['support-attachments/1/globex.png']);

        $this->assertSame(200, $this->download($this->acme, $acmeTicket->id, 'acme.png'));
        $this->assertSame(404, $this->download($this->acme, $acmeTicket->id, 'globex.png'), 'on the disk next to it, but not recorded on this ticket');
    }

    public function test_files_of_internal_notes_are_for_support_staff_only(): void
    {
        $ticket = $this->ticket($this->acme, []);
        $this->reply($this->acme, $ticket, ["support-attachments/{$this->acme->id}/1/public.pdf"]);
        $this->reply($this->acme, $ticket, ["support-attachments/{$this->acme->id}/1/internal.pdf"], internal: true);

        $this->assertSame(200, $this->download($this->acme, $ticket->id, 'public.pdf'));
        $this->assertSame(404, $this->download($this->acme, $ticket->id, 'internal.pdf'));
        $this->assertSame(200, $this->download($this->acme, $ticket->id, 'internal.pdf', admin: true));
        $this->assertSame(200, $this->download($this->acme, $ticket->id, 'public.pdf', admin: true));
    }

    public function test_existing_files_move_into_the_tenants_directory_and_only_its_own(): void
    {
        $acmeTicket = $this->ticket($this->acme, ['support-attachments/1/acme.png']);
        $this->reply($this->acme, $acmeTicket, ['support-attachments/1/reply.pdf']);
        $this->ticket($this->globex, ['support-attachments/1/globex.png']);
        // A second acme ticket whose file is gone: its recorded path is kept
        $gone = $this->acme->run(fn () => SupportTicket::create([
            'user_id' => 1, 'subject' => 'Old', 'description' => 'x', 'category' => 'other', 'priority' => 'low',
            'attachments' => ['support-attachments/2/gone.png'],
        ]));

        $result = $this->acme->run(fn () => TicketAttachments::namespaceExisting());

        $this->assertSame(['moved' => 2, 'missing' => 1, 'rows' => 2], $result);
        $dir = "support-attachments/{$this->acme->id}/1";
        Storage::assertExists(["{$dir}/acme.png", "{$dir}/reply.pdf"]);
        Storage::assertMissing(['support-attachments/1/acme.png', 'support-attachments/1/reply.pdf']);
        Storage::assertExists('support-attachments/1/globex.png');
        $this->acme->run(function () use ($acmeTicket, $gone, $dir) {
            $this->assertSame(["{$dir}/acme.png"], SupportTicket::find($acmeTicket->id)->attachments);
            $this->assertSame(["{$dir}/reply.pdf"], SupportTicketReply::query()->first()->attachments);
            $this->assertSame(['support-attachments/2/gone.png'], SupportTicket::find($gone->id)->attachments);
        });
        $this->assertSame(200, $this->download($this->acme, $acmeTicket->id, 'acme.png'));

        // Idempotent; the other tenant's run moves its own file
        $this->assertSame(['moved' => 0, 'missing' => 1, 'rows' => 0], $this->acme->run(fn () => TicketAttachments::namespaceExisting()));
        $this->assertSame(['moved' => 1, 'missing' => 0, 'rows' => 1], $this->globex->run(fn () => TicketAttachments::namespaceExisting()));
        Storage::assertExists("support-attachments/{$this->globex->id}/1/globex.png");
        $this->assertSame(['moved' => 0, 'missing' => 0, 'rows' => 0], TicketAttachments::namespaceExisting(), 'outside a tenant nothing moves');
    }

    public function test_a_move_interrupted_before_its_row_was_updated_is_completed(): void
    {
        $ticket = $this->ticket($this->acme, ['support-attachments/1/acme.png']);
        // The file reached the tenant directory, the row still names the old path
        Storage::move('support-attachments/1/acme.png', "support-attachments/{$this->acme->id}/1/acme.png");

        $this->assertSame(200, $this->download($this->acme, $ticket->id, 'acme.png'), 'found in the tenant directory meanwhile');
        $this->assertSame(['moved' => 1, 'missing' => 0, 'rows' => 1], $this->acme->run(fn () => TicketAttachments::namespaceExisting()));
    }

    public function test_the_ticket_tenant_migration_moves_the_tenants_files(): void
    {
        $ticket = $this->ticket($this->acme, ['support-attachments/1/acme.png']);

        $this->acme->run(fn () => (require base_path('Modules/Ticket/Database/TenantMigrations/0056_namespace_support_attachments.php'))->up());

        Storage::assertExists("support-attachments/{$this->acme->id}/1/acme.png");
        $this->assertSame(["support-attachments/{$this->acme->id}/1/acme.png"], $this->acme->run(fn () => SupportTicket::find($ticket->id)->attachments));
    }

    public function test_a_deleted_tenants_files_go_and_no_others(): void
    {
        Storage::put("support-attachments/{$this->acme->id}/1/acme.png", 'a');
        Storage::put("support-attachments/{$this->globex->id}/1/globex.png", 'g');
        Storage::put('support-attachments/1/single.png', 's');

        TicketAttachments::deleteTenantFiles($this->acme->id);
        TicketAttachments::deleteTenantFiles('../support-attachments');

        Storage::assertMissing("support-attachments/{$this->acme->id}/1/acme.png");
        Storage::assertExists(["support-attachments/{$this->globex->id}/1/globex.png", 'support-attachments/1/single.png']);
    }

    public function test_the_platform_administrators_are_the_assignee_choices(): void
    {
        $admin = User::factory()->create(['username' => 'root']);
        $admin->forceFill(['is_admin' => true])->save();
        User::factory()->create(['username' => 'member']);

        $staff = app(TenancyAdminSupportTicketController::class)->staff()->getData(true)['data'];

        $this->assertSame([$admin->id], array_column($staff, 'id'));
    }
}
