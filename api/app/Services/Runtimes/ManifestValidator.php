<?php

namespace App\Services\Runtimes;

use App\Services\Images\ImageName;
use App\Services\Wasm\WasmShim;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses and validates a runtime manifest: YAML or JSON text → JSON Schema
 * (resources/runtimes/runtime.schema.json) → rules a schema cannot express → normalized
 * manifest with every default filled in.
 */
class ManifestValidator
{
    /** Ids a runtime can not take: CaaS uses "docker" as its icon key, the rest are API words. */
    public const RESERVED_IDS = ['docker', 'caas', 'custom', 'runtime', 'runtimes', 'validate'];

    private static ?array $schema = null;

    public static function schemaPath(): string
    {
        return resource_path('runtimes/runtime.schema.json');
    }

    public static function schema(): array
    {
        return self::$schema ??= json_decode(file_get_contents(self::schemaPath()), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, string>  $files  files uploaded with the runtime (dockerfile sources)
     * @param  bool  $builtin  a catalog manifest — the built-in catalog or a runtime source
     *                         (RuntimeSourceSync): versionDiscovery and @path build files allowed
     *
     * @throws InvalidManifestException
     */
    public function parse(string $text, array $files = [], bool $builtin = false): RuntimeManifest
    {
        return $this->validate($this->decode($text), $files, $builtin);
    }

    /**
     * @throws InvalidManifestException
     */
    public function decode(string $text): array
    {
        if (trim($text) === '') {
            throw new InvalidManifestException([['path' => '/', 'message' => 'manifest is empty']]);
        }

        try {
            $data = Yaml::parse($text, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            throw new InvalidManifestException([['path' => '/', 'message' => 'is not valid YAML or JSON: '.$e->getMessage()]]);
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidManifestException([['path' => '/', 'message' => 'must be a mapping (runtime.yaml)']]);
        }

        return $data;
    }

    /**
     * @param  array<string, string>  $files
     *
     * @throws InvalidManifestException
     */
    public function validate(array $data, array $files = [], bool $builtin = false): RuntimeManifest
    {
        $problems = (new JsonSchemaValidator(self::schema()))->validate($data);

        if ($problems === []) {
            $problems = $this->semanticProblems($data, $files, $builtin);
        }

        if ($problems !== []) {
            throw new InvalidManifestException($problems);
        }

        return new RuntimeManifest($this->normalize($data));
    }

    /**
     * Rules beyond the schema: version sources, run mode requirements, regex validity.
     *
     * @return list<array{path: string, message: string}>
     */
    private function semanticProblems(array $data, array $files, bool $builtin): array
    {
        $problems = [];
        $add = function (string $path, string $message) use (&$problems) {
            $problems[] = ['path' => $path, 'message' => $message];
        };

        if (in_array($data['id'], self::RESERVED_IDS, true)) {
            $add('/id', 'is reserved');
        }

        if (isset($data['versionDiscovery']) && ! $builtin) {
            $add('/versionDiscovery', 'is only available to catalogs (the built-in catalog, runtime sources); list versions instead');
        }
        if (! isset($data['versions']) && ! isset($data['versionDiscovery'])) {
            $add('/versions', 'is required');
        }

        $seen = [];
        $defaults = 0;
        $uploads = false;
        foreach ($data['versions'] ?? [] as $i => $version) {
            $path = "/versions/{$i}";
            $sources = array_values(array_filter(['image', 'dockerfile', 'git', 'upload'], fn ($k) => isset($version[$k])));

            if (count($sources) !== 1) {
                $add($path, 'needs exactly one of image, dockerfile, git or upload');
            }
            if (isset($version['upload']) && ImageName::reference((string) $version['upload']) === null) {
                $add("{$path}/upload", 'is not a reference to an uploaded image (repository[:tag], repository@sha256:<digest> or the upload id)');
            }
            $uploads = $uploads || isset($version['upload']);
            if (isset($version['buildArgs']) && ! isset($version['dockerfile']) && ! isset($version['git'])) {
                $add("{$path}/buildArgs", 'only applies to a dockerfile or git version');
            }
            if (isset($version['dockerfile']) && ! array_key_exists($version['dockerfile'], $files)) {
                $add("{$path}/dockerfile", "names {$version['dockerfile']}, which is not among the uploaded files");
            }
            if (isset($seen[$version['version']])) {
                $add("{$path}/version", 'is listed twice');
            }
            $seen[$version['version']] = true;
            $defaults += ! empty($version['default']) ? 1 : 0;
            $this->lifecycleProblems($version, $path, $add);
            // a version's own build files replace the runtime's: they need its build (build.command)
            if (isset($version['build']) && ! isset($data['build'])) {
                $add("{$path}/build", 'only applies to a runtime with a build (build.command)');
            }
        }
        foreach ($data['versionDiscovery']['lifecycle'] ?? [] as $label => $lifecycle) {
            $this->lifecycleProblems((array) $lifecycle, '/versionDiscovery/lifecycle/'.$label, $add);
        }
        $this->lifecycleProblems($data, '', $add);
        if (isset($data['replacement']) && $data['replacement'] === $data['id']) {
            $add('/replacement', 'names the runtime itself');
        }
        if ($defaults > 1) {
            $add('/versions', 'has more than one default version');
        }

        $run = $data['run'];
        $mode = $run['mode'];
        $triggers = $data['triggers'] ?? RuntimeManifest::TRIGGERS;

        if (in_array($mode, ['exec', 'persistent'], true) && empty($run['command'])) {
            $add('/run/command', "is required in {$mode} mode");
        }
        if ($mode === 'http' && in_array('schedule', $triggers, true)
            && ($run['schedule'] ?? 'invoke') === 'invoke' && empty($run['command'])) {
            $add('/run/command', 'is required for the schedule trigger of an http runtime (the wrapper starts the server); or set run.schedule: native');
        }
        if (isset($run['schedule']) && $mode !== 'http') {
            $add('/run/schedule', 'only applies to http mode');
        }
        if (isset($run['input']) && $mode !== 'exec') {
            $add('/run/input', 'only applies to exec mode');
        }
        if (isset($run['workers']) && $mode !== 'persistent') {
            $add('/run/workers', 'only applies to persistent mode');
        }

        if (isset($data['handler']['pattern'])) {
            $regex = '~'.str_replace(['~', '{handler}'], ['\~', 'handler'], $data['handler']['pattern']).'~';
            if (@preg_match($regex, '') === false) {
                $add('/handler/pattern', 'is not a valid regular expression');
            }
        }

        $handlerFile = ($data['handler']['file'] ?? 'main').'.'.$data['extensions'][0];
        if (isset($data['dependencies']['file']) && $data['dependencies']['file'] === $handlerFile) {
            $add('/dependencies/file', 'must differ from the handler file');
        }

        // WebAssembly: the WAGI executor is Spin's; a Wasm runtime with uploaded versions builds in
        // its own toolchain image (the version image is the uploaded module, docs/wasm.md#wasm-exec)
        $spinClass = WasmShim::get(WasmShim::SPIN)->runtimeClass;
        if (isset($data['wagi']) && ($data['runtimeClass'] ?? null) !== $spinClass) {
            $add('/wagi', "needs runtimeClass {$spinClass}: the WAGI executor is part of the Spin shim");
        }
        if ($uploads && WasmShim::forRuntimeClass($data['runtimeClass'] ?? null) !== null
            && (! isset($data['build']['image']) || str_contains((string) $data['build']['image'], '{image}'))) {
            $add('/build/image', 'is required for a WebAssembly runtime with upload versions: the build runs in this toolchain image and gets the uploaded module as RUNR_WASM_MODULE');
        }

        $buildFiles = ['/build/files' => $data['build']['files'] ?? []];
        foreach ($data['versions'] ?? [] as $i => $version) {
            $buildFiles["/versions/{$i}/build/files"] = $version['build']['files'] ?? [];
        }
        foreach ($buildFiles as $path => $entries) {
            foreach ($entries as $name => $content) {
                foreach ((array) $content as $variant) {
                    if (! $builtin && str_starts_with((string) $variant, '@')) {
                        $add("{$path}/{$name}", 'file references (@path) are only resolved in catalogs (the built-in catalog, runtime sources)');
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * Lifecycle dates of the runtime ($path '') or a version: a sunset before the deprecation
     * (or before the end of life it would bring forward needlessly) is a mistake worth naming.
     *
     * @param  callable(string, string): void  $add
     */
    private function lifecycleProblems(array $item, string $path, callable $add): void
    {
        $deprecated = $item['deprecated'] ?? null;
        $sunset = $item['sunset'] ?? null;
        if (is_string($deprecated) && is_string($sunset) && $sunset < $deprecated) {
            $add("{$path}/sunset", "is before the deprecation ({$deprecated})");
        }
    }

    private function normalize(array $data): array
    {
        $versions = [];
        foreach ($data['versions'] ?? [] as $v) {
            $versions[] = [
                'version' => $v['version'],
                'image' => $v['image'] ?? null,
                'dockerfile' => $v['dockerfile'] ?? null,
                'upload' => $v['upload'] ?? null,
                'git' => isset($v['git']) ? $v['git'] + ['ref' => null, 'path' => '', 'dockerfile' => 'Dockerfile'] : null,
                'buildArgs' => $v['buildArgs'] ?? [],
                'default' => (bool) ($v['default'] ?? false),
                'eol' => $v['eol'] ?? null,
                'gpu' => (bool) ($v['gpu'] ?? false),
            ] + array_filter([
                'deprecated' => $v['deprecated'] ?? null,
                'sunset' => $v['sunset'] ?? null,
                // a version's own starter files and build files (an SDK whose code differs by version)
                'template' => $v['template'] ?? null,
                'build' => $v['build'] ?? null,
            ], fn ($x) => $x !== null);
        }
        if ($versions && ! in_array(true, array_column($versions, 'default'), true)) {
            $versions[array_key_last($versions)]['default'] = true;
        }

        $mode = $data['run']['mode'];
        $run = [
            'mode' => $mode,
            'command' => $data['run']['command'] ?? null,
            'schedule' => $data['run']['schedule'] ?? ($mode === 'http' ? 'invoke' : null),
            'input' => $data['run']['input'] ?? ($mode === 'exec' ? 'json' : null),
            'workers' => $data['run']['workers'] ?? ($mode === 'persistent' ? 1 : null),
            'env' => $data['run']['env'] ?? [],
        ];

        $normalized = [
            'apiVersion' => $data['apiVersion'],
            'kind' => $data['kind'],
            'id' => $data['id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'language' => $data['language'] ?? 'plaintext',
            'extensions' => array_values($data['extensions']),
            'category' => $data['category'] ?? 'general',
            'handler' => [
                'file' => $data['handler']['file'] ?? 'main',
                'function' => $data['handler']['function'] ?? 'handler',
                'pattern' => $data['handler']['pattern'] ?? null,
            ],
            'template' => ['files' => $data['template']['files'] ?? ['{file}' => '']],
            'run' => $run,
            'triggers' => array_values($data['triggers'] ?? RuntimeManifest::TRIGGERS),
            'sandbox' => array_values($data['sandbox'] ?? RuntimeManifest::SANDBOXES),
            'resources' => $data['resources'] ?? [],
            'runtimeClass' => $data['runtimeClass'] ?? null,
        ];

        // Lifecycle (docs/runtimes.md#lifecycle): deprecated (a date or true), sunset, replacement, notice
        foreach (['deprecated', 'sunset', 'replacement', 'notice'] as $key) {
            if (isset($data[$key])) {
                $normalized[$key] = $data[$key];
            }
        }

        if (isset($data['wagi'])) {
            $normalized['wagi'] = [
                'argv' => $data['wagi']['argv'] ?? '${SCRIPT_NAME} ${ARGS}',
                'files' => (bool) ($data['wagi']['files'] ?? false),
                'environment' => $data['wagi']['environment'] ?? [],
            ];
        }

        if (isset($normalized['resources']['gpu'])) {
            $normalized['resources']['gpu'] += ['resource' => 'nvidia.com/gpu'];
        }

        if ($versions) {
            $normalized['versions'] = $versions;
        }
        if (isset($data['versionDiscovery'])) {
            $normalized['versionDiscovery'] = $data['versionDiscovery'] + ['eol' => null, 'filter' => null];
        }
        if (isset($data['dependencies'])) {
            $normalized['dependencies'] = $data['dependencies'] + ['template' => null, 'image' => '{image}', 'copy' => [], 'env' => []];
        }
        if (isset($data['build'])) {
            $normalized['build'] = $data['build'] + ['image' => '{image}', 'runImage' => '{image}', 'source' => true, 'files' => []];
        }

        return $normalized;
    }
}
