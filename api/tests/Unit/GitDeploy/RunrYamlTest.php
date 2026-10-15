<?php

namespace Tests\Unit\GitDeploy;

use App\Services\GitDeploy\RunrYaml;
use Tests\TestCase;

/** runr.yaml as the CLI writes it (docs/cli.md) → the settings the server applies. */
class RunrYamlTest extends TestCase
{
    private const CLI_SAMPLE = <<<'YAML'
name: orders-api          # project name; its slug names the service and the URL
type: faas
runtime: python
runtime_version: "3.13"
region: eu-1
description: Order intake

handler: main.handler

resources:
  cpu: 250m
  memory_mb: 256
  timeout_seconds: 30

scale:
  min: 0
  max: 10
  to_zero: true

trigger:
  type: schedule
  schedule: "*/5 * * * *"

env:
  LOG_LEVEL: info
  RETRIES: 3
  VERBOSE: false

ignore: ["*.md", "tests"]

dev:
  port: 8080
  env:
    LOG_LEVEL: debug
YAML;

    public function test_the_cli_sample_maps_to_project_settings(): void
    {
        $parsed = RunrYaml::parse(self::CLI_SAMPLE);

        $this->assertSame([], $parsed['warnings']);
        $this->assertSame([
            'name' => 'orders-api',
            'type' => 'faas',
            'runtime' => 'python',
            'runtime_version' => '3.13',
            'region' => 'eu-1',
            'description' => 'Order intake',
            'handler' => 'main.handler',
            'cpu' => '250m',
            'memory_mb' => 256,
            'timeout_seconds' => 30,
            'min_scale' => 0,
            'max_scale' => 10,
            'scale_to_zero' => true,
            'trigger_type' => 'schedule',
            'schedule' => '*/5 * * * *',
            'env' => ['LOG_LEVEL' => 'info', 'RETRIES' => '3', 'VERBOSE' => 'false'],
        ], $parsed['settings']);

        $this->assertSame(['*.md', 'tests'], RunrYaml::ignorePatterns(self::CLI_SAMPLE));
    }

    public function test_container_keys_and_queue_trigger(): void
    {
        $parsed = RunrYaml::parse(<<<'YAML'
type: caas
image: ghcr.io/acme/web:1.4.2
command: [/bin/server]
args: [--port, "8080"]
ports: [8080]
trigger: { type: queue, queue: orders }
YAML);

        $this->assertSame('ghcr.io/acme/web:1.4.2', $parsed['settings']['image']);
        $this->assertSame(['/bin/server'], $parsed['settings']['command']);
        $this->assertSame(['--port', '8080'], $parsed['settings']['args']);
        $this->assertSame([8080], $parsed['settings']['ports']);
        $this->assertSame('queue', $parsed['settings']['trigger_type']);
        $this->assertSame('orders', $parsed['settings']['queue_stream']);
    }

    public function test_unknown_keys_are_ignored_and_bad_values_warn(): void
    {
        $parsed = RunrYaml::parse(<<<'YAML'
name: x
future_key: { whatever: true }
resources: { memory_mb: lots }
scale: { min: -1, to_zero: maybe }
trigger: { schedule: "* * * * *" }
env:
  OK: "1"
  NESTED: { a: b }
ports: [http]
YAML);

        $this->assertSame(['name' => 'x', 'env' => ['OK' => '1']], $parsed['settings']);
        $this->assertCount(6, $parsed['warnings']);
        $this->assertStringContainsString('secrets are set in RunR', implode(' ', $parsed['warnings']));
    }

    public function test_broken_files_warn_and_apply_nothing(): void
    {
        $this->assertStringContainsString('not valid YAML', RunrYaml::parse("name: [unclosed\n")['warnings'][0]);
        $this->assertSame(['runr.yaml must be a mapping'], RunrYaml::parse("- a\n- b\n")['warnings']);
        $this->assertSame(['settings' => [], 'warnings' => []], RunrYaml::parse(null));
        $this->assertSame([], RunrYaml::ignorePatterns("name: [unclosed\n"));
    }
}
