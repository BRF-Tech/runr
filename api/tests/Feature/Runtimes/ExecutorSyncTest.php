<?php

namespace Tests\Feature\Runtimes;

use App\Models\ExecutorImage;
use App\Services\Runtimes\BuiltinCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * executor:sync keeps the version and EOL logic of the built-ins — now read from their
 * manifests' versionDiscovery (image repository, image template, endoflife.date product, the
 * Node.js LTS filter) — and syncs platform runtimes from the versions they list. Tags come from
 * the repository's own registry: tags/list behind an anonymous token, paged through the Link header.
 */
class ExecutorSyncTest extends TestCase
{
    use RefreshDatabase, RegistersRuntimes;

    /** Tags per tags/list page of the fake registries: two, so a longer list takes Link pages */
    private const PAGE_SIZE = 2;

    /** @var array<string, list<string>> tags per repository ("ghcr.io/…"; Docker Hub without its host) */
    private array $tags = [
        'ghcr.io/brf-tech/runr/exec-python' => ['3.12', '3.14', 'latest', '3.14-rc1'],
        'ghcr.io/brf-tech/runr/exec-nodejs' => ['20.19', '22.22', '23.11', '24.14', '24.9'],
        'ghcr.io/brf-tech/runr/exec-go' => ['1.26'],
        'ghcr.io/brf-tech/runr/exec-php' => ['8.2', '8.4'],
    ];

    /** @var array<string, string> repository → the `last` of the page its registry fails (500) */
    private array $failingPage = [];

    /** @var array<string, list<string>> repository → the `last` of each page served ("" = the first) */
    private array $pages = [];

    /** @var list<string> scopes of the anonymous tokens handed out */
    private array $tokenScopes = [];

    /** @var list<string> */
    private array $eolQueries = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            // Token services: an anonymous pull token for a repository that exists (ghcr.io refuses
            // one for a repository it does not have)
            if (preg_match('~^https://(ghcr\.io|auth\.docker\.io)/token\?~', $url)
                && preg_match('~^repository:(.+):pull$~', (string) ($query['scope'] ?? ''), $m)) {
                $repository = $this->repositoryKey(($query['service'] ?? '') === 'registry.docker.io' ? 'registry-1.docker.io' : 'ghcr.io', $m[1]);
                if (! isset($this->tags[$repository])) {
                    return Http::response(['errors' => [['code' => 'DENIED', 'message' => 'requested access to the resource is denied']]], 403);
                }
                $this->tokenScopes[] = $query['scope'];

                return Http::response(['token' => "anon:{$m[1]}", 'access_token' => "anon:{$m[1]}"]);
            }

