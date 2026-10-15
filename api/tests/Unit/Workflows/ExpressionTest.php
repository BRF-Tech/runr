<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Engine\Expression;
use App\Workflows\Engine\ExpressionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExpressionTest extends TestCase
{
    private function vars(): array
    {
        return [
            'event' => ['name' => 'order.created', 'data' => ['order_id' => 42, 'total' => 99.5, 'tags' => ['a', 'b'], 'customer' => ['tier' => 'gold']]],
            'async' => ['name' => 'order.paid', 'data' => ['order_id' => 42, 'paid' => true, 'method' => 'card']],
        ];
    }

    public static function truthyCases(): array
    {
        return [
            ['event.data.order_id == async.data.order_id', true],
            ['event.data.order_id != async.data.order_id', false],
            ['event.data.total > 50', true],
            ['event.data.total >= 99.5', true],
            ['event.data.total < 10', false],
            ['event.data.total <= 99.5', true],
            ["event.data.customer.tier == 'gold'", true],
            ['event.data.customer.tier == "silver"', false],
            ['async.data.paid', true],
            ['!async.data.paid', false],
            ['async.data.paid && event.data.total > 50', true],
            ['async.data.paid && event.data.total > 500', false],
            ['async.data.missing || event.data.total > 50', true],
            ['(event.data.total > 500 || async.data.method == "card") && async.data.paid', true],
            ['event.data.tags[0] == "a"', true],
            ['event.data.tags[5] == null', true],
            ['event.data["order_id"] == 42', true],
            ['event.data.nothing.deeper == null', true],
            ['event.data.order_id == 42.0', true],
            ['true', true],
            ['null', false],
            ['-1 < 0', true],
        ];
    }

    #[DataProvider('truthyCases')]
    public function test_evaluates(string $expr, bool $expected): void
    {
        $this->assertSame($expected, Expression::test($expr, $this->vars()), $expr);
    }

    public function test_no_type_juggling_between_strings_and_numbers(): void
    {
        $vars = ['event' => ['data' => ['id' => '42', 'n' => 42]]];

        $this->assertFalse(Expression::test('event.data.id == 42', $vars));
        $this->assertFalse(Expression::test('event.data.id > 1', $vars));
        $this->assertTrue(Expression::test('event.data.id == "42"', $vars));
        $this->assertTrue(Expression::test('event.data.n == 42', $vars));
        $this->assertFalse(Expression::test('event.data.n == "42"', $vars));
    }

    public function test_reads_objects_too(): void
    {
        $vars = ['event' => json_decode('{"data":{"user":{"id":"u1"}}}', false)];

        $this->assertSame('u1', Expression::evaluate('event.data.user.id', $vars));
    }

    public function test_evaluate_returns_values(): void
    {
        $this->assertSame(42, Expression::evaluate('event.data.order_id', $this->vars()));
        $this->assertSame(['tier' => 'gold'], Expression::evaluate('event.data.customer', $this->vars()));
        $this->assertNull(Expression::evaluate('unknown.path', $this->vars()));
    }

    public function test_match_shorthand(): void
    {
        $this->assertSame('event.data.order_id == async.data.order_id', Expression::fromMatch('data.order_id'));
        $this->assertSame(
            '(event.data.order_id == async.data.order_id) && (async.data.paid)',
            Expression::combine('data.order_id', 'async.data.paid'),
        );
        $this->assertNull(Expression::combine(null, ''));
        $this->assertTrue(Expression::test(Expression::combine('data.order_id', null), $this->vars()));
    }

    public static function invalidExpressions(): array
    {
        return [['event.data =='], ['(a'], ['a b'], ["'unterminated"], ['a.'], ['a[x]'], ['a ++ b'], ['#']];
    }

    #[DataProvider('invalidExpressions')]
    public function test_invalid_expressions_throw_and_never_match(string $expr): void
    {
        $this->assertFalse(Expression::test($expr, $this->vars()));
        $this->expectException(ExpressionException::class);
        Expression::validate($expr);
    }

    public function test_invalid_match_field(): void
    {
        $this->expectException(ExpressionException::class);
        Expression::fromMatch('data.order_id == 1');
    }

    public function test_length_limit(): void
    {
        $this->expectException(ExpressionException::class);
        Expression::validate(str_repeat('a && ', 300).'a');
    }
}
