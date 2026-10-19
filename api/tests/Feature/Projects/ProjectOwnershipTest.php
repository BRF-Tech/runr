<?php

namespace Tests\Feature\Projects;

use App\Events\ProjectOwnerChanged;
use App\Models\AuditEvent;
use App\Models\Notification;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * The owner of a project — the payer of its bills (docs/billing.md): the creator unless the
 * ownership is transferred (PUT /api/projects/{project}/owner) by the owner or someone who
 * manages the project's access, to a member who can see the project.
 */
class ProjectOwnershipTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private User $admin;

    private User $developer;

    private User $viewer;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([ProjectOwnerChanged::class]);

        $this->userWithRole('owner');
        $this->admin = $this->userWithRole('admin');
        $this->developer = $this->userWithRole('developer');
        $this->viewer = $this->userWithRole('viewer');

        $this->project = $this->caasProject('orders');
        $this->project->forceFill(['created_by' => (string) $this->developer->id, 'owner_id' => (string) $this->developer->id])->save();
    }

    public function test_the_owner_hands_the_project_over_to_a_member_who_sees_it(): void
    {
        $this->signedIn($this->developer)->putJson("/api/projects/{$this->project->id}/owner", ['user_id' => $this->viewer->id])
            ->assertOk()
            ->assertJsonPath('data.owner_id', (string) $this->viewer->id)
            ->assertJsonPath('data.owner.username', $this->viewer->username)
            ->assertJsonPath('previous_owner_id', (string) $this->developer->id);

        $this->assertSame((string) $this->viewer->id, $this->project->fresh()->payerId());
        Event::assertDispatched(ProjectOwnerChanged::class, fn ($e) => $e->previousOwnerId === (string) $this->developer->id
            && $e->ownerId === (string) $this->viewer->id
            && $e->actorId === (string) $this->developer->id);

        $audit = AuditEvent::query()->where('action', 'project.owner.transfer')->sole();
        $this->assertSame((string) $this->viewer->id, $audit->metadata['owner_id']);
        $this->assertSame((string) $this->developer->id, $audit->metadata['previous_owner_id']);
    }

    public function test_only_the_owner_or_an_access_manager_transfers(): void
    {
        // The viewer sees the project but neither owns it nor manages its access
        $this->signedIn($this->viewer)->putJson("/api/projects/{$this->project->id}/owner", ['user_id' => $this->viewer->id])
            ->assertForbidden();

        // An admin (projects.manage_access) transfers any project
        $this->signedIn($this->admin)->putJson("/api/projects/{$this->project->id}/owner", ['user_id' => $this->admin->id])
            ->assertOk()->assertJsonPath('data.owner_id', (string) $this->admin->id);
    }

    public function test_the_new_owner_must_exist_and_see_the_project(): void
    {
        // A member whose role does not see projects (billing: bills, not projects)
        $outsider = $this->userWithRole('billing');

        $this->signedIn($this->developer)->putJson("/api/projects/{$this->project->id}/owner", [])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->signedIn($this->developer)->putJson("/api/projects/{$this->project->id}/owner", ['user_id' => 'no-such-user'])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->signedIn($this->developer)->putJson("/api/projects/{$this->project->id}/owner", ['user_id' => $outsider->id])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');

        $this->assertSame((string) $this->developer->id, $this->project->fresh()->owner_id);
        Event::assertNotDispatched(ProjectOwnerChanged::class);
    }

    public function test_a_platform_administrator_is_owner_only_as_a_member_who_sees_the_project(): void
    {
        // Platform administrators hold every permission — that does not make them a payer
        $platform = $this->platformAdmin();
        RoleAssignment::query()->where('subject_id', (string) $platform->id)->whereNull('project_id')->delete();

        $this->signedIn($this->developer)->putJson("/api/projects/{$this->project->id}/owner", ['user_id' => $platform->id])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->assertSame((string) $this->developer->id, $this->project->fresh()->owner_id);

        // With project access of their own (a member who sees the project) they can be
        $this->grantProject($platform, $this->project, 'viewer');
        $this->signedIn($this->developer)->putJson("/api/projects/{$this->project->id}/owner", ['user_id' => $platform->id])->assertOk();
    }

    public function test_the_new_owner_is_told_that_the_bills_are_theirs(): void
    {
        $this->signedIn($this->developer)->putJson("/api/projects/{$this->project->id}/owner", ['user_id' => $this->viewer->id])->assertOk();

        $notice = Notification::query()->where('user_id', (string) $this->viewer->id)->sole();
        $this->assertSame('project.owner_assigned', $notice->type);
        $this->assertStringContainsString('orders', $notice->title);
        $this->assertStringContainsString('current month', (string) $notice->body);
    }

    public function test_handing_the_project_to_its_owner_changes_nothing(): void
    {
        $this->signedIn($this->developer)->putJson("/api/projects/{$this->project->id}/owner", ['user_id' => $this->developer->id])
            ->assertOk()->assertJsonPath('data.owner_id', (string) $this->developer->id);

        Event::assertNotDispatched(ProjectOwnerChanged::class);
    }

    public function test_the_payer_is_the_owner_else_the_creator_never_the_system(): void
    {
        $project = new Project(['owner_id' => null, 'created_by' => 'u-1']);
        $this->assertSame('u-1', $project->payerId());

        $project->owner_id = 'u-2';
        $this->assertSame('u-2', $project->payerId());

        $system = new Project(['created_by' => 'system']);
        $this->assertNull($system->payerId());
    }

    public function test_the_project_shows_its_owner(): void
    {
        $this->signedIn($this->viewer)->getJson("/api/projects/{$this->project->id}")
            ->assertOk()
            ->assertJsonPath('data.owner_id', (string) $this->developer->id)
            ->assertJsonPath('data.owner.name', $this->developer->name)
            ->assertJsonPath('data.stopped', null);
    }
}
