<?php

namespace App\Console\Commands\Concerns;

trait E2eTestResultTrait
{
    private function printResultsTable(array $tests, float $startTime): void
    {
        $this->info('');
        $this->info('── Results ──');

        $maxLen = max(array_map(fn ($t) => strlen($t['name']), $tests));
        $maxLen = max($maxLen, 4); // min "Test" header width

        $header = '  '.str_pad('Test', $maxLen + 2)
            .str_pad('Version', 9)
            .str_pad('Deploy', 8)
            .str_pad('Ready', 7)
            .str_pad('Verify', 8)
            .'Time';
        $this->line($header);

        $separator = '  '.str_repeat('─', $maxLen + 2)
            .str_repeat('─', 9)
            .str_repeat('─', 8)
            .str_repeat('─', 7)
            .str_repeat('─', 8)
            .str_repeat('─', 8);
        $this->line($separator);

        foreach ($tests as $t) {
            $line = '  '.str_pad($t['name'], $maxLen + 2)
                .$this->cell($this->stepIcon($t['steps']['version']), 9)
                .$this->cell($this->stepIcon($t['steps']['deploy']), 8)
                .$this->cell($this->stepIcon($t['steps']['ready']), 7)
                .$this->cell($this->stepIcon($t['steps']['verify']), 8)
                .$this->testDuration($t);
            $this->line($line);
        }

        $this->printSummary($tests, $startTime);
        $this->printFailures($tests);
        $this->printSlowest($tests);
    }

    /** $cell padded to $width columns as shown: style tags take none, an icon one */
    private function cell(string $cell, int $width): string
    {
        $shown = mb_strwidth((string) preg_replace('/<[^<>]*>/', '', $cell));

        return $cell.str_repeat(' ', max(0, $width - $shown));
    }

    private function printSummary(array $tests, float $startTime): void
    {
        $counts = $this->countResults($tests);
        $total = $counts['passed'] + $counts['failed'];
        $elapsed = round(microtime(true) - $startTime);

        $this->info('');
        $this->info("  Passed: {$counts['passed']}/{$total}  Failed: {$counts['failed']}");
        $this->info('  Duration: '.intdiv($elapsed, 60).'m '.($elapsed % 60).'s');
    }

    private function printFailures(array $tests): void
    {
        $failures = array_filter($tests, fn ($t) => ! $this->isTestPassed($t));
        if (empty($failures)) {
            return;
        }

        $this->info('');
        $this->info('── Failures ──');

        foreach ($failures as $t) {
            foreach ($t['steps'] as $step => $val) {
                if (is_string($val) && $val !== 'skipped') {
                    $this->line("  <fg=red>✗</> {$t['name']} [{$step}]: {$val}");
                }
            }
        }
    }

    private function printSlowest(array $tests, int $count = 3): void
    {
        $top = $this->slowestTests($tests, $count);
        if (empty($top)) {
            return;
        }

        $this->info('');
        $this->info('── Slowest ──');
        foreach ($top as $i => $t) {
            $num = $i + 1;
            $this->line("  {$num}. {$t['name']}  {$this->testDuration($t)}");
        }
    }

    /**
     * A test row with its duration (the Time column), taken when the test's own work is done:
     * from its start to its last step, as the other e2e suites (test:e2e-tenancy, -ticket,
     * -authprovider) time each check. Functions deployed one after another
     * (runTestsSequentially) are timed from build to ready: the verification that runs for all
     * of them together afterwards is no single test's time. A row that never started
     * (startTime 0) gets no duration.
     */
    private function finishTest(array $test): array
    {
        if (($test['startTime'] ?? 0) > 0) {
            $test['duration'] = microtime(true) - $test['startTime'];
        }

        return $test;
    }

    /** The Time column: the test's duration, "─" without one */
    private function testDuration(array $test): string
    {
        return isset($test['duration']) ? $this->formatDuration(round($test['duration'] * 1000)) : '─';
    }

    /** The passed tests that have a duration, the longest first */
    private function slowestTests(array $tests, int $count): array
    {
        $passed = array_values(array_filter($tests, fn ($t) => $this->isTestPassed($t) && isset($t['duration'])));
        usort($passed, fn ($a, $b) => $b['duration'] <=> $a['duration']);

        return array_slice($passed, 0, $count);
    }

    private function stepIcon($value): string
    {
        if ($value === true) {
            return '<fg=green>✓</>';
        }
        if ($value === null || $value === 'skipped') {
            return '─';
        }

        return '<fg=red>✗</>';
    }

    private function formatDuration(float $ms): string
    {
        return $ms >= 1000 ? round($ms / 1000, 1).'s' : round($ms).'ms';
    }

    private function isTestPassed(array $test): bool
    {
        foreach ($test['steps'] as $val) {
            if ($val !== true) {
                return false;
            }
        }

        return true;
    }

    private function countResults(array $tests): array
    {
        $passed = 0;
        $failed = 0;
        foreach ($tests as $t) {
            $this->isTestPassed($t) ? $passed++ : $failed++;
        }

        return compact('passed', 'failed');
    }

    private function stepIconPlain($value): string
    {
        if ($value === true) {
            return '✓';
        }
        if ($value === null || $value === 'skipped') {
            return '─';
        }

        return '✗';
    }

    private function saveResultsMarkdown(array $tests, float $startTime): void
    {
        $counts = $this->countResults($tests);
        $total = $counts['passed'] + $counts['failed'];
        $elapsed = round(microtime(true) - $startTime);
        $date = date('Y-m-d H:i:s');
        $ts = date('Ymd-His');

        $md = "# E2E Test Results — {$date}\n\n";
        $md .= "**Passed:** {$counts['passed']}/{$total}  ";
        $md .= "**Failed:** {$counts['failed']}  ";
        $md .= '**Duration:** '.intdiv($elapsed, 60).'m '.($elapsed % 60)."s\n\n";

        // Results table
        $md .= "## Results\n\n";
        $md .= "| Test | Version | Deploy | Ready | Verify | Time |\n";
        $md .= "|------|---------|--------|-------|--------|------|\n";

        foreach ($tests as $t) {
            $md .= "| {$t['name']} "
                ."| {$this->stepIconPlain($t['steps']['version'])} "
                ."| {$this->stepIconPlain($t['steps']['deploy'])} "
                ."| {$this->stepIconPlain($t['steps']['ready'])} "
                ."| {$this->stepIconPlain($t['steps']['verify'])} "
                ."| {$this->testDuration($t)} |\n";
        }

        // Failures
        $failures = array_filter($tests, fn ($t) => ! $this->isTestPassed($t));
        if (! empty($failures)) {
            $md .= "\n## Failures\n\n";
            foreach ($failures as $t) {
                foreach ($t['steps'] as $step => $val) {
                    if (is_string($val) && $val !== 'skipped') {
                        $md .= "- **{$t['name']}** [{$step}]: {$val}\n";
                    }
                }
            }
        }

        // Slowest
        $top = $this->slowestTests($tests, 3);
        if (! empty($top)) {
            $md .= "\n## Slowest\n\n";
            foreach ($top as $i => $t) {
                $md .= ($i + 1).". {$t['name']} — {$this->testDuration($t)}\n";
            }
        }

        $path = storage_path("e2e-results-{$ts}.md");
        file_put_contents($path, $md);
        $this->info("  Report saved: {$path}");
    }
}
