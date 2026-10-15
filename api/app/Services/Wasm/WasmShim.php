<?php

namespace App\Services\Wasm;

/**
 * A runwasi containerd shim RunR installs on region nodes (WebAssembly support), with the
 * RuntimeClass its pods use. Pure data — no cluster access.
 *
 * - spin:     containerd-shim-spin (spinframework/containerd-shim-spin), runs Spin apps — the shim
 *             the wasm-* runtime plugins deploy on. Embeds the Spin runtime (SPIN_RUNTIME).
 * - wasmtime: containerd-shim-wasmtime (containerd/runwasi), runs plain WASI command modules
 *             and components; optional, for images built outside RunR.
 *
 * Both are runwasi shims: a container whose entrypoint is an ELF binary or a script (#!) runs as
 * a regular Linux container through runwasi's libcontainer executor, everything else as
 * WebAssembly — so Knative's queue-proxy sidecar runs next to the Wasm app under the same
 * pod-level RuntimeClass.
 *
 * Where the binary comes from:
 * - spin:     RunR's build of containerd-shim-spin (runtime/wasm-shim-spin), an image
 *             (ghcr.io/runrsh/containerd-shim-spin) whose /shim the installer pod's init container
 *             copies; the installer checks the binary against the image's SHA256SUMS. The build
 *             gives the Linux containers of a Wasm pod (queue-proxy) the environment of their pod
 *             spec, which the upstream releases since v0.16.0 do not (containerd/runwasi #1061,
 *             fixed by runwasi PR #1183). Back to the upstream release tarballs once a
 *             containerd-shim-spin release contains that fix.
 * - wasmtime: runwasi's release tarballs, pinned by version and sha256 (the digest runwasi
 *             publishes in its SHA256SUMS).
 */
final class WasmShim
{
    public const SPIN = 'spin';

    public const WASMTIME = 'wasmtime';

    /** Shims RunR installs, in display order. */
    public const IDS = [self::SPIN, self::WASMTIME];

    /** Spin runtime version embedded in the pinned spin shim (its spin-trigger crates). */
    public const SPIN_RUNTIME = '4.1.0';

    /** Node label of every node RunR prepared for WebAssembly (any shim). */
    public const NODE_LABEL = 'runr.dev/wasm';

    /** Nodes the WebAssembly installer DaemonSet runs on (the selected nodes). */
    public const TARGET_LABEL = 'runr.dev/wasm-target';

    /** Nodes the cleanup DaemonSet runs on (uninstall, nodes taken out of the selection). */
    public const CLEANUP_LABEL = 'runr.dev/wasm-cleanup';

