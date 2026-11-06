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
        $this->assertSame(['chart' => '1.21.4', 'app' => '1.21.0'], KnativeVersionHelper::resolveVersionPair(33, $offline));
        $this->assertSame(['chart' => '1.23.1', 'app' => '1.23.0'], KnativeVersionHelper::resolveVersionPair(34, $offline));
        $this->assertSame(['chart' => '1.24.0', 'app' => '1.24.0'], KnativeVersionHelper::resolveVersionPair(35, $offline));
        $this->assertSame(['chart' => '1.24.0', 'app' => '1.24.0'], KnativeVersionHelper::resolveVersionPair(36, $offline), 'a newer Kubernetes');
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

    /** The rows of the Knative release schedule: what a new install picks from. */
    public function test_the_newest_knative_minors_follow_the_kubernetes_version(): void
    {
        $this->assertSame(['1.21', '1.20', '1.19', '1.18', '1.17'], KnativeVersionHelper::compatibleVersions(33));
        $this->assertSame(['1.23', '1.22', '1.21', '1.20', '1.19'], KnativeVersionHelper::compatibleVersions(34));
        $this->assertSame(['1.24', '1.23', '1.22', '1.21', '1.20'], KnativeVersionHelper::compatibleVersions(35));
    }

    /** What the operator charts carry (cmd/operator/kodata/knative-serving of each tag). */
    private const OPERATOR_HOLDS = [
        '1.24.0' => ['1.24', '1.23', '1.22', '1.21'],
        '1.23.1' => ['1.23', '1.22', '1.21', '1.20'],
        '1.22.3' => ['1.22', '1.21', '1.20', '1.19'],
        '1.21.4' => ['1.21', '1.20', '1.19', '1.18'],
        '1.20.2' => ['1.20', '1.19', '1.18', '1.17'],
        '1.19.6' => ['1.19', '1.18', '1.17', '1.16'],
        '1.18.3' => ['1.18', '1.17', '1.16', '1.15'],
        '1.17.8' => ['1.17', '1.16', '1.15', '1.14'],
    ];

    /**
     * The chart of a new install is the newest the cluster supports, whatever minor is chosen for
     * Serving afterwards: a minor outside that chart fails, so it must not be on the list.
     */
    public function test_a_new_install_is_offered_only_what_the_chart_it_gets_can_install(): void
    {
        foreach (range(30, 36) as $k8sMinor) {
            $chart = KnativeVersionHelper::resolveVersionPair($k8sMinor, $this->helm([]))['chart'];
            $offered = KnativeVersionHelper::offeredVersions($k8sMinor, [], null);

            $this->assertNotSame([], $offered, "Kubernetes 1.{$k8sMinor}");
            $this->assertSame([], array_diff($offered, self::OPERATOR_HOLDS[$chart]), "Kubernetes 1.{$k8sMinor}, operator chart {$chart}");
            $this->assertStringStartsWith($offered[0].'.', $chart, 'the preselected minor is the chart\'s own');
        }

        $all = ['1.24', '1.23', '1.22', '1.21', '1.20', '1.19', '1.18', '1.17'];
        $this->assertSame(['1.24', '1.23', '1.22', '1.21'], KnativeVersionHelper::offeredVersions(35, $all, null));
        $this->assertSame(['1.23', '1.22', '1.21', '1.20'], KnativeVersionHelper::offeredVersions(34, $all, null));
        $this->assertSame(['1.20', '1.19', '1.18', '1.17'], KnativeVersionHelper::offeredVersions(32, $all, null));
        $this->assertSame(['1.21', '1.20', '1.19'], KnativeVersionHelper::offeredVersions(33, ['1.24', '1.21', '1.20', '1.19'], null), 'what the repository has and the cluster supports');
        $this->assertSame([], KnativeVersionHelper::offeredVersions(26, [], null), 'a cluster below every row');
    }

    /** A Serving install without a version: the operator that runs does not move for it. */
    public function test_the_default_version_is_one_the_running_operator_can_install(): void
    {
        $repository = $this->helm([
            ['version' => 'v1.24.0', 'appVersion' => '1.24.0'],
            ['version' => 'v1.21.4', 'appVersion' => '1.21.4'],
            ['version' => 'v1.20.2', 'appVersion' => '1.20.2'],
        ]);

        $this->assertSame(['chart' => '1.24.0', 'app' => '1.24.0'], KnativeVersionHelper::resolveVersionPair(35, $repository), 'no operator yet');
        $this->assertSame(['chart' => '1.21.4', 'app' => '1.21.0'], KnativeVersionHelper::resolveVersionPair(35, $repository, '1.21.4'));
        $this->assertSame(['chart' => '1.21.4', 'app' => '1.21.0'], KnativeVersionHelper::resolveVersionPair(33, $repository, 'v1.24.0'), 'newer than the cluster takes');
        $this->assertSame(['chart' => '1.21.4', 'app' => '1.21.0'], KnativeVersionHelper::resolveVersionPair(35, $this->helm([]), '1.21.4'), 'an unreadable repository');
        $this->assertSame(['chart' => '1.23.1', 'app' => '1.23.0'], KnativeVersionHelper::fallbackPair(34, '1.24.0'));
        $this->assertSame(['chart' => '1.24.0', 'app' => '1.24.0'], KnativeVersionHelper::resolveVersionPair(35, $repository, 'not a version'));
    }

    /** An operator that already runs decides, older or newer than what the cluster could take. */
    public function test_a_running_operator_decides_what_a_new_install_is_offered(): void
    {
        $all = ['1.24', '1.23', '1.22', '1.21', '1.20', '1.19', '1.18', '1.17'];

        foreach (self::OPERATOR_HOLDS as $chart => $holds) {
            $this->assertSame([], array_diff(KnativeVersionHelper::offeredVersions(36, [], null, $chart), $holds), "operator {$chart}");
        }
        $this->assertSame(['1.21', '1.20', '1.19', '1.18'], KnativeVersionHelper::offeredVersions(35, $all, null, '1.21.4'));
        $this->assertSame(['1.22', '1.21', '1.20', '1.19'], KnativeVersionHelper::offeredVersions(35, $all, null, 'v1.22.3'));
        $this->assertSame(['1.21'], KnativeVersionHelper::offeredVersions(33, $all, null, '1.24.0'), 'newer than the cluster takes');
        $this->assertSame(['1.24', '1.23', '1.22', '1.21'], KnativeVersionHelper::offeredVersions(35, $all, null, 'not a version'), 'an unreadable label: as without one');
    }

    /** Knative upgrades one minor at a time, and the upgrade moves the operator first. */
    public function test_a_running_serving_is_offered_its_next_minor_whatever_the_operator_holds(): void
    {
        $all = ['1.24', '1.23', '1.22', '1.21', '1.20', '1.19', '1.18', '1.17'];

        $this->assertSame(['1.22', '1.21', '1.20', '1.19', '1.18'], KnativeVersionHelper::offeredVersions(35, $all, '1.21.0', '1.21.4'), 'one past the operator');
        $this->assertSame(['1.24', '1.23', '1.22', '1.21', '1.19'], KnativeVersionHelper::offeredVersions(35, $all, '1.18.2'), 'behind the operator');
        $this->assertSame(['1.24', '1.23', '1.22', '1.21', '1.18'], KnativeVersionHelper::offeredVersions(35, $all, '1.17.0'));
        $this->assertSame(['1.24', '1.23', '1.22', '1.21', '1.19'], KnativeVersionHelper::offeredVersions(35, [], '1.18'), 'an unreadable repository: the table');
        $this->assertSame(['1.23', '1.22', '1.21', '1.20', '1.18'], KnativeVersionHelper::offeredVersions(34, $all, 'v1.17.8'));
        $this->assertSame(['1.24', '1.23', '1.22', '1.21'], KnativeVersionHelper::offeredVersions(35, $all, '1.21.0'), 'its next minor is among them');
        $this->assertSame(['1.24', '1.23', '1.22', '1.21'], KnativeVersionHelper::offeredVersions(35, array_diff($all, ['1.19']), '1.18.0'), 'the repository has no chart of it');
        $this->assertSame(['1.21', '1.20', '1.19', '1.18'], KnativeVersionHelper::offeredVersions(33, $all, '1.21.4'), 'the next minor needs a newer Kubernetes');
        $this->assertSame(['1.24', '1.23', '1.22', '1.21'], KnativeVersionHelper::offeredVersions(35, $all, 'unknown'));
    }

    public function test_prometheus_and_loki_charts_are_pinned(): void
    {
        $this->assertSame('92.21.2', (new class
        {
            use KnativePrometheusTrait;
        })::PROMETHEUS_CHART_VERSION);
        $this->assertSame('2.10.3', ManagedLoki::CHART_VERSION);
    }

    /**
     * The kube-prometheus-stack values RunR sets, as rendered with `helm template` against the
     * pinned chart: 92.21.2 still takes every one of them and renders them as 92.18.0 did. A key
     * added here, or one a new chart renames or drops, is rendered against the chart again.
     */
    public function test_prometheus_values_are_the_keys_rendered_against_the_pinned_chart(): void
    {
        $values = (new class
        {
            use KnativePrometheusTrait;
        })::prometheusHelmValues('knative-serving');

        $this->assertSame(['alertmanager', 'grafana', 'kubelet', 'prometheus'], array_keys($values));
        $this->assertSame(['enabled' => false], $values['alertmanager']);
        $this->assertSame(['enabled' => false], $values['grafana']);
        $this->assertSame(['serviceMonitor' => ['cAdvisor' => false]], $values['kubelet'], 'the standalone cAdvisor replaces the kubelet\'s');
        $this->assertSame(['prometheusSpec'], array_keys($values['prometheus']));
        $this->assertSame([
            'securityContext',
            'initContainers',
            'retention',
            'storageSpec',
            'tolerations',
            'affinity',
            'podMonitorSelectorNilUsesHelmValues',
            'serviceMonitorSelectorNilUsesHelmValues',
            'ruleSelectorNilUsesHelmValues',
            'additionalScrapeConfigs',
        ], array_keys($values['prometheus']['prometheusSpec']));
    }
}
