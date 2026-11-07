<?php

namespace App\Services\Sandboxes\Exec;

use Symfony\Component\Yaml\Yaml;

/**
 * What the native exec client takes from a region kubeconfig (its current context): the API
 * server's address and the TLS and credentials to reach it, the same subset KubernetesClient
 * uses — certificate-authority-data or insecure-skip-tls-verify, tls-server-name, a bearer token
 * (`token`: also a ServiceAccount's, as a kubeconfig of `kubectl create token` has it), and
 * client-certificate-data + client-key-data, each alone or together. Anything only kubectl can do
 * (UNSUPPORTED_USER / UNSUPPORTED_CLUSTER: an exec plugin, an auth provider, a token, certificate
 * or CA in a file, basic auth, impersonation, a proxy-url) throws NativeExecUnsupported, and so
 * does a proxy of the environment (environmentProxy(), checked by SelectingExecTransport): such a
 * region keeps running commands through kubectl.
 *
 * The CA, client certificate and key go to 0600 files under the temp directory (PHP's TLS reads
 * them from files), one set per distinct kubeconfig, kept for the process (Octane worker, Reverb)
 * and written again if a temp cleaner removed them — as KubernetesClient does.
 */
final class ExecKubeconfig
{
    /** User fields only kubectl handles => what the log line calls them */
    public const UNSUPPORTED_USER = [
        'exec' => 'an exec credential plugin',
        'auth-provider' => 'an auth provider',
        'tokenFile' => 'a token file',
        'username' => 'basic auth',
        'password' => 'basic auth',
        'client-certificate' => 'a client certificate file',
        'client-key' => 'a client key file',
        'as' => 'impersonation',
        'as-uid' => 'impersonation',
        'as-groups' => 'impersonation',
        'as-user-extra' => 'impersonation',
    ];

    /** Cluster fields only kubectl handles => what the log line calls them */
    public const UNSUPPORTED_CLUSTER = [
        'certificate-authority' => 'a CA file',
        'proxy-url' => 'a proxy',
    ];

    private const MAX_TLS_SETS = 16;

    /** @var array<string, array{dir: string, files: array<string, string>}> */
    private static array $tlsSets = [];

    /** @var array<string, self> parsed kubeconfigs by hash */
    private static array $parsed = [];