    private const DEFINITIONS = [
        self::SPIN => [
            'name' => 'Spin',
            // containerd-shim-spin v0.26.0 + runwasi PR #1183 (runtime/wasm-shim-spin)
            'version' => 'v0.26.0-runr.1',
            'binary' => 'containerd-shim-spin-v2',
            'runtime_type' => 'io.containerd.spin.v2',
            'handler' => 'spin-runr',
            // the name the Spin operator (SpinKube) uses for its shim executor
            'runtime_class' => 'wasmtime-spin-v2',
            'image' => 'ghcr.io/runrsh/containerd-shim-spin:v0.26.0-runr.1',
            // the platforms of the image (linux/amd64, linux/arm64) as uname -m
            'arches' => ['x86_64', 'aarch64'],
            'artifacts' => [],
            'description' => 'Spin apps (HTTP components built with the Spin SDKs) — the shim of the wasm-* runtimes',
        ],
        self::WASMTIME => [
            'name' => 'Wasmtime',
            'version' => 'v0.6.1',
            'binary' => 'containerd-shim-wasmtime-v1',
            'runtime_type' => 'io.containerd.wasmtime.v1',
            'handler' => 'wasmtime-runr',
            'runtime_class' => 'wasmtime-v1',
            'artifacts' => [
                'x86_64' => [
                    'url' => 'https://github.com/containerd/runwasi/releases/download/containerd-shim-wasmtime/v0.6.1/containerd-shim-wasmtime-x86_64-linux-musl.tar.gz',
                    'sha256' => 'a9b1215ee670f11414c8fd8e970b52679085929c8ec73a0fe6ed9cf215cf9ba1',
                ],
                'aarch64' => [
                    'url' => 'https://github.com/containerd/runwasi/releases/download/containerd-shim-wasmtime/v0.6.1/containerd-shim-wasmtime-aarch64-linux-musl.tar.gz',
                    'sha256' => '0ae922715dd484a923825fd4fa25052fe00f8769d29c236704f1f5638f735ae0',
                ],
            ],
            'description' => 'WASI command modules and components (wasi:cli/run) packaged as OCI images',
        ],
    ];

    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly string $binary,
        public readonly string $runtimeType,
        public readonly string $handler,
        public readonly string $runtimeClass,
        /** @var array<string, array{url: string, sha256: string}> uname -m → release tarball (empty for an image shim) */
        public readonly array $artifacts,
        public readonly string $description,
        /** The image whose /shim carries the binary (null: release tarballs) */
        public readonly ?string $image = null,
        /** @var list<string> uname -m of the architectures it is published for */
        public readonly array $arches = [],
    ) {}

    public static function get(string $id): self
    {
        $d = self::DEFINITIONS[$id] ?? throw new \InvalidArgumentException("Unknown WebAssembly shim '{$id}' (RunR installs ".implode(', ', self::IDS).')');

        return new self($id, $d['name'], $d['version'], $d['binary'], $d['runtime_type'], $d['handler'], $d['runtime_class'], $d['artifacts'],
            $d['description'], $d['image'] ?? null, $d['arches'] ?? array_keys($d['artifacts']));
    }

    /** @return list<self> */
    public static function all(): array
    {
        return array_map([self::class, 'get'], self::IDS);
    }

    public static function isSupported(string $id): bool
    {
        return isset(self::DEFINITIONS[$id]);
    }

    /** The shim whose RuntimeClass this is, null for any other RuntimeClass. */
    public static function forRuntimeClass(?string $runtimeClass): ?self
    {
        foreach (self::IDS as $id) {
            if (self::DEFINITIONS[$id]['runtime_class'] === $runtimeClass) {
                return self::get($id);
            }
        }

        return null;
    }

    /** @return list<string> every RuntimeClass name RunR creates for WebAssembly */
    public static function runtimeClassNames(): array
    {
        return array_map(fn (string $id) => self::DEFINITIONS[$id]['runtime_class'], self::IDS);
    }

    /** Node label of the nodes where this shim is installed and loaded (RuntimeClass nodeSelector). */
    public function nodeLabel(): string
    {
        return self::NODE_LABEL.'-'.$this->id;
    }

    /** Architectures (kubernetes.io/arch) the shim is published for. */
    public function supportsArch(string $kubernetesArch): bool
    {
        return in_array(self::unameArch($kubernetesArch), $this->arches, true);
    }

    /** kubernetes.io/arch → uname -m (the architecture names of the shims' artifacts). */
    public static function unameArch(string $kubernetesArch): string
    {
        return match ($kubernetesArch) {
            'amd64' => 'x86_64',
            'arm64' => 'aarch64',
            default => $kubernetesArch,
        };
    }

    /**
     * One line per shim for the node scripts (WASM_SHIMS):
     * "id binary handler runtime_type url_x86_64 sha_x86_64 url_aarch64 sha_aarch64" (release
     * tarballs), "id binary handler runtime_type image <image> image <image>" (an image shim:
     * the installer pod's init container copied the image's /shim to WasmNodeScripts::SHIM_FILES/<id>)
     */
    public function scriptLine(): string
    {
        if ($this->image !== null) {
            return implode(' ', [$this->id, $this->binary, $this->handler, $this->runtimeType, 'image', $this->image, 'image', $this->image]);
        }

        return implode(' ', [
            $this->id,
            $this->binary,
            $this->handler,
            $this->runtimeType,
            $this->artifacts['x86_64']['url'],
            $this->artifacts['x86_64']['sha256'],
            $this->artifacts['aarch64']['url'],
            $this->artifacts['aarch64']['sha256'],
        ]);
    }

    /** @return array{id: string, name: string, version: string, runtime_class: string, handler: string, description: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'runtime_class' => $this->runtimeClass,
            'handler' => $this->handler,
            'description' => $this->description,
        ];
    }
}
