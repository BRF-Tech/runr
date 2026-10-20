<?php

namespace App\Services\Runtimes;

/**
 * A validated, normalized runtime manifest (runtime.yaml, apiVersion runr.sh/v1) — every
 * optional field carries its default (ManifestValidator::normalize()). The single description
 * of a language runtime: built-ins (runtimes/<id>/runtime.yaml of BRF-Tech/runr-runtimes),
 * platform runtimes and tenant custom runtimes all reach the build and deploy code through this
 * class.
 */
final class RuntimeManifest
{
    public const TRIGGERS = ['http', 'schedule', 'queue'];

    public const SANDBOXES = ['runc', 'gvisor', 'kata'];

    /** Where the wrapper sits in a function container (init container target / baked path). */
    public const WRAPPER_PATH = '/runr/runr-wrapper';

    public function __construct(private readonly array $data) {}

    /**
     * The runtime trigger a project trigger runs as: `workflow` projects (durable workflows,
     * docs/workflows.md) are served over HTTP, so they need — and run in — the http trigger.
     */
    public static function runtimeTrigger(string $trigger): string
    {
        return $trigger === 'workflow' ? 'http' : $trigger;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function id(): string
    {
        return $this->data['id'];
    }

    public function name(): string
    {
        return $this->data['name'];
    }

    public function description(): ?string
    {
        return $this->data['description'] ?? null;
    }

    public function icon(): ?string
    {
        return $this->data['icon'] ?? null;
    }

    public function language(): string
    {
        return $this->data['language'];
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return $this->data['extensions'];
    }

    /** Extension of the handler file. */
    public function extension(): string
    {
        return $this->data['extensions'][0];
    }

    public function category(): string
    {
        return $this->data['category'];
    }

    /**
     * @return list<array{version: string, image: ?string, dockerfile: ?string, upload: ?string, git: ?array, buildArgs: array, default: bool, eol: ?string, gpu: bool}>
     */
    public function versions(): array
    {
        return $this->data['versions'] ?? [];
    }

    public function version(string $version): ?array
    {
        foreach ($this->versions() as $v) {
            if ($v['version'] === $version) {
                return $v;
            }
        }

        return null;
    }

    public function versionDiscovery(): ?array
    {
        return $this->data['versionDiscovery'] ?? null;
    }

    public function handlerFile(): string
    {
        return $this->data['handler']['file'];
    }

    public function handlerFunction(): string
    {
        return $this->data['handler']['function'];
    }

    /** Default handler file name, e.g. main.py. */
    public function defaultFilename(): string
    {
        return $this->handlerFile().'.'.$this->extension();
    }

    /** Regex the handler file must match on save (null = no check), {handler} resolved. */
    public function handlerPattern(?string $handler = null): ?string
    {
        $pattern = $this->data['handler']['pattern'] ?? null;
        if ($pattern === null) {
            return null;
        }

        return '~'.str_replace(['~', '{handler}'], ['\~', preg_quote($handler ?? $this->handlerFunction(), '~')], $pattern).'~';
    }

    /**
     * Starter files for a project: the template with placeholders resolved, "{file}" named after
     * the handler file.
     *
     * @return array<string, string>
     */
    public function templateFiles(string $filename, string $handler, bool $withDependencies): array
    {
        $values = ['file' => $filename, 'handler' => $handler];
        $files = [];

        foreach ($this->data['template']['files'] as $name => $content) {
            $files[Placeholders::render((string) $name, $values)] = Placeholders::render($content, $values);
        }

        $deps = $this->dependencies();
        if ($withDependencies && $deps && isset($deps['template'])) {
            $files[$deps['file']] = $deps['template'];
        }

        return $files;
    }

    public function dependencies(): ?array
    {
        return $this->data['dependencies'] ?? null;
    }

    public function dependencyFile(): ?string
    {
        return $this->data['dependencies']['file'] ?? null;
    }

    public function build(): ?array
    {
        return $this->data['build'] ?? null;
    }

    /** Every version of the function is compiled into an image (build step). */
    public function isCompiled(): bool
    {
        return isset($this->data['build']);
    }

    /** Whether a function with these files is built into an image (compile step or dependencies). */
    public function needsBuild(array $files): bool
    {
        if ($this->isCompiled()) {
            return true;
        }

        $depFile = $this->dependencyFile();

        return $depFile !== null && array_key_exists($depFile, $files);
    }

    public function runMode(): string
    {
        return $this->data['run']['mode'];
    }

    /** @return list<string>|null */
    public function runCommand(): ?array
    {
        return $this->data['run']['command'] ?? null;
    }

    /** http mode: native | invoke */
    public function scheduleMode(): string
    {
        return $this->data['run']['schedule'];
    }

    public function input(): string
    {
        return $this->data['run']['input'];
    }

    public function workers(): int
    {
        return $this->data['run']['workers'];
    }

    /** @return array<string, string> */
    public function runEnv(): array
    {
        return $this->data['run']['env'];
    }

    /** @return list<string> */
    public function triggers(): array
    {
        return $this->data['triggers'];
    }

    public function supportsTrigger(string $trigger): bool
    {
        return in_array($trigger, $this->triggers(), true);
    }

    /** @return list<string> */
    public function sandbox(): array
    {
        return $this->data['sandbox'];
    }

    /**
     * Whether the runtime runs under a pod RuntimeClass: null / runc = runc, gvisor, kata-* = kata.
     */
    public function supportsRuntimeClass(?string $runtimeClass): bool
    {
        $kind = match (true) {
            $runtimeClass === null, $runtimeClass === '', $runtimeClass === 'runc' => 'runc',
            $runtimeClass === 'gvisor' => 'gvisor',
            str_starts_with($runtimeClass, 'kata') => 'kata',
            default => $runtimeClass,
        };

        return in_array($kind, $this->sandbox(), true);
    }

    /** @return array{cpu?: string, memory_mb?: int, gpu?: array{count: int, resource: string}} */
    public function resources(): array
    {
        return $this->data['resources'] ?? [];
    }

    /** GPU limit of the function container ({count, resource}), null without one. */
    public function gpu(): ?array
    {
        return $this->data['resources']['gpu'] ?? null;
    }

    /** RuntimeClass the function pods use instead of the region's sandbox mapping. */
    public function runtimeClass(): ?string
    {
        return $this->data['runtimeClass'] ?? null;
    }

    /**
     * Spin's WAGI executor (docs/wasm.md#wasm-exec): RunR generates the spin.toml for the module
     * the build produces. {argv, files, environment}, null for every other runtime.
     *
     * @return array{argv: string, files: bool, environment: array<string, string>}|null
     */
    public function wagi(): ?array
    {
        return $this->data['wagi'] ?? null;
    }

    /** Versions whose image is an uploaded image (`upload:`, docs/images.md). */
    public function uploadVersions(): array
    {
        return array_values(array_filter($this->versions(), fn ($v) => ($v['upload'] ?? null) !== null));
    }

    /**
     * The universal wrapper is PID 1 for exec / persistent runtimes, and for an http runtime's
     * schedule trigger in invoke mode (it starts the server and sends it one event).
     */
    public function needsWrapper(string $trigger): bool
    {
        return match ($this->runMode()) {
            'exec', 'persistent' => true,
            default => $trigger === 'schedule' && $this->scheduleMode() === 'invoke',
        };
    }

    /** Whether any trigger of this runtime runs through the wrapper (built images bake it in). */
    public function usesWrapper(): bool
    {
        foreach ($this->triggers() as $trigger) {
            if ($this->needsWrapper($trigger)) {
                return true;
            }
        }

        return false;
    }

    /** RUNR_WRAPPER_MODE of the wrapper. */
    public function wrapperMode(): string
    {
        return $this->runMode() === 'http' ? 'http' : $this->runMode();
    }

    /**
     * The run command with placeholders resolved.
     *
     * @return list<string>|null
     */
    public function renderedCommand(array $values): ?array
    {
        $command = $this->runCommand();

        return $command === null ? null : array_map(fn ($part) => Placeholders::render($part, $values), $command);
    }

    /** sha256 of the normalized manifest. */
    public function digest(): string
    {
        return hash('sha256', json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
