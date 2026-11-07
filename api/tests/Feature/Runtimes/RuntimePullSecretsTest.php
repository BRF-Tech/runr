<?php

namespace Tests\Feature\Runtimes;

use App\Jobs\CheckRuntimeImageBuildJob;
use App\Jobs\DeleteRuntimeSourceSecretsJob;
use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\Runtime;
use App\Models\RuntimeSource;
use App\Services\KnativeDeployService;
use App\Services\Manifests\PrepullManifests;
use App\Services\Manifests\ZotManifests;
use App\Services\Runtimes\ManifestValidator;
use App\Services\Runtimes\RuntimeImageBuildService;
use App\Services\Runtimes\RuntimePullSecrets;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Runtimes\RuntimeVersionDiscovery;
use App\Services\Runtimes\Sources\RuntimeSourceSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\FakesBuildCluster;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Private runtime images (docs/runtimes.md#private-sources): every place RunR pulls a runtime image
 * of a source with registry credentials uses them — the function pods (imagePullSecrets and a
 * RunR-managed kubernetes.io/dockerconfigjson Secret in the namespace), the Kaniko builds (docker
 * config, FROM straight from the registry, not the pull-through), runtime image builds (also the
 * Git token of the source), the node pre-pull (crictl --creds from a Secret), version discovery —
 * and no credential ever appears in a manifest RunR keeps or logs.
 */
class RuntimePullSecretsTest extends TestCase
{
    use FakesBuildCluster, RefreshDatabase, RegistersRuntimes;

    private const PASSWORD = 'reg-Secret-Pa55word-xyz';

    private RuntimeSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBuildCluster();
        $this->zotConfig = json_encode(ZotManifests::mirrorConfig());
        Bus::fake([CheckRuntimeImageBuildJob::class]);

        $this->source = RuntimeSource::query()->create([
            'name' => 'acme', 'kind' => RuntimeSource::KIND_OCI, 'scope' => RuntimeSource::SCOPE_PLATFORM,
            'url' => 'registry.acme.test/runtimes/catalog:stable',
            'credentials' => ['registry' => ['username' => 'bot', 'password' => self::PASSWORD]],
        ]);
    }

    /** A runtime of the source (as a sync leaves it), its images on the source's registry. */
    private function sourceRuntime(string $manifest): Runtime
    {
        $runtime = $this->registerRuntime($manifest);
        $runtime->forceFill(['source_id' => $this->source->id])->save();
        app(RuntimeRegistry::class)->forget();

        return $runtime;
    }

    private function kubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    private function secretWrites(): array
    {
        return collect($this->calls)->filter(fn ($c) => in_array($c['method'], ['PATCH', 'POST'], true) && str_contains($c['path'], '/secrets'))->values()->all();
    }

    public function test_function_pods_of_a_private_image_get_its_pull_secret(): void
    {
        $this->sourceRuntime($this->bashManifest(['versions' => [['version' => '5.2', 'image' => 'registry.acme.test/runtimes/bash:5.2', 'default' => true]]]));
        [$project, $version] = $this->runtimeProject('bash', 'http', ['main.sh' => 'echo hi'], ['kubeconfig' => $this->kubeconfig()]);
        $this->calls = [];

        $manifests = app(KnativeDeployService::class)->buildManifests($project, $version);

        $spec = collect($manifests)->firstWhere('kind', 'Service')['spec']['template']['spec'];
        $name = RuntimePullSecrets::secretName($this->source);
        $this->assertSame([['name' => $name]], $spec['imagePullSecrets']);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($manifests), 'the value is not part of the deployment');

        $writes = $this->secretWrites();
        $this->assertCount(1, $writes);
        $this->assertStringEndsWith("/namespaces/fn/secrets/{$name}", $writes[0]['path']);
        $this->assertSame('kubernetes.io/dockerconfigjson', $writes[0]['body']['type']);
        $config = json_decode(base64_decode($writes[0]['body']['data']['.dockerconfigjson']), true);
        $this->assertSame(['username' => 'bot', 'password' => self::PASSWORD, 'auth' => base64_encode('bot:'.self::PASSWORD)], $config['auths']['registry.acme.test']);
    }

    public function test_public_images_and_images_on_another_registry_get_none(): void
    {
        $this->sourceRuntime($this->bashManifest());   // docker.io/library/bash: not the source's registry
        [$project, $version] = $this->runtimeProject('bash', 'http', ['main.sh' => 'echo hi'], ['kubeconfig' => $this->kubeconfig()]);
        [$python, $pythonVersion] = $this->runtimeProject('python', 'http', ['main.py' => 'x'], ['kubeconfig' => $this->kubeconfig()]);
        $this->calls = [];

        $bash = collect(app(KnativeDeployService::class)->buildManifests($project, $version))->firstWhere('kind', 'Service')['spec']['template']['spec'];
        $py = collect(app(KnativeDeployService::class)->buildManifests($python, $pythonVersion))->firstWhere('kind', 'Service')['spec']['template']['spec'];

        $this->assertArrayNotHasKey('imagePullSecrets', $bash);
        $this->assertArrayNotHasKey('imagePullSecrets', $py);
        $this->assertSame([], $this->secretWrites());
    }

    public function test_a_function_build_pulls_private_bases_with_the_docker_config(): void
    {
        $this->sourceRuntime($this->cManifest([
            'versions' => [['version' => '17', 'image' => 'registry.acme.test/runtimes/gcc:17', 'default' => true]],
            'build' => ['runImage' => 'ghcr.io/brf-tech/runr/reqs/alpine:3.21'],
        ]));

        $this->submit($this->buildVersion('c', null, 'internal', ['main.c' => 'int main(void) { return 0; }']));

        // the private base straight from its registry, the public one through the pull-through
        $this->assertSame(['registry.acme.test/runtimes/gcc:17', self::ZOT.'/ghcr.io/brf-tech/runr/reqs/alpine:3.21'], $this->fromLines());
        $pod = $this->created('jobs')['spec']['template']['spec'];
        $kaniko = collect($pod['containers'])->firstWhere('name', 'kaniko');
        $this->assertContains(['name' => 'DOCKER_CONFIG', 'value' => '/kaniko/.docker'], $kaniko['env']);
        $this->assertContains(['name' => 'runr-docker-config', 'mountPath' => '/kaniko/.docker', 'readOnly' => true], $kaniko['volumeMounts']);
        $this->assertContains(['name' => 'runr-docker-config', 'secret' => ['secretName' => RuntimePullSecrets::secretName($this->source), 'items' => [['key' => '.dockerconfigjson', 'path' => 'config.json']]]], $pod['volumes']);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($this->created('jobs')));
        $this->assertCount(1, $this->secretWrites());
    }

    public function test_a_runtime_image_build_of_a_git_source_uses_its_token_and_registry(): void
    {
        $this->source->forceFill([
            'kind' => RuntimeSource::KIND_GIT, 'url' => 'https://git.acme.test/acme/runtimes.git', 'registry_host' => 'registry.acme.test',
            'credentials' => ['git' => ['token' => 'git-Token-123456789'], 'registry' => ['username' => 'bot', 'password' => self::PASSWORD]],
        ])->save();
        $q = Yaml::parse($this->qManifest());
        $q['versions'] = [['version' => '1.0', 'git' => ['url' => 'https://git.acme.test/acme/q.git', 'ref' => 'main'], 'default' => true]];
        $runtime = $this->sourceRuntime(Yaml::dump($q, 8, 2));
        $region = KnativeRegion::create(['name' => 'eu', 'project_namespace' => 'fn', 'is_active' => true, 'kubeconfig' => $this->kubeconfig(),
            'registry_mode' => 'internal', 'registry_status' => 'ready', 'registry_address' => self::ZOT, 'registry_namespace' => 'runr-registry']);
        $this->calls = [];

        app(RuntimeImageBuildService::class)->ensure($runtime, app(RuntimeRegistry::class)->manifest('q')->versions()[0], $region);

        $job = collect($this->calls)->first(fn ($c) => $c['method'] === 'POST' && str_ends_with($c['path'], '/namespaces/fn/jobs'))['body'];
        $kaniko = $job['spec']['template']['spec']['containers'][0];
        $env = collect($kaniko['env'])->keyBy('name');
        $this->assertSame(['name' => RuntimePullSecrets::secretName($this->source).'-git', 'key' => 'password'], $env['GIT_PASSWORD']['valueFrom']['secretKeyRef']);
        $this->assertSame('/kaniko/.docker', $env['DOCKER_CONFIG']['value']);
        $this->assertStringNotContainsString('git-Token-123456789', json_encode($job));
        $this->assertCount(2, $this->secretWrites(), 'the pull secret and the git secret');
    }

    public function test_the_pre_pull_reads_private_credentials_from_a_secret(): void
    {
        $daemonSet = PrepullManifests::daemonSet(
            ['ghcr.io/brf-tech/runr/exec-python:3.14', 'registry.acme.test/runtimes/bash:5.2'],
            credentials: ['registry.acme.test/runtimes/bash:5.2' => 's01abc'],
            credentialSecret: 'runr-image-prepull-creds',
        );

        $pull = collect($daemonSet['spec']['template']['spec']['initContainers'])->firstWhere('name', 'pull-all');
        $script = end($pull['command']);
        $this->assertStringContainsString("crictl pull --creds \"\$RUNR_PULL_CREDS_S01ABC\" 'registry.acme.test/runtimes/bash:5.2'", $script);
        $this->assertStringContainsString("crictl pull 'ghcr.io/brf-tech/runr/exec-python:3.14'", $script);
        $this->assertSame([['name' => 'RUNR_PULL_CREDS_S01ABC', 'valueFrom' => ['secretKeyRef' => ['name' => 'runr-image-prepull-creds', 'key' => 's01abc']]]], $pull['env']);
    }

    public function test_the_registry_prewarm_leaves_private_bases_out(): void
    {
        $this->sourceRuntime($this->cManifest([
            'versions' => [['version' => '17', 'image' => 'registry.acme.test/runtimes/gcc:17', 'default' => true]],
        ]));

        $images = app(RuntimeRegistry::class)->buildBaseImages();

        $this->assertNotContains('registry.acme.test/runtimes/gcc:17', $images);
        $this->assertContains('ghcr.io/brf-tech/runr/reqs/alpine:3.21', $images);
    }

    public function test_the_pull_secrets_leave_the_regions_with_the_source(): void
    {
        KnativeRegion::create(['name' => 'eu', 'project_namespace' => 'fn', 'is_active' => true, 'kubeconfig' => $this->kubeconfig()]);
        $deleted = [];
        Http::swap(new Factory);
        Http::fake(function (Request $request) use (&$deleted) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && $path === '/api/v1/secrets') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return ($query['labelSelector'] ?? null) === RuntimePullSecrets::LABEL.'='.strtolower($this->source->id)
                    ? Http::response(['items' => [
                        ['metadata' => ['name' => RuntimePullSecrets::secretName($this->source), 'namespace' => 'fn']],
                        ['metadata' => ['name' => RuntimePullSecrets::secretName($this->source).'-git', 'namespace' => 'fn']],
                    ]])
                    : Http::response(['items' => []]);
            }
            if ($request->method() === 'DELETE') {
                $deleted[] = $path;
            }

            return Http::response(['kind' => 'Status'], 200);
        });

        (new DeleteRuntimeSourceSecretsJob($this->source->id))->handle();

        $name = RuntimePullSecrets::secretName($this->source);
        $this->assertSame(["/api/v1/namespaces/fn/secrets/{$name}", "/api/v1/namespaces/fn/secrets/{$name}-git"], $deleted);
    }

    public function test_version_discovery_of_a_private_registry_uses_the_credentials(): void
    {
        $tokenAuth = null;
        Http::swap(new Factory);
        Http::fake(function (Request $request) use (&$tokenAuth) {
            $url = $request->url();
            if (str_starts_with($url, 'https://auth.acme.test/token')) {
                $tokenAuth = $request->header('Authorization')[0] ?? null;

                return $tokenAuth === 'Basic '.base64_encode('bot:'.self::PASSWORD) ? Http::response(['token' => 'private-token']) : Http::response([], 401);
            }
            if (str_contains($url, '/v2/runtimes/exec-zig/tags/list')) {
                return $request->hasHeader('Authorization', 'Bearer private-token')
                    ? Http::response(['tags' => ['0.13', '0.14']])
                    : Http::response([], 401, ['WWW-Authenticate' => 'Bearer realm="https://auth.acme.test/token",service="registry.acme.test",scope="repository:runtimes/exec-zig:pull"']);
            }

            return Http::response([], 404);
        });
        $runtime = $this->registerRuntime($this->bashManifest(['id' => 'zig', 'name' => 'Zig']));
        $runtime->forceFill(['source_id' => $this->source->id])->save();
        $manifest = app(ManifestValidator::class)->validate([
            'apiVersion' => 'runr.sh/v1', 'kind' => 'Runtime', 'id' => 'zig', 'name' => 'Zig', 'extensions' => ['zig'],
            'versionDiscovery' => ['repository' => 'registry.acme.test/runtimes/exec-zig', 'image' => 'registry.acme.test/runtimes/exec-zig:{version}'],
            'run' => ['mode' => 'exec', 'command' => ['/app/handler']],
        ], [], true);

        app(RuntimeVersionDiscovery::class)->sync($manifest, app(RuntimeSourceSync::class)->registryClientFor($runtime));

        $this->assertSame('Basic '.base64_encode('bot:'.self::PASSWORD), $tokenAuth);
        $this->assertSame(['0.13', '0.14'], ExecutorImage::query()->where('runtime', 'zig')->where('is_active', true)->orderBy('sort_order')->pluck('version')->all());
    }
}
