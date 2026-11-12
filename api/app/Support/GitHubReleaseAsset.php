<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * A file of a GitHub release (https://github.com/<owner>/<repo>/releases/download/<tag>/<file>), as
 * RunR's region setup downloads them: Helm charts whose repository index points there (Zot's, the
 * prometheus-community and grafana charts) and Knative's CRDs. github.com answers such a download
 * with a 5xx now and then: a 504 Gateway Timeout failed a region's whole registry setup ("Zot Helm
 * install failed: … 504 Gateway Timeout").
 *
 * fetch() tries a download that answers 5xx or 429, or whose connection breaks, again (TRIES tries
 * in all, 2, 4, 8 s apart); then the file comes once through the GitHub API's asset endpoint
 * (viaApi(): the release by its tag, the asset by its name, Accept: application/octet-stream), which
 * is served from elsewhere. A 404 or another 4xx ends at once: the file does not exist. With a
 * digest (a chart's, from its repository's index) a body with another sha256 is refused.
 */
final class GitHubReleaseAsset
{
    /** Tries of a download that keeps answering 5xx */
    public const TRIES = 4;

    /** Seconds before the second try; doubled before each next one */
    public const FIRST_WAIT = 2;

    private const API = 'https://api.github.com';

    /**
     * The release download URL's parts, or null for another URL.
     *
     * @return array{owner: string, repo: string, tag: string, file: string}|null
     */
    public static function parse(string $url): ?array
    {
        if (! preg_match('#^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/releases/download/([^/?\#]+)/([^/?\#]+)$#', $url, $m)) {
            return null;
        }

        return ['owner' => $m[1], 'repo' => $m[2], 'tag' => rawurldecode($m[3]), 'file' => rawurldecode($m[4])];
    }

    /**
     * The file's body: the download tried again on a transient failure, then through the API; null
     * when neither gives it (or not with $sha256).
     */
    public static function fetch(string $url, ?string $sha256 = null, int $timeout = 60): ?string
    {
        for ($try = 1; ; $try++) {
            $transient = false;
            try {
                $response = Http::timeout($timeout)->get($url);
                if ($response->successful()) {
                    return self::checked($response->body(), $sha256, $url);
                }
                $transient = $response->serverError() || $response->status() === 429;
                $why = 'HTTP '.$response->status();
            } catch (ConnectionException $e) {
                $transient = true;
                $why = $e->getMessage();
            } catch (Throwable $e) {
                $why = $e->getMessage();
            }
            if (! $transient) {
                return null;
            }
            if ($try >= self::TRIES) {
                break;
            }
            $wait = self::FIRST_WAIT * 2 ** ($try - 1);
            Log::warning('GitHub release download failed, trying again', ['url' => $url, 'try' => $try + 1, 'of' => self::TRIES, 'wait_s' => $wait, 'error' => mb_strimwidth($why, 0, 200, '…')]);
            Sleep::for($wait)->seconds();
        }

        return self::viaApi($url, $sha256, $timeout);
    }

    /**
     * The file through the GitHub API's asset endpoint, once (no token: the anonymous limit is 60
     * requests an hour, enough for a fallback); null for another URL, a release or an asset that is
     * not there, a failed download or another digest.
     */
    public static function viaApi(string $url, ?string $sha256 = null, int $timeout = 60): ?string
    {
        $parts = self::parse($url);
        if ($parts === null) {
            return null;
        }
        try {
            $release = Http::timeout($timeout)->retry(2, 2000, throw: false)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get(self::API.'/repos/'.$parts['owner'].'/'.$parts['repo'].'/releases/tags/'.rawurlencode($parts['tag']));
            if (! $release->successful()) {
                Log::warning('GitHub release download: the release is not readable through the API', ['url' => $url, 'status' => $release->status()]);

                return null;
            }
            $asset = collect((array) $release->json('assets'))->first(fn ($a) => is_array($a) && ($a['name'] ?? null) === $parts['file']);
            $assetUrl = is_array($asset) ? (string) ($asset['url'] ?? '') : '';
            if (! str_starts_with($assetUrl, self::API.'/')) {
                Log::warning('GitHub release download: the release has no such asset', ['url' => $url]);

                return null;
            }
            $file = Http::timeout($timeout)->retry(2, 2000, throw: false)
                ->withHeaders(['Accept' => 'application/octet-stream'])
                ->get($assetUrl);
            if (! $file->successful()) {
                Log::warning('GitHub release download: the asset endpoint failed too', ['url' => $url, 'status' => $file->status()]);

                return null;
            }
        } catch (Throwable $e) {
            Log::warning('GitHub release download: the API failed too', ['url' => $url, 'error' => mb_strimwidth($e->getMessage(), 0, 200, '…')]);

            return null;
        }
        Log::info('GitHub release download came through the API asset endpoint', ['url' => $url]);

        return self::checked($file->body(), $sha256, $url);
    }

    private static function checked(string $body, ?string $sha256, string $url): ?string
    {
        if ($sha256 !== null && ! hash_equals(strtolower($sha256), hash('sha256', $body))) {
            Log::error('GitHub release download: the file is not the one the index names', ['url' => $url, 'want' => $sha256]);

            return null;
        }

        return $body;
    }
}
