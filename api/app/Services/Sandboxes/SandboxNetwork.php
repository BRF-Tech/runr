<?php

namespace App\Services\Sandboxes;

/**
 * The `network` of a sandbox.
 *
 *  - "none" (default): no traffic in or out — the namespace's default-deny NetworkPolicy.
 *  - {"egress_allow": [...]}: outbound traffic to the listed destinations only, plus DNS to the
 *    cluster DNS. Entries: an IPv4 / IPv6 address or CIDR, or a host name; each optionally with a
 *    port ("pypi.org:443", "10.0.0.0/8:5432", "[2001:db8::1]:443"). Host names are resolved by the
 *    API when the network is applied and their addresses allowed. Inbound traffic stays denied.
 *
 * Ranges in config('sandbox.egress_always_denied') — the cloud metadata addresses — are carved out
 * of every allowed block (NetworkPolicy ipBlock.except) and cannot be allowed on their own.
 *
 * Stored on the sandbox as {mode, egress_allow, rules: [{cidr, port, entry}]}.
 */
final class SandboxNetwork
{
    public function __construct(private HostResolver $resolver) {}

    /**
     * Normalize + validate + resolve a request's `network`.
     *
     * @throws SandboxException network_invalid
     */
    public function parse(mixed $input): array
    {
        $entries = $this->entries($input);
        if ($entries === null) {
            return ['mode' => 'none', 'egress_allow' => [], 'rules' => []];
        }

        $max = (int) config('sandbox.limits.max_egress_rules', 50);
        if (count($entries) > $max) {
            $this->invalid(__('sandboxes.network_too_many', ['max' => $max]));
        }

        $rules = [];
        $normalized = [];
        foreach ($entries as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                $this->invalid(__('sandboxes.network_entry_invalid', ['entry' => is_scalar($entry) ? (string) $entry : json_encode($entry)]));
            }
            $entry = strtolower(trim($entry));
            [$host, $port] = $this->splitPort($entry);

            foreach ($this->blocks($host, $entry) as $cidr) {
                $rules[] = ['cidr' => $cidr, 'port' => $port, 'entry' => $entry];
            }
            $normalized[] = $entry;
        }

