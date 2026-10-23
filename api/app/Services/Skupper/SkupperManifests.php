<?php

namespace App\Services\Skupper;

use App\Models\PrivateDatabaseBinding;
use App\Models\Project;
use App\Services\Environments\EnvironmentScope;
use App\Services\Manifests\KnativeManifests;

/**
 * Skupper v2 (skupper.io/v2alpha1) resources of a private database connection,
 * built as plain arrays — no cluster access here.
 */
class SkupperManifests
{
    public const API_VERSION = 'skupper.io/v2alpha1';

    public const SITE_NAME = 'runr';

    public static function labels(Project $project, ?PrivateDatabaseBinding $binding = null): array
    {
        $labels = array_merge([
            'app.kubernetes.io/managed-by' => 'runr',
            'runr/project-id' => $project->id,
        ], KnativeManifests::extraLabels($project));

        // An environment's connection (docs/environments.md): the environment's labels too
        if ($environment = EnvironmentScope::of($project)) {
            $labels += EnvironmentScope::labels(EnvironmentScope::base($project), $environment);
        }

        if ($binding) {
            $labels['runr/private-connection'] = $binding->resource_name;
        }

        return $labels;
    }

    /**
     * The namespace's Skupper site. Consumer side: it only creates outgoing links, so no link
     * access (ingress) is requested.
     */
    public static function site(string $namespace): array
    {
        return [
            'apiVersion' => self::API_VERSION,
            'kind' => 'Site',
            'metadata' => [
                'name' => self::SITE_NAME,
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr'],
            ],
            'spec' => (object) [],
        ];
    }

    /**
     * Listener: traffic to the local Service {host}:{port} goes to the connector(s) with the
     * routing key — the Managed PostgreSQL database.
     */
    public static function listener(PrivateDatabaseBinding $binding, Project $project, string $namespace): array
    {
        return [
            'apiVersion' => self::API_VERSION,
            'kind' => 'Listener',
            'metadata' => [
                'name' => $binding->resource_name,
                'namespace' => $namespace,
                'labels' => self::labels($project, $binding),
            ],
            'spec' => [
                'routingKey' => $binding->routing_key,
                'host' => $binding->host,
                'port' => (int) $binding->port,
            ],
        ];
    }

    /**
     * The link material under RunR's names: an AccessToken (redeemed by the Skupper controller
     * into a Link of the same name), or a Link plus its TLS Secret.
     *
     * @param  array{kind:string, spec:array, secret?:array}  $link  SkupperLinkParser::parse() result
     * @return list<array>
     */
    public static function link(array $link, PrivateDatabaseBinding $binding, Project $project, string $namespace): array
    {
        $metadata = [
            'name' => $binding->resource_name,
            'namespace' => $namespace,
            'labels' => self::labels($project, $binding),
        ];

        if ($link['kind'] === 'AccessToken') {
            return [[
                'apiVersion' => self::API_VERSION,
                'kind' => 'AccessToken',
                'metadata' => $metadata,
                'spec' => $link['spec'],
            ]];
        }

        $secretName = self::linkSecretName($binding);

        return [
            [
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => array_merge($metadata, ['name' => $secretName]),
                'type' => $link['secret']['type'] ?? 'kubernetes.io/tls',
                'data' => $link['secret']['data'],
            ],
            [
                'apiVersion' => self::API_VERSION,
                'kind' => 'Link',
                'metadata' => $metadata,
                'spec' => array_merge($link['spec'], ['tlsCredentials' => $secretName]),
            ],
        ];
    }

    public static function linkSecretName(PrivateDatabaseBinding $binding): string
    {
        return $binding->resource_name.'-link';
    }

    /**
     * Credentials Secret — the only place the database user/password are stored.
     *
     * @param  array{database:string, username:string, password:string}  $credentials
     */
    public static function credentialsSecret(PrivateDatabaseBinding $binding, Project $project, string $namespace, array $credentials): array
    {
        $host = $binding->host;
        $port = (int) $binding->port;

        $values = [
            'DATABASE_URL' => sprintf(
                'postgresql://%s:%s@%s:%d/%s?sslmode=%s',
                rawurlencode($credentials['username']),
                rawurlencode($credentials['password']),
                $host,
                $port,
                rawurlencode($credentials['database']),
                $binding->sslmode,
            ),
            'PGHOST' => $host,
            'PGPORT' => (string) $port,
            'PGDATABASE' => $credentials['database'],
            'PGUSER' => $credentials['username'],
            'PGPASSWORD' => $credentials['password'],
            'PGSSLMODE' => $binding->sslmode,
        ];

        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => [
                'name' => $binding->secret_name,
                'namespace' => $namespace,
                'labels' => self::labels($project, $binding),
            ],
            'type' => 'Opaque',
            'data' => array_map('base64_encode', $values),
        ];
    }

    /**
     * Container env with the project's bindings: RunR-managed entries (secretKeyRef to a
     * "*-pgconn" Secret) and user entries with the same names are replaced; the rest is kept.
     *
     * @param  iterable<PrivateDatabaseBinding>  $bindings
     */
    public static function mergeEnv(array $env, iterable $bindings): array
    {
        $add = [];
        foreach ($bindings as $binding) {
            array_push($add, ...$binding->envVars());
        }
        $names = array_column($add, 'name');

        $kept = array_values(array_filter($env, function ($entry) use ($names) {
            $ref = $entry['valueFrom']['secretKeyRef']['name'] ?? '';

            return ! str_ends_with((string) $ref, PrivateDatabaseBinding::SECRET_SUFFIX)
                && ! in_array($entry['name'] ?? null, $names, true);
        }));

        return array_merge($kept, $add);
    }
}