            if (preg_match('~^https://(ghcr\.io|registry-1\.docker\.io)/v2/(.+)/tags/list~', $url, $m)) {
                [$host, $name] = [$m[1], $m[2]];
                if (! $request->hasHeader('Authorization', "Bearer anon:{$name}")) {
                    [$realm, $service] = $host === 'ghcr.io' ? ['https://ghcr.io/token', 'ghcr.io'] : ['https://auth.docker.io/token', 'registry.docker.io'];

                    return Http::response(['errors' => [['code' => 'UNAUTHORIZED']]], 401, [
                        'WWW-Authenticate' => "Bearer realm=\"{$realm}\",service=\"{$service}\",scope=\"repository:{$name}:pull\"",
                    ]);
                }

                $repository = $this->repositoryKey($host, $name);
                $last = (string) ($query['last'] ?? '');
                if (($this->failingPage[$repository] ?? null) === $last) {
                    return Http::response(['errors' => [['code' => 'UNKNOWN']]], 500);
                }
                $this->pages[$repository][] = $last;

                $tags = $this->tags[$repository];
                $offset = $last === '' ? 0 : (int) array_search($last, $tags, true) + 1;
                $page = array_slice($tags, $offset, self::PAGE_SIZE);
                $more = $offset + self::PAGE_SIZE < count($tags);

                return Http::response(['name' => $name, 'tags' => $page], 200, $more
                    ? ['Link' => '</v2/'.$name.'/tags/list?last='.end($page).'&n=1000>; rel="next"']
                    : []);
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

    private function repositoryKey(string $host, string $name): string
    {
        return $host === 'registry-1.docker.io' ? $name : "{$host}/{$name}";
    }

    public function test_built_in_versions_come_from_the_manifest_discovery(): void
    {
        $this->artisan('executor:sync')->assertExitCode(0);

        $python = ExecutorImage::where('runtime', 'python')->orderBy('sort_order')->get();
        $this->assertSame(['3.12', '3.14'], $python->pluck('version')->all());
        $this->assertSame(['ghcr.io/brf-tech/runr/exec-python:3.12', 'ghcr.io/brf-tech/runr/exec-python:3.14'], $python->pluck('docker_image')->all());
        $this->assertSame([false, true], $python->pluck('is_default')->all());
        $this->assertSame('2028-10-31', $python->first()->eol_at->toDateString());

        // Node.js: even majors, newest minor each — 24.14 is on the second page of the tag list
        $this->assertSame(['20.19', '22.22', '24.14'], ExecutorImage::where('runtime', 'nodejs')->orderBy('sort_order')->pluck('version')->all());
        $this->assertSame(['', '22.22', '24.14'], $this->pages['ghcr.io/brf-tech/runr/exec-nodejs'], 'every page, through the Link header');
        $this->assertContains('nodejs/24.14', $this->eolQueries);

        // one anonymous token per repository, for its own pull scope
        $this->assertContains('repository:brf-tech/runr/exec-python:pull', $this->tokenScopes);
        $this->assertSame(count($this->tokenScopes), count(array_unique($this->tokenScopes)));

        // the ML runtimes are not in this registry (no token, no tags): nothing is created for them
        $this->assertSame(0, ExecutorImage::whereIn('runtime', ['tensorflow', 'pytorch'])->count());
    }

    public function test_ml_runtimes_get_their_gpu_builds_from_the_gpu_tags(): void
    {
        $this->tags['ghcr.io/brf-tech/runr/exec-pytorch'] = ['2.13', '2.14', '2.14-gpu', '2.12-gpu', '2.14-cuda', '2.15-rc1-gpu', 'latest'];
        $this->tags['ghcr.io/brf-tech/runr/exec-tensorflow'] = ['2.21-gpu', '2.21'];
        // seeded before 0.5.1, never published: retired by the sync, the old default included
        ExecutorImage::create(['runtime' => 'pytorch', 'version' => '2.3', 'docker_image' => 'ghcr.io/brf-tech/runr/exec-pytorch:2.3', 'is_default' => true, 'is_active' => true, 'category' => 'ml']);
        ExecutorImage::create(['runtime' => 'pytorch', 'version' => '2.3-gpu', 'docker_image' => 'ghcr.io/brf-tech/runr/exec-pytorch:2.3-gpu', 'is_active' => true, 'gpu_required' => true, 'category' => 'ml']);

        $this->artisan('executor:sync')->assertExitCode(0);

        $pytorch = ExecutorImage::where('runtime', 'pytorch')->where('is_active', true)->orderBy('sort_order')->get();
        $this->assertSame(['2.13', '2.14', '2.14-gpu'], $pytorch->pluck('version')->all(), 'a GPU tag without its version and other suffixes are skipped');
        $this->assertSame(
            ['ghcr.io/brf-tech/runr/exec-pytorch:2.13', 'ghcr.io/brf-tech/runr/exec-pytorch:2.14', 'ghcr.io/brf-tech/runr/exec-pytorch:2.14-gpu'],
            $pytorch->pluck('docker_image')->all(),
        );
        $this->assertSame([false, true, false], $pytorch->pluck('is_default')->all(), 'the default is the newest CPU version');
        $this->assertSame([false, false, true], $pytorch->pluck('gpu_required')->all());
        $this->assertSame(['ml'], $pytorch->pluck('category')->unique()->values()->all());

        $tensorflow = ExecutorImage::where('runtime', 'tensorflow')->orderBy('sort_order')->get();
        $this->assertSame(['2.21', '2.21-gpu'], $tensorflow->pluck('version')->all());
        $this->assertSame([true, false], $tensorflow->pluck('is_default')->all());
        $this->assertSame([false, true], $tensorflow->pluck('gpu_required')->all());

        $retired = ExecutorImage::where('runtime', 'pytorch')->whereIn('version', ['2.3', '2.3-gpu'])->get();
        $this->assertSame([false, false], $retired->pluck('is_active')->all());
        $this->assertSame([false, false], $retired->pluck('is_default')->all(), 'a retired version is no longer the default');

        // one EOL lookup per version, the GPU build shares it; the other runtimes stay general
        $this->assertContains('pytorch/2.14', $this->eolQueries);
        $this->assertEmpty(array_filter($this->eolQueries, fn (string $q) => str_contains($q, 'gpu')));
        $this->assertSame(['general'], ExecutorImage::where('runtime', 'python')->pluck('category')->unique()->values()->all());
    }

    public function test_versions_gone_from_the_registry_are_retired(): void
    {
        ExecutorImage::create(['runtime' => 'php', 'version' => '8.1', 'docker_image' => 'ghcr.io/brf-tech/runr/exec-php:8.1', 'is_active' => true]);

        $this->artisan('executor:sync')->assertExitCode(0);

        $this->assertFalse(ExecutorImage::where('runtime', 'php')->where('version', '8.1')->sole()->is_active);
        $this->assertTrue(ExecutorImage::where('runtime', 'php')->where('version', '8.4')->sole()->is_active);
    }

    public function test_a_partial_tag_list_retires_nothing(): void
    {
        $this->tags['ghcr.io/brf-tech/runr/exec-php'] = ['8.2', '8.3', '8.4'];
        $this->failingPage['ghcr.io/brf-tech/runr/exec-php'] = '8.3';
        ExecutorImage::create(['runtime' => 'php', 'version' => '8.1', 'docker_image' => 'ghcr.io/brf-tech/runr/exec-php:8.1', 'is_active' => true]);

        $this->artisan('executor:sync')
            ->expectsOutputToContain('Partial tag list from ghcr.io/brf-tech/runr/exec-php, skipping cleanup.')
            ->assertExitCode(0);

        // the first page is synced, the version it does not list stays
        $php = ExecutorImage::where('runtime', 'php')->where('is_active', true)->orderBy('version')->pluck('version')->all();
        $this->assertSame(['8.1', '8.2', '8.3'], $php);
    }

    public function test_a_repository_without_a_registry_host_is_on_docker_hub_and_runrsh_moves(): void
    {
        // a catalog with the repository forms of earlier manifests: Docker Hub's runrsh/exec-python,
        // RunR's image namespace up to 0.5.0 (RegistryMove)
        $bundle = array_column(json_decode((string) file_get_contents(BuiltinCatalog::bundlePath()), true)['runtimes'], null, 'id');
        $bundle['python']['versionDiscovery']['repository'] = 'runrsh/exec-python';
        $bundle['go']['versionDiscovery'] = ['repository' => 'ghcr.io/runrsh/exec-go', 'image' => 'ghcr.io/runrsh/exec-go:{version}', 'eol' => 'go'];
        $this->tags['runrsh/exec-python'] = ['3.10', '3.11', '3.12'];

        $dir = sys_get_temp_dir().'/runr-catalog-'.uniqid();
        foreach (['python', 'go'] as $id) {
            mkdir("{$dir}/{$id}", 0777, true);
            file_put_contents("{$dir}/{$id}/runtime.yaml", Yaml::dump($bundle[$id], 20, 2));
        }
        config(['executor.catalog_path' => $dir]);
        BuiltinCatalog::flush();

        try {
            $this->artisan('executor:sync')->assertExitCode(0);
        } finally {
            config(['executor.catalog_path' => null]);
            BuiltinCatalog::flush();
            foreach (['python', 'go'] as $id) {
                unlink("{$dir}/{$id}/runtime.yaml");
                rmdir("{$dir}/{$id}");
            }
            rmdir($dir);
        }

        $python = ExecutorImage::where('runtime', 'python')->orderBy('sort_order')->get();
        $this->assertSame(['3.10', '3.11', '3.12'], $python->pluck('version')->all());
        $this->assertSame(['', '3.11'], $this->pages['runrsh/exec-python'], 'registry-1.docker.io, paged');
        $this->assertContains('repository:runrsh/exec-python:pull', $this->tokenScopes);

        $go = ExecutorImage::where('runtime', 'go')->sole();
        $this->assertSame(['1.26', 'ghcr.io/brf-tech/runr/exec-go:1.26'], [$go->version, $go->docker_image]);
        $this->assertArrayHasKey('ghcr.io/brf-tech/runr/exec-go', $this->pages);
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
