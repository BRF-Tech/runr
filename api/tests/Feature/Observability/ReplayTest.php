<?php

namespace Tests\Feature\Observability;

use App\Models\Observability\ProjectObservabilitySetting;
use App\Models\Observability\ReplayCapture;
use App\Models\Project;
use App\Services\Observability\Replay\ReplayService;
use App\Services\Observability\ReplayTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\TestCase;

/**
 * Request replay: switching capture on (new token, CaaS refused), the ingest endpoint (token,
 * disabled project, redaction and body limit re-applied, retention, capture limit), listing and
 * deleting captures, and the replay itself (headers forwarded without credentials / hop-by-hop,
 * override headers never stored, X-RunR-Replay marker, response diff, queue projects refused).
 */
class ReplayTest extends TestCase
{
    use ActsWithRoles, FakesObservabilityCluster, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeObservabilityCluster();
        config(['observability.replay.ingest_url' => 'https://console.runr.test']);
    }

    private function enable(Project $project, array $extra = []): string
    {
        $owner = $this->userWithRole('owner');
        $this->signedIn($owner)->putJson("/api/projects/{$project->id}/observability/replay", ['enabled' => true] + $extra)->assertOk();

        return app(ReplayTokens::class)->token('_.'.$project->id, ProjectObservabilitySetting::forProject($project));
    }

    private function capturePayload(array $overrides = []): array
    {
        return array_replace([
            'captured_at' => now()->subMinute()->toIso8601String(),
            'trigger' => 'http',
            'request_id' => 'req-1',
            'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
            'revision' => 'orders-00003',
            'method' => 'POST',
            'path' => '/orders',
            'query' => 'page=2&token=leaked',
            'headers' => ['content-type' => 'application/json', 'authorization' => 'Bearer leaked', 'x-signature' => 'sig', 'connection' => 'keep-alive'],
            'redacted_headers' => ['cookie'],
            'body' => '{"item":42,"note":"'.str_repeat('x', 100).'"}',
            'body_base64' => false,
            'body_size' => 120,
            'status' => 500,
            'timed_out' => false,
            'duration_ms' => 12.5,
            'response_headers' => ['content-type' => 'application/json', 'set-cookie' => 'sid=1'],
            'response_body' => '{"error":"database unreachable"}',
        ], $overrides);
    }

    public function test_switching_capture_on(): void
    {
        $owner = $this->userWithRole('owner');
        $dev = $this->userWithRole('developer');
        $viewer = $this->userWithRole('viewer');
        $project = $this->obsProject($this->obsRegion());

        $this->signedIn($viewer)->putJson("/api/projects/{$project->id}/observability/replay", ['enabled' => true])
            ->assertForbidden()->assertJsonPath('permissions', ['replay.manage']);

        $this->signedIn($dev)->putJson("/api/projects/{$project->id}/observability/replay", [
            'enabled' => true, 'max_body_bytes' => 2048, 'retention_days' => 3, 'redact_headers' => ['X-Signature', ' x-signature '], 'redact_query' => ['Session'],
        ])->assertOk()
            ->assertJsonPath('data.replay_enabled', true)
            ->assertJsonPath('data.replay_max_body_bytes', 2048)
            ->assertJsonPath('data.replay_redact_headers', ['x-signature'])
            ->assertJsonPath('data.replay_redact_query', ['session'])
            ->assertJsonPath('data.replay_available', true)
            ->assertJsonMissingPath('data.replay_salt');

        $salt = ProjectObservabilitySetting::forProject($project)->replay_salt;
        $this->assertNotEmpty($salt);

        // off and on again: a new token
        $this->signedIn($owner)->putJson("/api/projects/{$project->id}/observability/replay", ['enabled' => false])->assertOk();
        $this->signedIn($owner)->putJson("/api/projects/{$project->id}/observability/replay", ['enabled' => true])->assertOk();
        $this->assertNotSame($salt, ProjectObservabilitySetting::forProject($project)->replay_salt);

        $this->signedIn($owner)->putJson("/api/projects/{$project->id}/observability/replay", ['enabled' => true, 'max_body_bytes' => 99999999])->assertUnprocessable();

        $caas = $this->caasProject('web');
        $this->signedIn($owner)->putJson("/api/projects/{$caas->id}/observability/replay", ['enabled' => true])->assertUnprocessable();
    }

    public function test_ingest_stores_a_redacted_capture(): void
    {
        $project = $this->obsProject($this->obsRegion());
        $token = $this->enable($project, ['max_body_bytes' => 64, 'retention_days' => 3, 'redact_headers' => ['x-signature']]);

        $this->postJson('/api/internal/observability/replay/_.'.$project->id, $this->capturePayload(), ['Authorization' => 'Bearer '.$token])
            ->assertStatus(202);

        $capture = ReplayCapture::query()->sole();
        $this->assertSame($project->id, $capture->project_id);
        $this->assertSame('page=2&token=REDACTED', $capture->query);
        $this->assertSame(['content-type' => 'application/json'], $capture->headers);
        $this->assertSame(['authorization', 'connection', 'cookie', 'x-signature'], $capture->redacted_headers);
        $this->assertSame(64, strlen($capture->body));
        $this->assertTrue($capture->body_truncated);
        $this->assertSame(120, $capture->body_size);
        $this->assertSame(['content-type' => 'application/json'], $capture->response_headers);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $capture->trace_id);
        $this->assertSame(3, (int) round(now()->diffInDays($capture->expires_at)));

        // bodies and headers are encrypted at rest
        $raw = \DB::table('replay_captures')->first();
        $this->assertStringNotContainsString('database unreachable', (string) $raw->response_body);
        $this->assertStringNotContainsString('application/json', (string) $raw->headers);
    }

    public function test_ingest_refuses_wrong_tokens_and_disabled_projects(): void
    {
        $project = $this->obsProject($this->obsRegion());
        $url = '/api/internal/observability/replay/_.'.$project->id;

        // capture off
        $this->postJson($url, $this->capturePayload(), ['Authorization' => 'Bearer whatever'])->assertUnauthorized();

        $token = $this->enable($project);
        $this->postJson($url, $this->capturePayload(), ['Authorization' => 'Bearer '.strrev($token)])->assertUnauthorized();
        $this->postJson($url, $this->capturePayload())->assertUnauthorized();
        $this->postJson('/api/internal/observability/replay/garbage', $this->capturePayload(), ['Authorization' => 'Bearer '.$token])->assertNotFound();
        $this->postJson('/api/internal/observability/replay/_.01JNOPROJECT00000000000000', $this->capturePayload(), ['Authorization' => 'Bearer '.$token])->assertUnauthorized();

        // switched off again: the old token stops working
        ProjectObservabilitySetting::forProject($project)->forceFill(['replay_enabled' => false])->save();
        $this->postJson($url, $this->capturePayload(), ['Authorization' => 'Bearer '.$token])->assertUnauthorized();

        $this->assertSame(0, ReplayCapture::query()->count());
    }

    public function test_capture_limit_drops_the_oldest(): void
    {
        config(['observability.replay.max_captures' => 2]);
        $project = $this->obsProject($this->obsRegion());
        $token = $this->enable($project);

        foreach ([3, 2, 1] as $minutes) {
            $this->postJson('/api/internal/observability/replay/_.'.$project->id, $this->capturePayload([
                'captured_at' => now()->subMinutes($minutes)->toIso8601String(), 'request_id' => "req-{$minutes}",
            ]), ['Authorization' => 'Bearer '.$token])->assertStatus(202);
        }

        $this->assertEqualsCanonicalizing(['req-2', 'req-1'], ReplayCapture::query()->pluck('request_id')->all());
    }

    public function test_list_show_and_delete(): void
    {
        $owner = $this->userWithRole('owner');
        $viewer = $this->userWithRole('viewer');
        $project = $this->obsProject($this->obsRegion());
        $settings = ProjectObservabilitySetting::forProject($project);
        $capture = app(ReplayService::class)->store($project, $settings, $this->capturePayload());

        $this->signedIn($viewer)->getJson("/api/projects/{$project->id}/replays")->assertForbidden();

        $this->signedIn($owner)->getJson("/api/projects/{$project->id}/replays?status=500")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $capture->id)
            ->assertJsonMissingPath('data.0.body');
        $this->signedIn($owner)->getJson("/api/projects/{$project->id}/replays?status=502")->assertJsonPath('meta.total', 0);

        $this->signedIn($owner)->getJson("/api/projects/{$project->id}/replays/{$capture->id}")
            ->assertOk()
            ->assertJsonPath('data.response_body', '{"error":"database unreachable"}')
            ->assertJsonPath('data.redacted_headers', ['authorization', 'connection', 'cookie']);

        $other = $this->obsProject($this->obsRegion(), 'billing');
        $this->signedIn($owner)->getJson("/api/projects/{$other->id}/replays/{$capture->id}")->assertNotFound();

        $this->signedIn($owner)->deleteJson("/api/projects/{$project->id}/replays/{$capture->id}")->assertNoContent();
        $this->assertSame(0, ReplayCapture::query()->count());
    }

    public function test_replay_resends_to_the_current_revision_and_diffs_the_response(): void
    {
        $owner = $this->userWithRole('owner');
        $region = $this->obsRegion(['knative_settings' => ['knative_domain' => 'eu.runr.test']]);
        $project = $this->obsProject($region);
        $capture = app(ReplayService::class)->store($project, ProjectObservabilitySetting::forProject($project), $this->capturePayload([
            'headers' => ['content-type' => 'application/json', 'x-tenant' => 'acme', 'traceparent' => '00-aaa', 'x-forwarded-for' => '1.2.3.4'],
        ]));

        $sent = null;
        $this->externalRoutes['https://orders.eu.runr.test/'] = function (Request $request) use (&$sent) {
            $sent = $request;

            return Http::response(['id' => 1, 'ok' => true], 201, ['X-Version' => '4']);
        };

        $response = $this->signedIn($owner)->postJson("/api/projects/{$project->id}/replays/{$capture->id}/replay", [
            'headers' => ['Authorization' => 'Bearer fresh-token'],
        ])->assertOk();

        $this->assertSame('POST', $sent->method());
        $this->assertSame('https://orders.eu.runr.test/orders?page=2&token=REDACTED', $sent->url());
        $this->assertSame($capture->id, $sent->header('X-RunR-Replay')[0]);
        $this->assertSame('acme', $sent->header('x-tenant')[0]);
        $this->assertSame('Bearer fresh-token', $sent->header('Authorization')[0]);
        $this->assertSame([], $sent->header('traceparent'));
        $this->assertSame([], $sent->header('x-forwarded-for'));
        $this->assertStringStartsWith('{"item":42', $sent->body());

        $response->assertJsonPath('data.original.status', 500)
            ->assertJsonPath('data.replay.status', 201)
            ->assertJsonPath('data.diff.status.changed', true)
            ->assertJsonPath('data.diff.changed', true)
            ->assertJsonPath('data.diff.headers.added.x-version', '4');

        $fresh = $capture->fresh();
        $this->assertSame(1, $fresh->replay_count);
        $this->assertSame(201, $fresh->last_replay['status']);
        $this->assertStringNotContainsString('fresh-token', json_encode($fresh->last_replay).json_encode($fresh->headers), 'override headers are never stored');
    }

    public function test_replay_of_a_queue_project_is_refused(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->obsProject($this->obsRegion(), 'worker', 'queue');
        $capture = app(ReplayService::class)->store($project, ProjectObservabilitySetting::forProject($project), $this->capturePayload(['trigger' => 'queue']));

        $this->signedIn($owner)->postJson("/api/projects/{$project->id}/replays/{$capture->id}/replay")->assertStatus(409);
        $this->signedIn($owner)->postJson("/api/projects/{$project->id}/replays/{$capture->id}/replay", ['headers' => ['bad header' => 'x']])->assertUnprocessable();
    }

    public function test_expired_captures_are_pruned(): void
    {
        $project = $this->obsProject($this->obsRegion());
        $service = app(ReplayService::class);
        $old = $service->store($project, ProjectObservabilitySetting::forProject($project), $this->capturePayload());
        $old->forceFill(['expires_at' => now()->subMinute()])->save();
        $service->store($project, ProjectObservabilitySetting::forProject($project), $this->capturePayload());

        $this->assertSame(1, $service->prune());
        $this->assertSame(1, ReplayCapture::query()->count());
    }

    public function test_deleting_the_project_removes_its_captures(): void
    {
        $project = $this->obsProject($this->obsRegion());
        app(ReplayService::class)->store($project, ProjectObservabilitySetting::forProject($project), $this->capturePayload());
        ProjectObservabilitySetting::forProject($project)->fill(['replay_enabled' => true])->save();

        $project->delete();

        $this->assertSame(0, ReplayCapture::query()->count());
        $this->assertSame(0, ProjectObservabilitySetting::query()->count());
    }
}
