<?php

namespace App\Console\Commands\Concerns;

trait E2eTestDataTrait
{
    /**
     * The ML cases' handlers (E2eTestRunnerTrait::mlTestConfigs()): the framework imported and a
     * matrix product computed, [[1, 2], [3, 4]] squared sums to 54; anything else raises (500).
     */
    private static function mlCode(): array
    {
        return [
            'pytorch' => <<<'PY'
import torch


def handler(event, context):
    m = torch.tensor([[1.0, 2.0], [3.0, 4.0]])
    total = float((m @ m).sum())
    if total != 54.0:
        raise RuntimeError(f"torch: [[1, 2], [3, 4]] squared sums to {total}, not 54")
    return {"statusCode": 200, "body": {"runtime": "pytorch", "framework": torch.__version__, "sum": total}}
PY,
            'tensorflow' => <<<'PY'
import tensorflow as tf


def handler(event, context):
    m = tf.constant([[1.0, 2.0], [3.0, 4.0]])
    total = float(tf.reduce_sum(tf.matmul(m, m)))
    if total != 54.0:
        raise RuntimeError(f"tensorflow: [[1, 2], [3, 4]] squared sums to {total}, not 54")
    return {"statusCode": 200, "body": {"runtime": "tensorflow", "framework": tf.__version__, "sum": total}}
PY,
        ];
    }

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
            'java' => <<<'JAVA'
import java.util.LinkedHashMap;
import java.util.Map;

public class Main {
    public static Object handler(Map<String, Object> event, Map<String, Object> context) {
        Map<String, Object> body = new LinkedHashMap<>();
        body.put("runtime", "java");
        body.put("method", context.get("method"));
        body.put("path", context.get("path"));
        body.put("headers", context.getOrDefault("headers", Map.of()));
        body.put("body", event);
        return Map.of("statusCode", 200, "body", body);
    }
}
JAVA,
            'dotnet' => <<<'CS'
using System.Collections.Generic;
using System.Text.Json.Nodes;

public static class Function
{
    public static object Handler(JsonNode? input, IDictionary<string, object?> context)
    {
        context.TryGetValue("method", out var method);
        context.TryGetValue("path", out var path);
        context.TryGetValue("headers", out var headers);
        return new
        {
            statusCode = 200,
            body = new { runtime = "dotnet", method, path, headers = headers ?? new Dictionary<string, string>(), body = input },
        };
    }
}
CS,
            'ruby' => <<<'RB'
def handler(event, context)
  { statusCode: 200, body: {
    runtime: "ruby", method: context["method"], path: context["path"],
    headers: context["headers"] || {}, body: event,
  } }
end
RB,
            'bun' => <<<'TS'
type Context = { method?: string; path?: string; headers?: Record<string, string> };

export async function handler(event: unknown, context: Context) {
  return {
    statusCode: 200,
    body: { runtime: 'bun', method: context.method, path: context.path, headers: context.headers ?? {}, body: event },
  };
}
TS,
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
            'java' => [
                'files' => [
                    ['name' => 'Main.java', 'content' => self::xaasEchoCode()['java']],
                    ['name' => 'pom.xml', 'content' => <<<'XML'
<project xmlns="http://maven.apache.org/POM/4.0.0">
  <modelVersion>4.0.0</modelVersion>
  <groupId>sh.runr</groupId>
  <artifactId>function</artifactId>
  <version>1.0.0</version>
  <dependencies>
    <dependency>
      <groupId>redis.clients</groupId>
      <artifactId>jedis</artifactId>
      <version>8.0.2</version>
    </dependency>
  </dependencies>
</project>
XML],
                ],
            ],
            'dotnet' => [
                'files' => [
                    ['name' => 'Function.cs', 'content' => self::xaasEchoCode()['dotnet']],
                    ['name' => 'Function.csproj', 'content' => <<<'XML'
<Project Sdk="Microsoft.NET.Sdk">
  <PropertyGroup>
    <ImplicitUsings>enable</ImplicitUsings>
    <Nullable>enable</Nullable>
  </PropertyGroup>
  <ItemGroup>
    <PackageReference Include="StackExchange.Redis" Version="3.4.31" />
  </ItemGroup>
</Project>
XML],
                ],
            ],
            'ruby' => [
                'files' => [
                    ['name' => 'main.rb', 'content' => self::xaasEchoCode()['ruby']],
                    ['name' => 'Gemfile', 'content' => "source \"https://rubygems.org\"\n\ngem \"redis\", \"~> 6.0\"\n"],
                ],
            ],
            'bun' => [
                'files' => [
                    ['name' => 'main.ts', 'content' => self::xaasEchoCode()['bun']],
                    ['name' => 'package.json', 'content' => '{"dependencies":{"ioredis":"^6"}}'],
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
        if (! empty($config['env_vars'])) {
            $configPayload['env_vars'] = $config['env_vars'];
        }

        // A suite's own config fields (e.g. the warmer suite's scale settings)
        $payload['config'] = array_merge($configPayload, $config['project_config'] ?? []);

        return $payload;
    }
}
