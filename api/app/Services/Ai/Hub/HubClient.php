<?php

namespace App\Services\Ai\Hub;

use App\Services\Ai\Engines\EngineCatalog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Server-side proxy to the Hugging Face Hub API (config ai.huggingface.endpoint): model search,
 * model info (pinned commit, size from the file list, license, gated flag) and a summary of the
 * model card. The organization's token (HubToken) is sent when one is set, so private and
 * gated models the token may read are found too. Answers are cached briefly per token.
 */
class HubClient
{
    public const SORTS = [
        'downloads' => 'downloads',
        'likes' => 'likes',
        'trending' => 'trendingScore',
        'updated' => 'lastModified',
    ];

    /** Weight files counted for the model size (the repository also holds docs, configs, other formats). */
    private const WEIGHT_PATTERNS = ['/\.safetensors$/', '/\.bin$/', '/\.gguf$/', '/\.onnx$/', '/\.pt$/', '/\.pth$/', '/\.h5$/', '/\.msgpack$/', '/\.ckpt$/'];

    public function __construct(private HubToken $token) {}

    public function enabled(): bool
    {
        return (bool) config('ai.huggingface.browse_enabled', true);
    }

    /**
     * @return array{data: list<array>, next_cursor: ?string}
     *
     * @throws HubException
     */
    public function search(?string $search, ?string $task, ?string $library, string $sort = 'downloads', int $limit = 20, ?string $cursor = null): array
    {
        $query = array_filter([
            'search' => $search !== null && trim($search) !== '' ? trim($search) : null,
            'pipeline_tag' => $task ?: null,
            // libraries are model tags on the Hub (huggingface_hub's list_models(library=…) does the same)
            'filter' => $library ?: null,
            'sort' => self::SORTS[$sort] ?? 'downloads',
            'direction' => '-1',
            'limit' => (string) max(1, min(50, $limit)),
            'cursor' => $cursor ?: null,
        ], fn ($v) => $v !== null);
        $query['full'] = 'false';
        $query['config'] = 'false';

        $response = $this->cached('search', $query, fn () => $this->request()->get($this->url('/api/models'), $query));
        $items = is_array($response['json']) ? $response['json'] : [];

        return [
            'data' => array_values(array_map([$this, 'searchItem'], array_filter($items, 'is_array'))),
            'next_cursor' => self::nextCursor($response['link'] ?? null),
        ];
    }

    /**
     * Model info for the deploy dialog and for pinning a revision.
     *
     * @throws HubException
     */
    public function info(string $id, string $revision = 'main'): array
    {
        self::assertId($id);

        $path = '/api/models/'.$id.($revision !== '' && $revision !== 'main' ? '/revision/'.rawurlencode($revision) : '');
        $response = $this->cached('info', ['id' => $id, 'revision' => $revision], fn () => $this->request()->get($this->url($path), ['blobs' => 'true']));
        $data = is_array($response['json']) ? $response['json'] : [];

        $files = [];
        $size = 0;
        foreach ($data['siblings'] ?? [] as $file) {
            $name = (string) ($file['rfilename'] ?? '');
            $bytes = isset($file['size']) ? (int) $file['size'] : null;
            $files[] = ['name' => $name, 'size' => $bytes];
            if ($bytes !== null && self::isWeightFile($name)) {
                $size += $bytes;
            }
        }

        $tags = array_values(array_filter((array) ($data['tags'] ?? []), 'is_string'));
        $license = $data['cardData']['license'] ?? self::tagValue($tags, 'license');
        $gated = $data['gated'] ?? false;

        $info = [
            'id' => (string) ($data['id'] ?? $id),
            'sha' => $data['sha'] ?? null,
            'task' => $data['pipeline_tag'] ?? null,
            'library' => $data['library_name'] ?? null,
            'license' => is_array($license) ? implode(', ', $license) : $license,
            'gated' => $gated !== false && $gated !== null,
            'private' => (bool) ($data['private'] ?? false),
            'downloads' => (int) ($data['downloads'] ?? 0),
            'likes' => (int) ($data['likes'] ?? 0),
            'tags' => array_slice($tags, 0, 30),
            'size_bytes' => $size > 0 ? $size : null,
            'parameters' => isset($data['safetensors']['total']) ? (int) $data['safetensors']['total'] : null,
            'files' => $files,
            'card_summary' => $this->cardSummary($id, (string) ($data['sha'] ?? $revision)),
            'last_modified' => $data['lastModified'] ?? null,
        ];

        $info['engines'] = EngineCatalog::enginesFor($info['task'], $info['library'], $info['tags']);

        return $info;
    }

    /**
     * Summary of the model card (README without its YAML header): the first paragraphs, at most
     * 1200 characters. Null when the card cannot be read.
     */
    public function cardSummary(string $id, string $revision = 'main'): ?string
    {
        try {
            $response = $this->cached('card', ['id' => $id, 'revision' => $revision], fn () => $this->request()->get($this->url('/'.$id.'/resolve/'.rawurlencode($revision ?: 'main').'/README.md')), false);
        } catch (HubException) {
            return null;
        }

        return self::summarize((string) ($response['body'] ?? ''));
    }

