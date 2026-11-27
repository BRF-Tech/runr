<?php

namespace Tests\Feature\OrgTransfer;

use App\Services\Dns\DnsResolver;
use App\Services\OrgTransfer\Destinations\DestinationException;
use App\Services\OrgTransfer\Destinations\S3Uploader;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * An organization's export or import bucket (a tenant's context) is held to the outbound guard:
 * every signed request is checked right before it is sent and goes to the addresses that check
 * saw (curl pin, no redirects). The platform's own transfers may use a bucket on its network.
 */
class S3TargetGuardTest extends TestCase
{
    /** @var array<string, list<string>> */
    public array $dns = [];

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->app->instance(DnsResolver::class, new class($test) extends DnsResolver
        {
            public function __construct(private readonly S3TargetGuardTest $test) {}

            public function addresses(string $name): array
            {
                return $this->test->dns[$name] ?? [];
            }
        });

        Http::fake(function ($request, array $options) {
            $this->sent[] = $options + ['url' => $request->url()];

            return Http::response('', 200, ['ETag' => '"e"']);
        });
    }

    private function uploader(bool $inTenant): S3Uploader
    {
        return new class($inTenant) extends S3Uploader
        {
            public function __construct(private readonly bool $tenant) {}

            protected function inTenant(): bool
            {
                return $this->tenant;
            }
        };
    }

    /** @return array{endpoint: string, bucket: string, access_key_id: string, secret_access_key: string, path_style: bool} */
    private function bucket(string $endpoint, bool $pathStyle = true): array
    {
        return ['endpoint' => $endpoint, 'bucket' => 'exports', 'access_key_id' => 'AKIA', 'secret_access_key' => 'secret', 'path_style' => $pathStyle];
    }

    public function test_a_tenants_bucket_is_pinned_to_the_checked_addresses(): void
    {
        $this->dns['s3.example.com'] = ['93.184.216.34'];
        $this->dns['exports.s3.example.com'] = ['93.184.216.35', '2606:4700:4700::1111'];

        $this->uploader(true)->send($this->bucket('https://s3.example.com'), 'HEAD', 'org.runr', [], '');
        $this->uploader(true)->send($this->bucket('https://s3.example.com:9000', false), 'HEAD', 'org.runr', [], '');

        $this->assertCount(2, $this->sent);
        $this->assertFalse($this->sent[0]['allow_redirects']);
        $this->assertSame('s3.example.com:443:93.184.216.34', $this->sent[0]['curl'][CURLOPT_RESOLVE][0]);
        $this->assertSame('exports.s3.example.com:9000:93.184.216.35,[2606:4700:4700::1111]', $this->sent[1]['curl'][CURLOPT_RESOLVE][0], 'virtual-hosted style: the bucket host');
    }

    public function test_a_tenants_bucket_on_an_internal_address_is_refused(): void
    {
        foreach (['http://minio.storage.svc:9000', 'http://169.254.169.254', 'http://[::ffff:169.254.169.254]', 'https://s3.example.com'] as $endpoint) {
            $this->dns['s3.example.com'] = ['64:ff9b::a9fe:a9fe'];
            try {
                $this->uploader(true)->send($this->bucket($endpoint), 'GET', 'org.runr', [], '');
                $this->fail("{$endpoint} reached");
            } catch (DestinationException $e) {
                $this->assertSame(__('observability.url.private'), $e->getMessage(), $endpoint);
            }
        }
        $this->assertSame([], $this->sent);
    }

    public function test_the_platforms_transfer_may_use_a_bucket_on_its_network(): void
    {
        $this->uploader(false)->send($this->bucket('http://minio.storage.svc:9000'), 'HEAD', 'org.runr', [], '');

        $this->assertCount(1, $this->sent);
        $this->assertArrayNotHasKey('curl', $this->sent[0]);
    }
}
