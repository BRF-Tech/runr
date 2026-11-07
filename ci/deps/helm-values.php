<?php

/*
 * The Helm values RunR installs a region chart with: what ci/deps-bump.sh renders the pinned and
 * the new chart with (helm template), so that a chart that refuses them, drops one of them or
 * renders other objects goes to a person instead of next.
 *
 *   php ci/deps/helm-values.php CHART              the values file the install writes
 *                                                  (HelmService::valuesYaml())
 *   php ci/deps/helm-values.php CHART --set-json   its --set-json values, one KEY=JSON per line
 *
 * CHART: traefik, kube-prometheus-stack, loki-stack or zot.
 *
 * Needs the API's dependencies (composer install in api/). The values are those of a new region
 * with the defaults: Serving in knative-serving, no trusted IPs, the default request timeout, no
 * other ingress class, a PVC-backed Zot of 1Gi on the default StorageClass. They come from the
 * methods the installs call (KnativeTraefikTrait, KnativePrometheusTrait, KnativeLokiTrait,
 * ZotManifestTrait), so a change there is rendered too; api/tests/Unit/DepsHelmValuesTest.php
 * keeps this file in step with them.
 */

use App\Services\HelmService;
use App\Services\Helpers\RequestTimeout;
use App\Services\Helpers\TraefikChartValues;
use App\Services\Helpers\TraefikImage;
use App\Services\KnativeInstallService;
use App\Services\ZotRegistryService;
use Illuminate\Contracts\Console\Kernel;

/**
 * The values of one chart (a key of ci/deps/lib.sh's DEPS_BOT that RunR installs with Helm).
 *
 * @return array<string, mixed>
 */
function runr_deps_helm_values(string $chart): array
{
    $servingNs = 'knative-serving';
    // the private builders, as the install calls them
    $call = static fn (object $service, string $method, mixed ...$args): array => (fn () => $this->{$method}(...$args))->call($service);

    switch ($chart) {
        case 'traefik':
            // KnativeTraefikTrait::installTraefik(): the values, the ingress class of a cluster
            // without another one and the reqs mirror's image (its --set-json:
            // runr_deps_helm_set_json())
            return array_replace_recursive(
                $call(new KnativeInstallService, 'traefikHelmValues', $servingNs, [], RequestTimeout::DEFAULT, TraefikChartValues::aliasStrategy()),
                KnativeInstallService::resolveTraefikIngressClassValues([], $servingNs),
                ['image' => TraefikImage::helmValues(TraefikImage::ref())],
            );
        case 'kube-prometheus-stack':
            return KnativeInstallService::prometheusHelmValues($servingNs);
        case 'loki-stack':
            return KnativeInstallService::lokiHelmValues();
        case 'zot':
            return $call(app(ZotRegistryService::class), 'buildZotPvcValues', '', '1Gi', false);
    }

    throw new InvalidArgumentException("no Helm values for {$chart}: traefik, kube-prometheus-stack, loki-stack or zot");
}

/**
 * The --set-json values the install passes next to the values file.
 *
 * @return array<string, string> key => JSON
 */
function runr_deps_helm_set_json(string $chart): array
{
    return match ($chart) {
        // KnativeTraefikTrait::installTraefik(): every namespace's KIngresses
        'traefik' => ['providers.knative.namespaces' => '[]'],
        default => [],
    };
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $chart = $argv[1] ?? '';
    $root = dirname(__DIR__, 2);
    if (! is_file("{$root}/api/vendor/autoload.php")) {
        fwrite(STDERR, "api/vendor/autoload.php is missing: run composer install in api/ first\n");
        exit(1);
    }
    require "{$root}/api/vendor/autoload.php";
    $app = require "{$root}/api/bootstrap/app.php";
    $app->make(Kernel::class)->bootstrap();

    try {
        if (($argv[2] ?? '') === '--set-json') {
            runr_deps_helm_values($chart);
            foreach (runr_deps_helm_set_json($chart) as $key => $json) {
                echo "{$key}={$json}\n";
            }
        } else {
            // the file the install writes: an empty array is a map ({}), as HelmService writes it
            echo HelmService::valuesYaml(runr_deps_helm_values($chart));
        }
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage()."\n");
        exit(1);
    }
}
