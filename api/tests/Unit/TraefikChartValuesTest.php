<?php

namespace Tests\Unit;

use App\Services\Concerns\KnativeTraefikTrait;
use App\Services\Contracts\KnativeConstants;
use App\Services\HelmService;
use App\Services\Helpers\TraefikChartValues;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Yaml\Yaml;

/**
 * The values of a Traefik release on its way to the pinned chart: chart 41 renamed the logging keys
 * and its schema refuses the old root key, so they are rewritten rather than reused.
 */
class TraefikChartValuesTest extends TestCase
{
    private const IMAGE = 'ghcr.io/brf-tech/runr/reqs/traefik:v3.7.17';

    /** What `helm get values -o json` prints for a release of chart 40, as RunR installs it. */
    private const CHART_40_LOGS = <<<'JSON'
        {
          "logs": {
            "general": {"level": "INFO", "format": "json"},
            "access": {
              "enabled": true, "format": "json", "filters": {},
              "fields": {
                "general": {"defaultMode": "keep"},
                "headers": {"defaultMode": "drop", "names": {"User-Agent": "keep", "X-Real-Ip": "keep", "X-Request-Id": "keep"}}
              }
            }
          }
        }
        JSON;

    private function migrated(string $json): stdClass
    {
        return TraefikChartValues::forPinnedChart(json_decode($json), self::IMAGE);
    }

    /** A value of a migrated release as the JSON Helm reads. */
    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    public function test_the_logging_keys_move_to_the_charts_new_names(): void
    {
        $values = $this->migrated(self::CHART_40_LOGS);

        $this->assertObjectNotHasProperty('logs', $values);
        $this->assertSame('{"level":"INFO","format":"json"}', $this->json($values->log));
        $this->assertSame(
            '{"enabled":true,"format":"json","filters":{},"fields":{"headers":{"defaultMode":"drop","names":'
            .'{"User-Agent":"keep","X-Real-Ip":"keep","X-Request-Id":"keep"}},"defaultMode":"keep"}}',
            $this->json($values->accessLog),
        );
    }

    public function test_a_region_of_chart_40_ends_up_with_what_a_new_region_is_given(): void
    {
        $builder = new class implements KnativeConstants
        {
            use KnativeTraefikTrait;

            public function values(): array
            {
                return $this->traefikHelmValues('knative-serving');
            }
        };
        $fresh = json_decode($this->json(Yaml::parse(HelmService::valuesYaml($builder->values()), Yaml::PARSE_OBJECT_FOR_MAP)));

        $this->assertObjectNotHasProperty('logs', $fresh, 'the chart\'s schema refuses it');
        $this->assertEquals($fresh->log, $this->migrated(self::CHART_40_LOGS)->log);
        $this->assertEquals($fresh->accessLog, $this->migrated(self::CHART_40_LOGS)->accessLog);
        $this->assertSame($fresh->global->aliasHeadersStrategy, $this->migrated(self::CHART_40_LOGS)->global->aliasHeadersStrategy);
    }

    /**
     * authResponseHeaders manage the dash form only: an alias of X-Runr-Caller a caller sends would
     * reach the callee next to the verified one, and backends that fold names read it instead.
     */
    public function test_request_headers_with_aliasing_names_are_deleted_on_every_entry_point(): void
    {
        $this->assertSame('delete', KnativeConstants::TRAEFIK_ALIAS_HEADERS_STRATEGY);

        $values = $this->migrated('{"global": {"checkNewVersion": false, "sendAnonymousUsage": false}}');
        $this->assertSame('{"checkNewVersion":false,"sendAnonymousUsage":false,"aliasHeadersStrategy":"delete"}', $this->json($values->global));

        // a global value holds for every entry point: none of them sets its own
        $this->assertSame('{"aliasHeadersStrategy":"delete"}', $this->json($this->migrated('{}')->global));
    }

    public function test_the_given_strategy_goes_into_the_values(): void
    {
        $values = TraefikChartValues::forPinnedChart(json_decode('{}'), self::IMAGE, TraefikChartValues::ALIAS_KEEP);

        $this->assertSame('{"aliasHeadersStrategy":"keep"}', $this->json($values->global));
    }

    public function test_the_strategy_a_traefik_runs_is_read_from_its_args(): void
    {
        $this->assertSame('delete', TraefikChartValues::runningAliasStrategy(['--ping=true', '--global.aliasHeadersStrategy=delete']));
        $this->assertSame('keep', TraefikChartValues::runningAliasStrategy(['--global.aliasHeadersStrategy=keep']));
        // Traefik's default without the argument
        $this->assertSame('keep', TraefikChartValues::runningAliasStrategy(['--ping=true']));
        $this->assertSame('keep', TraefikChartValues::runningAliasStrategy([]));
    }

    public function test_the_helm_value_reaches_the_config(): void
    {
        $config = fn () => (require dirname(__DIR__, 2).'/config/knative.php')['traefik_alias_headers_strategy'];

        $this->assertSame('delete', $config());

        putenv('KNATIVE_TRAEFIK_ALIAS_HEADERS_STRATEGY=keep');
        $_ENV['KNATIVE_TRAEFIK_ALIAS_HEADERS_STRATEGY'] = $_SERVER['KNATIVE_TRAEFIK_ALIAS_HEADERS_STRATEGY'] = 'keep';
        try {
            $this->assertSame('keep', $config());
        } finally {
            putenv('KNATIVE_TRAEFIK_ALIAS_HEADERS_STRATEGY');
            unset($_ENV['KNATIVE_TRAEFIK_ALIAS_HEADERS_STRATEGY'], $_SERVER['KNATIVE_TRAEFIK_ALIAS_HEADERS_STRATEGY']);
        }
    }

