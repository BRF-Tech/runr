<?php

namespace Tests\Feature\Runtimes;

use App\Jobs\AnnounceRuntimePatchesJob;
use App\Models\RuntimeSource;
use App\Services\Dns\DnsResolver;
use App\Services\GitDeploy\GitRunner;
use App\Services\Runtimes\Sources\CatalogPayload;
use App\Services\Runtimes\Sources\GitSourceReader;
use App\Services\Runtimes\Sources\RuntimeSourceException;
use App\Services\Runtimes\Sources\RuntimeSourceSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsRuntimeSources;
use Tests\TestCase;

/**
 * An organization's runtime source names its registry and Git host: RunR reads it through the
 * outbound guard (GHSA-2c93-rcvw-qpq2 follow-up) — every registry request, the token service of a
 * Bearer challenge and each redirect checked and pinned; a Git host checked and pinned (https:
 * curl's resolve list, SSH: the checked address under the host's name); errors shown to the
 * organization without what a host answered. Platform sources are read as before.
 */
class RuntimeSourceOutboundGuardTest extends TestCase
{
    use BuildsRuntimeSources, RefreshDatabase;

    /** @var array<string, list<string>> */
    public array $dns = [];

    private ?Process $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRuntimeSources();
        Bus::fake([AnnounceRuntimePatchesJob::class]);

