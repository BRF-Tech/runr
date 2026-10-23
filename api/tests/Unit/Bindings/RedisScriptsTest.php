<?php

namespace Tests\Unit\Bindings;

use App\Models\Binding;
use App\Services\Bindings\DataServices\KvService;
use App\Services\Bindings\Kv\RedisEndpoint;
use App\Services\Bindings\Kv\RedisScripts;
use Tests\TestCase;

/**
 * redis-cli operations of KV bindings: the ACL rules of a binding user and the operation tasks
 * (credentials only in /ops files, ACL SAVE on the managed KV Redis).
 */
class RedisScriptsTest extends TestCase
{
    private function binding(): Binding
    {
        return new Binding(['name' => 'cache', 'resource_name' => 'cache-0a1b2c3d', 'type' => 'kv']);
    }

    private function managed(): RedisEndpoint
    {
        return new RedisEndpoint('runr-kv.runr-data.svc.cluster.local', 6379, 'admin-secret', true, true, 'managed');
    }

    private function region(bool $acl = true): RedisEndpoint
    {
        return new RedisEndpoint('runr-redis.fn', 6379, 'region-secret', $acl, false, 'region');
    }

    public function test_the_user_is_restricted_to_its_prefix(): void
    {
        $rule = RedisScripts::setUser($this->binding(), ['p1']);

        $this->assertStringStartsWith('ACL SETUSER cache-0a1b2c3d reset on >p1 ', $rule);
        $this->assertStringContainsString(' ~cache-0a1b2c3d:* ', $rule);
        $this->assertStringContainsString(' &cache-0a1b2c3d:* ', $rule);
        $this->assertStringContainsString(' +@all ', $rule);
        foreach (['-@admin', '-@dangerous', '+info', '-select', '-function', '-script|flush', '-script|kill', '-scan', '-randomkey', '-pubsub|channels', '-pubsub|numsub'] as $part) {
            $this->assertStringContainsString(" {$part}", $rule, "{$part} is part of the rules");
        }
        $this->assertStringNotContainsString('-@write', $rule);

        // Denials come after +@all (ACL rules apply left to right)
        $this->assertGreaterThan(strpos($rule, '+@all'), strpos($rule, '-@dangerous'));
    }

    public function test_read_only_mode_keeps_reads_and_deletes(): void
    {
        $rule = RedisScripts::setUser($this->binding(), ['p1'], true);

        $this->assertStringEndsWith(' -@write +del +unlink +expire +pexpire +persist', $rule);
    }

    public function test_every_password_is_kept_and_a_disabled_user_is_off(): void
    {
        $rule = RedisScripts::setUser($this->binding(), ['new', 'old', 'new', ''], false, false);

        $this->assertStringStartsWith('ACL SETUSER cache-0a1b2c3d reset off >new >old ~', $rule);
        $this->assertSame(1, substr_count($rule, '>new'));
    }

    public function test_commands_read_the_admin_password_from_a_file(): void
    {
        $task = RedisScripts::commands($this->managed(), 'redis:7-alpine', 'kv-provision', ['ACL SETUSER x on >p ~x:*']);

        $this->assertSame('kv-provision', $task->purpose);
        $this->assertSame('redis:7-alpine', $task->image);
        $this->assertSame('admin-secret', $task->files['auth']);
        $this->assertStringNotContainsString('admin-secret', $task->script);
        $this->assertStringContainsString('export REDISCLI_AUTH="$(cat /ops/auth)"', $task->script);
        $this->assertStringContainsString("-h 'runr-kv.runr-data.svc.cluster.local' -p 6379 --no-auth-warning --user default", $task->script);
        $this->assertStringContainsString('< /ops/commands.txt', $task->script);
        $this->assertStringContainsString('echo RUNR_OK', $task->script);
        $this->assertStringContainsString('NOPERM', $task->script, 'error replies fail the task');

        // Managed KV Redis: ACL changes are saved to its ACL file
        $this->assertSame("ACL SETUSER x on >p ~x:*\nACL SAVE\n", $task->files['commands.txt']);
    }