        return ['mode' => 'allowlist', 'egress_allow' => array_values(array_unique($normalized)), 'rules' => $rules];
    }

    /** null for network: none, else the list of entries. */
    private function entries(mixed $input): ?array
    {
        if ($input === null || $input === 'none' || $input === '' || $input === false) {
            return null;
        }
        if (is_string($input)) {
            $this->invalid(__('sandboxes.network_invalid'));
        }
        if (! is_array($input)) {
            $this->invalid(__('sandboxes.network_invalid'));
        }
        if (array_is_list($input)) {
            return $input === [] ? null : $input;
        }
        if (($input['mode'] ?? null) === 'none') {
            return null;
        }

        $allow = $input['egress_allow'] ?? null;
        if (! is_array($allow) || ! array_is_list($allow)) {
            $this->invalid(__('sandboxes.network_invalid'));
        }

        return $allow === [] ? null : $allow;
    }

    /** [host-or-cidr, port|null] */
    private function splitPort(string $entry): array
    {
        if (preg_match('/^\[([0-9a-f:.]+)\](?::(\d+))?$/', $entry, $m)) {
            return [$m[1], isset($m[2]) ? $this->port($m[2], $entry) : null];
        }
        // IPv6 without brackets has no port
        if (substr_count($entry, ':') > 1) {
            return [$entry, null];
        }
        if (preg_match('/^(.+):(\d+)$/', $entry, $m)) {
            return [$m[1], $this->port($m[2], $entry)];
        }

        return [$entry, null];
    }

    private function port(string $value, string $entry): int
    {
        $port = (int) $value;
        if ($port < 1 || $port > 65535) {
            $this->invalid(__('sandboxes.network_entry_invalid', ['entry' => $entry]));
        }

        return $port;
    }

    /** @return list<string> CIDR blocks of an entry */
    private function blocks(string $host, string $entry): array
    {
        if (str_contains($host, '/')) {
            [$ip, $bits] = explode('/', $host, 2);
            if (! filter_var($ip, FILTER_VALIDATE_IP) || ! ctype_digit($bits)) {
                $this->invalid(__('sandboxes.network_entry_invalid', ['entry' => $entry]));
            }
            $max = str_contains($ip, ':') ? 128 : 32;
            if ((int) $bits > $max) {
                $this->invalid(__('sandboxes.network_entry_invalid', ['entry' => $entry]));
            }

            return [$this->checkAllowed(self::networkAddress($ip, (int) $bits).'/'.(int) $bits, $entry)];
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$this->checkAllowed($host.(str_contains($host, ':') ? '/128' : '/32'), $entry)];
        }

        if (! preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $host)) {
            $this->invalid(__('sandboxes.network_entry_invalid', ['entry' => $entry]));
        }

        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            $this->invalid(__('sandboxes.network_unresolvable', ['host' => $host]));
        }

        $blocks = [];
        foreach ($addresses as $address) {
            $cidr = $address.(str_contains($address, ':') ? '/128' : '/32');
            // A name pointing at a metadata address is not an error of the request; that address stays denied
            if (! self::coveredByDenied($cidr)) {
                $blocks[] = $cidr;
            }
        }
        if ($blocks === []) {
            $this->invalid(__('sandboxes.network_denied_range', ['entry' => $entry]));
        }

        return $blocks;
    }

    private function checkAllowed(string $cidr, string $entry): string
    {
        if (self::coveredByDenied($cidr)) {
            $this->invalid(__('sandboxes.network_denied_range', ['entry' => $entry]));
        }

        return $cidr;
    }

    /**
     * NetworkPolicy egress rules for an allowlist (rules as stored by parse()): DNS to the cluster
     * DNS, then one rule per port (null = every port) with its address blocks.
     */
    public static function egressRules(array $network): array
    {
        $rules = $network['rules'] ?? [];
        if (($network['mode'] ?? 'none') !== 'allowlist' || $rules === []) {
            return [];
        }

        $egress = [[
            'to' => [[
                'namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => 'kube-system']],
                'podSelector' => ['matchLabels' => ['k8s-app' => 'kube-dns']],
            ]],
            'ports' => [
                ['protocol' => 'UDP', 'port' => 53],
                ['protocol' => 'TCP', 'port' => 53],
            ],
        ]];

        $byPort = [];
        foreach ($rules as $rule) {
            $byPort[(string) ($rule['port'] ?? '*')][$rule['cidr']] = true;
        }
        ksort($byPort);

        foreach ($byPort as $port => $cidrs) {
            $to = [];
            foreach (array_keys($cidrs) as $cidr) {
                $block = ['cidr' => $cidr];
                $except = self::exceptFor($cidr);
                if ($except !== []) {
                    $block['except'] = $except;
                }
                $to[] = ['ipBlock' => $block];
            }

            $rule = ['to' => $to];
            if ($port !== '*') {
                $rule['ports'] = [
                    ['protocol' => 'TCP', 'port' => (int) $port],
                    ['protocol' => 'UDP', 'port' => (int) $port],
                ];
            }
            $egress[] = $rule;
        }

        return $egress;
    }

    /** Always-denied ranges inside $cidr (ipBlock.except must lie within the block). */
    public static function exceptFor(string $cidr): array
    {
        $except = [];
        foreach ((array) config('sandbox.egress_always_denied', []) as $denied) {
            if (self::contains($cidr, $denied) && $cidr !== $denied) {
                $except[] = $denied;
            }
        }

        return $except;
    }

    /** Whether $cidr lies completely inside an always-denied range. */
    public static function coveredByDenied(string $cidr): bool
    {
        foreach ((array) config('sandbox.egress_always_denied', []) as $denied) {
            if (self::contains($denied, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /** Whether CIDR $outer contains CIDR $inner (same address family). */
    public static function contains(string $outer, string $inner): bool
    {
        [$oIp, $oBits] = array_pad(explode('/', $outer, 2), 2, null);
        [$iIp, $iBits] = array_pad(explode('/', $inner, 2), 2, null);
        $o = @inet_pton((string) $oIp);
        $i = @inet_pton((string) $iIp);
        if ($o === false || $i === false || strlen($o) !== strlen($i)) {
            return false;
        }
        $oBits = $oBits === null ? strlen($o) * 8 : (int) $oBits;
        $iBits = $iBits === null ? strlen($i) * 8 : (int) $iBits;
        if ($iBits < $oBits) {
            return false;
        }

        return self::maskBits($o, $oBits) === self::maskBits($i, $oBits);
    }

    /** Network address of $ip/$bits ("10.1.2.3", 8 → "10.0.0.0"). */
    public static function networkAddress(string $ip, int $bits): string
    {
        $packed = inet_pton($ip);

        return inet_ntop(self::maskBits($packed, $bits));
    }

    private static function maskBits(string $packed, int $bits): string
    {
        $out = '';
        $len = strlen($packed);
        for ($n = 0; $n < $len; $n++) {
            $take = max(0, min(8, $bits - $n * 8));
            $mask = $take === 0 ? 0 : (0xFF << (8 - $take)) & 0xFF;
            $out .= chr(ord($packed[$n]) & $mask);
        }

        return $out;
    }

    private function invalid(string $message): never
    {
        throw new SandboxException($message, 422, 'network_invalid', ['errors' => ['network' => [$message]]]);
    }
}
