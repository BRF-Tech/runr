<?php

namespace Tests\Unit;

use App\Services\HelmResult;
use App\Services\HelmService;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * HelmService's command lines for Helm 4: every install, upgrade and rollback applies client-side
 * as Helm 3 did (--server-side=false; Helm 4 would install a new release server-side), a wait is
 * Helm 3's (--wait=legacy, one argument; Helm 4's bare --wait is kstatus), and an upgrade that rolls
 * itself back says --rollback-on-failure (Helm 3's --atomic). None of the flags Helm 4 renamed.
 */
class HelmCommandLineTest extends TestCase
{
    private const RENAMED = ['--atomic', '--force'];

    private function helm(): HelmService
    {
        return new class extends HelmService
        {
            /** @var list<list<string>> */
            public array $commands = [];

            protected function run(array $command): HelmResult
            {
                $this->commands[] = $command;

                return new HelmResult(true, '', '', 0);
            }
        };
    }

    /**
     * Each call that applies a release's manifests: whether it waits, whether a failure rolls it back
     *
     * @return array<string, array{Closure(HelmService): HelmResult, bool, bool}>
     */
    public static function applying(): array
    {
        return [
            'install' => [fn (HelmService $h) => $h->install('r', 'repo/c', 'ns', ['a' => 'b'], 'kc', '1.2.3'), true, false],
            'install without a wait' => [fn (HelmService $h) => $h->install('r', 'repo/c', 'ns', [], 'kc', '1.2.3', wait: false), false, false],
            'upgrade' => [fn (HelmService $h) => $h->upgrade('r', 'repo/c', 'ns', [], 'kc', '1.2.3'), true, false],
            'values reused' => [fn (HelmService $h) => $h->upgradeReusingValues('r', 'repo/c', 'ns', 'kc', '1.2.3', ['k' => '1']), false, false],
            'values reused, waiting' => [fn (HelmService $h) => $h->upgradeReusingValues('r', 'repo/c', 'ns', 'kc', '1.2.3', ['k' => '1'], wait: true), true, false],
            'another chart' => [fn (HelmService $h) => $h->upgradeToChart('r', 'repo/c', 'ns', 'kc', '1.2.3', ['k' => 1]), true, true],
            'another chart version' => [fn (HelmService $h) => $h->upgradeToChartVersion('r', 'repo/c', 'ns', 'kc', '1.2.3', '{}'), true, true],
            'rollback' => [fn (HelmService $h) => $h->rollback('r', 'ns', 'kc', 2), false, false],
        ];
    }

    #[DataProvider('applying')]
    public function test_a_release_is_applied_as_helm_3_did(Closure $call, bool $waits, bool $rollsBack): void
    {
        $helm = $this->helm();
        $call($helm);

        $this->assertCount(1, $helm->commands);
        $argv = $helm->commands[0];
        $this->assertSame(['--server-side=false'], array_values(preg_grep('/^--server-side/', $argv)), 'client-side apply');
        $this->assertNotContains('--force-conflicts', $argv, 'a server-side flag: helm refuses it with client-side apply');
        $this->assertSame($waits ? ['--wait=legacy'] : [], array_values(preg_grep('/^--wait/', $argv)));
        if ($waits) {
            $this->assertSame('10m', $argv[array_search('--timeout', $argv, true) + 1]);
        }
        $this->assertSame($rollsBack, in_array('--rollback-on-failure', $argv, true));
        foreach (self::RENAMED as $flag) {
            $this->assertNotContains($flag, $argv, "{$flag} is deprecated in Helm 4");
        }
    }

    public function test_reading_a_release_passes_no_apply_flags(): void
    {
        $helm = $this->helm();
        $helm->releaseValuesJson('r', 'ns', 'kc');
        $helm->listedRelease('r', 'ns', 'kc', 'c');
        $helm->history('r', 'ns', 'kc');
        $helm->uninstall('r', 'ns', 'kc');

        $this->assertSame([['get', 'values'], ['list'], ['history'], ['uninstall']], array_map(
            fn (array $argv) => $argv[1] === 'get' ? [$argv[1], $argv[2]] : [$argv[1]],
            $helm->commands,
        ));
        foreach ($helm->commands as $argv) {
            $this->assertSame([], preg_grep('/^--(server-side|wait|rollback-on-failure|atomic)/', $argv), implode(' ', $argv));
        }
    }
}
