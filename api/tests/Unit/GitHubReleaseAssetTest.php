<?php

namespace Tests\Unit;

use App\Support\GitHubReleaseAsset;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * A file of a GitHub release (Zot's chart, Knative's CRDs): a 5xx, a 429 or a broken connection is
 * tried again, 4 tries in all, 2, 4, 8 s apart, then the file comes once through the GitHub API's
 * asset endpoint; a 404 ends at once; a digest that does not match refuses the body.
 */
class GitHubReleaseAssetTest extends TestCase
{
    private const URL = 'https://github.com/project-zot/helm-charts/releases/download/zot-0.1.130/zot-0.1.130.tgz';

    private const RELEASE = 'https://api.github.com/repos/project-zot/helm-charts/releases/tags/zot-0.1.130';

    private const ASSET = 'https://api.github.com/repos/project-zot/helm-charts/releases/assets/123';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private static function release(): array
    {
        return ['assets' => [
            ['name' => 'zot-0.1.129.tgz', 'url' => 'https://api.github.com/repos/project-zot/helm-charts/releases/assets/122'],
            ['name' => 'zot-0.1.130.tgz', 'url' => self::ASSET],
        ]];
    }

    public function test_the_url_of_a_release_download(): void
    {
        $this->assertSame(['owner' => 'project-zot', 'repo' => 'helm-charts', 'tag' => 'zot-0.1.130', 'file' => 'zot-0.1.130.tgz'], GitHubReleaseAsset::parse(self::URL));
        $this->assertSame('knative-v1.21.0', GitHubReleaseAsset::parse('https://github.com/knative/serving/releases/download/knative-v1.21.0/serving-crds.yaml')['tag']);
        $this->assertNull(GitHubReleaseAsset::parse('https://zotregistry.dev/helm-charts/zot-0.1.130.tgz'));
        $this->assertNull(GitHubReleaseAsset::parse('https://github.com/project-zot/helm-charts/archive/main.tar.gz'));
    }

    public function test_a_gateway_timeout_is_tried_again(): void
    {
        Http::fake([self::URL => Http::sequence()->push('', 504)->push('', 502)->push('the chart', 200)]);

        $this->assertSame('the chart', GitHubReleaseAsset::fetch(self::URL));
        Http::assertSentCount(3);
        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(4)->seconds()]);
    }

    public function test_after_four_tries_the_file_comes_through_the_api(): void
    {
        Http::fake([
            self::URL => Http::response('', 504),
            self::RELEASE => Http::response(self::release()),
            self::ASSET => Http::response('the chart'),
        ]);

        $this->assertSame('the chart', GitHubReleaseAsset::fetch(self::URL, hash('sha256', 'the chart')));
        $this->assertCount(4, Http::recorded(fn (Request $r) => $r->url() === self::URL));
        Http::assertSent(fn (Request $r) => $r->url() === self::ASSET && $r->hasHeader('Accept', 'application/octet-stream'));
        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(4)->seconds(), Sleep::for(8)->seconds()]);
    }

    public function test_a_file_that_is_not_there_ends_at_once(): void
    {
        Http::fake([self::URL => Http::response('Not Found', 404), 'api.github.com/*' => Http::response(self::release())]);

        $this->assertNull(GitHubReleaseAsset::fetch(self::URL));
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_another_digest_is_refused(): void
    {
        Http::fake([
            self::URL => Http::response('', 503),
            self::RELEASE => Http::response(self::release()),
            self::ASSET => Http::response('another chart'),
        ]);

        $this->assertNull(GitHubReleaseAsset::fetch(self::URL, hash('sha256', 'the chart')));
        Http::assertSent(fn (Request $r) => $r->url() === self::ASSET);
    }

    public function test_a_direct_download_of_another_digest_is_refused(): void
    {
        Http::fake([self::URL => Http::response('another chart')]);

        $this->assertNull(GitHubReleaseAsset::fetch(self::URL, hash('sha256', 'the chart')));
        $this->assertSame('another chart', GitHubReleaseAsset::fetch(self::URL));
    }

    public function test_no_asset_of_that_name_is_no_file(): void
    {
        Http::fake([self::RELEASE => Http::response(['assets' => [['name' => 'other.tgz', 'url' => self::ASSET]]])]);

        $this->assertNull(GitHubReleaseAsset::viaApi(self::URL));
        Http::assertNotSent(fn (Request $r) => $r->url() === self::ASSET);
    }

    public function test_no_release_is_no_file(): void
    {
        Http::fake([self::RELEASE => Http::response(['message' => 'Not Found'], 404)]);

        $this->assertNull(GitHubReleaseAsset::viaApi(self::URL));
    }

    public function test_an_asset_url_elsewhere_is_not_followed(): void
    {
        Http::fake([self::RELEASE => Http::response(['assets' => [['name' => 'zot-0.1.130.tgz', 'url' => 'https://example.test/zot.tgz']]])]);

        $this->assertNull(GitHubReleaseAsset::viaApi(self::URL));
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), 'https://example.test/'));
    }

    public function test_another_host_gets_its_tries_but_no_api(): void
    {
        $url = 'https://zotregistry.dev/helm-charts/zot-0.1.130.tgz';
        Http::fake([$url => Http::response('', 504)]);

        $this->assertNull(GitHubReleaseAsset::fetch($url));
        Http::assertSentCount(4);
    }
}
