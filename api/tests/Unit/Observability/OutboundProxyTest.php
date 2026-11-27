<?php

namespace Tests\Unit\Observability;

use App\Services\Observability\OutboundProxy;
use PHPUnit\Framework\TestCase;

/**
 * Which proxy of the API's environment carries a pinned request (OutboundProxy): HTTPS_PROXY /
 * https_proxy for https, http_proxy (HTTP_PROXY on the command line) for http, unless NO_PROXY
 * names the host (Go's rules) or the host is loopback; http(s) proxies only.
 */
class OutboundProxyTest extends TestCase
{
    public function test_the_variable_of_the_scheme_is_used(): void
    {
        $env = ['HTTPS_PROXY' => 'http://proxy.corp:3128', 'http_proxy' => 'http://plain.corp:8080'];

        $this->assertSame('http://proxy.corp:3128', OutboundProxy::for('https', 'api.example.com', 443, $env));
        $this->assertSame('http://plain.corp:8080', OutboundProxy::for('http', 'hooks.example.com', 80, $env));
        $this->assertSame('http://proxy.corp:3128', OutboundProxy::for('https', 'api.example.com', 6443, ['https_proxy' => 'proxy.corp:3128']), 'no scheme: http://');
        $this->assertSame('https://user:p%40ss@proxy.corp', OutboundProxy::for('https', 'api.example.com', 443, ['HTTPS_PROXY' => 'https://user:p%40ss@proxy.corp']));
        $this->assertNull(OutboundProxy::for('https', 'api.example.com', 443, ['http_proxy' => 'http://plain.corp:8080']), 'an http proxy does not carry https');
        $this->assertNull(OutboundProxy::for('https', 'api.example.com', 443, []));
    }

    public function test_http_proxy_in_upper_case_is_read_on_the_command_line_only(): void
    {
        // a web server's environment gets HTTP_PROXY from a request's Proxy header (httpoxy)
        $this->assertSame(['HTTP_PROXY', 'http://plain.corp:8080'], OutboundProxy::variable('http', ['HTTP_PROXY' => 'http://plain.corp:8080']), 'PHPUnit runs on the command line');
    }

    public function test_no_proxy_and_loopback_go_straight(): void
    {
        $proxy = ['HTTPS_PROXY' => 'http://proxy.corp:3128'];

        foreach ([
            'example.com' => 'api.example.com',
            '.example.com' => 'api.example.com',
            '*.example.com' => 'api.example.com',
            'api.example.com:6443' => 'api.example.com',
            '*' => 'api.example.com',
            '203.0.113.0/24' => '203.0.113.10',
            '2001:db8::/32' => '[2001:db8::1]',
        ] as $noProxy => $host) {
            $this->assertNull(OutboundProxy::for('https', $host, 6443, $proxy + ['NO_PROXY' => $noProxy]), "{$noProxy} / {$host}");
        }
        $this->assertNull(OutboundProxy::for('https', 'api.example.com', 443, $proxy + ['no_proxy' => 'example.com']));
        $this->assertNotNull(OutboundProxy::for('https', 'api.example.com', 443, $proxy + ['NO_PROXY' => 'api.example.com:6443']), 'another port');
        $this->assertNotNull(OutboundProxy::for('https', 'example.com', 443, $proxy + ['NO_PROXY' => '.example.com']), 'subdomains only');
        $this->assertNotNull(OutboundProxy::for('https', 'notexample.com', 443, $proxy + ['NO_PROXY' => 'example.com']));
        $this->assertNull(OutboundProxy::for('https', '127.0.0.1', 443, $proxy));
        $this->assertNull(OutboundProxy::for('https', 'localhost', 443, $proxy));
    }

    public function test_only_http_proxies_carry_a_pinned_request(): void
    {
        $this->assertNull(OutboundProxy::for('https', 'api.example.com', 443, ['HTTPS_PROXY' => 'socks5h://proxy.corp:1080']), 'a socks proxy: straight to the address');
        $this->assertNull(OutboundProxy::for('https', 'api.example.com', 443, ['HTTPS_PROXY' => 'http://']));
    }
}
