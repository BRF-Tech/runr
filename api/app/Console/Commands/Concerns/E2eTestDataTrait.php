<?php

namespace App\Console\Commands\Concerns;

trait E2eTestDataTrait
{
    private static function xaasEchoCode(): array
    {
        return [
            'python' => <<<'PY'
def handler(event, context):
    return {"statusCode": 200, "body": {
        "runtime": "python",
        "method": context.get("method"),
        "path": context.get("path"),
        "headers": context.get("headers", {}),
        "body": event,
    }}
PY,
            'nodejs' => <<<'JS'
exports.handler = async (event, context) => {
  return {
    statusCode: 200,
    body: {
      runtime: 'nodejs',
      method: context.method,
      path: context.path,
      headers: context.headers || {},
      body: event,
    },
  };
};
JS,
            'go' => <<<'GO'
package main

func Handler(event interface{}, context interface{}) (interface{}, error) {
	ctx, _ := context.(map[string]interface{})
	return map[string]interface{}{
		"statusCode": 200,
		"body": map[string]interface{}{
			"runtime": "go",
			"method":  ctx["method"],
			"path":    ctx["path"],
			"headers": ctx["headers"],
			"body":    event,
		},
	}, nil
}
GO,
            'php' => <<<'PHP'
<?php

function handler($event, $context): array
{
    return ['statusCode' => 200, 'body' => [
        'runtime' => 'php',
        'method'  => $context['method'],
        'path'    => $context['path'],
        'headers' => $context['headers'] ?? [],
        'body'    => $event,
    ]];
}
PHP,
        ];
    }

    /** FaaS: echo handler + dependency files (for the build test) */
    private static function faasFiles(): array
    {
        return [
            'python' => [
                'files' => [
                    ['name' => 'main.py', 'content' => self::xaasEchoCode()['python']],
                    ['name' => 'requirements.txt', 'content' => "redis>=5.0\n"],
                ],
            ],
            'nodejs' => [
                'files' => [
                    ['name' => 'main.js', 'content' => self::xaasEchoCode()['nodejs']],
                    ['name' => 'package.json', 'content' => '{"dependencies":{"ioredis":"^5"}}'],
                ],
            ],
            'go' => [
                'files' => [
                    ['name' => 'main.go', 'content' => self::xaasEchoCode()['go']],
                ],
            ],
            'php' => [
                'files' => [
                    ['name' => 'main.php', 'content' => self::xaasEchoCode()['php']],
                    ['name' => 'composer.json', 'content' => '{"require":{"predis/predis":"^2.0"}}'],
                ],
            ],
        ];
    }

    private function buildProjectPayload(string $name, array $config, string $regionId): array
    {
        $payload = [
            'name' => $name,
            'knative_region_id' => $regionId,
            'service_type' => $config['service_type'],
        ];

        if ($config['service_type'] !== 'caas') {
            $payload['runtime'] = $config['runtime'] ?? 'python';
            $payload['runtime_version'] = $config['runtime_version'] ?? null;
        }

        $configPayload = [
            'trigger_type' => $config['trigger_type'] ?? 'http',
            'cpu' => '250m',
            'memory_mb' => 128,
        ];

        if ($config['service_type'] === 'caas') {
            $configPayload['image'] = $config['image'];
        }
        if (($config['trigger_type'] ?? 'http') === 'schedule') {
            $configPayload['schedule'] = '0 0 1 1 *'; // yearly — e2e manually triggers
        }
        if (($config['trigger_type'] ?? 'http') === 'queue') {
            $configPayload['queue_stream'] = "test-stream-{$name}";
        }

        $payload['config'] = $configPayload;

        return $payload;
    }
}
