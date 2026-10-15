<?php

namespace Tests\Concerns;

use App\Models\KnativeRegion;
use App\Models\UploadedImage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;

/**
 * A fake region for uploaded images (docs/images.md): an internal Zot registry, and the API server
 * the RunR API talks to — the receiver Job's pod (ready unless $receiverReady is false), the
 * receiver behind the service proxy (PUT upload?offset= tracks the contiguous bytes received,
 * POST complete answers 202, GET status answers $receiverStatus) and Zot's manifest DELETE.
 * Every request is recorded in $imgCalls; $routes ("METHOD /path" or "METHOD /path*") win.
 */
trait FakesImageCluster
{
    protected const IMG_ZOT = '10.43.0.5:5000';

    /** @var list<array{method: string, path: string, query: string, body: mixed}> */
    protected array $imgCalls = [];

    protected bool $receiverReady = true;

    protected int $receiverReceived = 0;

    protected array $receiverStatus = ['state' => 'verifying', 'received' => 0];

    protected function imageKubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    protected function imageRegion(array $attributes = []): KnativeRegion
    {
        static $n = 0;
        $n++;

        return KnativeRegion::create(array_merge([
            'name' => "img-{$n}",
            'project_namespace' => 'fn',
            'is_active' => true,
            'kubeconfig' => $this->imageKubeconfig(),
            'registry_mode' => 'internal',
            'registry_status' => 'ready',
            'registry_address' => self::IMG_ZOT,
            'registry_namespace' => 'runr-registry',
        ], $attributes))->fresh();
    }

    /** An uploaded image record (no upload, no cluster). */
    protected function uploadedImage(KnativeRegion $region, array $attributes = []): UploadedImage
    {
        $owner = $attributes['owner_key'] ?? UploadedImage::PLATFORM_OWNER;
        $repository = $attributes['repository'] ?? 'app';

        return UploadedImage::create(array_merge([
            'owner_key' => $owner,
            'knative_region_id' => $region->id,
            'repository' => $repository,
            'tag' => '1.0',
            'registry_repository' => "runr-uploads/{$owner}/{$repository}",
            'digest' => 'sha256:'.str_repeat('a', 64),
            'media_type' => 'application/vnd.oci.image.manifest.v1+json',
            'kind' => UploadedImage::KIND_IMAGE,
            'source_format' => 'docker',
            'size_bytes' => 1024,
            'archive_bytes' => 2048,
            'platforms' => ['linux/amd64'],
            'layers' => 2,
            'source_filename' => 'app.tar',
        ], $attributes));
    }

    protected function fakeImageCluster(array $routes = []): void
    {
        Http::fake(function (Request $request) use ($routes) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $query = (string) parse_url($request->url(), PHP_URL_QUERY);
            $method = $request->method();
            $raw = $request->body();
            $body = json_decode($raw, true) ?? $raw;
            $this->imgCalls[] = ['method' => $method, 'path' => $path, 'query' => $query, 'body' => $body];

            foreach ($routes as $key => $response) {
                [$m, $p] = explode(' ', $key, 2);
                $match = str_ends_with($p, '*') ? str_starts_with($path, rtrim($p, '*')) : $path === $p;
                if ($m === $method && $match) {
                    if ($response instanceof \Closure) {
                        $response = $response($request);
                        if (! is_array($response)) {
                            return $response;
                        }
                    }
                    if (is_array($response) && array_is_list($response) && count($response) === 2 && is_int($response[1])) {
                        return Http::response($response[0], $response[1]);
                    }

                    return Http::response($response);
                }
            }

            // the receiver Job's pod
            if ($method === 'GET' && $path === '/api/v1/namespaces/fn/pods') {
                return Http::response(['items' => [[
                    'metadata' => ['name' => 'image-recv-pod'],
                    'status' => ['phase' => 'Running', 'conditions' => [['type' => 'Ready', 'status' => $this->receiverReady ? 'True' : 'False']]],
                ]]]);
            }

            if (preg_match('#^/api/v1/namespaces/fn/services/http:image-recv-[a-z0-9]+:8080/proxy/(.+)$#', $path, $m)) {
                parse_str($query, $q);
                switch ("{$method} {$m[1]}") {
                    case 'PUT upload':
                        $offset = (int) ($q['offset'] ?? 0);
                        if ($offset <= $this->receiverReceived) {
                            $this->receiverReceived = max($this->receiverReceived, $offset + strlen($raw));
                        }

                        return Http::response(['received' => $this->receiverReceived, 'size' => 0]);
                    case 'POST complete':
                        return Http::response(['state' => 'verifying', 'received' => $this->receiverReceived], 202);
                    case 'GET status':
                        return Http::response($this->receiverStatus);
                }
            }

            // Zot (manifest deletes by digest)
            if ($method === 'DELETE' && str_starts_with($path, '/api/v1/namespaces/runr-registry/services/http:runr-zot:5000/proxy/v2/')) {
                return Http::response('', 202);
            }

            return $method === 'GET'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response(is_array($body) ? $body : ['kind' => 'Status'], 200);
        });
    }

    protected function imgCallsTo(string $method, string $needle): array
    {
        return array_values(array_filter($this->imgCalls, fn ($c) => $c['method'] === $method && str_contains($c['path'], $needle)));
    }

    /** PUT one raw chunk of an upload. */
    protected function putChunk(string $uploadId, int $offset, string $bytes): TestResponse
    {
        return $this->call('PUT', "/api/images/uploads/{$uploadId}/chunk?offset={$offset}", [], [], [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $bytes);
    }

    /** A finished push as the receiver reports it. */
    protected function pushedStatus(array $result = []): array
    {
        return [
            'state' => 'pushed',
            'received' => 8,
            'size' => 8,
            'sha256' => str_repeat('b', 64),
            'result' => array_merge([
                'reference' => self::IMG_ZOT.'/runr-uploads/platform/app@sha256:'.str_repeat('c', 64),
                'digest' => 'sha256:'.str_repeat('c', 64),
                'media_type' => 'application/vnd.oci.image.index.v1+json',
                'kind' => 'image',
                'format' => 'oci',
                'size_bytes' => 7000,
                'platforms' => ['linux/amd64', 'linux/arm64'],
                'layers' => 4,
                'archive_sha256' => str_repeat('b', 64),
            ], $result),
        ];
    }
}
