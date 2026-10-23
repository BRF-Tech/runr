<?php

namespace Tests\Feature\Projects;

use App\Models\ContainerConfig;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Models\User;
use App\Models\Version;
use App\Models\XaasConfig;
use App\Services\GitService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsTriggerFixtures;
use Tests\TestCase;

/**
 * A request on a project that another request deletes while it runs (an e2e cleanup deleting the
 * project while the UI's save is on its way): the project's config row, triggers … go with it.
 * The request answers 404 as for an unknown project — the paths that load a row and write it
 * check for it (ProjectService::updateConfigRow(), the controllers' fresh()), the exception
 * mapping (DeletedProject) turns what fails elsewhere into the same 404 — never a server error,
 * and nothing is reported.
 */
class DeletedProjectRequestTest extends TestCase
{
    use ActsWithRoles, BuildsTriggerFixtures, RefreshDatabase;

    private User $owner;

    /** @var list<string> levels of the log entries written during the test */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->owner = $this->userWithRole('owner');
        // the version a config change creates is committed without a repository
        $this->mock(GitService::class, fn ($mock) => $mock->shouldReceive('writeFiles')->andReturn('abc123'));
        Event::listen(MessageLogged::class, fn (MessageLogged $entry) => $this->logged[] = $entry->level);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function project(string $type): Project
    {
        $region = KnativeRegion::query()->firstOrCreate(['name' => 'race'], ['project_namespace' => 'race', 'is_active' => true, 'is_demo' => true]);
        $project = Project::query()->create(['name' => "race-{$type}", 'service_type' => $type, 'runtime' => $type === 'caas' ? null : 'python']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();

        $sizing = ['project_id' => $project->id, 'cpu' => '250m', 'memory_mb' => 128, 'timeout_seconds' => 30, 'trigger_type' => 'http'];
        match ($type) {
            'caas' => ContainerConfig::query()->create($sizing + ['image' => 'nginx:1.27-alpine']),
            'xaas' => XaasConfig::query()->create($sizing + ['filename' => 'main.py', 'handler' => 'handler']),
            'faas' => FaasConfig::query()->create($sizing + ['handler' => 'main.handler']),
        };

        return $project->fresh();
    }

    /**
     * Another request deletes the project when $event of $model fires — once, without the
     * project's model events, its rows going with it as the foreign keys cascade.
     *
     * @param  class-string<Model>  $model
     */
    private function deleteWhen(string $model, string $event, Project $project): void
    {
        $done = false;
        Event::listen("eloquent.{$event}: {$model}", function () use (&$done, $project) {
            if (! $done) {
                $done = true;
                Project::query()->whereKey($project->id)->delete();
            }
        });
    }

    private function assertGone(TestResponse $response, Project $project): void
    {
        $response->assertNotFound();
        $this->assertStringContainsString(Project::class, (string) $response->json('message'));
        $this->assertFalse(Project::query()->whereKey($project->id)->exists());
        $this->assertSame(0, Version::query()->where('project_id', $project->id)->count(), 'no version of a deleted project');
        $this->assertNotContains('error', $this->logged, 'a request on a deleted project is no error to report');
    }

    /** @return array<string, array{string, class-string<Model>}> */
    public static function configTypes(): array
    {
        return [
            'xaas' => ['xaas', XaasConfig::class],
            'faas' => ['faas', FaasConfig::class],
            'caas' => ['caas', ContainerConfig::class],
        ];
    }

    // ── The project update (PUT /projects/{project}) ──────────────────────────

    #[DataProvider('configTypes')]
    public function test_a_config_change_of_a_project_deleted_before_its_config_row_loads_answers_404(string $type, string $configModel): void
    {
        $project = $this->project($type);
        // the description is written first: the project goes right after it, before the config row loads
        $this->deleteWhen(Project::class, 'updated', $project);

        $response = $this->actingAs($this->owner, 'sanctum')->putJson("/api/projects/{$project->id}", [
            'description' => 'raced',
            'config' => ['max_scale' => 3],
        ]);

        $this->assertGone($response, $project);
        $this->assertSame(0, $configModel::query()->where('project_id', $project->id)->count());
    }

    #[DataProvider('configTypes')]
    public function test_a_config_change_of_a_project_deleted_between_the_load_and_the_write_answers_404(string $type, string $configModel): void
    {
        $project = $this->project($type);
        // the config row is loaded: the project goes right before its UPDATE (which then changes nothing)
        $this->deleteWhen($configModel, 'updating', $project);

        $response = $this->actingAs($this->owner, 'sanctum')->putJson("/api/projects/{$project->id}", [
            'config' => ['max_scale' => 3],
        ]);

        $this->assertGone($response, $project);
    }

    public function test_a_config_change_of_a_project_that_stays_creates_its_version(): void
    {
        $project = $this->project('xaas');

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/projects/{$project->id}", ['config' => ['max_scale' => 3]])
            ->assertOk()
            ->assertJsonPath('data.config.max_scale', 3);
        $this->assertSame(1, Version::query()->where('project_id', $project->id)->count());
    }

    // ── Other project changes that load a row and write it ───────────────────────

    public function test_a_deploy_strategy_change_of_a_project_deleted_while_it_is_written_answers_404(): void
    {
        $project = $this->project('caas');
        $this->deleteWhen(ContainerConfig::class, 'updating', $project);

        $response = $this->actingAs($this->owner, 'sanctum')->putJson("/api/projects/{$project->id}/deploy-strategy", ['strategy' => 'canary']);

        $this->assertGone($response, $project);
    }

    public function test_a_network_change_of_a_project_deleted_while_it_is_written_answers_404(): void
    {
        $project = $this->project('caas');
        $this->deleteWhen(Project::class, 'updating', $project);

        $response = $this->actingAs($this->owner, 'sanctum')->putJson("/api/projects/{$project->id}/network", ['access_mode' => 'internal']);

        $this->assertGone($response, $project);
    }

    public function test_a_trigger_added_to_a_project_deleted_while_it_is_saved_answers_404(): void
    {
        $project = $this->triggerProject($this->triggerRegion());
        // the project's triggers are read: the project goes before the new one is inserted, whose
        // foreign key then fails (the exception mapping's case)
        $this->deleteWhen(ProjectTrigger::class, 'retrieved', $project);

        $response = $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$project->id}/triggers", [
            'type' => 'schedule', 'name' => 'nightly', 'config' => ['cron' => '0 3 * * *'],
        ]);

        $this->assertGone($response, $project);
        $this->assertSame(0, ProjectTrigger::query()->where('project_id', $project->id)->count());
    }

    // ── The exception mapping (DeletedProject) ──────────────────────────────────

    public function test_a_server_error_of_a_request_whose_project_was_deleted_answers_404_and_is_not_reported(): void
    {
        $project = $this->project('caas');
        Route::middleware(SubstituteBindings::class)->post('/test/deleted-project/{project}', function (Project $project) {
            Project::query()->whereKey($project->id)->delete();

            throw new RuntimeException('a row of the project is gone');
        });

        $this->assertGone($this->postJson("/test/deleted-project/{$project->id}"), $project);
    }

    public function test_a_controller_that_loads_the_project_by_its_id_answers_404_too(): void
    {
        $project = $this->project('caas');
        Route::post('/test/deleted-project-id/{project}', function (string $project) {
            Project::query()->whereKey($project)->delete();

            throw new RuntimeException('a row of the project is gone');
        });

        $this->assertGone($this->postJson("/test/deleted-project-id/{$project->id}"), $project);
    }

    public function test_a_server_error_of_a_project_that_exists_stays_a_reported_server_error(): void
    {
        $project = $this->project('caas');
        Route::middleware(SubstituteBindings::class)->post('/test/broken-project/{project}', function (Project $project) {
            throw new RuntimeException('broken');
        });

        $this->postJson("/test/broken-project/{$project->id}")->assertStatus(500);
        $this->assertContains('error', $this->logged);
    }

    public function test_an_answer_of_its_own_stays_when_the_project_was_deleted(): void
    {
        $project = $this->project('caas');
        Route::middleware(SubstituteBindings::class)->post('/test/refused-project/{project}', function (Project $project) {
            Project::query()->whereKey($project->id)->delete();

            throw ValidationException::withMessages(['name' => 'refused']);
        });

        $this->postJson("/test/refused-project/{$project->id}")->assertUnprocessable()->assertJsonValidationErrors('name');
    }
}
