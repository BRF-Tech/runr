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

    public function test_the_network_section(): void
    {
        $parsed = RunrYaml::parse(<<<'YAML'
network:
  access: Allowlist
  allow_cidrs: [203.0.113.0/24, " 198.51.100.7 "]
  callers: [shop, billing.acme]
YAML);

        $this->assertSame(['access_mode' => 'allowlist', 'allowed_cidrs' => ['203.0.113.0/24', '198.51.100.7'], 'callers' => ['shop', 'billing.acme']], $parsed['settings']['network']);
        $this->assertSame([], $parsed['warnings']);

        // an empty list clears the allow list
        $this->assertSame(['callers' => []], RunrYaml::parse("network:\n  callers: []\n")['settings']['network']);

        $bad = RunrYaml::parse("network:\n  access: internal\n  callers: shop\n  allow_cidrs: [{a: b}]\n");
        $this->assertSame(['access_mode' => 'internal'], $bad['settings']['network']);
        $this->assertSame(['runr.yaml: network.allow_cidrs must be a list of strings', 'runr.yaml: network.callers must be a list of strings'], $bad['warnings']);
        $this->assertSame(['runr.yaml: network must be a mapping'], RunrYaml::parse("network: internal\n")['warnings']);
    }

    public function test_scale_schedule_is_the_timed_warmer(): void
    {
        $parsed = RunrYaml::parse(<<<'YAML'
scale:
  max: 5
  schedule:
    - cron: "0 9 * * 1-5"
      min: 3
    - { cron: "0 18 * * 1-5", min: "0" }
YAML);

        $this->assertSame([
            'max_scale' => 5,
            'scale_schedule' => [['cron' => '0 9 * * 1-5', 'min_scale' => 3], ['cron' => '0 18 * * 1-5', 'min_scale' => 0]],
        ], $parsed['settings']);
        $this->assertSame([], $parsed['warnings']);

        // [] / null turns it off; absent leaves it as it is
        $this->assertSame(['scale_schedule' => []], RunrYaml::parse("scale:\n  schedule: []\n")['settings']);
        $this->assertSame(['scale_schedule' => []], RunrYaml::parse("scale:\n  schedule:\n")['settings']);
        $this->assertArrayNotHasKey('scale_schedule', RunrYaml::parse("scale: { max: 2 }\n")['settings']);
    }

    public function test_a_malformed_schedule_warns_and_is_not_applied(): void
    {
        $parsed = RunrYaml::parse(<<<'YAML'
scale:
  schedule:
    - cron: "0 9 * * *"
      min: 2
    - cron: "0 18 * * *"
      min: -1
    - "0 20 * * *"
YAML);

        $this->assertSame([], $parsed['settings'], 'all rows or none');
        $this->assertCount(2, $parsed['warnings']);
        $this->assertStringContainsString('scale.schedule[1]', $parsed['warnings'][0]);

        $this->assertSame(['runr.yaml: scale.schedule must be a list of {cron, min} rows'], RunrYaml::parse("scale:\n  schedule: { cron: x }\n")['warnings']);
    }

    public function test_broken_files_warn_and_apply_nothing(): void
    {
        $this->assertStringContainsString('not valid YAML', RunrYaml::parse("name: [unclosed\n")['warnings'][0]);
        $this->assertSame(['runr.yaml must be a mapping'], RunrYaml::parse("- a\n- b\n")['warnings']);
        $this->assertSame(['settings' => [], 'warnings' => []], RunrYaml::parse(null));
        $this->assertSame([], RunrYaml::ignorePatterns("name: [unclosed\n"));
    }

    public function test_the_deploy_section_carries_the_deploy_strategy(): void
    {
        $parsed = RunrYaml::parse(<<<'YAML'
deploy:
  strategy: blue-green
  blue-green: { auto_promote: true, health_path: /healthz }
  canary: { steps: [10, 50, 100], interval_minutes: 5 }
YAML);

        $this->assertSame([], $parsed['warnings']);
        $this->assertSame([
            'strategy' => 'blue-green',
            'blue_green' => ['auto_promote' => true, 'health_path' => '/healthz'],
            'canary' => ['steps' => [10, 50, 100], 'interval_minutes' => 5],
        ], $parsed['settings']['deploy_strategy']);
    }

    public function test_a_deploy_section_that_is_no_mapping_warns(): void
    {
        $this->assertSame(['runr.yaml: deploy must be a mapping (strategy, blue_green, canary)'], RunrYaml::parse("deploy: canary\n")['warnings']);

        $parsed = RunrYaml::parse("deploy: { strategy: canary, canary: [10, 50] }\n");
        $this->assertSame(['runr.yaml: deploy.canary must be a mapping'], $parsed['warnings']);
        $this->assertSame(['strategy' => 'canary'], $parsed['settings']['deploy_strategy']);
    }

    public function test_a_trigger_list_with_shorthands_wins_over_the_single_trigger(): void
    {
        $parsed = RunrYaml::parse(<<<'YAML'
trigger:
  type: queue
  queue: old
triggers:
  - type: http
  - type: schedule
    name: nightly
    schedule: "0 3 * * *"
  - type: queue
    queue: orders
    delivery: { concurrency: 4 }
  - type: webhook
    name: github
    verify: github
    enabled: false
    secrets: { signing_secret: nope }
  - name: typeless
YAML);

        $this->assertArrayNotHasKey('trigger_type', $parsed['settings']);
        $this->assertSame([
            ['type' => 'http', 'config' => []],
            ['type' => 'schedule', 'name' => 'nightly', 'config' => ['cron' => '0 3 * * *']],
            ['type' => 'queue', 'config' => ['delivery' => ['concurrency' => 4], 'stream' => 'orders']],
            ['type' => 'webhook', 'name' => 'github', 'enabled' => false, 'config' => ['verify' => 'github']],
        ], $parsed['settings']['triggers']);

        $warnings = implode(' ', $parsed['warnings']);
        $this->assertStringContainsString('triggers and trigger are both set', $warnings);
        $this->assertStringContainsString('triggers[3].secrets is ignored', $warnings);
        $this->assertStringContainsString('triggers[4] needs a type', $warnings);
    }

    public function test_a_malformed_trigger_list_warns(): void
    {
        $parsed = RunrYaml::parse("triggers: http\n");

        $this->assertArrayNotHasKey('triggers', $parsed['settings']);
        $this->assertSame(['runr.yaml: triggers must be a non-empty list of mappings'], $parsed['warnings']);
    }
}