    private function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly string $pathPrefix,
        public readonly bool $insecure,
        public readonly ?string $serverName,
        public readonly ?string $token,
        private readonly ?string $caPem,
        private readonly ?string $certPem,
        private readonly ?string $keyPem,
    ) {}

    /** @throws NativeExecUnsupported */
    public static function parse(string $kubeconfig): self
    {
        $key = hash('sha256', $kubeconfig);
        if (isset(self::$parsed[$key])) {
            return self::$parsed[$key];
        }
        if (count(self::$parsed) >= 64) {
            self::$parsed = [];
        }

        return self::$parsed[$key] = self::fromYaml($kubeconfig);
    }

    /** @throws NativeExecUnsupported */
    private static function fromYaml(string $kubeconfig): self
    {
        try {
            $config = Yaml::parse($kubeconfig);
        } catch (\Throwable $e) {
            throw new NativeExecUnsupported('the kubeconfig is not valid YAML');
        }
        if (! is_array($config)) {
            throw new NativeExecUnsupported('the kubeconfig is empty');
        }

        $contextName = $config['current-context'] ?? null;
        $context = self::named($config['contexts'] ?? null, $contextName)['context'] ?? null;
        if (! is_array($context)) {
            throw new NativeExecUnsupported('the kubeconfig has no current context');
        }
        $cluster = self::named($config['clusters'] ?? null, $context['cluster'] ?? null)['cluster'] ?? null;
        $user = self::named($config['users'] ?? null, $context['user'] ?? null)['user'] ?? [];
        if (! is_array($cluster) || ! is_array($user)) {
            throw new NativeExecUnsupported('the kubeconfig has no cluster or user for its current context');
        }

        foreach (self::UNSUPPORTED_USER as $field => $what) {
            if (! empty($user[$field])) {
                throw new NativeExecUnsupported("the kubeconfig user uses {$what} ({$field}), which only kubectl handles");
            }
        }
        foreach (self::UNSUPPORTED_CLUSTER as $field => $what) {
            if (! empty($cluster[$field])) {
                throw new NativeExecUnsupported("the kubeconfig cluster uses {$what} ({$field}), which only kubectl handles");
            }
        }

        $url = parse_url((string) ($cluster['server'] ?? ''));
        $scheme = strtolower((string) ($url['scheme'] ?? ''));
        if (! in_array($scheme, ['https', 'http'], true) || empty($url['host']) || isset($url['user']) || isset($url['query'])) {
            throw new NativeExecUnsupported('the kubeconfig server is not an http(s) URL');
        }

        $decode = static function (mixed $value, string $what): ?string {
            if ($value === null || $value === '') {
                return null;
            }
            $pem = base64_decode((string) $value, true);
            if ($pem === false || $pem === '') {
                throw new NativeExecUnsupported("the kubeconfig {$what} is not base64");
            }

            return $pem;
        };
        $cert = $decode($user['client-certificate-data'] ?? null, 'client certificate');
        $key = $decode($user['client-key-data'] ?? null, 'client key');
        if (($cert === null) !== ($key === null)) {
            throw new NativeExecUnsupported('the kubeconfig has a client certificate without its key, or the other way round');
        }

        return new self(
            $scheme,
            (string) $url['host'],
            (int) ($url['port'] ?? ($scheme === 'https' ? 443 : 80)),
            rtrim((string) ($url['path'] ?? ''), '/'),
            ! empty($cluster['insecure-skip-tls-verify']),
            isset($cluster['tls-server-name']) && $cluster['tls-server-name'] !== '' ? (string) $cluster['tls-server-name'] : null,
            isset($user['token']) && $user['token'] !== '' ? (string) $user['token'] : null,
            $decode($cluster['certificate-authority-data'] ?? null, 'certificate authority'),
            $cert,
            $key,
        );
    }

    /** @return array<string, mixed>|null the entry of a named list ("clusters", "users", …) */
    private static function named(mixed $list, mixed $name): ?array
    {
        if (! is_array($list) || ! is_string($name)) {
            return null;
        }
        foreach ($list as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $name) {
                return $entry;
            }
        }

        return null;
    }

    /** "host:port" as a socket address (IPv6 in brackets, as parse_url keeps it). */
    public function address(): string
    {
        return ($this->scheme === 'https' ? 'tls://' : 'tcp://').$this->host.':'.$this->port;
    }

    /** The Host header. */
    public function hostHeader(): string
    {
        $default = $this->scheme === 'https' ? 443 : 80;

        return $this->host.($this->port === $default ? '' : ':'.$this->port);
    }

    /** Identifies the API server (for the per-server fallback memory). */
    public function serverKey(): string
    {
        return $this->scheme.'://'.$this->host.':'.$this->port.$this->pathPrefix;
    }

    /**
     * The proxy variable of the environment that sends this API server through a proxy, null when
     * none does: HTTPS_PROXY / https_proxy (HTTP_PROXY / http_proxy for an http server), unless
     * NO_PROXY / no_proxy names the server, the way kubectl reads them (Go's ProxyFromEnvironment:
     * "*", a domain with its subdomains, ".domain" for subdomains only, an IP or CIDR, each with an
     * optional port; localhost and loopback addresses never go through a proxy). The API's other
     * cluster calls (curl) follow these variables as well; the native client does not, so such a
     * server's commands go to kubectl.
     *
     * @param  array<string, string>|null  $env  the variables to read (tests); null: the process's
     */
    public function environmentProxy(?array $env = null): ?string
    {
        $read = static function (string ...$names) use ($env): array {
            foreach ($names as $name) {
                $value = $env === null ? getenv($name) : ($env[$name] ?? false);
                if (is_string($value) && trim($value) !== '') {
                    return [$name, trim($value)];
                }
            }

            return [null, ''];
        };

        [$variable] = $this->scheme === 'https' ? $read('HTTPS_PROXY', 'https_proxy') : $read('HTTP_PROXY', 'http_proxy');
        if ($variable === null) {
            return null;
        }

        $host = strtolower(trim($this->host, '[]'));
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : null;
        if ($host === 'localhost' || ($ip !== null && self::isLoopback($ip))) {
            return null;
        }

        [, $noProxy] = $read('NO_PROXY', 'no_proxy');

        return self::noProxyMatches($noProxy, $host, $ip, (string) $this->port) ? null : $variable;
    }

    /** Whether a NO_PROXY list names the host (Go's httpproxy rules, see environmentProxy()). */
    private static function noProxyMatches(string $noProxy, string $host, ?string $ip, string $port): bool
    {
        foreach (explode(',', strtolower($noProxy)) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if ($entry === '*') {
                return true;
            }
            if (str_contains($entry, '/')) {
                if ($ip !== null && self::inCidr($ip, $entry)) {
                    return true;
                }

                continue;
            }

            // host:port, [v6]:port, a bare IPv6
            $entryPort = '';
            if (preg_match('/^\[([^\]]+)\](?::(\d+))?$/', $entry, $m)) {
                $entry = $m[1];
                $entryPort = $m[2] ?? '';
            } elseif (substr_count($entry, ':') === 1) {
                [$entry, $entryPort] = explode(':', $entry, 2);
            }
            if ($entry === '' || ($entryPort !== '' && $entryPort !== $port)) {
                continue;
            }

            if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
                if ($ip !== null && inet_pton($entry) === inet_pton($ip)) {
                    return true;
                }

                continue;
            }

            if (str_starts_with($entry, '*.')) {
                $entry = substr($entry, 1);
            }
            $matchHost = ! str_starts_with($entry, '.');
            $suffix = $matchHost ? '.'.$entry : $entry;
            if (str_ends_with($host, $suffix) || ($matchHost && $host === $entry)) {
                return true;
            }
        }

        return false;
    }

    private static function isLoopback(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($ip, '127.');
        }
        $packed = inet_pton($ip);

        return $packed === inet_pton('::1') || ($packed !== false && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff\x7f"));
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr, 2) + [1 => ''];
        $a = @inet_pton($ip);
        $b = @inet_pton($network);
        if ($a === false || $b === false || strlen($a) !== strlen($b) || ! ctype_digit($bits) || (int) $bits > strlen($a) * 8) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    /**
     * Stream context options of the TLS connection ("ssl" wrapper options).
     *
     * @return array<string, mixed>
     */
    public function sslOptions(): array
    {
        if ($this->scheme !== 'https') {
            return [];
        }
        $files = $this->tlsFiles();
        $options = [
            'verify_peer' => ! $this->insecure,
            'verify_peer_name' => ! $this->insecure,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'disable_compression' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];
        $name = $this->serverName ?? trim($this->host, '[]');
        $options['peer_name'] = $name;
        if (filter_var($name, FILTER_VALIDATE_IP) === false) {
            $options['SNI_server_name'] = $name;
        }
        if (isset($files['ca'])) {
            $options['cafile'] = $files['ca'];
        }
        if (isset($files['cert'], $files['key'])) {
            $options['local_cert'] = $files['cert'];
            $options['local_pk'] = $files['key'];
        }

        return $options;
    }

    /** @return array<string, string> ca / cert / key file paths of this kubeconfig */
    private function tlsFiles(): array
    {
        $pems = array_filter(['ca' => $this->caPem, 'cert' => $this->certPem, 'key' => $this->keyPem]);
        if ($pems === []) {
            return [];
        }
        $id = hash('sha256', serialize($pems));
        $set = self::$tlsSets[$id] ?? null;
        foreach ($set['files'] ?? [] as $path) {
            if (! is_file($path)) {
                self::forget($id);
                $set = null;
                break;
            }
        }
        if ($set === null) {
            while (count(self::$tlsSets) >= self::MAX_TLS_SETS) {
                self::forget((string) array_key_first(self::$tlsSets));
            }
            $dir = sys_get_temp_dir().'/runr-exec-tls-'.bin2hex(random_bytes(8));
            if (! @mkdir($dir, 0700)) {
                throw new NativeExecUnsupported('cannot write the TLS files of the kubeconfig');
            }
            $files = [];
            foreach ($pems as $name => $pem) {
                $path = "{$dir}/{$name}.pem";
                if (@file_put_contents($path, $pem, LOCK_EX) === false) {
                    throw new NativeExecUnsupported('cannot write the TLS files of the kubeconfig');
                }
                @chmod($path, 0600);
                $files[$name] = $path;
            }
            $set = ['dir' => $dir, 'files' => $files];
        }

        // Most recently used last
        unset(self::$tlsSets[$id]);
        self::$tlsSets[$id] = $set;

        return $set['files'];
    }

    private static function forget(string $id): void
    {
        $set = self::$tlsSets[$id] ?? null;
        unset(self::$tlsSets[$id]);
        foreach ($set['files'] ?? [] as $path) {
            @unlink($path);
        }
        if (isset($set['dir'])) {
            @rmdir($set['dir']);
        }
    }

    /** Removes every TLS file this process wrote (tests). */
    public static function flush(): void
    {
        foreach (array_keys(self::$tlsSets) as $id) {
            self::forget($id);
        }
        self::$parsed = [];
    }
}
