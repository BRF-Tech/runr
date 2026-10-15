<?php

namespace Tests\Feature;

use App\Console\Commands\Concerns\E2eTestResultTrait;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The results table of `php artisan test:e2e` (E2eTestResultTrait): the Time column, the
 * slowest list and the Markdown report show how long each test took (finishTest), not the
 * time since it started.
 */
class E2eResultsTableTest extends TestCase
{
    private BufferedOutput $buffer;

    private function command(): Command
    {
        $this->buffer = new BufferedOutput;
        $command = new class extends Command
        {
            use E2eTestResultTrait;

            public function showTable(array $tests, float $start): void
            {
                $this->printResultsTable($tests, $start);
            }

            public function writeReport(array $tests, float $start): void
            {
                $this->saveResultsMarkdown($tests, $start);
            }

            public function finish(array $test): array
            {
                return $this->finishTest($test);
            }
        };
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->buffer));

        return $command;
    }

    private function row(string $name, float $startTime, ?float $duration, bool $passed = true): array
    {
        return [
            'name' => $name,
            'steps' => ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => $passed ? true : 'HTTP 500'],
            'startTime' => $startTime,
            'duration' => $duration,
        ];
    }

    /** A test that started long ago and took 2.5 s: the table says 2.5s, not the ~600 s since its start */
    private function rows(): array
    {
        $now = microtime(true);

        return [
            $this->row('caas-nginx-http', $now - 600, 2.5),
            $this->row('faas-python-http', $now - 300, 41.2),
            $this->row('xaas-go-http', $now - 200, 0.42),
            $this->row('sbx-create', 0.0, null),
            $this->row('caas-broken', $now - 100, 7.0, false),
        ];
    }

    public function test_finish_test_takes_the_duration_from_the_start(): void
    {
        $command = $this->command();

        $finished = $command->finish($this->row('a', microtime(true) - 3, null));
        $this->assertGreaterThanOrEqual(3.0, $finished['duration']);
        $this->assertLessThan(4.0, $finished['duration']);

        // never started: no duration
        $this->assertNull($command->finish($this->row('b', 0.0, null))['duration']);
    }

    public function test_the_time_column_shows_each_tests_duration(): void
    {
        $this->command()->showTable($this->rows(), microtime(true) - 700);
        $out = $this->buffer->fetch();

        $this->assertMatchesRegularExpression('/caas-nginx-http\s.*\s2\.5s$/m', $out);
        $this->assertMatchesRegularExpression('/faas-python-http\s.*\s41\.2s$/m', $out);
        $this->assertMatchesRegularExpression('/xaas-go-http\s.*\s420ms$/m', $out);
        $this->assertMatchesRegularExpression('/sbx-create\s.*\s─$/m', $out);
        $this->assertDoesNotMatchRegularExpression('/\b(600|300|200|100)(\.\d)?s\b/', $out, 'time since the start shown');

        // slowest: passed tests, longest first (the failed one is not listed)
        $this->assertStringContainsString("1. faas-python-http  41.2s\n  2. caas-nginx-http  2.5s\n  3. xaas-go-http  420ms", $out);
        $this->assertStringNotContainsString('. caas-broken', $out);
    }

    public function test_the_markdown_report_shows_the_durations(): void
    {
        $this->command()->writeReport($this->rows(), microtime(true) - 700);
        $this->assertMatchesRegularExpression('/Report saved: (\S+\.md)/', $out = $this->buffer->fetch());
        preg_match('/Report saved: (\S+\.md)/', $out, $m);

        try {
            $md = (string) file_get_contents($m[1]);
            $this->assertStringContainsString('| caas-nginx-http | ✓ | ✓ | ✓ | ✓ | 2.5s |', $md);
            $this->assertStringContainsString('| sbx-create | ✓ | ✓ | ✓ | ✓ | ─ |', $md);
            $this->assertStringContainsString("1. faas-python-http — 41.2s\n2. caas-nginx-http — 2.5s\n3. xaas-go-http — 420ms", $md);
        } finally {
            @unlink($m[1]);
        }
    }
}
