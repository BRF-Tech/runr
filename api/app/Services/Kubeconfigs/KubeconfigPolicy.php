<?php

namespace App\Services\Kubeconfigs;

use JsonException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * What the kubeconfig of an organization's own region may hold (docs/security.md, "Tenant region
 * kubeconfigs"). kubectl, helm and RunR's own clients act on everything a kubeconfig says, and
 * several of its fields act on the machine that reads it: a credential plugin (users[].user.exec,
 * auth-provider) runs a program, a token, certificate or CA *file* is read from RunR's disk, a
 * proxy-url sends the connection elsewhere, impersonation acts as another account. Those belong
 * to the operator's own (platform) regions; an organization's region connects with data alone.
 *
 * normalize() reads the kubeconfig (YAML, or JSON) and accepts only:
 *
 *  - top level   apiVersion (v1), kind (Config), clusters, users, contexts, current-context and
 *                preferences (colors only; dropped);
 *  - cluster     server (https://host[:port][/path]), certificate-authority-data, tls-server-name,
 *                insecure-skip-tls-verify;
 *  - user        token, client-certificate-data with client-key-data, username and password;
 *  - context     cluster, user, namespace;
 *
 * and only the current context with its cluster and user — an entry nothing uses is refused, so
 * nothing can hide in a second user. Anything else (extensions included) is refused with the
 * field's path. The result is rebuilt from the checked values (the server URL from its checked
 * parts), never the text that came in: kubectl and helm read exactly what was checked, whatever
 * YAML features (anchors, aliases, merge keys, tags) the text used. Several documents in one text
 * are refused by the parser.
 *
 * The server's address is checked and pinned by TenantKubeconfigs, which marks the cluster with
 * the addresses it checked (KubeconfigPin): that marker is RunR's own and is dropped here, so a
 * pinned kubeconfig read again is checked and pinned anew.
 */
final class KubeconfigPolicy
{
    public const MAX_BYTES = 262144;

    private const TOP_LEVEL = ['apiVersion', 'kind', 'clusters', 'users', 'contexts', 'current-context', 'preferences'];

    private const CLUSTER_FIELDS = ['server', 'certificate-authority-data', 'tls-server-name', 'insecure-skip-tls-verify'];

    private const USER_FIELDS = ['token', 'client-certificate-data', 'client-key-data', 'username', 'password'];

    private const CONTEXT_FIELDS = ['cluster', 'user', 'namespace'];

    /** Refused fields with a message of their own (regions.kubeconfig.<reason>); others: "field" */
    private const REFUSED = [
        'exec' => 'exec',
        'auth-provider' => 'exec',
        'tokenFile' => 'file',
        'client-certificate' => 'file',
        'client-key' => 'file',
        'certificate-authority' => 'file',
        'proxy-url' => 'proxy',
        'as' => 'impersonation',
        'as-uid' => 'impersonation',
        'as-groups' => 'impersonation',
        'as-user-extra' => 'impersonation',
    ];

    /** The three lists of a kubeconfig and the key of their entries' content */
    private const LISTS = ['clusters' => 'cluster', 'users' => 'user', 'contexts' => 'context'];

