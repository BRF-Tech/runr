<?php

namespace Tests\Support;

use App\Models\KnativeRegion;
use App\Services\OrgTransfer\Region\RegionTransfer;
use App\Services\OrgTransfer\Region\RegionTransferException;
use App\Services\OrgTransfer\Region\TransferHandle;
use App\Services\OrgTransfer\Region\TransferTool;

/**
 * RegionTransfer without a cluster (org export / import tests): a sender "produces" the bytes in
 * $produce (per purpose prefix, else a default), a receiver keeps what was uploaded in $received;
 * $failPurpose makes a transfer of that purpose fail like a tool that wrote /work/.failed.
 */
class FakeRegionTransfer extends RegionTransfer
{
    /** @var list<array{mode: string, region: string, namespace: string, purpose: string, tool: ?TransferTool, pull: ?string, files: array}> */
    public array $started = [];

    /** @var array<string, string> purpose prefix => bytes a sender serves */
    public array $produce = [];

    /** @var array<string, string> "<purpose>/<file>" => bytes uploaded */
    public array $received = [];

    /** @var array<string, array<string, mixed>> purpose prefix => the agent's result (the tool's .done JSON) */
    public array $results = [];

    /** @var list<array{purpose: string, name: string, held: int}> */
    public array $downloads = [];

    public ?string $failPurpose = null;

    public int $stopped = 0;

    /** @var array<string, array{purpose: string, mode: string}> */
    private array $handles = [];

    public function startSender(KnativeRegion $region, string $namespace, string $purpose, ?TransferTool $tool, ?string $pull = null): TransferHandle
    {
        return $this->begin('send', $region, $namespace, $purpose, $tool, $pull, []);
    }

    public function startReceiver(KnativeRegion $region, string $namespace, string $purpose, TransferTool $tool, array $files): TransferHandle
    {
        return $this->begin('receive', $region, $namespace, $purpose, $tool, null, $files);
    }

    public function waitFor(TransferHandle $handle, array $states, ?callable $tick = null, ?int $timeout = null): array
    {
        $info = $this->handles[$handle->name];
        if ($this->failPurpose !== null && str_starts_with($info['purpose'], $this->failPurpose)) {
            throw new RegionTransferException("Transfer {$handle->name} failed: tool failed");
        }

        $bytes = $this->bytesFor($info['purpose']);
        // A sender serves its bytes under every name a tool of the tests writes (dumps, a package)
        $names = str_starts_with($info['purpose'], 'image-') ? ['image.tar']
            : ($info['files'] !== [] ? [$this->fileOf($info)] : ['data.dump', 'data.kvdump', 'data.tar', 'package.tar.zst']);

        return [
            'state' => in_array('done', $states, true) ? 'done' : $states[0],
            'files' => array_map(fn (string $file) => ['name' => $file, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'received' => 0], $names),
            'result' => str_starts_with($info['purpose'], 'image-') ? ['digest' => 'sha256:'.str_repeat('d', 64), 'platforms' => ['linux/amd64']] : $this->resultFor($info['purpose']),
        ];
    }

    /** Downloads as RegionTransfer does them: resumed after the bytes $target holds ($held). */
    public function download(TransferHandle $handle, string $name, int $size, string $target, ?callable $progress = null): void
    {
        $purpose = $this->handles[$handle->name]['purpose'];
        $bytes = $this->bytesFor($purpose);
        clearstatcache(true, $target);
        $held = is_file($target) ? (int) filesize($target) : 0;
        if ($held > strlen($bytes)) {
            $held = 0;
        }
        $this->downloads[] = ['purpose' => $purpose, 'name' => $name, 'held' => $held];
        file_put_contents($target, substr($bytes, $held), $held > 0 ? FILE_APPEND : 0);
        if ($progress) {
            $progress(strlen($bytes), strlen($bytes));
        }
    }

    public function upload(TransferHandle $handle, string $name, string $source, ?callable $progress = null): void
    {
        $this->received[$this->handles[$handle->name]['purpose'].'/'.$name] = (string) file_get_contents($source);
    }

    public function stop(TransferHandle $handle): void
    {
        $this->stopped++;
    }

    private function begin(string $mode, KnativeRegion $region, string $namespace, string $purpose, ?TransferTool $tool, ?string $pull, array $files): TransferHandle
    {
        $name = 'runr-xfer-'.count($this->started);
        $this->started[] = ['mode' => $mode, 'region' => (string) $region->id, 'namespace' => $namespace, 'purpose' => $purpose, 'tool' => $tool, 'pull' => $pull, 'files' => $files];
        $this->handles[$name] = ['purpose' => $purpose, 'mode' => $mode, 'files' => $files];

        return new TransferHandle($region, $namespace, $name, 'token', true);
    }

    private function bytesFor(string $purpose): string
    {
        foreach ($this->produce as $prefix => $bytes) {
            if (str_starts_with($purpose, $prefix)) {
                return $bytes;
            }
        }

        return 'bytes of '.$purpose;
    }

    /** @return array<string, mixed> */
    private function resultFor(string $purpose): array
    {
        foreach ($this->results as $prefix => $result) {
            if (str_starts_with($purpose, $prefix)) {
                return $result;
            }
        }

        return [];
    }

    private function fileOf(array $info): string
    {
        return $info['files'] !== [] ? (string) array_key_first($info['files']) : 'data.dump';
    }
}
