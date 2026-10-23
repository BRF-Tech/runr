<?php

namespace App\Services\GlobalProjects\Data;

/**
 * A dedicated Postgres binding of a global project as a CloudNativePG distributed topology
 * (docs/global-projects.md, "Postgres across regions"), pure builders.
 *
 * Every region of the project runs a Cluster of the same name (pg-<binding>): the home's is the
 * primary, the others are replica clusters that stream from it through the binding's data link.
 * Each one knows the topology — spec.replica.self (its own name in the topology: r<region id>),
 * spec.replica.primary (the home's) and spec.replica.source (whom it streams from; the home's
 * names the region it would follow after a demotion) — and lists every member, itself included
 * (CloudNativePG's webhook refuses a self, primary or source that names no external cluster), as
 * externalClusters, all reached through the region's link (which always leads to the current home).
 *
 *   planned switchover A → B   A: primary = B (CloudNativePG demotes it, status.demotionToken),
 *                              B: primary = B + promotionToken (promotes once it replayed A's last
 *                              WAL — nothing written on A is lost)
 *   failover (A gone)          B: primary = B without a token (promotes at once: what A wrote
 *                              after B's last received WAL is lost); A is rebuilt as a replica of
 *                              B when its cluster answers again
 *
 * The streaming user is CloudNativePG's streaming_replica with the home's client certificate
 * (copied into the region as pg-<binding>-src-replication / -src-ca); the superuser and owner
 * Secrets are the home's in every region, so a promoted cluster keeps the binding's passwords.
 */
final class RegionalPostgresManifests
{
    /** The region's name in the topology (an externalClusters name). */
    public static function topologyName(string $regionId): string
    {
        return 'r'.strtolower($regionId);
    }

    public static function sourceReplicationSecret(string $cluster): string
    {
        return $cluster.'-src-replication';
    }

    public static function sourceCaSecret(string $cluster): string
    {
        return $cluster.'-src-ca';
    }

    public static function superuserSecret(string $cluster): string
    {
        return $cluster.'-superuser';
    }

    /**
     * The dedicated cluster manifest of one region with its place in the topology.
     *
     * @param  array  $base  BindingManifests::dedicatedCluster() of the binding
     * @param  list<string>  $members  topology names of every region (the home's included)
     * @param  array|null  $bootstrap  the cluster's bootstrap as it was created (live), null: a new
     *                                 cluster — initdb at the home ($base), pg_basebackup elsewhere
     */
    public static function cluster(array $base, string $self, string $primary, string $source, array $members, string $linkHost, ?array $bootstrap = null, ?string $promotionToken = null): array
    {
        $cluster = (string) $base['metadata']['name'];
        $manifest = $base;

        if ($bootstrap !== null) {
            $manifest['spec']['bootstrap'] = $bootstrap;
        } elseif ($self !== $primary) {
            $manifest['spec']['bootstrap'] = ['pg_basebackup' => ['source' => $primary]];
        }

        $replica = ['self' => $self, 'primary' => $primary, 'source' => $source];
        if ($promotionToken !== null && $promotionToken !== '') {
            $replica['promotionToken'] = $promotionToken;
        }
        $manifest['spec']['replica'] = $replica;
        $manifest['spec']['superuserSecret'] = ['name' => self::superuserSecret($cluster)];
        $manifest['spec']['enableSuperuserAccess'] = true;

        $external = [];
        foreach (array_values(array_unique([...$members, $self, $primary, $source])) as $member) {
            $external[] = [
                'name' => $member,
                'connectionParameters' => [
                    'host' => $linkHost,
                    'port' => '5432',
                    'user' => 'streaming_replica',
                    'dbname' => 'postgres',
                    // the home's server certificate names its own Services, not the link: its CA
                    'sslmode' => 'verify-ca',
                ],
                'sslKey' => ['name' => self::sourceReplicationSecret($cluster), 'key' => 'tls.key'],
                'sslCert' => ['name' => self::sourceReplicationSecret($cluster), 'key' => 'tls.crt'],
                'sslRootCert' => ['name' => self::sourceCaSecret($cluster), 'key' => 'ca.crt'],
            ];
        }
        $manifest['spec']['externalClusters'] = $external;

        return $manifest;
    }

    /**
     * Copies of the home's Secrets a region's cluster needs: the superuser and owner Secrets as
     * they are (same passwords everywhere), the home's replication client certificate and CA under
     * the -src- names.
     *
     * @param  array<string, array>  $home  live Secrets of the home: superuser, owner, replication, ca
     * @return list<array>
     */
    public static function secrets(string $cluster, string $namespace, array $home, array $labels): array
    {
        $secret = fn (string $name, string $type, array $data) => [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'type' => $type,
            'data' => $data,
        ];

        $out = [];
        if (isset($home['superuser']['data']['password'])) {
            $out[] = $secret(self::superuserSecret($cluster), 'kubernetes.io/basic-auth', [
                'username' => $home['superuser']['data']['username'] ?? base64_encode('postgres'),
                'password' => $home['superuser']['data']['password'],
            ]);
        }
        if (isset($home['owner']['data']['password'])) {
            $out[] = $secret($cluster.'-owner', 'kubernetes.io/basic-auth', [
                'username' => (string) ($home['owner']['data']['username'] ?? ''),
                'password' => $home['owner']['data']['password'],
            ]);
        }
        if (isset($home['replication']['data']['tls.crt'], $home['replication']['data']['tls.key'])) {
            $out[] = $secret(self::sourceReplicationSecret($cluster), 'kubernetes.io/tls', [
                'tls.crt' => $home['replication']['data']['tls.crt'],
                'tls.key' => $home['replication']['data']['tls.key'],
            ]);
        }
        if (isset($home['ca']['data']['ca.crt'])) {
            $out[] = $secret(self::sourceCaSecret($cluster), 'Opaque', ['ca.crt' => $home['ca']['data']['ca.crt']]);
        }

        return $out;
    }

    /**
     * SQL of a replica's state, printed as "STATE <in recovery t|f> <receiver status> <replay lag
     * seconds>" (OpsResult::marked('STATE')).
     */
    public static function stateSql(): string
    {
        return "SELECT 'STATE ' || pg_is_in_recovery()::text || ' ' || coalesce((SELECT status FROM pg_stat_wal_receiver LIMIT 1), 'none') || ' ' || "
            ."coalesce(round(extract(epoch FROM (now() - pg_last_xact_replay_timestamp())))::bigint::text, '-');";
    }

    /**
     * A state line of stateSql().
     *
     * @return array{primary: bool, streaming: bool, lag_seconds: ?int}|null
     */
    public static function parseState(?string $line): ?array
    {
        if ($line === null || ! preg_match('/^(t|f|true|false) (\S+) (\S+)$/', trim($line), $m)) {
            return null;
        }

        return [
            'primary' => in_array($m[1], ['f', 'false'], true),
            'streaming' => $m[2] === 'streaming',
            'lag_seconds' => is_numeric($m[3]) ? max(0, (int) $m[3]) : null,
        ];
    }
}