    /** @throws KubeconfigRefused */
    public static function normalize(string $text): TenantKubeconfig
    {
        $config = self::parse($text);

        foreach (array_keys($config) as $key) {
            if (! in_array($key, self::TOP_LEVEL, true)) {
                self::refuseField((string) $key);
            }
        }
        if ((isset($config['apiVersion']) && $config['apiVersion'] !== 'v1') || (isset($config['kind']) && $config['kind'] !== 'Config')) {
            throw new KubeconfigRefused('not_a_config');
        }
        self::checkPreferences($config['preferences'] ?? null);

        // Every entry first: a refused field names itself even in an entry nothing uses
        $entries = [];
        foreach (self::LISTS as $list => $content) {
            $entries[$list] = self::entries($config[$list] ?? [], $list, $content);
        }

        $current = $config['current-context'] ?? null;
        if (! is_string($current) || $current === '') {
            throw new KubeconfigRefused('no_context');
        }
        $context = $entries['contexts'][$current] ?? throw new KubeconfigRefused('no_context');
        foreach (['cluster', 'user'] as $field) {
            if (! isset($context['value'][$field]) || ! is_string($context['value'][$field]) || $context['value'][$field] === '') {
                throw new KubeconfigRefused('missing', ['entry' => "{$context['path']}.context.{$field}"]);
            }
        }
        $cluster = $entries['clusters'][$context['value']['cluster']] ?? throw new KubeconfigRefused('missing', ['entry' => 'clusters']);
        $user = $entries['users'][$context['value']['user']] ?? throw new KubeconfigRefused('missing', ['entry' => 'users']);

        $used = ['clusters' => $context['value']['cluster'], 'users' => $context['value']['user'], 'contexts' => $current];
        foreach ($entries as $list => $byName) {
            foreach ($byName as $name => $entry) {
                if ((string) $name !== $used[$list]) {
                    throw new KubeconfigRefused('unused', ['entry' => $entry['path']]);
                }
            }
        }

        $server = self::server($cluster['value']['server'] ?? null);
        $clusterValue = ['server' => $server['url']];
        foreach (['certificate-authority-data', 'tls-server-name', 'insecure-skip-tls-verify'] as $field) {
            if (array_key_exists($field, $cluster['value'])) {
                $clusterValue[$field] = $cluster['value'][$field];
            }
        }

        $normalized = [
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'clusters' => [['name' => $context['value']['cluster'], 'cluster' => $clusterValue]],
            // an empty map is written as {} (Yaml::dump), as kubectl reads it
            'users' => [['name' => $context['value']['user'], 'user' => $user['value']]],
            'contexts' => [['name' => $current, 'context' => $context['value']]],
            'current-context' => $current,
        ];

        return new TenantKubeconfig($normalized, $server['host'], $server['port']);
    }

    /** @return array<string, mixed> */
    private static function parse(string $text): array
    {
        if (strlen($text) > self::MAX_BYTES) {
            throw new KubeconfigRefused('too_large', ['kb' => intdiv(self::MAX_BYTES, 1024)]);
        }

        try {
            $config = Yaml::parse($text);
        } catch (ParseException) {
            try {
                $config = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new KubeconfigRefused('invalid');
            }
        }
        if (! is_array($config) || $config === [] || array_is_list($config)) {
            throw new KubeconfigRefused('invalid');
        }

        return $config;
    }

    private static function checkPreferences(mixed $preferences): void
    {
        if ($preferences === null || $preferences === []) {
            return;
        }
        if (! is_array($preferences) || array_is_list($preferences)) {
            throw new KubeconfigRefused('value', ['field' => 'preferences']);
        }
        foreach ($preferences as $key => $value) {
            if ($key !== 'colors') {
                self::refuseField('preferences.'.$key);
            }
            if (! is_bool($value)) {
                throw new KubeconfigRefused('value', ['field' => 'preferences.colors']);
            }
        }
    }

