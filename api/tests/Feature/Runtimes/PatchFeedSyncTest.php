<?php

namespace Tests\Feature\Runtimes;

use App\Jobs\AnnounceRuntimePatchesJob;
use App\Models\ExecutorImage;
use App\Models\Notification;
use App\Models\PlatformAdvisory;
use App\Models\RuntimePatch;
use App\Models\RuntimePatchFeed;
use App\Services\Runtimes\Patches\PatchFeedSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsRuntimePatches;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * The signed runtime patch feed (PatchFeedSync, docs/security.md#runtime-patch-feed): a HEAD per
 * check, the feed read only when its digest changed, the cosign signature verified with the
 * configured key, schema v1 validated; a refused feed is ignored (the last good one stays) and the
 * platform administrators hear about it once.
 */
class PatchFeedSyncTest extends TestCase
{
    use ActsWithRoles, BuildsRuntimePatches, RefreshDatabase, RegistersRuntimes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltinVersions();
        $this->makeFeedKeys();
        $this->fakeRegistry();
        Bus::fake([AnnounceRuntimePatchesJob::class]);
    }

    public function test_without_a_public_key_the_feed_is_off(): void
    {
        config(['runtime_patches.public_key' => '']);

        $this->artisan('runtime-patches:sync')->assertExitCode(0);

        $this->assertSame(RuntimePatchFeed::DISABLED, RuntimePatchFeed::current()->status);
        $this->assertSame([], $this->registryRequests);
        Http::assertNothingSent();
    }

    public function test_a_signed_feed_is_stored(): void
    {
        $digest = $this->publishFeed($this->feed([
            $this->feedPatch('3.14.8-r1', 'routine', ['published_at' => now()->subDays(5)->toIso8601String()]),
            $this->feedPatch('3.14.9-r1', 'zero-day', ['deadline_at' => null]),
        ], [
            ['version' => '0.5.11', 'level' => 'critical', 'reason' => ['en' => 'Fixes the API', 'tr' => 'API düzeltmesi'], 'url' => 'https://example.test/0.5.11', 'published_at' => now()->toIso8601String()],
        ]));

        $this->artisan('runtime-patches:sync')->assertExitCode(0);

        $feed = RuntimePatchFeed::current();
        $this->assertSame(RuntimePatchFeed::OK, $feed->status);
        $this->assertSame($digest, $feed->manifest_digest);
        $this->assertTrue($feed->signature_verified);
        $this->assertNotNull($feed->fetched_at);

        $zeroDay = RuntimePatch::query()->findOrFail('python-3.14-3.14.9-r1');
        $this->assertSame('zero-day', $zeroDay->level);
        $this->assertSame(['en' => 'Python 3.14.9-r1 fixes things', 'tr' => 'Python 3.14.9-r1 bir şeyleri düzeltir'], $zeroDay->reason);
        $this->assertSame(['CVE-2026-1111'], $zeroDay->cves);
        // no deadline in the feed: the default days of the level after publication
        $this->assertEqualsWithDelta($zeroDay->published_at->copy()->addDays(3)->timestamp, $zeroDay->deadline_at->timestamp, 1);
        $this->assertNull(RuntimePatch::query()->findOrFail('python-3.14-3.14.8-r1')->deadline_at, 'routine patches have no deadline');

        // the floating tag points to the newest patch
        $this->assertSame(self::digestOf('python:3.14.9-r1'), ExecutorImage::query()->where('runtime', 'python')->where('version', '3.14')->value('digest'));
        $this->assertNull(ExecutorImage::query()->where('runtime', 'go')->value('digest'));

        $this->assertSame('critical', PlatformAdvisory::query()->where('version', '0.5.11')->value('level'));

        Bus::assertDispatched(AnnounceRuntimePatchesJob::class, fn ($job) => $job->patchIds === ['python-3.14-3.14.8-r1', 'python-3.14-3.14.9-r1']);
    }

    public function test_an_unchanged_feed_costs_one_head(): void
    {
        $this->publishFeed($this->feed([$this->feedPatch('3.14.9-r1')]));
        $this->artisan('runtime-patches:sync')->assertExitCode(0);
        $this->registryRequests = [];

        $this->artisan('runtime-patches:sync')->assertExitCode(0);

        $this->assertSame(['HEAD /v2/brf-tech/runr/runtime-patches/manifests/feed'], $this->registryRequests);
        Bus::assertDispatchedTimes(AnnounceRuntimePatchesJob::class, 1);
    }

    public function test_a_feed_signed_with_another_key_is_ignored_and_reported_once(): void
    {
        $admin = $this->platformAdmin();
        $this->publishFeed($this->feed([$this->feedPatch('3.14.8-r1')]));
        $this->artisan('runtime-patches:sync')->assertExitCode(0);

        $other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($other, $otherPem);
        $bad = $this->publishFeed($this->feed([$this->feedPatch('3.14.9-r1', 'critical')]), $otherPem);

        $this->artisan('runtime-patches:sync')->assertExitCode(1);
        $this->artisan('runtime-patches:sync')->assertExitCode(1);

        $feed = RuntimePatchFeed::current();
        $this->assertSame(RuntimePatchFeed::INVALID_SIGNATURE, $feed->status);
        $this->assertSame($bad, $feed->rejected_digest);
        $this->assertNotSame($bad, $feed->manifest_digest, 'the last good feed stays');
        // the last good patches stay, the refused feed's are not taken
        $this->assertTrue(RuntimePatch::query()->findOrFail('python-3.14-3.14.8-r1')->in_feed);
        $this->assertNull(RuntimePatch::query()->find('python-3.14-3.14.9-r1'));

        $notices = Notification::query()->where('user_id', $admin->id)->where('type', 'runtime_patch_feed')->get();
        $this->assertCount(1, $notices, 'once per refused digest');
        $this->assertSame('critical', $notices->first()->level);
    }

    public function test_a_signature_of_another_manifest_does_not_count(): void
    {
        $this->publishFeed($this->feed([$this->feedPatch('3.14.9-r1')]), signedDigest: 'sha256:'.str_repeat('c', 64));

        $this->artisan('runtime-patches:sync')->assertExitCode(1);

        $this->assertSame(RuntimePatchFeed::INVALID_SIGNATURE, RuntimePatchFeed::current()->status);
        $this->assertSame(0, RuntimePatch::query()->count());
    }

    public function test_an_invalid_feed_is_refused(): void
    {
        $this->publishFeed($this->feed([$this->feedPatch('3.14.9-r1', 'urgent', ['digest' => 'sha256:nope'])]));

        $this->artisan('runtime-patches:sync')->assertExitCode(1);

        $feed = RuntimePatchFeed::current();
        $this->assertSame(RuntimePatchFeed::INVALID_SCHEMA, $feed->status);
        $this->assertStringContainsString('digest', (string) $feed->error);
        $this->assertSame(0, RuntimePatch::query()->count());
    }

    public function test_an_unreachable_registry_keeps_the_last_good_feed(): void
    {
        $this->publishFeed($this->feed([$this->feedPatch('3.14.9-r1')]));
        $this->artisan('runtime-patches:sync')->assertExitCode(0);
        $this->registry = [];

        $this->artisan('runtime-patches:sync')->assertExitCode(1);

        $this->assertSame(RuntimePatchFeed::UNREACHABLE, RuntimePatchFeed::current()->status);
        $this->assertTrue(RuntimePatch::query()->findOrFail('python-3.14-3.14.9-r1')->in_feed);
    }

    public function test_a_patch_the_feed_drops_leaves_it_but_is_kept(): void
    {
        $this->publishFeed($this->feed([$this->feedPatch('3.14.8-r1'), $this->feedPatch('3.14.9-r1')]));
        $this->artisan('runtime-patches:sync')->assertExitCode(0);

        $this->publishFeed($this->feed([$this->feedPatch('3.14.9-r1')]));
        $this->artisan('runtime-patches:sync')->assertExitCode(0);

        $dropped = RuntimePatch::query()->findOrFail('python-3.14-3.14.8-r1');
        $this->assertFalse($dropped->in_feed);
        $this->assertNotNull($dropped->removed_at);
        $this->assertTrue(RuntimePatch::query()->findOrFail('python-3.14-3.14.9-r1')->in_feed);
    }

    public function test_a_platform_administrators_extension_survives_a_new_read(): void
    {
        $this->publishFeed($this->feed([$this->feedPatch('3.14.9-r1', 'critical')]));
        $this->artisan('runtime-patches:sync')->assertExitCode(0);
        RuntimePatch::query()->whereKey('python-3.14-3.14.9-r1')->update(['deadline_extended_to' => now()->addMonth()]);

        $this->publishFeed($this->feed([$this->feedPatch('3.14.9-r1', 'critical'), $this->feedPatch('3.14.10-r1')]));
        $this->artisan('runtime-patches:sync')->assertExitCode(0);

        $this->assertNotNull(RuntimePatch::query()->findOrFail('python-3.14-3.14.9-r1')->deadline_extended_to);
    }

    public function test_an_anonymous_pull_token_is_fetched_when_the_registry_asks_for_one(): void
    {
        $digest = $this->publishFeed($this->feed([$this->feedPatch('3.14.9-r1')]));
        $tokens = 0;
        $this->registryGate = function ($request) use (&$tokens) {
            if (str_starts_with($request->url(), 'https://ghcr.io/token')) {
                $tokens++;

                return Http::response(['token' => 'anon']);
            }
            if (! $request->hasHeader('Authorization', 'Bearer anon')) {
                return Http::response('', 401, ['WWW-Authenticate' => 'Bearer realm="https://ghcr.io/token",service="ghcr.io",scope="repository:brf-tech/runr/runtime-patches:pull"']);
            }

            return null;
        };

        $this->artisan('runtime-patches:sync')->assertExitCode(0);

        $this->assertSame($digest, RuntimePatchFeed::current()->manifest_digest);
        $this->assertSame(1, $tokens, 'one token for every request of the repository');
    }

    public function test_the_schema_rules(): void
    {
        $this->assertSame([], PatchFeedSchema::errors($this->feed([$this->feedPatch('3.14.9-r1')])));
        $this->assertNotSame([], PatchFeedSchema::errors(['schema' => 2, 'generated_at' => now()->toIso8601String(), 'patches' => []]));
        $this->assertNotSame([], PatchFeedSchema::errors('nope'));
        $this->assertNotSame([], PatchFeedSchema::errors($this->feed([$this->feedPatch('3.14.9-r1', extra: ['reason' => ['tr' => 'yalnız Türkçe']])])), 'reason.en is required');
        $this->assertNotSame([], PatchFeedSchema::errors($this->feed([
            $this->feedPatch('3.14.9-r1'),
            $this->feedPatch('3.14.9-r1', extra: ['id' => 'another-id']),
        ])), 'one tag per runtime version');
        $this->assertNotSame([], PatchFeedSchema::errors($this->feed([], [['version' => 'latest', 'level' => 'critical', 'reason' => ['en' => 'x']]])));
    }
}