    /**
     * Validate a token with the Hub (whoami-v2): the account name, or null when the Hub rejects it.
     *
     * @throws HubException the Hub is not reachable
     */
    public function whoami(string $token): ?string
    {
        try {
            $response = $this->baseRequest()->withToken($token)->get($this->url('/api/whoami-v2'));
        } catch (\Throwable $e) {
            throw new HubException(__('ai.hub.unreachable', ['message' => $e->getMessage()]), 503);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return null;
        }
        if (! $response->successful()) {
            throw new HubException(__('ai.hub.error', ['status' => $response->status()]), 502);
        }

        return (string) ($response->json('name') ?? '');
    }

    public static function summarize(string $markdown): ?string
    {
        $text = preg_replace('/^---\R.*?\R---\R/s', '', $markdown) ?? $markdown;
        // HTML blocks, images and badges are not part of a summary
        $text = preg_replace('/<[^>]+>/', '', $text) ?? $text;
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $text) ?? $text;
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > 1200 ? rtrim(mb_substr($text, 0, 1200)).'…' : $text;
    }

    public static function isWeightFile(string $name): bool
    {
        foreach (self::WEIGHT_PATTERNS as $pattern) {
            if (preg_match($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /** Hub repository ids: owner/name (letters, digits, - _ .). */
    public static function assertId(string $id): void
    {
        if (! preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]{0,95}/[A-Za-z0-9][A-Za-z0-9._-]{0,95}$#', $id) || str_contains($id, '..')) {
            throw new HubException(__('ai.hub.invalid_id', ['id' => $id]), 422);
        }
    }

    private function searchItem(array $item): array
    {
        $id = (string) ($item['id'] ?? $item['modelId'] ?? '');
        $tags = array_values(array_filter((array) ($item['tags'] ?? []), 'is_string'));

        return [
            'id' => $id,
            'author' => $item['author'] ?? (str_contains($id, '/') ? explode('/', $id)[0] : null),
            'task' => $item['pipeline_tag'] ?? null,
            'library' => $item['library_name'] ?? null,
            'downloads' => (int) ($item['downloads'] ?? 0),
            'likes' => (int) ($item['likes'] ?? 0),
            'gated' => ($item['gated'] ?? false) !== false && ($item['gated'] ?? null) !== null,
            'private' => (bool) ($item['private'] ?? false),
            'last_modified' => $item['lastModified'] ?? ($item['createdAt'] ?? null),
            'tags' => array_slice($tags, 0, 10),
        ];
    }

    private static function tagValue(array $tags, string $prefix): ?string
    {
        foreach ($tags as $tag) {
            if (str_starts_with($tag, $prefix.':')) {
                return substr($tag, strlen($prefix) + 1);
            }
        }

        return null;
    }

    /** Cursor of the next page from the Hub's Link header (rel="next"). */
    public static function nextCursor(?string $link): ?string
    {
        if (! $link || ! preg_match('/<([^>]+)>;\s*rel="next"/', $link, $m)) {
            return null;
        }

        parse_str((string) parse_url($m[1], PHP_URL_QUERY), $query);

        return isset($query['cursor']) ? (string) $query['cursor'] : null;
    }

    /**
     * @return array{json: mixed, body: string, link: ?string}
     *
     * @throws HubException
     */
    private function cached(string $kind, array $key, \Closure $call, bool $json = true): array
    {
        if (! $this->enabled()) {
            throw new HubException(__('ai.hub.disabled'), 503);
        }

        $token = $this->token->get();
        $cacheKey = 'runr:hf:'.$kind.':'.sha1(json_encode($key).'|'.($token ? sha1($token) : '-').'|'.config('ai.huggingface.endpoint'));
        $ttl = (int) config('ai.huggingface.cache_seconds', 300);

        if ($ttl > 0 && ($hit = Cache::get($cacheKey)) !== null) {
            return $hit;
        }

        try {
            /** @var Response $response */
            $response = $call();
        } catch (\Throwable $e) {
            throw new HubException(__('ai.hub.unreachable', ['message' => $e->getMessage()]), 503);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new HubException(__('ai.hub.forbidden'), 403);
        }
        if ($response->status() === 404) {
            throw new HubException(__('ai.hub.not_found'), 404);
        }
        if (! $response->successful()) {
            throw new HubException(__('ai.hub.error', ['status' => $response->status()]), 502);
        }

        $result = [
            'json' => $json ? $response->json() : null,
            'body' => $json ? '' : $response->body(),
            'link' => $response->header('Link') ?: null,
        ];

        if ($ttl > 0) {
            Cache::put($cacheKey, $result, $ttl);
        }

        return $result;
    }

    private function baseRequest(): PendingRequest
    {
        return Http::timeout((int) config('ai.huggingface.timeout', 15))
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'RunR/'.config('app.version', 'dev')]);
    }

    private function request(): PendingRequest
    {
        $request = $this->baseRequest();
        $token = $this->token->get();

        return $token ? $request->withToken($token) : $request;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('ai.huggingface.endpoint', 'https://huggingface.co'), '/').$path;
    }
}
