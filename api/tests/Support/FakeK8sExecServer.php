<?php

namespace Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Runs tests/Support/fake-k8s-exec-server.php (a stand-in for the API server's exec endpoint)
 * on a free local port and builds kubeconfigs that point at it; reads its request log.
 */
final class FakeK8sExecServer
{
    public int $port = 0;

    public string $log;

    private string $dir;

    /** @var resource|null */
    private $proc = null;

    /** @var array<string, string> */
    public array $tls = [];

    private function __construct()
    {
        $this->dir = sys_get_temp_dir().'/fake-k8s-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->log = $this->dir.'/log.jsonl';
        touch($this->log);
    }

    /** @param array<string, mixed> $options see fake-k8s-exec-server.php; tls: true for certificates of its own */
    public static function start(array $options = []): self
    {
        $server = new self;
        if (($options['tls'] ?? false) !== false) {
            $server->tls = self::certificates($server->dir);
            $options['tls'] = ['cert' => $server->tls['server_cert'], 'key' => $server->tls['server_key']]
                + (! empty($options['client_certs']) ? ['ca' => $server->tls['ca']] : []);
        }
        unset($options['client_certs']);
        $portFile = $server->dir.'/port';
        $pipes = [];
        $server->proc = proc_open(
            [PHP_BINARY, __DIR__.'/fake-k8s-exec-server.php', $portFile, json_encode($options + ['log' => $server->log])],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $server->dir.'/stderr', 'w']],
            $pipes,
        );
        $until = microtime(true) + 10;
        while ((! is_file($portFile) || filesize($portFile) === 0) && microtime(true) < $until) {
            usleep(20_000);
        }
        $server->port = (int) @file_get_contents($portFile);
        if ($server->port === 0) {
            throw new \RuntimeException('the fake API server did not start: '.@file_get_contents($server->dir.'/stderr'));
        }

        return $server;
    }

    /**
     * A kubeconfig for this server.
     *
     * @param  array<string, mixed>  $cluster  extra cluster fields
     * @param  array<string, mixed>  $user  user fields
     */
    public function kubeconfig(array $cluster = [], array $user = [], string $prefix = '', ?string $host = null): string
    {
        $scheme = $this->tls === [] ? 'http' : 'https';

        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'test',
            'contexts' => [['name' => 'test', 'context' => ['cluster' => 'fake', 'user' => 'runr']]],
            'clusters' => [['name' => 'fake', 'cluster' => ['server' => "{$scheme}://".($host ?? '127.0.0.1').":{$this->port}{$prefix}"] + $cluster]],
            'users' => [['name' => 'runr', 'user' => $user === [] ? (object) [] : $user]],
        ], 6, 2, Yaml::DUMP_OBJECT_AS_MAP);
    }

    /** @return list<array<string, mixed>> */
    public function events(string $event): array
    {
        $out = [];
        foreach ((is_file($this->log) ? file($this->log, FILE_IGNORE_NEW_LINES) : []) ?: [] as $line) {
            $row = json_decode($line, true);
            if (($row['event'] ?? null) === $event) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** Waits until $n connections ended (the server logs an end when it closed one). */
    public function waitEnds(int $n, float $seconds = 5.0): array
    {
        $until = microtime(true) + $seconds;
        while (count($this->events('end')) < $n && microtime(true) < $until) {
            usleep(20_000);
        }

        return $this->events('end');
    }

    public function stop(): void
    {
        foreach ($this->events('request') as $row) {
            if (! empty($row['pid']) && function_exists('posix_kill')) {
                @posix_kill((int) $row['pid'], 9);
            }
        }
        if (is_resource($this->proc)) {
            proc_terminate($this->proc, 9);
            proc_close($this->proc);
        }
        $this->proc = null;
        if (is_dir($this->dir)) {
            exec('rm -rf '.escapeshellarg($this->dir));
        }
    }

    /**
     * A CA, a server certificate for 127.0.0.1 / localhost and a client certificate (CN runr-test).
     *
     * @return array<string, string> PEM file paths and the base64 kubeconfig fields
     */
    public static function certificates(string $dir): array
    {
        $cnf = $dir.'/openssl.cnf';
        file_put_contents($cnf, implode("\n", [
            '[ req ]', 'distinguished_name = dn', '[ dn ]',
            '[ v3_ca ]', 'basicConstraints = critical,CA:TRUE', 'keyUsage = critical,keyCertSign,cRLSign', 'subjectKeyIdentifier = hash',
            '[ v3_server ]', 'basicConstraints = CA:FALSE', 'keyUsage = critical,digitalSignature,keyEncipherment', 'extendedKeyUsage = serverAuth',
            'subjectAltName = IP:127.0.0.1,DNS:localhost',
            '[ v3_client ]', 'basicConstraints = CA:FALSE', 'keyUsage = critical,digitalSignature,keyEncipherment', 'extendedKeyUsage = clientAuth',
            '',
        ]));
        $key = static fn () => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $opts = static fn (string $ext) => ['config' => $cnf, 'x509_extensions' => $ext, 'digest_alg' => 'sha256'];

        $caKey = $key();
        $ca = openssl_csr_sign(openssl_csr_new(['commonName' => 'fake-k8s-ca'], $caKey, ['config' => $cnf]), null, $caKey, 2, $opts('v3_ca'), random_int(1, PHP_INT_MAX));
        $serverKey = $key();
        $serverCert = openssl_csr_sign(openssl_csr_new(['commonName' => '127.0.0.1'], $serverKey, ['config' => $cnf]), $ca, $caKey, 2, $opts('v3_server'), random_int(1, PHP_INT_MAX));
        $clientKey = $key();
        $clientCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'runr-test'], $clientKey, ['config' => $cnf]), $ca, $caKey, 2, $opts('v3_client'), random_int(1, PHP_INT_MAX));

        $pem = [];
        openssl_x509_export($ca, $pem['ca']);
        openssl_x509_export($serverCert, $pem['server_cert']);
        openssl_pkey_export($serverKey, $pem['server_key'], null, ['config' => $cnf]);
        openssl_x509_export($clientCert, $pem['client_cert']);
        openssl_pkey_export($clientKey, $pem['client_key'], null, ['config' => $cnf]);

        $out = [];
        foreach ($pem as $name => $content) {
            file_put_contents("{$dir}/{$name}.pem", $content);
            $out[$name] = "{$dir}/{$name}.pem";
            $out[$name.'_data'] = base64_encode($content);
        }

        return $out;
    }
}
