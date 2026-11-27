<?php

namespace App\Services\Observability;

/**
 * Where a guarded request may go (OutboundUrlGuard::target()): the reason it may not, or the
 * HTTP client options that make it go only to the addresses the guard checked.
 *
 * A host name is resolved once, by the guard; options() pins it with curl's CURLOPT_RESOLVE to
 * every address that answered, so the client does not resolve the name a second time — a name
 * cannot answer a public address for the check and an internal one for the connection (DNS
 * rebinding). Through a proxy of the API's environment (OutboundProxy) the request goes as a
 * CONNECT to the first checked address (CURLOPT_CONNECT_TO), so the proxy does not resolve the
 * name either; TLS still verifies the name. A literal address needs no pin, nor does a request
 * that may reach private targets. Redirects are never followed: a redirect would lead to a URL
 * nobody checked.
 */
final class OutboundTarget
{
    /** Why the URL may not be called (translated), null when it may */
    public readonly ?string $reason;

    /**
     * @param  string|null  $code  why the URL may not be called: invalid, invalid_scheme,
     *                             https_required, credentials, private or unresolvable
     *                             (observability.url.*); null when it may
     * @param  list<string>  $addresses  the checked addresses of $host (empty: nothing to pin)
     * @param  string  $scheme  http or https: which proxy of the environment carries the request
     */
    private function __construct(
        public readonly ?string $code,
        public readonly string $host = '',
        public readonly int $port = 0,
        public readonly array $addresses = [],
        public readonly string $scheme = 'https',
    ) {
        $this->reason = $code === null ? null : (string) __('observability.url.'.$code);
    }

    public static function blocked(string $code): self
    {
        return new self($code);
    }

    /** May be called; the client resolves the host itself (a literal address, or private targets allowed). */
    public static function direct(): self
    {
        return new self(null);
    }

    /** @param  list<string>  $addresses */
    public static function pinned(string $host, int $port, array $addresses, string $scheme = 'https'): self
    {
        return new self(null, $host, $port, array_values(array_unique($addresses)), strtolower($scheme) === 'http' ? 'http' : 'https');
    }

    /** The proxy of the API's environment that carries this pinned request (OutboundProxy), null: none. */
    public function proxy(): ?string
    {
        return $this->addresses === [] ? null : OutboundProxy::for($this->scheme, rtrim($this->host, '.'), $this->port);
    }

    public function allowed(): bool
    {
        return $this->code === null;
    }

    /**
     * Options for Laravel's HTTP client / Guzzle (withOptions()): no redirects, and the pin of the
     * checked addresses. "host:port:addr1,addr2" — every address, so the client can still fail over
     * from one to the next, IPv6 addresses in brackets; the name with and without a trailing dot,
     * whichever form the client resolves. No proxy then: the request goes straight there.
     *
     * Where a proxy of the environment applies (proxy()), the request goes through it as a
     * CONNECT to the first checked address, the name kept for the Host header and TLS
     * (CURLOPT_CONNECT_TO; an http:// request is tunnelled too, CURLOPT_HTTPPROXYTUNNEL, so the
     * proxy never gets a name to resolve). One address: a CONNECT names one.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $options = ['allow_redirects' => false];
        if ($this->addresses === []) {
            return $options;
        }

        $name = rtrim($this->host, '.');
        $proxy = $this->proxy();
        if ($proxy !== null) {
            $first = str_contains($this->addresses[0], ':') ? '['.$this->addresses[0].']' : $this->addresses[0];
            $options['proxy'] = $proxy;
            $options['curl'] = [
                CURLOPT_CONNECT_TO => [
                    $name.':'.$this->port.':'.$first.':'.$this->port,
                    $name.'.:'.$this->port.':'.$first.':'.$this->port,
                ],
                CURLOPT_HTTPPROXYTUNNEL => true,
            ];

            return $options;
        }

        $list = implode(',', array_map(fn (string $ip) => str_contains($ip, ':') ? '['.$ip.']' : $ip, $this->addresses));
        $options['proxy'] = '';
        $options['curl'] = [CURLOPT_RESOLVE => [
            $name.':'.$this->port.':'.$list,
            $name.'.:'.$this->port.':'.$list,
        ]];

        return $options;
    }
}
