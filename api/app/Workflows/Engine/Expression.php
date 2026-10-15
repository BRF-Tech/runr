<?php

namespace App\Workflows\Engine;

/**
 * The small, side-effect free expression language of workflow definitions (trigger `if`,
 * waitForEvent `if`, cancel_on `if`, concurrency `key`, `idempotency`):
 *
 *   expr    := or
 *   or      := and ( "||" and )*
 *   and     := not ( "&&" not )*
 *   not     := "!" not | compare
 *   compare := primary ( ("==" | "!=" | ">" | ">=" | "<" | "<=") primary )?
 *   primary := string | number | true | false | null | path | "(" expr ")"
 *   path    := ident ( "." ident | "[" number "]" | "[" string "]" )*
 *
 * Paths read the variables given to evaluate() — `event` (the run's trigger event) and
 * `async` (the incoming event) — a missing path is null. Strings use '…' or "…".
 * `match: "data.order_id"` (waitForEvent / cancel_on) is shorthand for
 * `event.data.order_id == async.data.order_id`.
 */
final class Expression
{
    private const MAX_LENGTH = 1000;

    /** @var list<array{0:string,1:mixed}> */
    private array $tokens = [];

    private int $pos = 0;

    private array $vars = [];

    /**
     * @param  array<string,mixed>  $vars
     *
     * @throws ExpressionException
     */
    public static function evaluate(string $expression, array $vars): mixed
    {
        $self = new self;
        $self->tokens = self::tokenize($expression);
        $self->vars = $vars;
        $self->pos = 0;

        $value = $self->parseOr();

        if ($self->pos < count($self->tokens)) {
            throw new ExpressionException('Unexpected "'.$self->tokens[$self->pos][1].'" in expression');
        }

        return $value;
    }

    /** Truthiness of an expression; an invalid expression never matches. */
    public static function test(string $expression, array $vars): bool
    {
        try {
            return self::truthy(self::evaluate($expression, $vars));
        } catch (ExpressionException) {
            return false;
        }
    }

    /** @throws ExpressionException when the expression does not parse */
    public static function validate(string $expression): void
    {
        $self = new self;
        $self->tokens = self::tokenize($expression);
        $self->vars = [];
        $self->parseOr();

        if ($self->pos < count($self->tokens)) {
            throw new ExpressionException('Unexpected "'.$self->tokens[$self->pos][1].'" in expression');
        }
    }