        $this->dns = ['registry.acme.test' => ['93.184.216.34'], 'git.acme.test' => ['93.184.216.35'], 'cdn.acme.test' => ['93.184.216.36']];
        $test = $this;
        $this->app->instance(DnsResolver::class, new class($test) extends DnsResolver
        {
            public function __construct(private readonly RuntimeSourceOutboundGuardTest $test) {}

            public function addresses(string $name): array
            {
                return $this->test->dns[$name] ?? [];
            }
        });
    }

    protected function tearDown(): void
    {
        $this->server?->stop(1);
        $this->tearDownRuntimeSources();
        parent::tearDown();
    }

    private function organizationSource(array $attributes = []): RuntimeSource
    {
        return $this->ociSource($attributes + ['scope' => RuntimeSource::SCOPE_ORGANIZATION]);
    }

    private function publishCatalog(): void
    {
        $this->publishArtifact('runtimes/catalog', 'stable', CatalogPayload::MEDIA_TYPE, $this->catalog([$this->luaManifest()]));
    }

    public function test_an_organizations_catalog_is_read_at_the_checked_addresses(): void
    {
        $this->publishCatalog();

        $source = app(RuntimeSourceSync::class)->sync($this->organizationSource(), true);

        $this->assertSame(RuntimeSource::OK, $source->status, (string) $source->error);
        $this->assertNotEmpty($this->registrySent);
        foreach ($this->registrySent as $sent) {
            $this->assertFalse($sent['options']['allow_redirects'], $sent['url']);
            $this->assertSame('registry.acme.test:443:93.184.216.34', $sent['options']['curl'][CURLOPT_RESOLVE][0], $sent['url']);
        }
    }

    public function test_an_organizations_registry_on_a_private_address_is_refused(): void
    {
        $this->publishCatalog();

        foreach (['10.0.0.5', '::ffff:169.254.169.254', '64:ff9b::a9fe:a9fe', '2002:a9fe:a9fe::'] as $internal) {
            $this->dns['registry.acme.test'] = [$internal];
            $source = app(RuntimeSourceSync::class)->sync($this->organizationSource(['name' => 'org-'.md5($internal)]), true);

            $this->assertSame(RuntimeSource::UNREACHABLE, $source->status, $internal);
            $this->assertSame(__('observability.url.private'), $source->error, $internal);
        }
        $this->assertSame([], $this->registryRequests, 'nothing sent to an internal address');

        // A platform source keeps reading its registry wherever it is
        $platform = app(RuntimeSourceSync::class)->sync($this->ociSource(['name' => 'platform']), true);
        $this->assertSame(RuntimeSource::OK, $platform->status, (string) $platform->error);
        $this->assertArrayNotHasKey('curl', end($this->registrySent)['options']);
    }

    public function test_a_redirect_is_followed_by_hand_to_checked_addresses_only(): void
    {
        $this->publishCatalog();
        $blob = collect(array_keys($this->registry))->first(fn ($path) => str_contains($path, '/blobs/') && $this->registry[$path]['body'] !== '' && str_contains($this->registry[$path]['body'], 'runtimes'));
        $this->registryGate = function (Request $request) use ($blob) {
            $url = $request->url();
            if (str_starts_with($url, 'https://cdn.acme.test/')) {
                return Http::response($this->registry[$blob]['body'], 200);
            }

            return (string) parse_url($url, PHP_URL_PATH) === $blob ? Http::response('', 307, ['Location' => 'https://cdn.acme.test/blob?sig=1']) : null;
        };

        $source = app(RuntimeSourceSync::class)->sync($this->organizationSource(['registry_host' => 'registry.acme.test', 'credentials' => ['registry' => ['username' => 'u', 'password' => 'p']]]), true);
        $this->assertSame(RuntimeSource::OK, $source->status, (string) $source->error);

        $cdn = collect($this->registrySent)->first(fn ($s) => str_starts_with($s['url'], 'https://cdn.acme.test/'));
        $this->assertNotNull($cdn, 'the redirect was followed');
        $this->assertSame('cdn.acme.test:443:93.184.216.36', $cdn['options']['curl'][CURLOPT_RESOLVE][0]);
        $this->assertArrayNotHasKey('Authorization', $cdn['headers'], 'the registry credentials stay with the registry');

        // A redirect to an internal address: not followed, the read fails without the detail
        $this->registryGate = fn (Request $request) => (string) parse_url($request->url(), PHP_URL_PATH) === $blob
            ? Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])
            : null;
        $this->registrySent = [];
        $source = app(RuntimeSourceSync::class)->sync($this->organizationSource(['name' => 'second']), true);
        $this->assertSame(RuntimeSource::UNREACHABLE, $source->status);
        $this->assertSame([], collect($this->registrySent)->filter(fn ($s) => str_contains($s['url'], '169.254'))->all());
    }

    public function test_a_token_service_on_a_private_address_is_refused(): void
    {
        $this->publishCatalog();
        $this->dns['auth.acme.test'] = ['10.0.0.9'];
        $this->registryGate = fn (Request $request) => str_starts_with($request->url(), 'https://registry.acme.test/')
            ? Http::response('', 401, ['WWW-Authenticate' => 'Bearer realm="https://auth.acme.test/token",service="registry"'])
            : null;

        $source = app(RuntimeSourceSync::class)->sync($this->organizationSource(), true);

        $this->assertNotSame(RuntimeSource::OK, $source->status);
        $this->assertSame([], collect($this->registrySent)->filter(fn ($s) => str_contains($s['url'], 'auth.acme.test'))->all());
    }

    public function test_an_organization_sees_a_generic_reason_the_platform_the_detail(): void
    {
        $this->registryGate = fn () => Http::response('upstream says 10.1.2.3 is down', 500);

        $org = app(RuntimeSourceSync::class)->sync($this->organizationSource(), true);
        $this->assertSame(__('api.runtime_sources.read_failed.unreachable'), $org->error);
        $test = app(RuntimeSourceSync::class)->test($this->organizationSource(['name' => 'draft']));
        $this->assertSame(__('api.runtime_sources.read_failed.unreachable'), $test['error']);

        $platform = app(RuntimeSourceSync::class)->sync($this->ociSource(['name' => 'platform']), true);
        $this->assertStringContainsString('HTTP 500', (string) $platform->error);
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} env and git config of a read */
    private function access(RuntimeSource $source): array
    {
        $method = new \ReflectionMethod(GitSourceReader::class, 'access');

        return $method->invoke(app(GitSourceReader::class), $source, sys_get_temp_dir());
    }

    public function test_an_organizations_git_host_is_checked_and_pinned(): void
    {
        $https = new RuntimeSource(['kind' => 'git', 'scope' => RuntimeSource::SCOPE_ORGANIZATION, 'url' => 'https://git.acme.test/acme/runtimes.git']);
        [$env, $config] = $this->access($https);
        $this->assertSame('git.acme.test:443:93.184.216.35', $config['http.curloptResolve']);
        $this->assertSame('false', $config['http.followRedirects']);
        $this->assertSame(['', '', '*'], [$env['https_proxy'], $env['HTTPS_PROXY'], $env['NO_PROXY']]);

        $ssh = new RuntimeSource(['kind' => 'git', 'scope' => RuntimeSource::SCOPE_ORGANIZATION, 'url' => 'ssh://git@git.acme.test:2222/acme/runtimes.git']);
        [$env] = $this->access($ssh);
        foreach (["'-F' '/dev/null'", "'ProxyCommand=none'", "'HostName=93.184.216.35'", "'HostKeyAlias=[git.acme.test]:2222'"] as $part) {
            $this->assertStringContainsString($part, $env['GIT_SSH_COMMAND']);
        }
        [$env] = $this->access(new RuntimeSource(['kind' => 'git', 'scope' => RuntimeSource::SCOPE_ORGANIZATION, 'url' => 'git@git.acme.test:acme/runtimes.git']));
        $this->assertStringContainsString("'HostKeyAlias=git.acme.test'", $env['GIT_SSH_COMMAND']);

        foreach (['https://git.internal.test/x.git' => ['10.0.0.5'], 'ssh://git@git.internal.test/x.git' => ['::ffff:127.0.0.1']] as $url => $addresses) {
            $this->dns['git.internal.test'] = $addresses;
            try {
                $this->access(new RuntimeSource(['kind' => 'git', 'scope' => RuntimeSource::SCOPE_ORGANIZATION, 'url' => $url]));
                $this->fail("{$url} accepted");
            } catch (RuntimeSourceException $e) {
                $this->assertSame(__('observability.url.private'), $e->getMessage());
                $this->assertTrue($e->shown);
            }
        }

        // A platform source is read as before
        [$env, $config] = $this->access(new RuntimeSource(['kind' => 'git', 'scope' => RuntimeSource::SCOPE_PLATFORM, 'url' => 'https://git.internal.test/x.git']));
        $this->assertArrayNotHasKey('http.curloptResolve', $config);
        $this->assertStringNotContainsString('HostName=', $env['GIT_SSH_COMMAND']);
    }

    /**
     * The pin with a real git: a name that cannot resolve (.invalid) is read from the pinned
     * address — git hands http.curloptResolve to curl and does not ask DNS (a dumb HTTP
     * repository served by PHP's built-in server).
     */
    public function test_git_reads_the_pinned_address_without_dns(): void
    {
        $repo = $this->gitRepository(['runtimes/lua/runtime.yaml' => "id: lua\n"]);
        $docroot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'runr-test-repo-www-'.bin2hex(random_bytes(4));
        $this->gitRepositories[] = $docroot;
        mkdir($docroot);
        $this->git($docroot, ['clone', '-q', '--bare', $repo, $docroot.'/repo.git']);
        $this->git($docroot.'/repo.git', ['update-server-info']);

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        $this->server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot]);
        $this->server->start();
        for ($i = 0; $i < 100 && ! @fsockopen('127.0.0.1', $port, $errno, $error, 0.1); $i++) {
            usleep(50_000);
        }

        [$code, $out, $err] = app(GitRunner::class)->attempt(
            ['ls-remote', '--', "http://runr-pin-test.invalid:{$port}/repo.git"], null, [], null, 30,
            ['protocol.allow' => 'never', 'protocol.http.allow' => 'always', 'http.curloptResolve' => "runr-pin-test.invalid:{$port}:127.0.0.1", 'http.followRedirects' => 'false'],
        );

        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString('refs/heads/main', $out);
    }
}