    public function test_which_images_take_the_strategy(): void
    {
        foreach ([
            'ghcr.io/brf-tech/runr/reqs/traefik:v3.7.17', KnativeConstants::TRAEFIK_IMAGE, 'r.example/traefik:v3.8.0', 'r.example:5000/traefik:3.7.17',
            'r.example/traefik:v3.7.17@sha256:'.str_repeat('a', 64),
            // no version in the reference: the chart runs its own
            'r.example/traefik@sha256:'.str_repeat('a', 64),
        ] as $image) {
            $this->assertTrue(TraefikChartValues::handlesAliases($image), $image);
        }

        foreach (['r.example/traefik:v3.7.16', 'r.example/traefik:v3.7.4', 'docker.io/traefik:3.6.9', 'r.example/traefik:v3.7'] as $image) {
            $this->assertFalse(TraefikChartValues::handlesAliases($image), $image);
        }
    }

    public function test_keys_a_chart_before_41_spelled_in_lowercase_are_camel_cased(): void
    {
        $values = $this->migrated('{"logs": {"access": {"filters": {"statuscodes": "500-599", "retryattempts": true, "minduration": "10ms"},
            "fields": {"general": {"defaultmode": "drop", "names": {"ClientUsername": "keep"}}, "headers": {"defaultmode": "keep"}}}}}');

        $this->assertSame('{"statusCodes":"500-599","retryAttempts":true,"minDuration":"10ms"}', $this->json($values->accessLog->filters));
        $this->assertSame('{"headers":{"defaultMode":"keep"},"defaultMode":"drop","names":{"ClientUsername":"keep"}}', $this->json($values->accessLog->fields));
    }

    /** A header of that name is the operator's: only the chart's own keys change. */
    public function test_header_names_are_not_keys_of_the_chart(): void
    {
        $values = $this->migrated('{"logs": {"access": {"fields": {"headers": {"names": {"X-Trace": "keep"}}}}}}');

        $this->assertSame('{"X-Trace":"keep"}', $this->json($values->accessLog->fields->headers->names));
    }

    /**
     * The chart's schema refuses a map for a list ("ports.web.proxyProtocol.trustedIPs: Invalid
     * type. Expected: array, given: object") and the reverse: {} and [] must come out as they went in.
     */
    public function test_empty_maps_and_lists_stay_what_they_are(): void
    {
        $values = $this->migrated('{"ports": {"web": {"proxyProtocol": {"trustedIPs": [], "insecure": false}}},
            "providers": {"knative": {"namespaces": []}}, "logs": {"access": {"filters": {}}}}');

        $this->assertSame('{"trustedIPs":[],"insecure":false}', $this->json($values->ports->web->proxyProtocol));
        $this->assertSame('{"namespaces":[]}', $this->json($values->providers->knative));
        $this->assertSame('{"filters":{}}', $this->json($values->accessLog));
    }

    public function test_everything_else_a_release_was_given_stays(): void
    {
        $values = $this->migrated('{"additionalArguments": ["--ping=true"], "ports": {"internal": {"port": 8081, "http": {"middlewares": ["a@kubernetescrd"]}}},
            "service": {"additionalServices": {"internal": {"type": "ClusterIP"}}}}');

        $this->assertSame('["--ping=true"]', $this->json($values->additionalArguments));
        $this->assertSame('{"port":8081,"http":{"middlewares":["a@kubernetescrd"]}}', $this->json($values->ports->internal));
        $this->assertSame('{"internal":{"type":"ClusterIP"}}', $this->json($values->service->additionalServices));
    }

    public function test_it_runs_the_given_image(): void
    {
        $values = $this->migrated('{"image": {"registry": "docker.io", "repository": "traefik", "tag": "v3.7.4", "pullPolicy": "Always"}}');

        $this->assertSame(
            '{"registry":"ghcr.io","repository":"brf-tech/runr/reqs/traefik","tag":"v3.7.17","pullPolicy":"Always"}',
            $this->json($values->image),
        );

        // an older digest does not stay next to the new tag
        $values = $this->migrated('{"image": {"registry": "ghcr.io", "repository": "x", "digest": "sha256:abc"}}');
        $this->assertObjectNotHasProperty('digest', $values->image);
    }

    public function test_values_of_the_new_chart_pass_as_they_are(): void
    {
        $values = $this->migrated('{"log": {"level": "DEBUG"}, "accessLog": {"enabled": false}}');

        $this->assertSame('{"level":"DEBUG"}', $this->json($values->log));
        $this->assertSame('{"enabled":false}', $this->json($values->accessLog));
    }

    public function test_which_charts_move(): void
    {
        foreach (['40.0.0', '40.3.0', 'v40.3.0', '41.7.1', '42.2.0'] as $version) {
            $this->assertTrue(TraefikChartValues::movable($version), $version);
        }

        $pinned = KnativeConstants::TRAEFIK_CHART_VERSION;
        foreach (['39.0.9', '28.0.0', $pinned, '999.0.0'] as $version) {
            $this->assertFalse(TraefikChartValues::movable($version), $version);
        }
    }
}