    public function test_no_acl_save_on_a_region_redis_or_without_acl_changes(): void
    {
        $region = RedisScripts::commands($this->region(), 'redis:7', 'kv-provision', ['ACL SETUSER x on']);
        $this->assertSame("ACL SETUSER x on\n", $region->files['commands.txt']);

        $ping = RedisScripts::commands($this->managed(), 'redis:7', 'kv-health', ['PING']);
        $this->assertSame("PING\n", $ping->files['commands.txt']);
    }

    public function test_a_logical_database_and_no_user_without_acl_support(): void
    {
        $endpoint = $this->region(false);

        $this->assertSame("-h 'runr-redis.fn' -p 6379 --no-auth-warning -n 4", $endpoint->cliArgs(4));
        $this->assertStringNotContainsString('--user', $endpoint->cliArgs());

        $task = RedisScripts::commands($endpoint, 'redis:7', 'kv-health', ['PING'], 4);
        $this->assertStringContainsString('-n 4', $task->script);
    }

    public function test_usage_task_lists_every_target(): void
    {
        $task = RedisScripts::usage($this->managed(), 'redis:7', [
            ['id' => 'b1', 'db' => 0, 'pattern' => 'cache-0a1b2c3d:*'],
            ['id' => 'b2', 'db' => 3, 'pattern' => '*'],
        ]);

        $this->assertSame('kv-usage', $task->purpose);
        $this->assertSame("b1 0 cache-0a1b2c3d:*\nb2 3 *\n", $task->files['targets']);
        $this->assertSame(RedisScripts::USAGE_LUA, $task->files['usage.lua']);
        $this->assertStringContainsString('MEMORY", "USAGE"', $task->files['usage.lua']);
        $this->assertStringContainsString('--eval /ops/usage.lua , "$pattern"', $task->script);
        $this->assertStringContainsString('echo "USAGE $id ${1:-0} ${2:-0}"', $task->script);
        $this->assertStringNotContainsString('admin-secret', $task->script);
    }

    public function test_purge_deletes_the_pattern_then_runs_the_follow_up_commands(): void
    {
        $task = RedisScripts::purge($this->managed(), 'redis:7', 'cache-0a1b2c3d:*', 0, ['ACL DELUSER cache-0a1b2c3d']);

        $this->assertSame('kv-purge', $task->purpose);
        $this->assertStringContainsString("--eval /ops/purge.lua , 'cache-0a1b2c3d:*'", $task->script);
        $this->assertStringContainsString('UNLINK', $task->files['purge.lua']);
        $this->assertSame("ACL DELUSER cache-0a1b2c3d\nACL SAVE\n", $task->files['commands.txt']);

        $db = RedisScripts::purge($this->region(false), 'redis:7', '*', 5);
        $this->assertStringContainsString('-n 5', $db->script);
        $this->assertSame('', $db->files['commands.txt']);
    }

    public function test_probe_checks_ping_and_acl_support_without_a_user(): void
    {
        $task = RedisScripts::probe($this->managed(), 'redis:7');

        $this->assertSame('kv-probe', $task->purpose);
        $this->assertStringContainsString('PING', $task->script);
        $this->assertStringContainsString('ACL WHOAMI', $task->script);
        $this->assertStringContainsString('ACL_SUPPORTED yes', $task->script);
        $this->assertStringNotContainsString('--user', $task->script);
        // The server version an organization import compares KV dumps with
        $this->assertStringContainsString('INFO server', $task->script);
        $this->assertStringContainsString('REDIS_VERSION', $task->script);
    }

    public function test_region_redis_addresses_are_parsed(): void
    {
        $this->assertSame(['runr-redis.fn', 6379], KvService::parseAddress('redis://runr-redis.fn:6379'));
        $this->assertSame(['10.0.0.5', 6380], KvService::parseAddress('rediss://:secret@10.0.0.5:6380/0'));
        $this->assertSame(['cache.example.com', 6379], KvService::parseAddress('cache.example.com'));
    }
}
