<?php

namespace Tests\Unit;

use App\Console\Commands\E2eTestCommand;
use PHPUnit\Framework\TestCase;

/**
 * The AI e2e left the model volume at its default ReadWriteMany on Kind, whose local-path
 * provisioner provisions ReadWriteOnce only: the claim stayed Pending and the mirror Job with it
 * ("snapshot running" for 900 s). On such a default StorageClass the suite sets ReadWriteOnce.
 */
class E2eAiModelStorageTest extends TestCase
{
    private function rwoOnly(?string $provisioner): bool
    {
        return (new \ReflectionMethod(E2eTestCommand::class, 'aiReadWriteOnceOnly'))->invoke(null, $provisioner);
    }

    public function test_local_path_storage_is_read_write_once(): void
    {
        $this->assertTrue($this->rwoOnly('rancher.io/local-path'));
        $this->assertFalse($this->rwoOnly('nfs.csi.k8s.io'));
        $this->assertFalse($this->rwoOnly(null), 'no default StorageClass: the region default stays');
    }
}
