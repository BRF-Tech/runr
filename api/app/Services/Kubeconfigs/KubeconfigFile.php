<?php

namespace App\Services\Kubeconfigs;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * A kubeconfig written for kubectl or helm (HelmService, KubectlCommand, ShellPtyProcess): a 0600
 * file under the temp directory, removed by close().
 *
 * A pinned kubeconfig (an organization's region, KubeconfigPin) is held to KubeconfigPolicy once
 * more right before it is written — what reaches the disk is the policy's rebuild, nothing else —
 * and its proxy-url becomes a local PinProxy for the server's checked addresses: kubectl and helm
 * resolve no name of the server themselves and follow no redirect to another host. Any other
 * kubeconfig is written as it is.
 */
final class KubeconfigFile
{
    private function __construct(public readonly string $path, private ?PinProxy $proxy) {}

    /**
     * @throws KubeconfigRefused a pinned kubeconfig the policy refuses
     * @throws RuntimeException the file or the proxy could not be set up
     */
    public static function write(string $yaml, string $prefix = 'runr-kc-'): self
    {
        $proxy = null;
        if (str_contains($yaml, KubeconfigPin::SCHEME.':')) {
            [$yaml, $proxy] = self::throughProxy($yaml);
        }

        return self::create($yaml, $prefix, $proxy);
    }

    /**
     * The kubeconfig as it is, for RunR's own client in another process (shell:keep-alive):
     * KubernetesClient reads a pin itself. Never hand it to kubectl or helm.
     */
    public static function plain(string $yaml, string $prefix = 'runr-kc-'): self
    {
        return self::create($yaml, $prefix, null);
    }

    private static function create(string $yaml, string $prefix, ?PinProxy $proxy): self
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false || @file_put_contents($path, $yaml, LOCK_EX) === false) {
            $proxy?->stop();

            throw new RuntimeException('The kubeconfig could not be written.');
        }
        @chmod($path, 0600);

        return new self($path, $proxy);
    }

    /** The kubeconfig goes through a PinProxy (an organization's region). */
    public function pinned(): bool
    {
        return $this->proxy !== null;
    }

    public function close(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
        $this->proxy?->stop();
        $this->proxy = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @return array{string, PinProxy|null} */
    private static function throughProxy(string $yaml): array
    {
        try {
            $config = Yaml::parse($yaml);
        } catch (\Throwable) {
            $config = null;
        }
        $clusters = is_array($config) ? ($config['clusters'] ?? null) : null;
        $cluster = is_array($clusters) && isset($clusters[0]['cluster']) && is_array($clusters[0]['cluster']) ? $clusters[0]['cluster'] : [];
        $addresses = KubeconfigPin::addresses($cluster);
        if ($addresses === null) {
            return [$yaml, null]; // the marker's text elsewhere: no pin
        }
        if ($addresses === []) {
            throw new KubeconfigRefused('server');
        }

        $checked = KubeconfigPolicy::normalize($yaml);
        $proxy = PinProxy::start($checked->host, $checked->port, $addresses);
        $out = $checked->config;
        $out['clusters'][0]['cluster']['proxy-url'] = 'http://127.0.0.1:'.$proxy->port;

        return [Yaml::dump($out, 10, 2), $proxy];
    }
}
