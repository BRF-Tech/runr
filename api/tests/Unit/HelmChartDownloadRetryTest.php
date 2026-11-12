<?php

namespace Tests\Unit;

use App\Services\HelmResult;
use App\Services\HelmService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * A Helm call whose chart download fails on the way (github.com's 504 that failed a region's Zot
 * install, run 42578321526 of runr-runtimes) is tried again, 4 tries in all, 2, 4, 8 s apart; a
 * chart of a GitHub release that still does not come is fetched once through the GitHub API,
 * checked against the digest of the repository's index, and installed from that file.
 */
class HelmChartDownloadRetryTest extends TestCase
{
    private const URL = 'https://github.com/project-zot/helm-charts/releases/download/zot-0.1.130/zot-0.1.130.tgz';

    private const GATEWAY_TIMEOUT = 'Error: failed to fetch '.self::URL.' : 504 Gateway Timeout';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    /**
     * A HelmService whose helm calls answer $results in order (the last one repeats) and whose
     * repository index gives $digest for zot 0.1.130; the commands and, for a local chart, the
     * file's content at the call are recorded.
     *
     * @param  list<HelmResult>  $results
     */
    private static function helm(array $results, ?string $digest = null): HelmService
    {
        return new class($results, $digest) extends HelmService
        {
            /** @var list<array{cmd: list<string>, chart: ?string}> */
            public array $calls = [];

            public function __construct(private array $results, private ?string $digest) {}

            protected function run(array $command): HelmResult
            {
                $chart = $command[3] === 'zot' ? $command[4] : $command[3];
                $this->calls[] = ['cmd' => $command, 'chart' => is_file($chart) ? file_get_contents($chart) : null];

                return $this->results[min(count($this->calls) - 1, count($this->results) - 1)];
            }

            public function getRepoChartEntries(string $repoName, string $chartName): array
            {
                return $repoName === 'zot' && $chartName === 'zot' && $this->digest
                    ? [['version' => '0.1.129', 'digest' => str_repeat('0', 64)], ['version' => '0.1.130', 'digest' => $this->digest]]
                    : [];
            }
        };
    }

    private static function failed(string $error): HelmResult
    {
        return new HelmResult(false, '', $error, 1);
    }

    private static function ok(): HelmResult
    {
        return new HelmResult(true, 'Release "zot" has been upgraded. Happy Helming!', '', 0);
    }

    private static function install(HelmService $helm): HelmResult
    {
        return $helm->install('zot', 'zot/zot', 'runr-registry', [], "apiVersion: v1\n", '0.1.130');
    }

    public function test_download_failures_on_the_way_are_recognised(): void
    {
        $this->assertSame(self::URL, self::failed(self::GATEWAY_TIMEOUT)->chartDownloadFailure());
        $this->assertSame(self::URL, self::failed('Error: failed to fetch '.self::URL.' : 503 Service Unavailable')->chartDownloadFailure());
        $this->assertSame(self::URL, self::failed('Error: failed to fetch '.self::URL.' : 429 Too Many Requests')->chartDownloadFailure());
        $this->assertSame(self::URL, self::failed('Error: Get "'.self::URL.'": net/http: TLS handshake timeout')->chartDownloadFailure());
        $this->assertNull(self::failed('Error: failed to fetch '.self::URL.' : 404 Not Found')->chartDownloadFailure());
        $this->assertNull(self::failed('Error: chart "zot" version "9.9.9" not found in https://zotregistry.dev/helm-charts repository')->chartDownloadFailure());
        $this->assertNull(self::failed('Error: INSTALLATION FAILED: etcdserver: request timed out')->chartDownloadFailure());
        $this->assertNull((new HelmResult(true, '', self::GATEWAY_TIMEOUT, 0))->chartDownloadFailure());
    }

