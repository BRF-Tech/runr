<?php

namespace Tests\Feature\Runtimes;

use App\Models\ExecutorImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * executor:sync keeps the version and EOL logic of the built-ins — now read from their
 * manifests' versionDiscovery (Docker Hub repository, image template, endoflife.date product,
 * the Node.js LTS filter) — and syncs platform runtimes from the versions they list.
 */
class ExecutorSyncTest extends TestCase
{
    use RefreshDatabase, RegistersRuntimes;

    private const TAGS = [
        'runrsh/exec-python' => ['3.12', '3.14', 'latest', '3.14-rc1'],
        'runrsh/exec-nodejs' => ['20.19', '22.22', '23.11', '24.14', '24.9'],
        'runrsh/exec-go' => ['1.26'],
        'runrsh/exec-php' => ['8.2', '8.4'],
    ];

    /** @var list<string> */
    private array $eolQueries = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (preg_match('~hub\.docker\.com/v2/repositories/([^/]+/[^/]+)/tags/~', $url, $m)) {
                $tags = self::TAGS[$m[1]] ?? null;

                return $tags === null
                    ? Http::response(['message' => 'not found'], 404)
                    : Http::response(['results' => array_map(fn ($t) => ['name' => $t], $tags), 'next' => null]);
            }

            if (preg_match('~endoflife\.date/api/([^/]+)/([^/]+)\.json~', $url, $m)) {
                $this->eolQueries[] = "{$m[1]}/{$m[2]}";

                return $m[1] === 'python' && $m[2] === '3.12'
                    ? Http::response(['eol' => '2028-10-31'])
                    : Http::response(['eol' => false]);
            }

            return Http::response([], 404);
        });
    }

    public function test_built_in_versions_come_from_the_manifest_discovery(): void
    {
        $this->artisan('executor:sync')->assertExitCode(0);

        $python = ExecutorImage::where('runtime', 'python')->orderBy('sort_order')->get();
        $this->assertSame(['3.12', '3.14'], $python->pluck('version')->all());
        $this->assertSame(['ghcr.io/brf-tech/exec-python:3.12', 'ghcr.io/brf-tech/exec-python:3.14'], $python->pluck('docker_image')->all());
        $this->assertSame([false, true], $python->pluck('is_default')->all());
        $this->assertSame('2028-10-31', $python->first()->eol_at->toDateString());

        // Node.js: even majors, newest minor each
        $this->assertSame(['20.19', '22.22', '24.14'], ExecutorImage::where('runtime', 'nodejs')->orderBy('sort_order')->pluck('version')->all());
        $this->assertContains('nodejs/24.14', $this->eolQueries);

        // the ML runtimes have no tags here: nothing is created for them
        $this->assertSame(0, ExecutorImage::whereIn('runtime', ['tensorflow', 'pytorch'])->count());
    }

    public function test_versions_gone_from_docker_hub_are_retired(): void
    {
        ExecutorImage::create(['runtime' => 'php', 'version' => '8.1', 'docker_image' => 'ghcr.io/brf-tech/exec-php:8.1', 'is_active' => true]);

        $this->artisan('executor:sync')->assertExitCode(0);

        $this->assertFalse(ExecutorImage::where('runtime', 'php')->where('version', '8.1')->sole()->is_active);
        $this->assertTrue(ExecutorImage::where('runtime', 'php')->where('version', '8.4')->sole()->is_active);
    }

    public function test_executor_runtimes_limits_the_synced_built_ins(): void
    {
        config(['executor.runtimes' => ['go']]);

        $this->artisan('executor:sync')->assertExitCode(0);

        $this->assertSame(['go'], ExecutorImage::distinct()->pluck('runtime')->all());
    }

    public function test_platform_runtimes_sync_from_their_manifest(): void
    {
        $this->registerRuntime($this->bashManifest());
        ExecutorImage::where('runtime', 'bash')->delete();

        $this->artisan('executor:sync')->assertExitCode(0);

        $bash = ExecutorImage::where('runtime', 'bash')->sole();
        $this->assertSame(['5.2', 'docker.io/library/bash:5.2', true], [$bash->version, $bash->docker_image, $bash->is_default]);
    }
}
