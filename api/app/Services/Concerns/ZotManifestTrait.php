<?php

namespace App\Services\Concerns;

use App\Services\Helpers\ContainerRuntimeHelper;
use App\Services\Manifests\ZotManifests;

trait ZotManifestTrait
{
    private function buildZotPvcValues(string $storageClass, string $size, bool $workersOnly = false): array
    {
        return ZotManifests::pvcValues($storageClass, $size, $workersOnly);
    }

    private function buildZotS3Values(array $s3, bool $workersOnly = false): array
    {
        return ZotManifests::s3Values($s3, $workersOnly);
    }

    private function buildTestPvcManifest(string $storageClass, string $size, string $namespace): array
    {
        return ZotManifests::testPvc($storageClass, $size, $namespace);
    }

    private function buildPvcConsumerPodManifest(string $pvcName, string $namespace): array
    {
        return ZotManifests::pvcConsumerPod($pvcName, $namespace);
    }

    private function buildS3TestPodManifest(array $s3, string $namespace): array
    {
        return ZotManifests::s3TestPod($s3, $namespace);
    }

    private function buildRegistryMirrorDaemonSet(
        string $registry,
        string $svcName,
        string $svcNs,
        bool $workersOnly = false,
        string $flavor = ContainerRuntimeHelper::FLAVOR_CONTAINERD,
        bool $restartInPlace = false,
    ): array {
        return ZotManifests::mirrorDaemonSet($registry, $svcName, $svcNs, $workersOnly, $flavor, $restartInPlace);
    }

    private function buildRegistryTestPodManifest(string $url, ?string $user, ?string $pass, string $namespace): array
    {
        return ZotManifests::registryTestPod($url, $user, $pass, $namespace);
    }
}