    public function test_a_gateway_timeout_is_tried_again(): void
    {
        $helm = self::helm([self::failed(self::GATEWAY_TIMEOUT), self::failed(self::GATEWAY_TIMEOUT), self::ok()]);

        $this->assertTrue(self::install($helm)->success);
        $this->assertCount(3, $helm->calls);
        $this->assertSame('zot/zot', $helm->calls[2]['cmd'][4]);
        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(4)->seconds()]);
    }

    public function test_after_four_tries_the_chart_comes_through_the_github_api(): void
    {
        Http::fake([
            'api.github.com/repos/project-zot/helm-charts/releases/tags/zot-0.1.130' => Http::response(['assets' => [
                ['name' => 'zot-0.1.130.tgz', 'url' => 'https://api.github.com/repos/project-zot/helm-charts/releases/assets/7'],
            ]]),
            'api.github.com/repos/project-zot/helm-charts/releases/assets/7' => Http::response('the zot chart'),
        ]);
        $helm = self::helm([self::failed(self::GATEWAY_TIMEOUT), self::failed(self::GATEWAY_TIMEOUT), self::failed(self::GATEWAY_TIMEOUT),
            self::failed(self::GATEWAY_TIMEOUT), self::ok()], hash('sha256', 'the zot chart'));

        $this->assertTrue(self::install($helm)->success);
        $this->assertCount(5, $helm->calls);
        $last = $helm->calls[4];
        $this->assertStringEndsWith('/zot-0.1.130.tgz', $last['cmd'][4]);
        $this->assertSame('the zot chart', $last['chart'], 'the helm call installs the file the API gave');
        $this->assertFileDoesNotExist($last['cmd'][4], 'the file is gone afterwards');
        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(4)->seconds(), Sleep::for(8)->seconds()]);
    }

    public function test_no_digest_or_another_one_no_file(): void
    {
        Http::fake([
            'api.github.com/repos/project-zot/helm-charts/releases/tags/zot-0.1.130' => Http::response(['assets' => [
                ['name' => 'zot-0.1.130.tgz', 'url' => 'https://api.github.com/repos/project-zot/helm-charts/releases/assets/7'],
            ]]),
            'api.github.com/repos/project-zot/helm-charts/releases/assets/7' => Http::response('another chart'),
        ]);

        $helm = self::helm([self::failed(self::GATEWAY_TIMEOUT)]);
        $result = self::install($helm);
        $this->assertFalse($result->success);
        $this->assertSame(self::GATEWAY_TIMEOUT, $result->error);
        $this->assertCount(4, $helm->calls);
        Http::assertNothingSent();

        $helm = self::helm([self::failed(self::GATEWAY_TIMEOUT)], hash('sha256', 'the zot chart'));
        $this->assertFalse(self::install($helm)->success);
        $this->assertCount(4, $helm->calls, 'a chart of another digest is not installed');
    }

    public function test_any_other_failure_is_returned_at_once(): void
    {
        $helm = self::helm([self::failed('Error: INSTALLATION FAILED: rendered manifests contain a resource that already exists')]);

        $this->assertFalse(self::install($helm)->success);
        $this->assertCount(1, $helm->calls);
        Sleep::assertNeverSlept();
    }

    public function test_the_upgrades_of_a_chart_are_tried_again_too(): void
    {
        $helm = self::helm([self::failed(self::GATEWAY_TIMEOUT), self::ok()]);
        $this->assertTrue($helm->upgradeToChart('zot', 'zot/zot', 'runr-registry', "apiVersion: v1\n", '0.1.130', ['a' => 1])->success);
        $this->assertCount(2, $helm->calls);

        $helm = self::helm([self::failed(self::GATEWAY_TIMEOUT), self::ok()]);
        $this->assertTrue($helm->upgradeToChartVersion('zot', 'zot/zot', 'runr-registry', "apiVersion: v1\n", '0.1.130', '{}')->success);
        $this->assertCount(2, $helm->calls);

        $helm = self::helm([self::failed(self::GATEWAY_TIMEOUT), self::ok()]);
        $this->assertTrue($helm->upgradeReusingValues('zot', 'zot/zot', 'runr-registry', "apiVersion: v1\n", '0.1.130', [])->success);
        $this->assertCount(2, $helm->calls);
    }
}
