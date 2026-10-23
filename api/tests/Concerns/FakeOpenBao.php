<?php

namespace Tests\Concerns;

use App\Services\Secrets\OpenBao\OpenBaoClient;

/**
 * An in-memory OpenBao of one cluster (see FakesOpenBao): KV v2 under the RunR mount, other paths
 * as written, policies, and every write in order. $unreachable makes every call fail like a server
 * that does not answer.
 */
class FakeOpenBao extends OpenBaoClient
{
    /** @var array<string, array<string, string>> KV path => keys */
    public array $kv = [];

    /** @var array<string, array> other paths => what was written last */
    public array $paths = [];

    /** @var array<string, string> policy => HCL */
    public array $policies = [];

    /** @var list<array{0: string, 1: string}> [verb, path] in order */
    public array $log = [];

    public bool $unreachable = false;

    public bool $initialized = true;

    public bool $sealed = false;

    public function __construct()
    {
        parent::__construct('', 'fake');
    }

    public function health(): ?array
    {
        return $this->unreachable ? null : ['initialized' => $this->initialized, 'sealed' => $this->sealed, 'version' => '2.4.1'];
    }

    public function init(): array
    {
        $this->down();
        $this->initialized = true;
        $this->log[] = ['init', ''];

        return ['key' => 'fake-unseal-key', 'root' => 'fake-root-token'];
    }

    public function unseal(string $key): bool
    {
        $this->down();
        $this->sealed = false;
        $this->log[] = ['unseal', ''];

        return true;
    }

    public function withToken(string $token): OpenBaoClient
    {
        return $this;
    }

    public function login(): string
    {
        $this->down();

        return 'fake-token';
    }

    public function read(string $path): ?array
    {
        $this->down();

        return $this->paths[$path] ?? null;
    }

    public function write(string $path, array $body = []): array
    {
        $this->down();
        $this->log[] = ['write', $path];
        $this->paths[$path] = $body;

        return [];
    }

    public function delete(string $path): void
    {
        $this->down();
        $this->log[] = ['delete', $path];
        unset($this->paths[$path]);
    }

    public function kvRead(string $path): ?array
    {
        $this->down();

        return $this->kv[$path] ?? null;
    }

    public function kvPatch(string $path, array $data): void
    {
        $this->down();
        $this->log[] = ['kv', $path];
        foreach ($data as $key => $value) {
            if ($value === null) {
                unset($this->kv[$path][$key]);
            } else {
                $this->kv[$path][$key] = (string) $value;
            }
        }
    }

    public function kvDestroy(string $path): void
    {
        $this->down();
        $this->log[] = ['destroy', $path];
        unset($this->kv[$path]);
    }

    public function putPolicy(string $name, string $hcl): void
    {
        $this->down();
        $this->log[] = ['policy', $name];
        $this->policies[$name] = $hcl;
    }

    public function deletePolicy(string $name): void
    {
        $this->down();
        $this->log[] = ['delete-policy', $name];
        unset($this->policies[$name]);
    }

    /** @return list<string> paths written, in order */
    public function written(): array
    {
        return array_values(array_map(fn ($e) => $e[1], array_filter($this->log, fn ($e) => $e[0] === 'write')));
    }

    private function down(): void
    {
        if ($this->unreachable) {
            throw new \RuntimeException('OpenBao of region fake: no answer');
        }
    }
}
