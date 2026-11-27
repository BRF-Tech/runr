<?php

namespace App\Services\Kubeconfigs;

use App\Services\Observability\OutboundProxy;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * A running bin/kube-pin-proxy.php: the local proxy that lets kubectl and helm (and the git reader
 * of organizations' sources) reach one server (host:port) at its checked addresses only
 * (KubeconfigFile). It listens on 127.0.0.1:$port and ends with stop() — or when this process
 * ends (its stdin closes). Where the API's environment has a proxy for the host (OutboundProxy),
 * the tunnels go through that proxy as a CONNECT to the checked address (RUNR_PIN_PROXY_UPSTREAM).
 */
final class PinProxy
{
    private const START_SECONDS = 10;

    /** @var resource|null */
    private $process;

    /** @var resource|null */
    private $stdin;

    /**
     * @param  resource  $process
     * @param  resource  $stdin
     */
    private function __construct($process, $stdin, public readonly int $port)
    {
        $this->process = $process;
        $this->stdin = $stdin;
    }

    /**
     * @param  list<string>  $addresses
     *
     * @throws RuntimeException the proxy did not start
     */
    public static function start(string $host, int $port, array $addresses): self
    {
        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $target = (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '['.$host.']' : $host).':'.$port.'='.implode(',', $addresses);
        // the operator's egress proxy for this host, in the environment of the proxy only (not
        // on its command line: the URL may carry the proxy's credentials)
        $upstream = OutboundProxy::for('https', $host, $port);
        $env = $upstream === null ? null : ['RUNR_PIN_PROXY_UPSTREAM' => $upstream] + getenv();
        $process = @proc_open([$php, base_path('bin/kube-pin-proxy.php'), $target], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, null, $env);
        if (! is_resource($process)) {
            throw new RuntimeException('The connection pin of the region (kube-pin-proxy) did not start.');
        }

        stream_set_timeout($pipes[1], self::START_SECONDS);
        $line = fgets($pipes[1]);
        fclose($pipes[1]);
        if ($line === false || ! ctype_digit(trim($line))) {
            fclose($pipes[0]);
            proc_terminate($process);
            proc_close($process);

            throw new RuntimeException('The connection pin of the region (kube-pin-proxy) did not start.');
        }

        return new self($process, $pipes[0], (int) trim($line));
    }

    public function stop(): void
    {
        if (is_resource($this->stdin)) {
            fclose($this->stdin);
        }
        $this->stdin = null;
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
    }

    public function __destruct()
    {
        $this->stop();
    }
}
