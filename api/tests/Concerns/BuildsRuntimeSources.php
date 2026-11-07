<?php

namespace Tests\Concerns;

use App\Models\RuntimeSource;
use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\Patches\RuntimePatches;
use App\Services\Runtimes\Sources\CatalogPayload;
use App\Services\SupplyChain\CosignVerifier;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Runtime source fixtures (docs/runtimes.md#runtime-sources): signed OCI catalogs in the fake
 * registry of BuildsRuntimePatches (the way `oras push` + `cosign sign --key` leave them), RunR's
 * default catalog built from the bundle, and local Git repositories of runtimes/<id>/runtime.yaml
 * read over file:// (runtime_sources.git_extra_protocols).
 */
trait BuildsRuntimeSources
{
    use BuildsRuntimePatches;

    /** @var list<string> temporary Git repositories to remove */
    protected array $gitRepositories = [];

    protected function setUpRuntimeSources(): void
    {
        $this->makeFeedKeys();
        config([
            'runtime_sources.default.reference' => 'ghcr.io/brf-tech/runr/runtime-catalog:stable',
            'runtime_sources.default.public_key' => $this->feedPublicKey,
            'runtime_sources.default.via_region' => '',
            'runtime_sources.git_extra_protocols' => ['file'],
        ]);
        BuiltinCatalog::flush();
        RuntimePatches::flushSources();
        $this->fakeRegistry();
    }

    protected function tearDownRuntimeSources(): void
    {
        foreach ($this->gitRepositories as $dir) {
            if (str_contains($dir, 'runr-test-repo-') && is_dir($dir)) {
                self::removeDirectory($dir);
            }
        }
        BuiltinCatalog::flush();
        RuntimePatches::flushSources();
    }

    /** The raw manifests of the catalog the API image ships. */
    protected function bundledManifests(): array
    {
        return json_decode(file_get_contents(BuiltinCatalog::bundlePath()), true)['runtimes'];
    }

    /** A catalog payload of raw manifests (path runtimes/<id>/runtime.yaml). */
    protected function catalog(array $manifests, array $patches = [], array $files = []): array
    {
        return [
            'schema' => 1,
            'generated_at' => now()->toIso8601String(),
            'source' => ['repository' => 'https://github.com/example/runtimes', 'revision' => str_repeat('a', 40)],
            'runtimes' => array_map(fn (array $m) => array_filter([
                'path' => "runtimes/{$m['id']}/runtime.yaml",
                'manifest' => $m,
                'files' => $files[$m['id']] ?? null,
            ], fn ($v) => $v !== null), array_values($manifests)),
            'patches' => $patches,
        ];
    }

    /**
     * Publish an OCI artifact of one JSON layer under $repository:$tag in the fake registry,
     * signed with $signingKey (default: the test key; false: unsigned). Returns the manifest digest.
     */
    protected function publishArtifact(string $repository, string $tag, string $mediaType, array $payload, string|false|null $signingKey = null): string
    {
        $repo = '/v2/'.$repository;
        $layer = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $layerDigest = 'sha256:'.hash('sha256', $layer);
        $manifest = json_encode([
            'schemaVersion' => 2,
            'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
            'artifactType' => $mediaType,
            'config' => ['mediaType' => 'application/vnd.oci.empty.v1+json', 'digest' => self::digestOf('{}'), 'size' => 2],
            'layers' => [['mediaType' => $mediaType, 'digest' => $layerDigest, 'size' => strlen($layer)]],
        ]);
        $manifestDigest = 'sha256:'.hash('sha256', $manifest);

        $this->registry["{$repo}/manifests/{$tag}"] = ['status' => 200, 'body' => $manifest, 'headers' => ['Docker-Content-Digest' => $manifestDigest, 'Content-Type' => 'application/vnd.oci.image.manifest.v1+json']];
        $this->registry["{$repo}/manifests/{$manifestDigest}"] = ['status' => 200, 'body' => $manifest, 'headers' => ['Docker-Content-Digest' => $manifestDigest]];
        $this->registry["{$repo}/blobs/{$layerDigest}"] = ['status' => 200, 'body' => $layer, 'headers' => []];

        if ($signingKey !== false) {
            $payloadJson = json_encode([
                'critical' => [
                    'identity' => ['docker-reference' => $repository],
                    'image' => ['docker-manifest-digest' => $manifestDigest],
                    'type' => CosignVerifier::SIGNATURE_TYPE,
                ],
                'optional' => null,
            ]);
            openssl_sign($payloadJson, $signature, openssl_pkey_get_private($signingKey ?? $this->feedPrivateKey), OPENSSL_ALGO_SHA256);
            $payloadDigest = 'sha256:'.hash('sha256', $payloadJson);
            $sigManifest = json_encode([
                'schemaVersion' => 2,
                'mediaType' => 'application/vnd.oci.image.manifest.v1+json',
                'config' => ['mediaType' => 'application/vnd.oci.image.config.v1+json', 'digest' => self::digestOf('cfg'), 'size' => 3],
                'layers' => [[
                    'mediaType' => CosignVerifier::PAYLOAD_TYPE,
                    'digest' => $payloadDigest,
                    'size' => strlen($payloadJson),
                    'annotations' => [CosignVerifier::SIGNATURE_ANNOTATION => base64_encode($signature)],
                ]],
            ]);
            $this->registry["{$repo}/manifests/".CosignVerifier::signatureTag($manifestDigest)] = ['status' => 200, 'body' => $sigManifest, 'headers' => []];
            $this->registry["{$repo}/blobs/{$payloadDigest}"] = ['status' => 200, 'body' => $payloadJson, 'headers' => []];
        }

        return $manifestDigest;
    }

    /** RunR's default catalog (ghcr.io/brf-tech/runr/runtime-catalog:stable) with $manifests. */
    protected function publishDefaultCatalog(array $manifests, string|false|null $signingKey = null): string
    {
        return $this->publishArtifact('brf-tech/runr/runtime-catalog', 'stable', CatalogPayload::MEDIA_TYPE, $this->catalog($manifests), $signingKey);
    }

    /** A new language for the default catalog (what runr-runtimes publishes without a RunR release). */
    protected function elixirManifest(array $override = []): array
    {
        return array_replace_recursive([
            'apiVersion' => 'runr.sh/v1',
            'kind' => 'Runtime',
            'id' => 'elixir',
            'name' => 'Elixir',
            'language' => 'elixir',
            'extensions' => ['ex', 'exs'],
            'versions' => [
                ['version' => '1.18', 'image' => 'ghcr.io/brf-tech/runr/exec-elixir:1.18', 'eol' => '2027-03-31'],
                ['version' => '1.19', 'image' => 'ghcr.io/brf-tech/runr/exec-elixir:1.19', 'default' => true],
            ],
            'template' => ['files' => ['{file}' => "defmodule Function do\n  def handler(_event, _context), do: %{statusCode: 200, body: \"Hello\"}\nend\n"]],
            'run' => ['mode' => 'http', 'command' => ['elixir', '/opt/runr/server.exs'], 'schedule' => 'native'],
        ], $override);
    }

    /** A small exec-mode manifest of a source runtime. */
    protected function luaManifest(string $id = 'lua', array $override = []): array
    {
        return array_replace_recursive([
            'apiVersion' => 'runr.sh/v1',
            'kind' => 'Runtime',
            'id' => $id,
            'name' => ucfirst($id),
            'language' => 'lua',
            'extensions' => ['lua'],
            'versions' => [
                ['version' => '5.3', 'image' => "registry.acme.test/runtimes/{$id}:5.3", 'eol' => '2030-01-01'],
                ['version' => '5.4', 'image' => "registry.acme.test/runtimes/{$id}:5.4", 'default' => true],
            ],
            'template' => ['files' => ['{file}' => "print('hello')\n"]],
            'run' => ['mode' => 'exec', 'input' => 'raw', 'command' => ['lua', '/app/code/{file}']],
        ], $override);
    }

    /** A connected OCI catalog source. */
    protected function ociSource(array $attributes = []): RuntimeSource
    {
        return RuntimeSource::query()->create(array_merge([
            'name' => 'acme-runtimes',
            'kind' => RuntimeSource::KIND_OCI,
            'scope' => RuntimeSource::SCOPE_PLATFORM,
            'url' => 'registry.acme.test/runtimes/catalog:stable',
            'public_key' => $this->feedPublicKey,
        ], $attributes));
    }

    /**
     * A local Git repository with runtimes/<id>/runtime.yaml (and extra files per path), one
     * commit on main. Returns its file:// URL.
     *
     * @param  array<string, string>  $files  path => content
     */
    protected function gitRepository(array $files, ?string $dir = null): string
    {
        $dir ??= sys_get_temp_dir().DIRECTORY_SEPARATOR.'runr-test-repo-'.bin2hex(random_bytes(5));
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
            $this->git($dir, ['init', '-q', '-b', 'main']);
            $this->gitRepositories[] = $dir;
        }
        foreach ($files as $path => $content) {
            $full = $dir.DIRECTORY_SEPARATOR.$path;
            if ($content === null) {
                @unlink($full);

                continue;
            }
            @mkdir(dirname($full), 0777, true);
            file_put_contents($full, $content);
        }
        $this->git($dir, ['add', '-A']);
        $this->git($dir, ['-c', 'user.email=t@example.test', '-c', 'user.name=Test', 'commit', '-q', '-m', 'runtimes', '--allow-empty']);

        return 'file://'.$dir;
    }

    protected function git(string $dir, array $args): string
    {
        $process = new Process(['git', ...$args], $dir, ['HOME' => sys_get_temp_dir(), 'GIT_CONFIG_NOSYSTEM' => '1']);
        $process->mustRun();

        return $process->getOutput();
    }

    protected function yamlOf(array $manifest): string
    {
        return Yaml::dump($manifest, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
}