    /**
     * The entries of clusters / users / contexts by name, each checked field by field.
     *
     * @return array<string, array{path: string, value: array<string, mixed>}>
     */
    private static function entries(mixed $list, string $listName, string $content): array
    {
        if ($list === null || $list === []) {
            return [];
        }
        if (! is_array($list) || ! array_is_list($list)) {
            throw new KubeconfigRefused('value', ['field' => $listName]);
        }

        $byName = [];
        foreach ($list as $i => $entry) {
            $path = "{$listName}[{$i}]";
            if (! is_array($entry) || array_is_list($entry)) {
                throw new KubeconfigRefused('value', ['field' => $path]);
            }
            foreach (array_keys($entry) as $key) {
                if ($key !== 'name' && $key !== $content) {
                    self::refuseField("{$path}.{$key}");
                }
            }
            $name = $entry['name'] ?? null;
            if (! self::isName($name)) {
                throw new KubeconfigRefused('value', ['field' => "{$path}.name"]);
            }
            if (isset($byName[$name])) {
                throw new KubeconfigRefused('duplicate', ['entry' => "{$listName} \"{$name}\""]);
            }

            $value = $entry[$content] ?? [];
            if (! is_array($value) || ($value !== [] && array_is_list($value))) {
                throw new KubeconfigRefused('value', ['field' => "{$path}.{$content}"]);
            }
            $byName[$name] = ['path' => $path, 'value' => self::fields($value, "{$path}.{$content}", $content)];
        }

        return $byName;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed> the checked fields (base64 without whitespace)
     */
    private static function fields(array $value, string $path, string $content): array
    {
        $allowed = match ($content) {
            'cluster' => self::CLUSTER_FIELDS,
            'user' => self::USER_FIELDS,
            default => self::CONTEXT_FIELDS,
        };

        $checked = [];
        foreach ($value as $field => $fieldValue) {
            $field = (string) $field;
            if ($content === 'cluster' && $field === 'proxy-url' && is_string($fieldValue) && KubeconfigPin::isMarker($fieldValue)) {
                continue; // RunR's own pin of an earlier check (TenantKubeconfigs): checked anew
            }
            if (! in_array($field, $allowed, true)) {
                self::refuseField("{$path}.{$field}");
            }
            $checked[$field] = self::fieldValue($field, $fieldValue, "{$path}.{$field}");
        }

        if ($content === 'user' && (isset($checked['client-certificate-data']) !== isset($checked['client-key-data']))) {
            throw new KubeconfigRefused('key_pair');
        }

        return $checked;
    }

    private static function fieldValue(string $field, mixed $value, string $path): mixed
    {
        switch ($field) {
            case 'insecure-skip-tls-verify':
                if (! is_bool($value)) {
                    throw new KubeconfigRefused('value', ['field' => $path]);
                }

                return $value;
            case 'certificate-authority-data':
            case 'client-certificate-data':
            case 'client-key-data':
                $data = is_string($value) ? (string) preg_replace('/\s+/', '', $value) : '';
                if ($data === '' || base64_decode($data, true) === false) {
                    throw new KubeconfigRefused('value', ['field' => $path]);
                }

                return $data;
            case 'tls-server-name':
                if (! is_string($value) || ! (self::isHostName(strtolower($value)) || filter_var($value, FILTER_VALIDATE_IP) !== false)) {
                    throw new KubeconfigRefused('value', ['field' => $path]);
                }

                return $value;
            case 'namespace':
                if (! is_string($value) || ! preg_match('/^[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])?$/', $value)) {
                    throw new KubeconfigRefused('value', ['field' => $path]);
                }

                return $value;
            case 'cluster':
            case 'user':
                if (! self::isName($value)) {
                    throw new KubeconfigRefused('value', ['field' => $path]);
                }

                return $value;
            case 'server':
                if (! is_string($value)) {
                    throw new KubeconfigRefused('server');
                }

                return $value;
            default: // token, username, password: a printable string (it goes into a header)
                if (! is_string($value) || $value === '' || strlen($value) > 16384 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                    throw new KubeconfigRefused('value', ['field' => $path]);
                }

                return $value;
        }
    }

    /**
     * The server URL rebuilt from its checked parts: https, a host name or a literal address
     * (IPv6 in brackets), an optional port, an optional path — no user info, query or fragment.
     *
     * @return array{url: string, host: string, port: int}
     */
    private static function server(mixed $server): array
    {
        if (! is_string($server) || ! preg_match('#^https://(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9._-]+)(?::(\d{1,5}))?(/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*)?$#D', $server, $m)) {
            throw new KubeconfigRefused('server');
        }

        $host = strtolower($m[1]);
        if (str_starts_with($host, '[')) {
            if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new KubeconfigRefused('server');
            }
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false && ! self::isHostName($host)) {
            throw new KubeconfigRefused('server');
        }

        $port = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 443;
        if ($port < 1 || $port > 65535) {
            throw new KubeconfigRefused('server');
        }
        $path = rtrim($m[3] ?? '', '/');

        return [
            'url' => 'https://'.$host.(isset($m[2]) && $m[2] !== '' ? ':'.$port : '').$path,
            'host' => trim(rtrim($host, '.'), '[]'),
            'port' => $port,
        ];
    }

    private static function refuseField(string $path): never
    {
        $dot = strrpos($path, '.');
        $field = $dot === false ? $path : substr($path, $dot + 1);

        throw new KubeconfigRefused(self::REFUSED[$field] ?? 'field', ['field' => $path]);
    }

    private static function isName(mixed $name): bool
    {
        return is_string($name) && $name !== '' && strlen($name) <= 253 && ! preg_match('/[\x00-\x1F\x7F]/', $name);
    }

    /** A DNS name whose last label is not numeric (that would be an IPv4 address in another notation). */
    private static function isHostName(string $host): bool
    {
        $name = rtrim($host, '.');
        if ($name === '' || strlen($name) > 253 || ! preg_match('/^(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?$/', $name)) {
            return false;
        }
        $labels = explode('.', $name);

        return ! preg_match('/^(?:0x[0-9a-f]*|[0-9]+)$/', end($labels));
    }
}
