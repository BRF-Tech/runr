<?php

namespace Tests\Unit;

use App\Services\Helpers\ManagedLoki;
use App\Services\KnativeInstallService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * RunR's loki-stack (Loki 2.6.1) with the delete API that log retention uses.
 */
class ManagedLokiTest extends TestCase
{
    /** The Loki config loki-stack 2.10.3 renders without RunR's keys (chart defaults) */
    private function chartDefaultConfig(): array
    {
        return [
            'auth_enabled' => false,
            'compactor' => ['working_directory' => '/data/loki/boltdb-shipper-compactor', 'shared_store' => 'filesystem'],
            'limits_config' => ['enforce_metric_name' => false, 'reject_old_samples' => true, 'reject_old_samples_max_age' => '168h', 'max_entries_limit_per_query' => 5000],
            'table_manager' => ['retention_deletes_enabled' => false, 'retention_period' => '0s'],
        ];
    }

    public function test_install_values_enable_deletes_without_a_retention_of_lokis_own(): void
    {
        $config = KnativeInstallService::lokiHelmValues()['loki']['config'];

        $this->assertTrue($config['compactor']['retention_enabled']);
        $this->assertSame('filter-and-delete', $config['compactor']['deletion_mode']);
        $this->assertSame('/data/loki/boltdb-shipper-compactor', $config['compactor']['working_directory']);
        $this->assertSame('filesystem', $config['compactor']['shared_store']);
        $this->assertTrue($config['limits_config']['allow_deletes']);
        // Loki 2.6 defaults to 744h once retention is on and treats 0s as 0: 100 years instead
        $this->assertSame('876000h', $config['limits_config']['retention_period']);

        // the rest of the install values are unchanged
        $values = KnativeInstallService::lokiHelmValues('longhorn');
        $this->assertSame('longhorn', $values['loki']['persistence']['storageClass']);
        $this->assertSame('20Gi', $values['loki']['persistence']['size']);
        $this->assertFalse($values['grafana']['enabled']);
    }

    public function test_missing_keys_are_detected_on_the_rendered_config(): void
    {
        $old = $this->chartDefaultConfig();
        $this->assertSame([
            'compactor.retention_enabled',
            'compactor.deletion_mode',
            'limits_config.allow_deletes',
            'limits_config.retention_period',
        ], ManagedLoki::missing($old));

        $new = array_replace_recursive($old, ManagedLoki::deletionConfig());
        $this->assertSame([], ManagedLoki::missing($new));

        // as read back from the chart's Secret
        $secret = ['data' => ['loki.yaml' => base64_encode(Yaml::dump($new, 4))]];
        $this->assertSame([], ManagedLoki::missing(ManagedLoki::configFromSecret($secret)));
        $this->assertNull(ManagedLoki::configFromSecret(['data' => []]));
        $this->assertNull(ManagedLoki::configFromSecret(null));
    }

    public function test_helm_set_json_carries_exactly_the_deletion_keys(): void
    {
        $this->assertSame([
            'loki.config.compactor.working_directory' => '"/data/loki/boltdb-shipper-compactor"',
            'loki.config.compactor.shared_store' => '"filesystem"',
            'loki.config.compactor.retention_enabled' => 'true',
            'loki.config.compactor.deletion_mode' => '"filter-and-delete"',
            'loki.config.limits_config.allow_deletes' => 'true',
            'loki.config.limits_config.retention_period' => '"876000h"',
        ], ManagedLoki::helmSetJson());
    }
}
