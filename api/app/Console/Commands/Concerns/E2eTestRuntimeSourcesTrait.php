<?php

namespace App\Console\Commands\Concerns;

use App\Models\Runtime;
use App\Models\RuntimeSource;
use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\RuntimePullSecrets;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Runtimes\Sources\CatalogPayload;
use App\Services\Runtimes\Sources\RuntimeSources;
use App\Services\Runtimes\Sources\RuntimeSourceSync;
use App\Services\SupplyChain\CosignVerifier;
use App\Services\SupplyChain\ImageRef;
use App\Support\Images\OciRegistryClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * E2E of runtime sources (test:e2e --suite=runtime-sources, runtime/lib/test-runtime-sources.sh).
 * The sources are read in this process (RuntimeSourceSync — validation, clash rules, the
 * signature check and what follows are the real ones); the functions are created, deployed and
 * called through the API and the region like any other.
 *
 *  - rs-default-catalog  RunR's catalog as an install reads it (config defaults:
 *                        ghcr.io/brf-tech/runr/runtime-catalog:stable, the default key): it verifies
 *                        and its runtimes are the built-in ones (mode source)
 *  - rs-oci-catalog      an OCI catalog signed with a throwaway key, answered for
 *                        registry.e2e.runr.test by this process: its exec-mode Bash runtime is
 *                        synced, a function on it deploys and answers; the catalog drops the
 *                        runtime: it is orphaned, the function still answers, a new project on it
 *                        is refused
 *  - rs-git-source       a copy of BRF-Tech/runr-sample-custom-executor's layout
 *                        (examples/runtime-sources/sample-custom-executor) served by git daemon in
 *                        a pod of the cluster (NodePort), connected as a git source: every runtime
 *                        of it is synced and offered; one whose image is published is deployed
 *  - rs-private-image    RUNR_E2E_PRIVATE_IMAGE (an http-mode image on a registry that needs
 *                        RUNR_E2E_PRIVATE_REGISTRY_USER / _PASSWORD; skipped without them): the
 *                        pull Secret lands in the namespace, the pod pulls with it and answers
 *  - rs-eol-refusal      a version whose sunset passes while its function runs: the deploy and the
 *                        save are refused (422), the running function still answers
 */
trait E2eTestRuntimeSourcesTrait
{
    private const RS_HOST = 'registry.e2e.runr.test';

    private const RS_CATALOG = 'registry.e2e.runr.test/e2e/catalog:stable';

    /** The bash image of examples/runtimes/bash on the reqs mirror (loaded into Kind by the suite). */
    private const RS_BASH_IMAGE = 'ghcr.io/brf-tech/runr/reqs/bash:5.2';

    private const RS_BASH = <<<'SH'
#!/usr/bin/env bash
printf 'Hello from a runtime source (%s)' "${RUNR_RUNTIME:-?}"
SH;

    /** @var array<string, array{0: int, 1: string, 2: array<string, string>}> path → fake registry answer */
    private array $rsRegistry = [];

    private string $rsPrivateKey = '';

    private string $rsPublicKey = '';

    private function runRuntimeSourcesSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Runtime sources (RunR catalog, OCI catalog, Git source, private image, EOL refusal) ──');

