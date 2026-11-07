<?php

namespace Tests\Feature\Runtimes;

use App\Jobs\AnnounceRuntimePatchesJob;
use App\Models\ExecutorImage;
use App\Models\Runtime;
use App\Models\RuntimeSource;
use App\Services\GitDeploy\GitRunner;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Runtimes\Sources\GitSourceReader;
use App\Services\Runtimes\Sources\RuntimeSourceSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\BuildsRuntimeSources;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Git runtime sources (docs/runtimes.md#git-sources): runtimes/<id>/runtime.yaml of a repository
 * at a ref, read with git (ls-remote, then a shallow clone when the commit changed); strict
 * validation with the file and JSON path of each problem; the files next to a dockerfile runtime;
 * nothing read from outside the clone; runtimes that leave the repository orphaned, adopted when
 * they come back.
 */
class RuntimeGitSourceTest extends TestCase
{
    use BuildsRuntimeSources, RefreshDatabase, RegistersRuntimes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltinVersions();
        $this->setUpRuntimeSources();
        Bus::fake([AnnounceRuntimePatchesJob::class]);
    }

    protected function tearDown(): void
    {
        $this->tearDownRuntimeSources();
        parent::tearDown();
    }

    private function gitSource(string $url, array $attributes = []): RuntimeSource
    {
        return RuntimeSource::query()->create(array_merge([
            'name' => 'acme-git',
            'kind' => RuntimeSource::KIND_GIT,
            'scope' => RuntimeSource::SCOPE_PLATFORM,
            'url' => $url,
            'ref' => 'main',
        ], $attributes));
    }

    private function sync(RuntimeSource $source, bool $force = false): RuntimeSource
    {
        $source = app(RuntimeSourceSync::class)->sync($source, $force);
        app(RuntimeRegistry::class)->forget();

        return $source;
    }

    public function test_a_repository_of_runtimes_is_read(): void
    {
        $url = $this->gitRepository([
            'runtimes/lua/runtime.yaml' => $this->yamlOf($this->luaManifest()),
            'runtimes/lua/README.md' => 'not part of anything',
            'README.md' => '# acme runtimes',
        ]);
        $source = $this->sync($this->gitSource($url));

        $this->assertSame(RuntimeSource::OK, $source->status, (string) $source->error);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) $source->revision);
        $this->assertSame(1, $source->runtimes_count);

        $lua = Runtime::query()->findOrFail('lua');
        $this->assertSame(Runtime::SOURCE_PLATFORM, $lua->source);
        $this->assertSame($source->id, $lua->source_id);
        $this->assertSame('runtimes/lua/runtime.yaml', $lua->source_path);
        $this->assertNull($lua->files, 'an image runtime uploads nothing');
        $this->assertSame(['5.3', '5.4'], ExecutorImage::query()->where('runtime', 'lua')->orderBy('sort_order')->pluck('version')->all());
        $this->assertTrue((bool) ExecutorImage::query()->where('runtime', 'lua')->where('version', '5.4')->value('is_default'));
        $this->assertNull(app(RuntimeRegistry::class)->unavailableReason('lua', null, 'http'));
    }

    public function test_the_sample_executor_repository_is_a_valid_source(): void
    {
        // examples/runtime-sources: a copy of BRF-Tech/runr-sample-custom-executor's runtimes/
        $dir = base_path('../examples/runtime-sources/sample-custom-executor');
        $this->assertDirectoryExists("{$dir}/runtimes");
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$dir}/runtimes", \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[substr($file->getPathname(), strlen($dir) + 1)] = (string) file_get_contents($file->getPathname());
        }

        $source = $this->sync($this->gitSource($this->gitRepository($files)));

        $this->assertSame(RuntimeSource::OK, $source->status, json_encode($source->problems).' '.$source->error);
        $this->assertSame(2, $source->runtimes_count);
        $lua = Runtime::query()->findOrFail('sample-lua');
        $this->assertArrayHasKey('Dockerfile', $lua->files, 'the 5.3 dockerfile version takes the files of its directory');
        $this->assertSame('persistent', app(RuntimeRegistry::class)->manifest('sample-lua')->runMode());
        $this->assertSame('http', app(RuntimeRegistry::class)->manifest('sample-zig')->runMode());
        $this->assertNotEmpty(app(RuntimeRegistry::class)->manifest('sample-zig')->build()['files'], '@file build files inlined');
        $old = ExecutorImage::query()->where('runtime', 'sample-lua')->where('version', '5.3')->firstOrFail();
        $this->assertSame(['2026-12-01', '2027-06-30'], [$old->deprecated_at->toDateString(), $old->sunset_at->toDateString()]);
    }

    public function test_an_unchanged_commit_is_not_cloned_again(): void
    {
        $url = $this->gitRepository(['runtimes/lua/runtime.yaml' => $this->yamlOf($this->luaManifest())]);
        $source = $this->sync($this->gitSource($url));
        $calls = [];
        $runner = new class($calls) extends GitRunner
        {
            public function __construct(private array &$calls) {}

            public function attempt(array $args, ?string $cwd = null, array $env = [], ?string $input = null, int $timeout = 120, array $config = []): array
            {
                $this->calls[] = $args[0];

                return parent::attempt($args, $cwd, $env, $input, $timeout, $config);
            }
        };
        $this->app->instance(GitRunner::class, $runner);
        $this->app->forgetInstance(GitSourceReader::class);
        $this->app->forgetInstance(RuntimeSourceSync::class);

        $this->sync($source->fresh());

        $this->assertSame(['ls-remote'], $calls);
    }

    public function test_every_bad_manifest_is_reported_with_its_file_and_json_path(): void
    {
        $exec = $this->luaManifest('broken');
        unset($exec['run']['command']);
        $url = $this->gitRepository([
            'runtimes/lua/runtime.yaml' => $this->yamlOf($this->luaManifest()),
            'runtimes/broken/runtime.yaml' => $this->yamlOf($exec),
            'runtimes/notyaml/runtime.yaml' => "id: [unclosed\n",
            'runtimes/other/runtime.yaml' => $this->yamlOf($this->luaManifest('elsewhere')),
        ]);

        $source = $this->sync($this->gitSource($url));

        $this->assertSame(RuntimeSource::PARTIAL, $source->status);
        $problems = collect($source->problems);
        $this->assertTrue($problems->contains(fn ($p) => $p['file'] === 'runtimes/broken/runtime.yaml' && $p['path'] === '/run/command' && $p['message'] === 'is required in exec mode'));
        $this->assertTrue($problems->contains(fn ($p) => $p['file'] === 'runtimes/notyaml/runtime.yaml' && $p['path'] === '/'));
        $this->assertTrue($problems->contains(fn ($p) => $p['file'] === 'runtimes/other/runtime.yaml' && $p['path'] === '/id' && str_contains($p['message'], "does not match its directory 'other'")));
        // the good one is read anyway
        $this->assertNotNull(Runtime::query()->find('lua'));
        $this->assertNull(Runtime::query()->find('broken'));
        $this->assertNull(Runtime::query()->find('elsewhere'));
    }

    public function test_a_dockerfile_runtime_takes_the_files_of_its_directory(): void
    {
        $q = $this->luaManifest('q', ['versions' => [['version' => '1.0', 'dockerfile' => 'Dockerfile', 'default' => true]]]);
        $q['versions'] = [['version' => '1.0', 'dockerfile' => 'Dockerfile', 'default' => true]];
        $url = $this->gitRepository([
            'runtimes/q/runtime.yaml' => $this->yamlOf($q),
            'runtimes/q/Dockerfile' => "FROM alpine:3.21\nCOPY q /usr/bin/q\n",
            'runtimes/q/q' => "#!/bin/sh\necho q\n",
        ]);

        $this->sync($this->gitSource($url));

        $this->assertSame(['Dockerfile' => "FROM alpine:3.21\nCOPY q /usr/bin/q\n", 'q' => "#!/bin/sh\necho q\n"], Runtime::query()->findOrFail('q')->files);
    }

    public function test_nothing_is_read_from_outside_the_clone(): void
    {
        $secret = sys_get_temp_dir().'/runr-test-secret-'.bin2hex(random_bytes(4));
        file_put_contents($secret, 'TOP-SECRET-CONTENT');
        $manifest = $this->luaManifest('leak', ['build' => ['command' => 'true', 'files' => ['x.txt' => '@../../../../../../../..'.$secret]]]);
        $url = $this->gitRepository(['runtimes/leak/runtime.yaml' => $this->yamlOf($manifest)]);
        $dir = substr($url, strlen('file://'));
        // a symlink in the repository is checked out as a plain file (core.symlinks=false)
        symlink($secret, $dir.'/runtimes/leak/link.txt');
        $this->git($dir, ['add', '-A']);
        $this->git($dir, ['-c', 'user.email=t@example.test', '-c', 'user.name=Test', 'commit', '-q', '-m', 'link']);

        $source = $this->sync($this->gitSource($url));

        $this->assertNull(Runtime::query()->find('leak'));
        $this->assertStringNotContainsString('TOP-SECRET-CONTENT', json_encode($source->toApi()));
        $this->assertTrue(collect($source->problems)->contains(fn ($p) => $p['path'] === '/build/files/x.txt'), json_encode($source->problems));
        @unlink($secret);
    }

    public function test_a_runtime_that_leaves_the_repository_is_orphaned_and_adopted_back(): void
    {
        $url = $this->gitRepository([
            'runtimes/lua/runtime.yaml' => $this->yamlOf($this->luaManifest()),
            'runtimes/moon/runtime.yaml' => $this->yamlOf($this->luaManifest('moon')),
        ]);
        $source = $this->gitSource($url);
        $this->sync($source);
        [$project] = $this->runtimeProject('moon');

        $dir = substr($url, strlen('file://'));
        $this->gitRepository(['runtimes/moon/runtime.yaml' => null], $dir);
        $source = $this->sync($source->fresh());

        $moon = Runtime::query()->findOrFail('moon');
        $this->assertNotNull($moon->orphaned_at, 'orphaned, not deleted: a project uses it');
        $this->assertSame(1, $source->runtimes_count);
        $registry = app(RuntimeRegistry::class);
        $this->assertNull($registry->deployBlockedReason('moon', $project->knative_region_id));
        $this->assertNotNull($registry->unavailableReason('moon', null, 'http'));
        $this->assertNotContains('moon', $registry->visible()->pluck('id')->all());
        $this->assertContains('moon', $registry->visible(true)->pluck('id')->all(), 'managers still see it');

        // it comes back: no longer orphaned, no sunset
        $this->gitRepository(['runtimes/moon/runtime.yaml' => $this->yamlOf($this->luaManifest('moon'))], $dir);
        $this->sync($source->fresh());
        $moon->refresh();
        $this->assertNull($moon->orphaned_at);
        $this->assertNull(ExecutorImage::query()->where('runtime', 'moon')->where('version', '5.4')->value('sunset_at'));
    }

    public function test_an_unreachable_repository_keeps_the_runtimes(): void
    {
        $url = $this->gitRepository(['runtimes/lua/runtime.yaml' => $this->yamlOf($this->luaManifest())]);
        $source = $this->sync($this->gitSource($url));
        $source->forceFill(['url' => 'file:///nonexistent/repo'])->save();

        $source = $this->sync($source->fresh(), true);

        $this->assertSame(RuntimeSource::UNREACHABLE, $source->status);
        $this->assertNotEmpty($source->error);
        $this->assertNull(Runtime::query()->findOrFail('lua')->orphaned_at);
    }

    public function test_the_ref_picks_the_branch_or_tag(): void
    {
        $url = $this->gitRepository(['runtimes/lua/runtime.yaml' => $this->yamlOf($this->luaManifest())]);
        $dir = substr($url, strlen('file://'));
        $this->git($dir, ['tag', 'v1']);
        $this->gitRepository(['runtimes/moon/runtime.yaml' => $this->yamlOf($this->luaManifest('moon'))], $dir);

        $this->sync($this->gitSource($url, ['ref' => 'v1']));
        $this->assertNull(Runtime::query()->find('moon'), 'the tag has no moon');

        $missing = $this->sync($this->gitSource($url, ['name' => 'x', 'ref' => 'nope']));
        $this->assertSame(RuntimeSource::UNREACHABLE, $missing->status);
        $this->assertStringContainsString('no branch or tag nope', (string) $missing->error);
    }
}
