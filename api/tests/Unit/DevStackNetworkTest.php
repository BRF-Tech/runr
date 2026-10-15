<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The development / e2e Compose stack (docker-compose.yml) joins the shared `kind` Docker network,
 * and Compose gives every service its service name as an alias on each of its networks. Several
 * stacks on one host (one per checkout, `COMPOSE_PROJECT_NAME`) therefore all answer to `api`,
 * `reverb`, … there: a hostname the stack uses to reach one of its own services must be one that
 * only exists on the stack's own network, and that network must be named after the project.
 */
class DevStackNetworkTest extends TestCase
{
    private function compose(): array
    {
        $file = dirname(__DIR__, 3).'/docker-compose.yml';
        if (! is_file($file)) {
            $this->markTestSkipped('docker-compose.yml is not part of this checkout');
        }

        return Yaml::parseFile($file);
    }

    /** @return array<string, string> */
    private function envDocker(): array
    {
        $env = [];
        foreach (file(dirname(__DIR__, 2).'/.env.docker', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m)) {
                $env[$m[1]] = trim($m[2], '"');
            }
        }

        return $env;
    }

    /** @return list<string> networks of a service, short or long syntax */
    private function networksOf(array $service): array
    {
        $networks = $service['networks'] ?? ['default'];

        return array_is_list($networks) ? $networks : array_keys($networks);
    }

    public function test_hosts_the_stack_connects_to_are_not_names_on_the_shared_kind_network(): void
    {
        $compose = $this->compose();
        $onKind = [];
        foreach ($compose['services'] as $name => $service) {
            if (in_array('kind', $this->networksOf($service), true)) {
                $onKind[] = $name;
            }
        }
        $this->assertContains('reverb', $onKind);

        $env = $this->envDocker();
        foreach (['DB_HOST', 'REDIS_HOST', 'REVERB_HOST', 'MAIL_HOST'] as $key) {
            $this->assertArrayHasKey($key, $env);
            $this->assertNotContains($env[$key], $onKind, "{$key}={$env[$key]} is also the name of every other stack's service on the kind network");
        }
    }

    public function test_reverb_answers_to_reverb_host_on_the_stacks_own_network(): void
    {
        $reverb = $this->compose()['services']['reverb'];

        $this->assertContains($this->envDocker()['REVERB_HOST'], $reverb['networks']['default']['aliases'] ?? []);
        $this->assertArrayHasKey('kind', $reverb['networks']);
    }
}
