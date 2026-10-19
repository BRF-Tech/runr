<?php

namespace Tests\Unit;

use App\Services\Concerns\KnativePrometheusTrait;
use App\Services\HelmService;
use App\Services\Helpers\KnativeVersionHelper;
use App\Services\Helpers\ManagedLoki;
use Tests\TestCase;

/**
 * The Knative Operator chart RunR installs when the chart repository cannot be read: the pinned
 * chart of the newest Knative minor the cluster's Kubernetes version supports — one fallback
 * chart (1.20.1) needed Kubernetes 1.32 while RunR accepts 1.30. Prometheus and Loki are pinned
 * to the chart versions installs resolved and were tested with.
 */
class KnativeVersionFallbackTest extends TestCase
{
    /** @param list<array{version: string, appVersion?: string}> $entries */
    private function helm(array $entries): HelmService
    {
        return new class($entries) extends HelmService
        {
            public function __construct(private readonly array $entries) {}

            public function getRepoChartEntries(string $repoName, string $chartName): array
            {
                return $this->entries;
            }
        };
    }

    public function test_without_the_repository_the_fallback_follows_the_cluster_version(): void
    {
        $offline = $this->helm([]);

        $this->assertSame(['chart' => '1.17.8', 'app' => '1.17.0'], KnativeVersionHelper::resolveVersionPair(30, $offline), 'the minimum Kubernetes version');
        $this->assertSame(['chart' => '1.18.3', 'app' => '1.18.0'], KnativeVersionHelper::resolveVersionPair(31, $offline));
        $this->assertSame(['chart' => '1.20.2', 'app' => '1.20.0'], KnativeVersionHelper::resolveVersionPair(32, $offline));
        $this->assertSame(['chart' => '1.21.4', 'app' => '1.21.0'], KnativeVersionHelper::resolveVersionPair(35, $offline));
        $this->assertSame(['chart' => '1.17.8', 'app' => '1.17.0'], KnativeVersionHelper::resolveVersionPair(null, $offline), 'an unknown version counts as the minimum');
        $this->assertSame(['chart' => '1.17.8', 'app' => '1.17.0'], KnativeVersionHelper::fallbackPair(27), 'below every pinned minor: the oldest');
    }

    public function test_every_pinned_minor_is_in_the_compatibility_table(): void
    {
        foreach (array_keys(KnativeVersionHelper::FALLBACK_CHART_VERSIONS) as $minor) {
            $this->assertArrayHasKey((int) explode('.', $minor)[1], KnativeVersionHelper::KNATIVE_K8S_COMPAT, $minor);
            $this->assertStringStartsWith($minor.'.', KnativeVersionHelper::FALLBACK_CHART_VERSIONS[$minor]);
        }
        $minimum = (int) config('knative.min_k8s_minor', 30);
        $this->assertNotEmpty(array_filter(
            array_keys(KnativeVersionHelper::FALLBACK_CHART_VERSIONS),
            fn ($minor) => KnativeVersionHelper::KNATIVE_K8S_COMPAT[(int) explode('.', $minor)[1]] <= $minimum,
        ), 'a pinned chart for the minimum Kubernetes version');
    }

    public function test_the_repository_wins_when_it_answers(): void
    {
        $helm = $this->helm([
            ['version' => 'v1.20.3', 'appVersion' => '1.20.3'],
            ['version' => 'v1.19.7', 'appVersion' => '1.19.7'],
        ]);

        $this->assertSame(['chart' => '1.20.3', 'app' => '1.20.0'], KnativeVersionHelper::resolveVersionPair(33, $helm));
        $this->assertSame(['chart' => '1.17.8', 'app' => '1.17.0'], KnativeVersionHelper::resolveVersionPair(30, $helm), 'nothing compatible in the repository');
    }

    public function test_prometheus_and_loki_charts_are_pinned(): void
    {
        $this->assertSame('92.10.1', (new class
        {
            use KnativePrometheusTrait;
        })::PROMETHEUS_CHART_VERSION);
        $this->assertSame('2.10.3', ManagedLoki::CHART_VERSION);
    }
}
