<?php

namespace App\Services\GitDeploy;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves git's smart HTTP protocol through `git http-backend` (CGI) behind the API:
 *  - the request body is spooled to a temporary file first (stdin of the backend: no pipe
 *    deadlock, git reads at its own pace; a gzip body is decompressed by the backend);
 *  - the backend's CGI headers become the response status and headers, its body is streamed;
 *  - per-request git config (GIT_CONFIG_*): receive.denyNonFastForwards, receive.maxInputSize,
 *    http.receivepack, uploadpack.allowFilter; RUNR_* variables reach the repository hooks;
 *  - $after runs once the backend exited (receive-pack: compare the refs).
 */
class SmartHttpBackend
{
    public function __construct(private GitRunner $git) {}

    /**
     * @param  string  $projectRoot  GIT_PROJECT_ROOT (directory of the bare repositories)
     * @param  string  $pathInfo  /<repo>.git/info/refs | /<repo>.git/git-upload-pack | ...
     * @param  array<string, string>  $env  extra environment (REMOTE_USER, RUNR_*)
     * @param  array<string, string>  $config  per-request git config
     * @param  Closure(): void|null  $after
     */
    public function serve(Request $request, string $projectRoot, string $pathInfo, array $env = [], array $config = [], ?Closure $after = null): Response
    {
        $limit = max(1, (int) config('git-deploy.max_push_mb', 50)) * 1048576;
        $input = tmpfile();
        $size = 0;

        if ($input === false) {
            return new Response('Temporary storage unavailable', 503, ['Content-Type' => 'text/plain']);
        }

        if (! $request->isMethod('GET')) {
            $body = $request->getContent(true);
            while (! feof($body)) {
                $chunk = fread($body, 1048576);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $size += strlen($chunk);
                // Room for the pack plus pkt-line commands; receive.maxInputSize enforces the pack itself
                if ($size > $limit + 1048576) {
                    fclose($input);

                    return new Response('Push larger than '.(int) config('git-deploy.max_push_mb', 50).' MB', 413, ['Content-Type' => 'text/plain']);
                }
                fwrite($input, $chunk);
            }
            rewind($input);
        }

        $cgi = [
            'GIT_PROJECT_ROOT' => $projectRoot,
            'GIT_HTTP_EXPORT_ALL' => '1',
            'PATH_INFO' => $pathInfo,
            'REQUEST_METHOD' => $request->getMethod(),
            'QUERY_STRING' => (string) $request->getQueryString(),
            'CONTENT_TYPE' => (string) $request->headers->get('Content-Type', ''),
            'REMOTE_ADDR' => (string) $request->ip(),
            'GIT_HTTP_MAX_REQUEST_BUFFER' => (string) ($limit + 1048576),
        ];
        if ($size > 0) {
            $cgi['CONTENT_LENGTH'] = (string) $size;
        }
        if ($encoding = $request->headers->get('Content-Encoding')) {
            $cgi['HTTP_CONTENT_ENCODING'] = $encoding;
        }
        if ($protocol = $request->headers->get('Git-Protocol')) {
            $cgi['GIT_PROTOCOL'] = $protocol;
        }

        $environment = $this->git->environment($cgi + $env, $config + [
            'http.uploadpack' => 'true',
            'uploadpack.allowFilter' => 'true',
        ]);

        $stderr = tmpfile();
        $pipes = [];
        $process = proc_open([$this->git->binary(), 'http-backend'], [0 => $input, 1 => ['pipe', 'w'], 2 => $stderr], $pipes, $projectRoot, $environment);

        if (! is_resource($process) || ! is_resource($pipes[1] ?? null)) {
            fclose($input);

            return new Response('git http-backend could not start', 500, ['Content-Type' => 'text/plain']);
        }

        $stdout = $pipes[1];
        [$status, $headers, $rest] = $this->readHeaders($stdout);

        if ($status === null) {
            $this->finish($process, $stdout, $input, $stderr, $pathInfo);
            if ($after) {
                $after();
            }

            return new Response('git http-backend failed', 500, ['Content-Type' => 'text/plain']);
        }

        $headers['Cache-Control'] ??= 'no-cache, max-age=0, must-revalidate';
        $headers['X-Accel-Buffering'] = 'no';

        return new StreamedResponse(function () use ($process, $stdout, $input, $stderr, $rest, $after, $pathInfo) {
            try {
                if ($rest !== '') {
                    echo $rest;
                }
                while (! feof($stdout)) {
                    $chunk = fread($stdout, 65536);
                    if ($chunk === false || $chunk === '') {
                        if (feof($stdout)) {
                            break;
                        }

                        continue;
                    }
                    echo $chunk;
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                }
            } finally {
                $this->finish($process, $stdout, $input, $stderr, $pathInfo);
                if ($after) {
                    $after();
                }
            }
        }, $status, $headers);
    }

    /**
     * Read the CGI header block ("Status: 200 OK", "Content-Type: ...", blank line).
     *
     * @param  resource  $stdout
     * @return array{0: ?int, 1: array<string, string>, 2: string} status, headers, body bytes already read
     */
    private function readHeaders($stdout): array
    {
        $buffer = '';
        while (! feof($stdout) && strlen($buffer) < 65536) {
            $chunk = fread($stdout, 8192);
            if ($chunk === false || $chunk === '') {
                if (feof($stdout)) {
                    break;
                }

                continue;
            }
            $buffer .= $chunk;
            if (preg_match("/\r?\n\r?\n/", $buffer, $m, PREG_OFFSET_CAPTURE)) {
                break;
            }
        }

        if (! preg_match("/\r?\n\r?\n/", $buffer, $m, PREG_OFFSET_CAPTURE)) {
            return [null, [], ''];
        }

        $end = $m[0][1];
        $block = substr($buffer, 0, $end);
        $rest = substr($buffer, $end + strlen($m[0][0]));
        $status = 200;
        $headers = [];

        foreach (preg_split("/\r?\n/", $block) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $line, 2));
            if (strcasecmp($name, 'Status') === 0) {
                $status = (int) $value ?: 200;

                continue;
            }
            $headers[$name] = $value;
        }

        return [$status, $headers, $rest];
    }

    /** @param resource $process */
    private function finish($process, $stdout, $input, $stderr, string $pathInfo): void
    {
        if (is_resource($stdout)) {
            fclose($stdout);
        }
        $code = proc_close($process);

        if ($code !== 0 && is_resource($stderr)) {
            rewind($stderr);
            Log::warning('git http-backend exited with an error', [
                'path' => $pathInfo,
                'code' => $code,
                'stderr' => mb_substr((string) stream_get_contents($stderr), 0, 2000),
            ]);
        }

        foreach ([$input, $stderr] as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
