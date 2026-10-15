<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Engine\Definition;
use App\Workflows\Engine\DefinitionException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Normalization and validation of workflow definitions (SDK introspection / manifest). */
class DefinitionTest extends TestCase
{
    public function test_minimal_definition_gets_defaults(): void
    {
        $def = Definition::fromArray(['name' => 'orders']);

        $this->assertSame('orders', $def->name);
        $this->assertSame([], $def->triggers);
        $this->assertSame(3, $def->config['retries']);
        $this->assertNull($def->config['timeout']);
        $this->assertSame([], $def->config['concurrency']);
        $this->assertNull($def->config['idempotency']);
        $this->assertSame([], $def->config['cancel_on']);
    }

    public function test_full_definition_is_normalized(): void
    {
        $def = Definition::fromArray([
            'name' => 'process-order',
            'description' => 'Charge and ship',
            'triggers' => [
                ['event' => 'order.created', 'if' => 'event.data.total > 0'],
                ['cron' => 'TZ=Europe/Istanbul 0 9 * * *'],
                ['cron' => '*/5 * * * *', 'timezone' => 'UTC'],
                ['queue' => 'orders'],
            ],
            'retries' => 5,
            'timeout' => '2d',
            'concurrency' => [['limit' => 10], ['limit' => 1, 'key' => 'event.data.customer_id']],
            'idempotency' => 'event.data.order_id',
            'cancel_on' => [['event' => 'order.cancelled', 'match' => 'data.order_id']],
        ]);

        $this->assertSame([
            ['event' => 'order.created', 'if' => 'event.data.total > 0'],
            ['cron' => '0 9 * * *', 'timezone' => 'Europe/Istanbul'],
            ['cron' => '*/5 * * * *', 'timezone' => 'UTC'],
            ['event' => 'queue/orders'],
        ], $def->triggers);
        $this->assertSame(5, $def->config['retries']);
        $this->assertSame('2d', $def->config['timeout']);
        $this->assertSame([['limit' => 10, 'key' => null], ['limit' => 1, 'key' => 'event.data.customer_id']], $def->config['concurrency']);
        $this->assertSame('event.data.order_id', $def->config['idempotency']);
        $this->assertSame([['event' => 'order.cancelled', 'expression' => '(event.data.order_id == async.data.order_id)']], $def->config['cancel_on']);
    }

    public function test_shorthands(): void
    {
        $def = Definition::fromArray(['id' => 'a', 'concurrency' => 3, 'retries' => ['attempts' => 2], 'timeout' => 90, 'cancelOn' => [['event' => 'x']]]);

        $this->assertSame('a', $def->name);
        $this->assertSame([['limit' => 3, 'key' => null]], $def->config['concurrency']);
        $this->assertSame(1, $def->config['retries']);
        $this->assertSame('90s', $def->config['timeout']);
        $this->assertSame([['event' => 'x']], $def->config['cancel_on']);
    }

    public function test_hash_changes_with_the_definition(): void
    {
        $a = Definition::fromArray(['name' => 'a', 'retries' => 1]);
        $b = Definition::fromArray(['name' => 'a', 'retries' => 2]);

        $this->assertSame($a->hash(), Definition::fromArray(['name' => 'a', 'retries' => 1])->hash());
        $this->assertNotSame($a->hash(), $b->hash());
    }

    public static function invalid(): array
    {
        return [
            'not an object' => ['x'],
            'missing name' => [['triggers' => []]],
            'bad name' => [['name' => '-bad']],
            'reserved name app' => [['name' => 'app']],
            'reserved name sync' => [['name' => 'SYNC']],
            'triggers not list' => [['name' => 'a', 'triggers' => ['event' => 'x']]],
            'empty trigger' => [['name' => 'a', 'triggers' => [[]]]],
            'bad event name' => [['name' => 'a', 'triggers' => [['event' => 'has space']]]],
            'bad cron' => [['name' => 'a', 'triggers' => [['cron' => 'every day']]]],
            'bad timezone' => [['name' => 'a', 'triggers' => [['cron' => '* * * * *', 'timezone' => 'Mars/Base']]]],
            'bad queue' => [['name' => 'a', 'triggers' => [['queue' => 'a b']]]],
            'bad trigger if' => [['name' => 'a', 'triggers' => [['event' => 'x', 'if' => 'a ==']]]],
            'negative retries' => [['name' => 'a', 'retries' => -1]],
            'too many retries' => [['name' => 'a', 'retries' => 99]],
            'string retries' => [['name' => 'a', 'retries' => '3']],
            'bad timeout' => [['name' => 'a', 'timeout' => 'soon']],
            'timeout over max' => [['name' => 'a', 'timeout' => '400d']],
            'concurrency zero' => [['name' => 'a', 'concurrency' => 0]],
            'two keyed limits' => [['name' => 'a', 'concurrency' => [['limit' => 1, 'key' => 'event.a'], ['limit' => 2, 'key' => 'event.b']]]],
            'three limits' => [['name' => 'a', 'concurrency' => [1, 2, 3]]],
            'bad idempotency' => [['name' => 'a', 'idempotency' => '(']],
            'cancel_on bad event' => [['name' => 'a', 'cancel_on' => [['event' => '']]]],
            'cancel_on bad match' => [['name' => 'a', 'cancel_on' => [['event' => 'x', 'match' => 'a b']]]],
        ];
    }

    #[DataProvider('invalid')]
    public function test_rejects_invalid_definitions(mixed $raw): void
    {
        $this->expectException(DefinitionException::class);
        Definition::fromArray($raw);
    }

    public function test_list_rejects_duplicates_and_non_lists(): void
    {
        $this->assertCount(2, Definition::listFromArray([['name' => 'a'], ['name' => 'b']]));

        try {
            Definition::listFromArray([['name' => 'a'], ['name' => 'a']]);
            $this->fail('duplicate accepted');
        } catch (DefinitionException $e) {
            $this->assertStringContainsString('twice', $e->getMessage());
        }

        $this->expectException(DefinitionException::class);
        Definition::listFromArray(['a' => ['name' => 'a']]);
    }

    public function test_list_size_limit(): void
    {
        config(['workflows.limits.max_workflows_per_project' => 2]);

        $this->expectException(DefinitionException::class);
        Definition::listFromArray([['name' => 'a'], ['name' => 'b'], ['name' => 'c']]);
    }
}
