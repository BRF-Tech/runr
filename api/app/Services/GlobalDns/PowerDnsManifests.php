<?php

namespace App\Services\GlobalDns;

/**
 * Pure manifest builders of the PowerDNS Authoritative server RunR runs in a region for the global
 * zone (docs/global-projects.md). One server per cluster, in its own namespace:
 *
 *   Secret      runr-pdns-api      the HTTP API key (PDNS_AUTH_API_KEY of the image)
 *   ConfigMap   runr-pdns-config   00-runr.conf, the DNS UPDATE policy, prepare.py, geo-zones.yaml
 *   PVC         runr-pdns-data     /var/lib/powerdns: the SQLite zone database, the GeoIP database
 *   Deployment  runr-pdns          one pod (Recreate: the volume is ReadWriteOnce); its init
 *                                  container creates the database schema on a new volume,
 *                                  downloads / refreshes the GeoIP database and enables the GeoIP
 *                                  backend when it has one
 *   Service     runr-pdns          LoadBalancer, UDP + TCP 53 → 5353 (the image runs as uid 953:
 *                                  no privileged port), externalTrafficPolicy Local — the server
 *                                  sees the resolvers' addresses, what proximity answers by
 *   Service     runr-pdns-api      ClusterIP 8081: the HTTP API, reached by RunR through the
 *                                  Kubernetes API server's service proxy only
 *
 * DNS UPDATE (RFC 2136) is on for cert-manager's DNS-01 challenges only: update-policy.lua admits
 * TXT records under _acme-challenge. signed with RunR's TSIG key and refuses everything else (the
 * policy script replaces PowerDNS' other update checks). LUA records are served
 * (enable-lua-records); they come from RunR's zone sync only — never from a DNS update.
 */
final class PowerDnsManifests
{
    public const DEPLOYMENT = 'runr-pdns';

    public const DNS_SERVICE = 'runr-pdns';

    public const API_SERVICE = 'runr-pdns-api';

    public const API_PORT = 8081;

    public const DNS_PORT = 5353;

    public const API_SECRET = 'runr-pdns-api';

    public const CONFIG_MAP = 'runr-pdns-config';

    public const VOLUME = 'runr-pdns-data';

