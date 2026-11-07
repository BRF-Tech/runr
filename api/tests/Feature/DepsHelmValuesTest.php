<?php

namespace Tests\Feature;

use App\Services\HelmService;
use App\Services\Helpers\TraefikImage;
use App\Services\KnativeInstallService;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * ci/deps/helm-values.php gives the dependency test bot the values RunR installs a region chart
 * with, from the methods the installs call: ci/deps-bump.sh renders the pinned and the new chart
 * with them. When one of those methods changes its name or arguments, this fails here instead of
 * in the bot's weekly run.
 */
class DepsHelmValuesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(base_path()).'/ci/deps/helm-values.php';
    }

    public function test_every_chart_the_installs_pass_values_to_has_them(): void
    {
        foreach (['traefik', 'kube-prometheus-stack', 'loki-stack', 'zot'] as $chart) {
            $this->assertNotEmpty(runr_deps_helm_values($chart), $chart);
        }
    }

    public function test_traefik_gets_the_install_values_the_mirrored_image_and_its_set_json(): void
    {
        $values = runr_deps_helm_values('traefik');

        $this->assertSame(3, $values['deployment']['replicas']);
        $this->assertSame(TraefikImage::helmValues(TraefikImage::ref()), $values['image']);
        $this->assertArrayHasKey('ingressClass', $values);
        $this->assertSame(['providers.knative.namespaces' => '[]'], runr_deps_helm_set_json('traefik'));
        $this->assertSame([], runr_deps_helm_set_json('zot'));
    }

    public function test_the_observability_charts_get_what_their_installs_pass(): void
    {
        $this->assertSame(KnativeInstallService::prometheusHelmValues('knative-serving'), runr_deps_helm_values('kube-prometheus-stack'));
        $this->assertSame(KnativeInstallService::lokiHelmValues(), runr_deps_helm_values('loki-stack'));
    }

    public function test_an_unknown_chart_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        runr_deps_helm_values('kata-deploy');
    }

    public function test_the_command_prints_the_values_file_the_install_writes(): void
    {
        $script = dirname(base_path()).'/ci/deps/helm-values.php';
        $process = new Process([PHP_BINARY, $script, 'loki-stack'], dirname(base_path()));
        $process->mustRun();

        $this->assertSame(HelmService::valuesYaml(KnativeInstallService::lokiHelmValues()), $process->getOutput());
        $this->assertIsArray(Yaml::parse($process->getOutput()));

        $process = new Process([PHP_BINARY, $script, 'traefik', '--set-json'], dirname(base_path()));
        $process->mustRun();
        $this->assertSame("providers.knative.namespaces=[]\n", $process->getOutput());
    }
}