        $tests = [$this->rsCheck('rs-default-catalog', fn () => $this->rsDefaultCatalog())];

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $this->rsPrivateKey);
        $this->rsPublicKey = openssl_pkey_get_details($key)['key'];
        $this->rsFakeRegistry();

        array_push($tests, ...$this->rsOciCatalog($regionId));
        array_push($tests, ...$this->rsGitSource($regionId));
        array_push($tests, ...$this->rsPrivateImage($regionId));
        array_push($tests, ...$this->rsEolRefusal($regionId));

        return $tests;
    }

    /** RunR's published catalog, read with the configuration an install has. */
    private function rsDefaultCatalog(): bool|string
    {
        $source = app(RuntimeSourceSync::class)->sync(RuntimeSource::default(), true);
        BuiltinCatalog::flush();
        $mode = app(BuiltinCatalog::class)->mode();
        $this->line("    {$source->reference()}: {$source->status}".($source->error ? " ({$source->error})" : '').", {$source->runtimes_count} runtime(s), mode {$mode}");

        if ($source->status !== RuntimeSource::OK || ! $source->signature_verified) {
            return "the published catalog does not verify with the default key: status {$source->status}".($source->error ? ": {$source->error}" : '');
        }
        if ($mode !== BuiltinCatalog::MODE_SOURCE || ! app(BuiltinCatalog::class)->has('python')) {
            return "the catalog is read but not in use (mode {$mode})";
        }

        return true;
    }

    /** @return list<array> */
    private function rsOciCatalog(string $regionId): array
    {
        $this->rsPublish([$this->rsBashManifest('e2e-bash')]);
        $source = null;
        $synced = $this->rsCheck('rs-oci-catalog-sync', function () use (&$source) {
            $source = app(RuntimeSources::class)->create(['name' => 'e2e-oci', 'kind' => 'oci-catalog', 'url' => self::RS_CATALOG, 'public_key' => $this->rsPublicKey], RuntimeSource::SCOPE_PLATFORM, null);
            $source = app(RuntimeSourceSync::class)->sync($source, true);

            return $source->status === RuntimeSource::OK && $source->signature_verified && Runtime::query()->whereKey('e2e-bash')->exists()
                ? true : "status {$source->status} ".json_encode($source->problems).' '.$source->error;
        });
        if ($synced['steps']['verify'] !== true) {
            return [$synced];
        }

        $function = $this->rsFunction('rs-oci-function', 'e2e-bash', $regionId, self::RS_BASH, 'Hello from a runtime source (e2e-bash)');
        $tests = [$synced, $function];
        if ($function['steps']['verify'] !== true) {
            return $tests;
        }

        $tests[] = $this->rsCheck('rs-oci-orphaned', function () use ($source, $function, $regionId) {
            $this->rsPublish([$this->rsBashManifest('e2e-other')]);
            app(RuntimeSourceSync::class)->sync($source->fresh(), true);
            if (Runtime::query()->findOrFail('e2e-bash')->orphaned_at === null) {
                return 'the runtime the catalog dropped is not orphaned';
            }
            $answer = $this->curlFunction((string) $function['slug'], '/');
            if (! is_array($answer) || $answer[0] !== '200') {
                return 'the running function stopped answering: '.json_encode($answer);
            }
            $refused = $this->api()->post('/api/projects', $this->buildProjectPayload('rs-orphan-new', ['service_type' => 'xaas', 'runtime' => 'e2e-bash', 'trigger_type' => 'http'], $regionId));

            return $refused->status() === 422 ? true : 'a new project on the orphaned runtime: HTTP '.$refused->status();
        });

        return $tests;
    }

    /** @return list<array> */
    private function rsGitSource(string $regionId): array
    {
        $dir = rtrim((string) ($this->option('examples') ?: '/tmp/runr-examples'), '/').'/runtime-sources/sample-custom-executor';
        if (! is_dir("{$dir}/runtimes")) {
            return [$this->rsCheck('rs-git-source', fn () => "the sample's layout is not at {$dir} (runtime/lib/test-runtime-sources.sh copies it)")];
        }

        $url = null;
        $served = $this->rsCheck('rs-git-serve', function () use ($dir, &$url) {
            $url = $this->rsServeGit($dir);

            return str_starts_with((string) $url, 'git://') ? true : (string) $url;
        });
        if ($served['steps']['verify'] !== true) {
            return [$served];
        }

        $expected = array_map(fn ($f) => basename(dirname($f)), glob("{$dir}/runtimes/*/runtime.yaml") ?: []);
        $source = null;
        $synced = $this->rsCheck('rs-git-sync', function () use ($url, $expected, &$source) {
            config(['runtime_sources.allow_insecure' => true]);
            $source = app(RuntimeSources::class)->create(['name' => 'e2e-sample', 'kind' => 'git', 'url' => $url, 'ref' => 'main', 'path' => 'runtimes'], RuntimeSource::SCOPE_PLATFORM, null);
            $source = app(RuntimeSourceSync::class)->sync($source, true);
            $this->line("    {$url}: {$source->status} at ".substr((string) $source->revision, 0, 12).", {$source->runtimes_count} runtime(s)");
            if ($source->status !== RuntimeSource::OK) {
                return "status {$source->status}: ".($source->error ?? json_encode($source->problems));
            }
            $listed = array_column((array) $this->api()->get('/api/runtimes')->json('data'), 'id');
            $missing = array_values(array_diff($expected, $listed));

            return $missing === [] ? true : 'not offered: '.implode(', ', $missing);
        });
        $tests = [$served, $synced];
        if ($synced['steps']['verify'] !== true) {
            return $tests;
        }

        // A runtime of the sample whose default image is published: deployed and called
        foreach ($expected as $id) {
            $manifest = app(RuntimeRegistry::class)->manifest($id);
            $default = collect($manifest?->versions() ?? [])->firstWhere('default', true);
            $image = (string) ($default['image'] ?? '');
            // the starter file as a new project gets it: placeholders resolved ({handler} — the
            // manifest's handler pattern, checked on save, wants the handler's name there)
            $template = $manifest ? (string) ($manifest->templateFiles($manifest->defaultFilename(), $manifest->handlerFunction(), false)[$manifest->defaultFilename()] ?? '') : '';
            if ($image === '' || $template === '' || ! $this->rsImageExists($image)) {
                $this->line("    {$id}: {$image} is not published (or not an image version), not deployed");

                continue;
            }
            $tests[] = $this->rsFunction("rs-git-{$id}", $id, $regionId, $template, '');
            break;
        }

        return $tests;
    }

    /** @return list<array> */
    private function rsPrivateImage(string $regionId): array
    {
        // the environment, else the file runtime/lib/test-runtime-sources.sh copies in
        $file = is_file('/tmp/runr-e2e-private.json') ? (array) json_decode((string) file_get_contents('/tmp/runr-e2e-private.json'), true) : [];
        $image = (string) (getenv('RUNR_E2E_PRIVATE_IMAGE') ?: ($file['image'] ?? ''));
        $user = (string) (getenv('RUNR_E2E_PRIVATE_REGISTRY_USER') ?: ($file['username'] ?? ''));
        $password = (string) (getenv('RUNR_E2E_PRIVATE_REGISTRY_PASSWORD') ?: ($file['password'] ?? ''));
        if ($image === '' || $user === '' || $password === '') {
            $this->line('  - rs-private-image: skipped (RUNR_E2E_PRIVATE_IMAGE, RUNR_E2E_PRIVATE_REGISTRY_USER and _PASSWORD not set)');

            return [];
        }

        $host = RuntimePullSecrets::host($image);
        $this->rsPublish([[
            'apiVersion' => 'runr.sh/v1', 'kind' => 'Runtime', 'id' => 'e2e-private', 'name' => 'E2E private', 'extensions' => ['txt'],
            'versions' => [['version' => '1.0', 'image' => $image, 'default' => true]],
            'template' => ['files' => ['{file}' => 'private']],
            'run' => ['mode' => 'http', 'schedule' => 'native'],
        ]], 'e2e/private');

        $source = null;
        $synced = $this->rsCheck('rs-private-sync', function () use ($host, $user, $password, &$source) {
            $source = app(RuntimeSources::class)->create([
                'name' => 'e2e-private', 'kind' => 'oci-catalog', 'url' => self::RS_HOST.'/e2e/private:stable', 'public_key' => $this->rsPublicKey,
                'registry_host' => $host, 'registry_username' => $user, 'registry_password' => $password,
            ], RuntimeSource::SCOPE_PLATFORM, null);
            $source = app(RuntimeSourceSync::class)->sync($source, true);

            return $source->status === RuntimeSource::OK ? true : "status {$source->status}: ".($source->error ?? json_encode($source->problems));
        });
        if ($synced['steps']['verify'] !== true) {
            return [$synced];
        }

        $function = $this->rsFunction('rs-private-function', 'e2e-private', $regionId, 'private', '');
        $tests = [$synced, $function];
        $tests[] = $this->rsCheck('rs-private-secret', function () use ($source, $function, $password) {
            $k8s = $this->getE2eKubernetesClient();
            $secret = $k8s->getResourceStatus('v1', 'Secret', RuntimePullSecrets::secretName($source), (string) $this->getE2eNamespace());
            if (($secret['type'] ?? null) !== 'kubernetes.io/dockerconfigjson') {
                return 'no pull Secret in the namespace';
            }
            $svc = $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', (string) $function['slug'], (string) $this->getE2eNamespace());
            if (! in_array(['name' => RuntimePullSecrets::secretName($source)], (array) ($svc['spec']['template']['spec']['imagePullSecrets'] ?? []), true)) {
                return 'the Knative Service has no imagePullSecrets';
            }

            return str_contains(json_encode($svc), $password) ? 'the password is in the Knative Service' : true;
        });

        return $tests;
    }

    /** @return list<array> */
    private function rsEolRefusal(string $regionId): array
    {
        $manifest = $this->rsBashManifest('e2e-sunset');
        $manifest['versions'][0]['sunset'] = now()->addDays(30)->toDateString();
        $this->rsPublish([$manifest], 'e2e/sunset');
        $source = app(RuntimeSources::class)->create(['name' => 'e2e-sunset', 'kind' => 'oci-catalog', 'url' => self::RS_HOST.'/e2e/sunset:stable', 'public_key' => $this->rsPublicKey], RuntimeSource::SCOPE_PLATFORM, null);
        app(RuntimeSourceSync::class)->sync($source, true);

        $function = $this->rsFunction('rs-sunset-function', 'e2e-sunset', $regionId, self::RS_BASH, 'Hello from a runtime source (e2e-sunset)', '1.0');
        if ($function['steps']['verify'] !== true) {
            return [$function];
        }

        return [$function, $this->rsCheck('rs-eol-refusal', function () use ($manifest, $source, $function) {
            $manifest['versions'][0]['sunset'] = now()->subDay()->toDateString();
            $this->rsPublish([$manifest], 'e2e/sunset');
            app(RuntimeSourceSync::class)->sync($source->fresh(), true);

            $deploy = $this->api()->post("/api/projects/{$function['projectId']}/deploy", ['version_id' => $function['versionId']]);
            if ($deploy->status() !== 422) {
                return 'a deploy after the sunset: HTTP '.$deploy->status();
            }
            $save = $this->api()->post("/api/projects/{$function['projectId']}/code", ['code' => self::RS_BASH]);
            if ($save->status() !== 422) {
                return 'a save after the sunset: HTTP '.$save->status();
            }
            $state = $this->api()->get("/api/projects/{$function['projectId']}")->json('data.runtime_lifecycle.state');
            if ($state !== 'sunset') {
                return "the project's lifecycle state is {$state} (want sunset)";
            }
            $answer = $this->curlFunction((string) $function['slug'], '/');

            return is_array($answer) && $answer[0] === '200' ? true : 'the running function stopped answering: '.json_encode($answer);
        })];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function rsBashManifest(string $id): array
    {
        return [
            'apiVersion' => 'runr.sh/v1', 'kind' => 'Runtime', 'id' => $id, 'name' => "E2E {$id}",
            'language' => 'shell', 'extensions' => ['sh'],
            'versions' => [['version' => '1.0', 'image' => self::RS_BASH_IMAGE, 'default' => true]],
            'template' => ['files' => ['{file}' => self::RS_BASH]],
            'run' => ['mode' => 'exec', 'input' => 'raw', 'command' => ['bash', '/app/code/{file}']],
        ];
    }

    /** A function on $runtime, deployed through the API; verified by calling it ($body: in the answer). */
    private function rsFunction(string $name, string $runtime, string $regionId, string $code, string $body, ?string $version = null): array
    {
        $batch = [$this->makeTestEntry($name, [
            'service_type' => 'xaas', 'runtime' => $runtime, 'runtime_version' => $version, 'trigger_type' => 'http', 'code' => $code,
        ], $regionId)];
        $this->runTestsSequentially($batch);
        $test = $batch[0];
        if ($test['steps']['ready'] !== true) {
            $test['steps']['verify'] = 'skipped';

            return $test;
        }

        $answer = $this->curlFunction((string) $test['slug'], '/');
        $test['steps']['verify'] = is_array($answer) && $answer[0] === '200' && ($body === '' || str_contains((string) $answer[2], $body))
            ? true
            : 'answer: '.mb_substr(json_encode($answer), 0, 300);
        $this->line('  '.($test['steps']['verify'] === true ? '<fg=green>✓</>' : '<fg=red>✗</>')." {$name}: ".($test['steps']['verify'] === true ? 'answers' : $test['steps']['verify']));

        return $test;
    }

    /**
     * Serve the sample layout from git daemon in a pod (its files from a ConfigMap, one commit on
     * main), reachable from this container at a node's address and a NodePort: the git URL, or why
     * not.
     */
    private function rsServeGit(string $dir): string
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = (string) $this->getE2eNamespace();
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = ltrim(substr($file->getPathname(), strlen($dir)), '/');
            if ($file->isFile() && ! str_starts_with($relative, '.git/') && $file->getSize() < 512 * 1024) {
                $files[str_replace('/', '---', $relative)] = (string) file_get_contents($file->getPathname());
            }
        }
        if ($files === []) {
            return "no files in {$dir}";
        }

        $labels = ['app' => 'e2e-git-sample'];
        $k8s->applyResource(['apiVersion' => 'v1', 'kind' => 'ConfigMap', 'metadata' => ['name' => 'e2e-git-sample', 'namespace' => $ns], 'data' => $files], $ns);
        $k8s->deleteResource('v1', 'Pod', 'e2e-git-sample', $ns);
        $script = implode(' && ', [
            'apk add --no-cache git git-daemon >/dev/null',
            'mkdir -p /srv/sample && cd /srv/sample && git init -q -b main',
            'for f in /files/*; do rel=$(basename "$f" | sed "s#---#/#g"); mkdir -p "$(dirname "$rel")"; cp "$f" "$rel"; done',
            'git add -A && git -c user.email=e2e@runr.sh -c user.name=e2e commit -qm sample',
            'exec git daemon --reuseaddr --base-path=/srv --export-all --port=9418 --listen=0.0.0.0 /srv',
        ]);
        $k8s->createResource([
            'apiVersion' => 'v1', 'kind' => 'Pod',
            'metadata' => ['name' => 'e2e-git-sample', 'namespace' => $ns, 'labels' => $labels],
            'spec' => [
                'containers' => [[
                    'name' => 'git', 'image' => 'ghcr.io/brf-tech/runr/reqs/alpine:3.21',
                    'command' => ['sh', '-c', $script],
                    'ports' => [['containerPort' => 9418]],
                    'readinessProbe' => ['tcpSocket' => ['port' => 9418], 'periodSeconds' => 2],
                    'volumeMounts' => [['name' => 'files', 'mountPath' => '/files']],
                ]],
                'volumes' => [['name' => 'files', 'configMap' => ['name' => 'e2e-git-sample']]],
            ],
        ], $ns);
        $k8s->applyResource([
            'apiVersion' => 'v1', 'kind' => 'Service',
            'metadata' => ['name' => 'e2e-git-sample', 'namespace' => $ns],
            'spec' => ['type' => 'NodePort', 'selector' => $labels, 'ports' => [['port' => 9418, 'targetPort' => 9418]]],
        ], $ns);

        $deadline = time() + 180;
        $ready = false;
        while (time() < $deadline && ! $ready) {
            $pod = $k8s->getResourceStatus('v1', 'Pod', 'e2e-git-sample', $ns);
            $ready = collect($pod['status']['conditions'] ?? [])->contains(fn ($c) => ($c['type'] ?? '') === 'Ready' && ($c['status'] ?? '') === 'True');
            $ready || sleep(3);
        }
        if (! $ready) {
            return 'the git daemon pod did not become ready';
        }

        $port = $k8s->getResourceStatus('v1', 'Service', 'e2e-git-sample', $ns)['spec']['ports'][0]['nodePort'] ?? null;
        $nodeIp = null;
        foreach ($k8s->listClusterResources('v1', 'Node')['items'] ?? [] as $node) {
            foreach ($node['status']['addresses'] ?? [] as $address) {
                if (($address['type'] ?? '') === 'InternalIP' && filter_var($address['address'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $nodeIp = (string) $address['address'];
                    break 2;
                }
            }
        }

        return $port && $nodeIp ? "git://{$nodeIp}:{$port}/sample" : 'no node address or NodePort';
    }

    private function rsImageExists(string $image): bool
    {
        try {
            $ref = ImageRef::parse($image);

            return OciRegistryClient::direct(15)->manifestDigest($ref->name(), (string) ($ref->tag ?? 'latest')) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** A catalog of $manifests, signed with the suite's key, answered for registry.e2e.runr.test/$repository:stable. */
    private function rsPublish(array $manifests, string $repository = 'e2e/catalog'): void
    {
        $payload = [
            'schema' => 1, 'generated_at' => now()->toIso8601String(),
            'source' => ['repository' => 'e2e', 'revision' => str_repeat('0', 40)],
            'runtimes' => array_map(fn (array $m) => ['path' => "runtimes/{$m['id']}/runtime.yaml", 'manifest' => $m], $manifests),
            'patches' => [],
        ];
        $layer = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $layerDigest = 'sha256:'.hash('sha256', $layer);
        $manifest = json_encode([
            'schemaVersion' => 2, 'mediaType' => 'application/vnd.oci.image.manifest.v1+json', 'artifactType' => CatalogPayload::MEDIA_TYPE,
            'config' => ['mediaType' => 'application/vnd.oci.empty.v1+json', 'digest' => 'sha256:'.hash('sha256', '{}'), 'size' => 2],
            'layers' => [['mediaType' => CatalogPayload::MEDIA_TYPE, 'digest' => $layerDigest, 'size' => strlen($layer)]],
        ]);
        $digest = 'sha256:'.hash('sha256', $manifest);
        $signed = json_encode(['critical' => [
            'identity' => ['docker-reference' => self::RS_HOST.'/'.$repository],
            'image' => ['docker-manifest-digest' => $digest],
            'type' => CosignVerifier::SIGNATURE_TYPE,
        ], 'optional' => null]);
        openssl_sign($signed, $signature, openssl_pkey_get_private($this->rsPrivateKey), OPENSSL_ALGO_SHA256);
        $payloadDigest = 'sha256:'.hash('sha256', $signed);
        $sig = json_encode([
            'schemaVersion' => 2, 'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
            'config' => ['mediaType' => 'application/vnd.oci.image.config.v1+json', 'digest' => 'sha256:'.hash('sha256', 'c'), 'size' => 1],
            'layers' => [['mediaType' => CosignVerifier::PAYLOAD_TYPE, 'digest' => $payloadDigest, 'size' => strlen($signed),
                'annotations' => [CosignVerifier::SIGNATURE_ANNOTATION => base64_encode($signature)]]],
        ]);

        $path = "/v2/{$repository}/";
        $this->rsRegistry = array_merge($this->rsRegistry, [
            $path.'manifests/stable' => [200, $manifest, ['Docker-Content-Digest' => $digest]],
            $path."manifests/{$digest}" => [200, $manifest, ['Docker-Content-Digest' => $digest]],
            $path."blobs/{$layerDigest}" => [200, $layer, []],
            $path.'manifests/'.CosignVerifier::signatureTag($digest) => [200, $sig, []],
            $path."blobs/{$payloadDigest}" => [200, $signed, []],
        ]);
    }

    /** registry.e2e.runr.test answered from $rsRegistry; every other request goes out. */
    private function rsFakeRegistry(): void
    {
        Http::fake(function (Request $request) {
            if (! str_starts_with($request->url(), 'https://'.self::RS_HOST.'/')) {
                return null;
            }
            [$status, $body, $headers] = $this->rsRegistry[(string) parse_url($request->url(), PHP_URL_PATH)] ?? [404, '{"errors":[{"code":"MANIFEST_UNKNOWN"}]}', []];

            return Http::response($request->method() === 'HEAD' ? '' : $body, $status, $headers);
        });
    }

    private function rsCheck(string $name, callable $check): array
    {
        return $this->admCheck($name, $check);
    }
}