    public const UID = 953;

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'app.kubernetes.io/name' => 'runr-pdns',
            'app.kubernetes.io/component' => 'global-dns',
            'app.kubernetes.io/managed-by' => 'runr',
        ];
    }

    /**
     * Every object of the server, in apply order.
     *
     * @param  array{image: string, ttl: int, health_interval: int, geoip_url: string, geoip_mode?: string, tsig_key_name: string, storage_class: ?string, storage_size: string, service_annotations: array<string, string>}  $options
     * @return list<array>
     */
    public static function all(string $namespace, string $apiKey, array $options): array
    {
        return [
            self::namespace($namespace),
            self::apiSecret($namespace, $apiKey),
            self::configMap($namespace, $options),
            self::volume($namespace, $options['storage_class'], $options['storage_size']),
            self::deployment($namespace, $options['image'], $options['geoip_url'], self::configHash($options), (string) ($options['geoip_mode'] ?? 'url')),
            self::dnsService($namespace, $options['service_annotations']),
            self::apiService($namespace),
        ];
    }

    public static function namespace(string $namespace): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => ['name' => $namespace, 'labels' => ['app.kubernetes.io/managed-by' => 'runr']],
        ];
    }

    public static function apiSecret(string $namespace, string $apiKey): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => self::API_SECRET, 'namespace' => $namespace, 'labels' => self::labels()],
            'type' => 'Opaque',
            'stringData' => ['api-key' => $apiKey],
        ];
    }

    /**
     * @param  array{ttl: int, health_interval: int, tsig_key_name: string}  $options
     */
    public static function configMap(string $namespace, array $options): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => ['name' => self::CONFIG_MAP, 'namespace' => $namespace, 'labels' => self::labels()],
            'data' => [
                '00-runr.conf' => self::serverConfig($options['ttl'], $options['health_interval']),
                'update-policy.lua' => self::updatePolicy($options['tsig_key_name']),
                'prepare.py' => self::prepareScript(),
                'geo-zones.yaml' => "domains: []\n",
            ],
        ];
    }

    /** pdns.conf settings of the server (the image's own config includes /etc/powerdns/pdns.d). */
    public static function serverConfig(int $ttl, int $healthInterval): string
    {
        return implode("\n", [
            'launch=gsqlite3',
            'gsqlite3-database=/var/lib/powerdns/pdns.sqlite3',
            'local-port='.self::DNS_PORT,
            'api=yes',
            'webserver=yes',
            'webserver-address=0.0.0.0',
            'webserver-port='.self::API_PORT,
            // the API Service is ClusterIP and reached through the Kubernetes API server's proxy; the
            // API key authenticates every call
            'webserver-allow-from=0.0.0.0/0,::/0',
            'enable-lua-records=yes',
            'lua-health-checks-interval='.max(1, $healthInterval),
            'edns-subnet-processing=yes',
            'dnsupdate=yes',
            'lua-dnsupdate-policy-script=/runr/update-policy.lua',
            'disable-axfr=yes',
            'version-string=anonymous',
            'default-ttl='.max(1, $ttl),
            '',
        ]);
    }

    /**
     * DNS UPDATE policy: TXT records under _acme-challenge. of the zone, signed with RunR's TSIG
     * key (PowerDNS validated the signature before the script runs). Everything else is refused.
     */
    public static function updatePolicy(string $tsigKeyName): string
    {
        $key = addslashes(rtrim(strtolower($tsigKeyName), '.'));

        return <<<LUA
            -- RunR (docs/global-projects.md): cert-manager's DNS-01 challenge records only
            local key = "{$key}"
            local prefix = "_acme-challenge."

            function updatepolicy(input)
              local tsig = input:getTsigName()
              if tsig == nil or string.lower(tsig:toStringNoDot()) ~= key then
                return false
              end
              if input:getQType() ~= pdns.TXT then
                return false
              end
              local name = string.lower(input:getQName():toStringNoDot())
              return string.sub(name, 1, string.len(prefix)) == prefix
            end

            LUA;
    }

    /**
     * Init container script (python3 of the PowerDNS image): the server config into pdns.d, the
     * SQLite schema on a new volume, the GeoIP database (downloaded when missing or older than 35
     * days; the current month's file, else the previous month's) and — when there is one — the
     * GeoIP backend. A failed download keeps the server running without GeoIP.
     */
    public static function prepareScript(): string
    {
        return <<<'PY'
            import datetime, gzip, json, os, shutil, sqlite3, time, urllib.request

            conf_dir = "/etc/powerdns/pdns.d"
            data_dir = "/var/lib/powerdns"
            db = data_dir + "/pdns.sqlite3"
            geoip = data_dir + "/geoip.mmdb"
            marker = data_dir + "/geoip.source"
            schema = "/usr/local/share/doc/pdns/schema.sqlite3.sql"

            # RunR reads this line from the container's log (the console's GeoIP status)
            def status(state, **fields):
                fields["status"] = state
                print("GEOIP_STATUS " + json.dumps(fields, sort_keys=True))

            shutil.copy("/runr/00-runr.conf", conf_dir + "/00-runr.conf")

            if not os.path.exists(db):
                with open(schema) as f, sqlite3.connect(db) as conn:
                    conn.executescript(f.read())
                print("zone database created")

            mode = os.environ.get("GEOIP_MODE", "url").strip() or "url"
            url = os.environ.get("GEOIP_URL", "").strip()
            if mode == "url" and url in ("", "-"):
                mode = "none"
            error = None
            source = None

            if mode == "none":
                if os.path.exists(geoip):
                    os.remove(geoip)
                status("off")
            elif mode == "file":
                # the operator's own file on this volume: geoip.mmdb, or geoip.mmdb.gz unpacked here
                packed = geoip + ".gz"
                if os.path.exists(packed) and (not os.path.exists(geoip) or os.path.getmtime(packed) > os.path.getmtime(geoip)):
                    with gzip.open(packed, "rb") as f, open(geoip + ".tmp", "wb") as out:
                        shutil.copyfileobj(f, out)
                    os.replace(geoip + ".tmp", geoip)
                source = "file"
            else:
                previous_source = open(marker).read().strip() if os.path.exists(marker) else ""
                stale = not os.path.exists(geoip) or time.time() - os.path.getmtime(geoip) > 35 * 86400 or previous_source != url
                source = previous_source or url
                if stale:
                    today = datetime.date.today()
                    previous = today.replace(day=1) - datetime.timedelta(days=1)
                    for day in (today, previous):
                        candidate = url.replace("{yyyy}", "%04d" % day.year).replace("{mm}", "%02d" % day.month)
                        try:
                            with urllib.request.urlopen(candidate, timeout=120) as response:
                                data = response.read()
                            if candidate.split("?")[0].endswith(".gz"):
                                data = gzip.decompress(data)
                            with open(geoip + ".tmp", "wb") as out:
                                out.write(data)
                            os.replace(geoip + ".tmp", geoip)
                            with open(marker, "w") as out:
                                out.write(url)
                            print("GeoIP database from " + candidate)
                            source = candidate
                            error = None
                            break
                        except Exception as e:
                            error = "%s: %s" % (candidate.split("?")[0], e)
                            print("GeoIP download failed: " + error)

            if mode != "none":
                if os.path.exists(geoip):
                    with open(conf_dir + "/10-geoip.conf", "w") as out:
                        out.write("launch+=geoip\ngeoip-database-files=mmdb:%s\ngeoip-zones-file=/runr/geo-zones.yaml\n" % geoip)
                    status("loaded", mode=mode, source=(source or "").split("?")[0], bytes=os.path.getsize(geoip),
                           updated=time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(os.path.getmtime(geoip))),
                           refresh_error=error)
                else:
                    print("no GeoIP database: proximity answers without a preferred region")
                    status("missing", mode=mode, source=(source or "").split("?")[0] or None, error=error)

            PY;
    }

    public static function volume(string $namespace, ?string $storageClass, string $size): array
    {
        $spec = [
            'accessModes' => ['ReadWriteOnce'],
            'resources' => ['requests' => ['storage' => $size]],
        ];
        if ($storageClass !== null && $storageClass !== '') {
            $spec['storageClassName'] = $storageClass;
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'PersistentVolumeClaim',
            'metadata' => ['name' => self::VOLUME, 'namespace' => $namespace, 'labels' => self::labels()],
            'spec' => $spec,
        ];
    }

    /**
     * @param  string  $geoipMode  url (download $geoipUrl) | file (the operator's file on the volume) | none
     */
    public static function deployment(string $namespace, string $image, string $geoipUrl, string $configHash, string $geoipMode = 'url'): array
    {
        $labels = self::labels();
        $selector = ['app.kubernetes.io/name' => $labels['app.kubernetes.io/name']];
        $mounts = [
            ['name' => 'data', 'mountPath' => '/var/lib/powerdns'],
            ['name' => 'conf', 'mountPath' => '/etc/powerdns/pdns.d'],
            ['name' => 'runr', 'mountPath' => '/runr', 'readOnly' => true],
        ];

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => ['name' => self::DEPLOYMENT, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => [
                'replicas' => 1,
                'strategy' => ['type' => 'Recreate'],
                'selector' => ['matchLabels' => $selector],
                'template' => [
                    // a changed config rolls the pod (the init container copies it at start)
                    'metadata' => ['labels' => $labels, 'annotations' => ['runr.dev/config-hash' => $configHash]],
                    'spec' => [
                        'securityContext' => [
                            'runAsUser' => self::UID,
                            'runAsGroup' => self::UID,
                            'runAsNonRoot' => true,
                            'fsGroup' => self::UID,
                        ],
                        'initContainers' => [[
                            'name' => 'prepare',
                            'image' => $image,
                            'command' => ['python3', '/runr/prepare.py'],
                            'env' => [['name' => 'GEOIP_MODE', 'value' => $geoipMode], ['name' => 'GEOIP_URL', 'value' => $geoipUrl]],
                            'volumeMounts' => $mounts,
                            'resources' => [
                                'requests' => ['cpu' => '50m', 'memory' => '64Mi'],
                                'limits' => ['memory' => '512Mi'],
                            ],
                        ]],
                        'containers' => [[
                            'name' => 'pdns',
                            'image' => $image,
                            'env' => [[
                                'name' => 'PDNS_AUTH_API_KEY',
                                'valueFrom' => ['secretKeyRef' => ['name' => self::API_SECRET, 'key' => 'api-key']],
                            ]],
                            'ports' => [
                                ['name' => 'dns-udp', 'containerPort' => self::DNS_PORT, 'protocol' => 'UDP'],
                                ['name' => 'dns-tcp', 'containerPort' => self::DNS_PORT, 'protocol' => 'TCP'],
                                ['name' => 'api', 'containerPort' => self::API_PORT, 'protocol' => 'TCP'],
                            ],
                            'readinessProbe' => ['tcpSocket' => ['port' => self::DNS_PORT], 'periodSeconds' => 5],
                            'livenessProbe' => ['tcpSocket' => ['port' => self::DNS_PORT], 'initialDelaySeconds' => 15, 'periodSeconds' => 20],
                            'volumeMounts' => $mounts,
                            'resources' => [
                                'requests' => ['cpu' => '50m', 'memory' => '96Mi'],
                                'limits' => ['memory' => '384Mi'],
                            ],
                        ]],
                        'volumes' => [
                            ['name' => 'data', 'persistentVolumeClaim' => ['claimName' => self::VOLUME]],
                            ['name' => 'conf', 'emptyDir' => new \stdClass],
                            ['name' => 'runr', 'configMap' => ['name' => self::CONFIG_MAP]],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, string>  $annotations
     */
    public static function dnsService(string $namespace, array $annotations = []): array
    {
        $metadata = ['name' => self::DNS_SERVICE, 'namespace' => $namespace, 'labels' => self::labels()];
        if ($annotations !== []) {
            $metadata['annotations'] = $annotations;
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => $metadata,
            'spec' => [
                'type' => 'LoadBalancer',
                // the server sees the resolver's address (proximity answers by it)
                'externalTrafficPolicy' => 'Local',
                'selector' => ['app.kubernetes.io/name' => 'runr-pdns'],
                'ports' => [
                    ['name' => 'dns-udp', 'port' => 53, 'targetPort' => self::DNS_PORT, 'protocol' => 'UDP'],
                    ['name' => 'dns-tcp', 'port' => 53, 'targetPort' => self::DNS_PORT, 'protocol' => 'TCP'],
                ],
            ],
        ];
    }

    public static function apiService(string $namespace): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => ['name' => self::API_SERVICE, 'namespace' => $namespace, 'labels' => self::labels()],
            'spec' => [
                'type' => 'ClusterIP',
                'selector' => ['app.kubernetes.io/name' => 'runr-pdns'],
                'ports' => [['name' => 'api', 'port' => self::API_PORT, 'targetPort' => self::API_PORT, 'protocol' => 'TCP']],
            ],
        ];
    }

    /**
     * The address of a LoadBalancer Service (IP, else host name), or null while it has none.
     */
    public static function loadBalancerAddress(?array $service): ?string
    {
        foreach ($service['status']['loadBalancer']['ingress'] ?? [] as $ingress) {
            $address = (string) ($ingress['ip'] ?? $ingress['hostname'] ?? '');
            if ($address !== '') {
                return $address;
            }
        }

        return null;
    }

    /**
     * The last GEOIP_STATUS line of the init container's log (prepareScript()): status loaded |
     * missing | off with mode, source, bytes, updated, error, refresh_error. Null without one.
     *
     * @return array<string, mixed>|null
     */
    public static function geoipState(string $log): ?array
    {
        $state = null;
        foreach (preg_split('/\r?\n/', $log) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'GEOIP_STATUS ')) {
                $decoded = json_decode(substr($line, strlen('GEOIP_STATUS ')), true);
                if (is_array($decoded) && in_array($decoded['status'] ?? null, ['loaded', 'missing', 'off'], true)) {
                    $state = $decoded;
                }
            }
        }

        return $state;
    }

    /** Hash of what the pod reads at start (its config), so a change rolls it. */
    public static function configHash(array $options): string
    {
        return substr(sha1(json_encode([
            self::serverConfig($options['ttl'], $options['health_interval']),
            self::updatePolicy($options['tsig_key_name']),
            self::prepareScript(),
            $options['geoip_url'],
            $options['geoip_mode'] ?? 'url',
        ])), 0, 16);
    }
}
