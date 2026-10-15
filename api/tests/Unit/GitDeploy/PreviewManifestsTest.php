<?php

namespace Tests\Unit\GitDeploy;

use App\Services\GitDeploy\PreviewManifests;
use Tests\TestCase;

/** A regular deploy's manifests → the preview's: names, labels, scaling, env, fork secrets. */
class PreviewManifestsTest extends TestCase
{
    private function manifests(): array
    {
        $labels = ['app.kubernetes.io/managed-by' => 'runr', 'runr/project-id' => 'P1', 'runr/version' => '1000000001', 'runr/tenant' => 'acme'];

        return [
            [
                'apiVersion' => 'v1', 'kind' => 'ConfigMap',
                'metadata' => ['name' => 'orders-v1000000001', 'namespace' => 'fn', 'labels' => $labels],
                'data' => ['main.py' => 'x'],
            ],
            // Secret env vars of the project (KnativeSecretEnvTrait)
            [
                'apiVersion' => 'v1', 'kind' => 'Secret',
                'metadata' => ['name' => 'orders-secret-env', 'namespace' => 'fn', 'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr/project-id' => 'P1']],
                'type' => 'Opaque',
                'data' => ['API_KEY' => base64_encode('k-123')],
            ],
            [
                'apiVersion' => 'serving.knative.dev/v1', 'kind' => 'Service',
                'metadata' => ['name' => 'orders', 'namespace' => 'fn', 'labels' => $labels, 'annotations' => ['runr.sh/project-id' => 'P1']],
                'spec' => ['template' => [
                    'metadata' => [
                        'labels' => ['runr/project-id' => 'P1', 'runr/version' => '1000000001'],
                        'annotations' => ['autoscaling.knative.dev/min-scale' => '2', 'autoscaling.knative.dev/max-scale' => '10'],
                    ],
                    'spec' => [
                        'containers' => [[
                            'name' => 'handler',
                            'env' => [
                                ['name' => 'GREETING', 'value' => 'hello'],
                                ['name' => 'API_URL', 'value' => 'https://api.example.com'],
                                ['name' => 'DATABASE_URL', 'valueFrom' => ['secretKeyRef' => ['name' => 'orders-db', 'key' => 'url']]],
                                ['name' => 'API_KEY', 'valueFrom' => ['secretKeyRef' => ['name' => 'orders-secret-env', 'key' => 'API_KEY']]],
                            ],
                            'envFrom' => [['secretRef' => ['name' => 'orders-secret-env']], ['configMapRef' => ['name' => 'shared']]],
                        ]],
                        'volumes' => [['name' => 'code', 'configMap' => ['name' => 'orders-v1000000001']]],
                    ],
                ]],
            ],
            ['apiVersion' => 'batch/v1', 'kind' => 'CronJob', 'metadata' => ['name' => 'orders-trigger']],
        ];
    }

    public function test_the_preview_service_and_its_config_map(): void
    {
        $out = PreviewManifests::transform($this->manifests(), 'P1', 'orders', 'orders-pr-12', 12, ['API_URL' => 'https://staging.example.com']);

        $this->assertSame(['ConfigMap', 'Secret', 'Service'], array_column($out, 'kind'));
        [$cm, $secret, $svc] = $out;

        // The preview's own copy of the secret env vars: values only in the Secret
        $this->assertSame('orders-pr-12-secret-env', $secret['metadata']['name']);
        $this->assertSame('orders-pr-12', $secret['metadata']['labels']['runr.dev/preview']);
        $this->assertSame(['API_KEY' => base64_encode('k-123')], $secret['data']);

        $this->assertSame('orders-pr-12-v1000000001', $cm['metadata']['name']);
        $this->assertSame('P1', $cm['metadata']['labels']['runr/project-id']); // project delete sweeps it
        $this->assertSame('orders-pr-12', $cm['metadata']['labels']['runr.dev/preview']);

        $this->assertSame('orders-pr-12', $svc['metadata']['name']);
        $this->assertArrayNotHasKey('runr/project-id', $svc['metadata']['labels']);
        $this->assertSame('P1', $svc['metadata']['labels']['runr/preview-of']);
        $this->assertSame('acme', $svc['metadata']['labels']['runr/tenant']);
        $this->assertSame('12', $svc['metadata']['labels']['runr/pr']);

        $template = $svc['spec']['template'];
        $this->assertArrayNotHasKey('runr/project-id', $template['metadata']['labels']);
        $this->assertArrayNotHasKey('autoscaling.knative.dev/min-scale', $template['metadata']['annotations']);
        $this->assertSame('3', $template['metadata']['annotations']['autoscaling.knative.dev/max-scale']);
        $this->assertSame('orders-pr-12-v1000000001', $template['spec']['volumes'][0]['configMap']['name']);

        $env = array_column($template['spec']['containers'][0]['env'], null, 'name');
        $this->assertSame('https://staging.example.com', $env['API_URL']['value']);
        $this->assertSame('hello', $env['GREETING']['value']);
        $this->assertSame('12', $env['RUNR_PREVIEW_PR']['value']);
        $this->assertArrayHasKey('DATABASE_URL', $env); // same-repository request: secrets stay
        $this->assertSame(['name' => 'orders-pr-12-secret-env', 'key' => 'API_KEY'], $env['API_KEY']['valueFrom']['secretKeyRef']);
        $this->assertStringNotContainsString('k-123', json_encode($svc));
        $this->assertTrue(PreviewManifests::referencesSecrets($out));
    }

    public function test_a_fork_preview_gets_no_secrets(): void
    {
        $out = PreviewManifests::transform($this->manifests(), 'P1', 'orders', 'orders-pr-13', 13, [], true);

        $this->assertSame(['ConfigMap', 'Service'], array_column($out, 'kind')); // no Secret
        $container = $out[1]['spec']['template']['spec']['containers'][0];
        $this->assertSame(['GREETING', 'API_URL', 'RUNR_PREVIEW_PR'], array_column($container['env'], 'name'));
        $this->assertSame([['configMapRef' => ['name' => 'shared']]], $container['envFrom']);
        $this->assertFalse(PreviewManifests::referencesSecrets($out));
    }

    public function test_service_names_fit_kubernetes(): void
    {
        $slug = str_repeat('a', 44);

        $this->assertSame("{$slug}-pr-12", PreviewManifests::serviceName($slug, 12));
        $this->assertLessThanOrEqual(63, strlen(PreviewManifests::serviceName(str_repeat('b', 63), 123456)));
    }
}
