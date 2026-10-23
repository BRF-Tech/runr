<?php

namespace App\Services\Rollouts;

use App\Models\Project;
use App\Services\Helpers\ResourceReservation;
use Illuminate\Validation\Rule;

/**
 * A project's deploy strategy (docs/deploy-strategies.md), stored as
 * deploy_strategy on its config row: direct (the default — the new revision takes all traffic
 * once ready, as before), blue_green or canary, with the options of both kept, so switching
 * between them does not lose what was set.
 *
 *   blue_green  auto_promote           promote by itself once ready (+ health checks), else wait
 *               health_path            path that must answer 2xx before an automatic promotion
 *               health_checks          consecutive 2xx answers needed (one every 10 s)
 *               keep_previous_minutes  the previous revision stays warm for an instant rollback
 *   canary      steps                  traffic shares of the new revision, strictly increasing
 *               interval_minutes       time per step
 *               error_rate_threshold   % of failed requests that rolls back (null: not checked)
 *               p95_threshold_ms       p95 latency that rolls back (null: not checked)
 *
 * Only HTTP and workflow functions roll out with a strategy (ELIGIBLE_TRIGGERS); the others —
 * and previews — always deploy directly. Stored values are read leniently: what does not fit
 * falls back to the default, nothing throws.
 */
final class DeployStrategy
{
    public const DIRECT = 'direct';

    public const BLUE_GREEN = 'blue_green';

    public const CANARY = 'canary';

    public const NAMES = [self::DIRECT, self::BLUE_GREEN, self::CANARY];

    /** Trigger types whose functions are served over HTTP through the Knative Service's routes. */
    public const ELIGIBLE_TRIGGERS = ['http', 'workflow'];

    public const DEFAULTS = [
        'strategy' => self::DIRECT,
        'blue_green' => [
            'auto_promote' => false,
            'health_path' => null,
            'health_checks' => 3,
            'keep_previous_minutes' => 15,
        ],
        'canary' => [
            'steps' => [10, 50, 100],
            'interval_minutes' => 5,
            'error_rate_threshold' => 5,
            'p95_threshold_ms' => null,
        ],
    ];

    /**
     * @param  array{auto_promote: bool, health_path: string|null, health_checks: int, keep_previous_minutes: int}  $blueGreen
     * @param  array{steps: list<int>, interval_minutes: int, error_rate_threshold: float|int|null, p95_threshold_ms: int|null}  $canary
     */
    private function __construct(
        public readonly string $strategy,
        public readonly array $blueGreen,
        public readonly array $canary,
    ) {}

    public static function defaults(): self
    {
        return self::fromArray(null);
    }

    /** The stored settings of the project (its config row), defaults where nothing is stored. */
    public static function forProject(Project $project): self
    {
        $config = ResourceReservation::configOf($project);

        return self::fromArray(is_array($stored = $config->deploy_strategy ?? null) ? $stored : null);
    }

    /**
     * "blue-green", "BlueGreen", "blue_green" → blue_green; null for a name that is no strategy.
     */
    public static function normalizeName(mixed $name): ?string
    {
        if (! is_string($name)) {
            return null;
        }

        $key = strtolower(str_replace(['-', ' '], '_', trim($name)));
        $key = $key === 'bluegreen' ? self::BLUE_GREEN : $key;

        return in_array($key, self::NAMES, true) ? $key : null;
    }

    /** Whether a function of this trigger type can roll out with a strategy. */
    public static function eligibleTrigger(?string $trigger): bool
    {
        return in_array($trigger ?? 'http', self::ELIGIBLE_TRIGGERS, true);
    }