    /** `match` field path → the equivalent comparison expression. */
    public static function fromMatch(string $field): string
    {
        $field = trim($field);
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $field)) {
            throw new ExpressionException("Invalid match field \"{$field}\"");
        }

        return "event.{$field} == async.{$field}";
    }

    /** Combine optional `match` and `if` into one expression (null: always matches). */
    public static function combine(?string $match, ?string $if): ?string
    {
        $parts = [];
        if ($match !== null && trim($match) !== '') {
            $parts[] = '('.self::fromMatch($match).')';
        }
        if ($if !== null && trim($if) !== '') {
            self::validate($if);
            $parts[] = '('.trim($if).')';
        }

        return $parts ? implode(' && ', $parts) : null;
    }

    public static function truthy(mixed $value): bool
    {
        return ! ($value === null || $value === false || $value === 0 || $value === 0.0 || $value === '' || $value === []);
    }

    // ── Tokenizer ──────────────────────────────────────────────────────────

    /** @return list<array{0:string,1:mixed}> */
    private static function tokenize(string $src): array
    {
        if (strlen($src) > self::MAX_LENGTH) {
            throw new ExpressionException('Expression is too long');
        }

        $tokens = [];
        $len = strlen($src);
        $i = 0;

        while ($i < $len) {
            $c = $src[$i];

            if (ctype_space($c)) {
                $i++;

                continue;
            }

            $two = substr($src, $i, 2);
            if (in_array($two, ['==', '!=', '>=', '<=', '&&', '||'], true)) {
                $tokens[] = ['op', $two];
                $i += 2;

                continue;
            }

            if (in_array($c, ['>', '<', '!', '(', ')', '.', '[', ']'], true)) {
                $tokens[] = ['op', $c];
                $i++;

                continue;
            }

            if ($c === '"' || $c === "'") {
                $quote = $c;
                $i++;
                $str = '';
                while ($i < $len && $src[$i] !== $quote) {
                    if ($src[$i] === '\\' && $i + 1 < $len) {
                        $i++;
                    }
                    $str .= $src[$i];
                    $i++;
                }
                if ($i >= $len) {
                    throw new ExpressionException('Unterminated string in expression');
                }
                $i++;
                $tokens[] = ['str', $str];

                continue;
            }

            if (ctype_digit($c) || ($c === '-' && $i + 1 < $len && ctype_digit($src[$i + 1]))) {
                $start = $i;
                $i++;
                while ($i < $len && (ctype_digit($src[$i]) || $src[$i] === '.')) {
                    $i++;
                }
                $num = substr($src, $start, $i - $start);
                if (! is_numeric($num)) {
                    throw new ExpressionException("Invalid number \"{$num}\"");
                }
                $tokens[] = ['num', str_contains($num, '.') ? (float) $num : (int) $num];

                continue;
            }

            if (ctype_alpha($c) || $c === '_') {
                $start = $i;
                while ($i < $len && (ctype_alnum($src[$i]) || $src[$i] === '_')) {
                    $i++;
                }
                $word = substr($src, $start, $i - $start);
                $tokens[] = match ($word) {
                    'true' => ['lit', true],
                    'false' => ['lit', false],
                    'null' => ['lit', null],
                    default => ['ident', $word],
                };

                continue;
            }

            throw new ExpressionException("Unexpected character \"{$c}\" in expression");
        }

        return $tokens;
    }

    // ── Parser / evaluator ─────────────────────────────────────────────────

    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function isOp(string $op): bool
    {
        $t = $this->peek();

        return $t !== null && $t[0] === 'op' && $t[1] === $op;
    }

    private function expectOp(string $op): void
    {
        if (! $this->isOp($op)) {
            $t = $this->peek();
            throw new ExpressionException("Expected \"{$op}\"".($t ? ', found "'.$t[1].'"' : ' at end of expression'));
        }
        $this->pos++;
    }

    private function parseOr(): mixed
    {
        $left = $this->parseAnd();
        while ($this->isOp('||')) {
            $this->pos++;
            $right = $this->parseAnd();
            $left = self::truthy($left) || self::truthy($right);
        }

        return $left;
    }

    private function parseAnd(): mixed
    {
        $left = $this->parseNot();
        while ($this->isOp('&&')) {
            $this->pos++;
            $right = $this->parseNot();
            $left = self::truthy($left) && self::truthy($right);
        }

        return $left;
    }

    private function parseNot(): mixed
    {
        if ($this->isOp('!')) {
            $this->pos++;

            return ! self::truthy($this->parseNot());
        }

        return $this->parseCompare();
    }

    private function parseCompare(): mixed
    {
        $left = $this->parsePrimary();
        $t = $this->peek();

        if ($t !== null && $t[0] === 'op' && in_array($t[1], ['==', '!=', '>', '>=', '<', '<='], true)) {
            $this->pos++;
            $right = $this->parsePrimary();

            return self::compare($t[1], $left, $right);
        }

        return $left;
    }

    private function parsePrimary(): mixed
    {
        $t = $this->peek();
        if ($t === null) {
            throw new ExpressionException('Unexpected end of expression');
        }

        if ($t[0] === 'op' && $t[1] === '(') {
            $this->pos++;
            $value = $this->parseOr();
            $this->expectOp(')');

            return $value;
        }

        if (in_array($t[0], ['str', 'num', 'lit'], true)) {
            $this->pos++;

            return $t[1];
        }

        if ($t[0] === 'ident') {
            return $this->parsePath();
        }

        throw new ExpressionException('Unexpected "'.$t[1].'" in expression');
    }

    private function parsePath(): mixed
    {
        $name = $this->tokens[$this->pos][1];
        $this->pos++;
        $value = $this->vars[$name] ?? null;

        while (true) {
            if ($this->isOp('.')) {
                $this->pos++;
                $t = $this->peek();
                if ($t === null || $t[0] !== 'ident') {
                    throw new ExpressionException('Expected a field name after "."');
                }
                $this->pos++;
                $value = self::field($value, $t[1]);

                continue;
            }

            if ($this->isOp('[')) {
                $this->pos++;
                $t = $this->peek();
                if ($t === null || ! in_array($t[0], ['num', 'str'], true)) {
                    throw new ExpressionException('Expected an index or a quoted key inside "[…]"');
                }
                $this->pos++;
                $this->expectOp(']');
                $value = self::field($value, $t[1]);

                continue;
            }

            return $value;
        }
    }

    private static function field(mixed $value, string|int $key): mixed
    {
        if (is_array($value)) {
            return $value[$key] ?? null;
        }
        if (is_object($value)) {
            return $value->{$key} ?? null;
        }

        return null;
    }

    private static function compare(string $op, mixed $a, mixed $b): bool
    {
        // Numbers compare numerically, everything else strictly: no type juggling ("1" != 1)
        return match ($op) {
            '==' => self::equals($a, $b),
            '!=' => ! self::equals($a, $b),
            '>' => self::ordered($a, $b) && $a > $b,
            '>=' => self::ordered($a, $b) && $a >= $b,
            '<' => self::ordered($a, $b) && $a < $b,
            '<=' => self::ordered($a, $b) && $a <= $b,
            default => throw new \LogicException("Unknown comparison operator {$op}"),
        };
    }

    private static function equals(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return (float) $a === (float) $b;
        }

        return $a === $b;
    }

    private static function ordered(mixed $a, mixed $b): bool
    {
        $num = fn ($v) => is_int($v) || is_float($v);

        return ($num($a) && $num($b)) || (is_string($a) && is_string($b));
    }
}
