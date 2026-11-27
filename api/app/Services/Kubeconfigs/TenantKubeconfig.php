<?php

namespace App\Services\Kubeconfigs;

use Symfony\Component\Yaml\Yaml;

/**
 * A kubeconfig KubeconfigPolicy accepted: one cluster, one user, one context, rebuilt from the
 * checked fields; $host and $port are its server's (the host without IPv6 brackets).
 */
final class TenantKubeconfig
{
    /** @param  array<string, mixed>  $config */
    public function __construct(
        public readonly array $config,
        public readonly string $host,
        public readonly int $port,
    ) {}

    /** The server for the outbound check: https://host:port/ (IPv6 in brackets). */
    public function serverUrl(): string
    {
        $host = filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '['.$this->host.']' : $this->host;

        return 'https://'.$host.':'.$this->port.'/';
    }

    /** The host when it is a literal address, null for a name. */
    public function literalAddress(): ?string
    {
        return filter_var($this->host, FILTER_VALIDATE_IP) !== false ? $this->host : null;
    }

    /** The kubeconfig as stored. */
    public function yaml(): string
    {
        return Yaml::dump($this->config, 10, 2);
    }

    /**
     * The kubeconfig with the checked addresses of its server (KubeconfigPin).
     *
     * @param  list<string>  $addresses
     */
    public function pinnedYaml(array $addresses): string
    {
        $config = $this->config;
        $config['clusters'][0]['cluster']['proxy-url'] = KubeconfigPin::marker($addresses);

        return Yaml::dump($config, 10, 2);
    }
}