    /**
     * Lenient read of stored (or already validated) settings.
     *
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromArray(?array $stored): self
    {
        $stored ??= [];
        $bg = is_array($stored['blue_green'] ?? null) ? $stored['blue_green'] : [];
        $canary = is_array($stored['canary'] ?? null) ? $stored['canary'] : [];
        $d = self::DEFAULTS;

        $path = $bg['health_path'] ?? $d['blue_green']['health_path'];
        $path = is_string($path) && str_starts_with(trim($path), '/') ? mb_substr(trim($path), 0, 255) : null;

        $steps = is_array($canary['steps'] ?? null) ? $canary['steps'] : $d['canary']['steps'];
        $steps = self::validSteps($steps) ? array_values(array_map('intval', $steps)) : $d['canary']['steps'];

        $error = $canary['error_rate_threshold'] ?? $d['canary']['error_rate_threshold'];
        if (array_key_exists('error_rate_threshold', $canary) && $canary['error_rate_threshold'] === null) {
            $error = null;
        }
        $p95 = array_key_exists('p95_threshold_ms', $canary) ? $canary['p95_threshold_ms'] : $d['canary']['p95_threshold_ms'];

        return new self(
            self::normalizeName($stored['strategy'] ?? null) ?? self::DIRECT,
            [
                'auto_promote' => (bool) ($bg['auto_promote'] ?? $d['blue_green']['auto_promote']),
                'health_path' => $path,
                'health_checks' => self::clampInt($bg['health_checks'] ?? null, 1, 20, $d['blue_green']['health_checks']),
                'keep_previous_minutes' => self::clampInt($bg['keep_previous_minutes'] ?? null, 0, 1440, $d['blue_green']['keep_previous_minutes']),
            ],
            [
                'steps' => $steps,
                'interval_minutes' => self::clampInt($canary['interval_minutes'] ?? null, 1, 1440, $d['canary']['interval_minutes']),
                'error_rate_threshold' => is_numeric($error) ? max(0, min(100, $error + 0)) : null,
                'p95_threshold_ms' => is_numeric($p95) && (int) $p95 >= 1 ? min(600000, (int) $p95) : null,
            ],
        );
    }

    /**
     * These settings with a validated partial update applied: strategy and the option keys
     * present in $input (a null clears an optional value).
     *
     * @param  array<string, mixed>  $input
     */
    public function merge(array $input): self
    {
        $current = $this->toArray();

        if (array_key_exists('strategy', $input)) {
            $current['strategy'] = self::normalizeName($input['strategy']) ?? $current['strategy'];
        }
        foreach (['blue_green', 'canary'] as $group) {
            $patch = $input[$group] ?? $input[str_replace('_', '-', $group)] ?? null;
            if (! is_array($patch)) {
                continue;
            }
            foreach (array_keys(self::DEFAULTS[$group]) as $key) {
                if (array_key_exists($key, $patch)) {
                    $current[$group][$key] = $patch[$key];
                }
            }
        }

        return self::fromArray($current);
    }

    public function isDirect(): bool
    {
        return $this->strategy === self::DIRECT;
    }

    /**
     * The options of $strategy (default: this one's); empty for direct.
     *
     * @return array<string, mixed>
     */
    public function options(?string $strategy = null): array
    {
        return match ($strategy ?? $this->strategy) {
            self::BLUE_GREEN => $this->blueGreen,
            self::CANARY => $this->canary,
            default => [],
        };
    }

    /** @return array{strategy: string, blue_green: array<string, mixed>, canary: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'strategy' => $this->strategy,
            'blue_green' => $this->blueGreen,
            'canary' => $this->canary,
        ];
    }

    /**
     * Rules of a (partial) update: PUT /projects/{project}/deploy-strategy, runr.yaml deploy:.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'strategy' => ['sometimes', 'string', Rule::in(array_merge(self::NAMES, ['blue-green', 'bluegreen']))],
            'blue_green' => ['sometimes', 'array'],
            'blue_green.auto_promote' => ['sometimes', 'boolean'],
            // a path of the function, optionally with a query: no scheme, host or spaces
            'blue_green.health_path' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^\/[^\s#]*$/'],
            'blue_green.health_checks' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'blue_green.keep_previous_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'canary' => ['sometimes', 'array'],
            'canary.steps' => ['sometimes', 'array', 'min:1', 'max:10', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_array($value) && ! self::validSteps($value)) {
                    $fail(__('rollouts.steps_invalid'));
                }
            }],
            'canary.steps.*' => ['integer', 'min:1', 'max:100'],
            'canary.interval_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'canary.error_rate_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'canary.p95_threshold_ms' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600000'],
        ];
    }

    /** Whole percentages 1..100, strictly increasing, 1..10 of them. */
    public static function validSteps(array $steps): bool
    {
        if ($steps === [] || count($steps) > 10 || ! array_is_list($steps)) {
            return false;
        }

        $previous = 0;
        foreach ($steps as $step) {
            if (! (is_int($step) || (is_string($step) && ctype_digit($step))) || (int) $step <= $previous || (int) $step > 100) {
                return false;
            }
            $previous = (int) $step;
        }

        return true;
    }

    private static function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
