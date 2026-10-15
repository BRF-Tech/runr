<?php

namespace App\Services\Manifests;

use App\Services\Helpers\ContainerRuntimeHelper;

class ZotManifests
{
    /**
     * PVC-backed Zot (StatefulSet). Values follow the pinned chart
     * (ZotRegistryService::ZOT_CHART_VERSION): `persistence` is a boolean and the volume
     * is described under `pvc` — a map under `persistence` is only "truthy" and its
     * size / storageClass were silently ignored (8Gi on the default class).
     */
    public static function pvcValues(string $storageClass, string $size, bool $workersOnly = false): array
    {
        // Note: securityContext/initContainers are NOT supported by Zot Helm chart values.
        // They are applied via StatefulSet patch in ZotRegistryService::patchZotPermissions().
        $pvc = [
            'create' => true,
            'accessModes' => ['ReadWriteOnce'],
            'storage' => $size,
        ];
        if ($storageClass !== '') {
            $pvc['storageClassName'] = $storageClass;
        }

        return array_merge([
            'mountConfig' => true,
            'persistence' => true,
            'pvc' => $pvc,
            'service' => ['type' => 'ClusterIP', 'port' => 5000],
            'configFiles' => [
                'config.json' => json_encode(self::mirrorConfig()),
            ],
        ], self::workerAffinityValues($workersOnly));
    }

    /**
     * volumeClaimTemplates of a StatefulSet are immutable: re-installing over an existing
     * Zot must keep the size / class / access modes it was created with, otherwise
     * `helm upgrade` fails with "updates to statefulset spec ... are forbidden".
     */
    public static function preserveExistingPvc(array $values, ?array $statefulSet): array
    {
        $template = collect($statefulSet['spec']['volumeClaimTemplates'] ?? [])->first();
        if (! $template || empty($values['persistence'])) {
            return $values;
        }

        $spec = $template['spec'] ?? [];
        if (! empty($template['metadata']['name'])) {
            $values['pvc']['name'] = $template['metadata']['name'];
        }
        if (! empty($spec['resources']['requests']['storage'])) {
            $values['pvc']['storage'] = $spec['resources']['requests']['storage'];
        }
        if (! empty($spec['accessModes'])) {
            $values['pvc']['accessModes'] = $spec['accessModes'];
        }
        if (! empty($spec['storageClassName'])) {
            $values['pvc']['storageClassName'] = $spec['storageClassName'];
        } else {
            unset($values['pvc']['storageClassName']);
        }

        return $values;
    }

    /**
     * S3-backed Zot: no volume → `persistence: false`, the chart renders a Deployment.
     */
    public static function s3Values(array $s3, bool $workersOnly = false): array
    {
        $config = self::mirrorConfig();
        $config['storage']['storageDriver'] = [
            'name' => 's3',
            'endpoint' => $s3['endpoint'],
            'region' => $s3['region'],
            'bucket' => $s3['bucket'],
            'accesskey' => $s3['access_key'],
            'secretkey' => $s3['secret_key'],
        ];

        return array_merge([
            'mountConfig' => true,
            'persistence' => false,
            'service' => ['type' => 'ClusterIP', 'port' => 5000],
            'configFiles' => [
                'config.json' => json_encode($config),
            ],
        ], self::workerAffinityValues($workersOnly));
    }

    public const DOCKER_HUB_UPSTREAM = 'https://registry-1.docker.io';

    public const GHCR_UPSTREAM = 'https://ghcr.io';

    /**
     * Local namespace of the ghcr.io pull-through cache: {zot}/ghcr.io/brf-tech/exec-python:3.14 is
     * synced on demand from ghcr.io/brf-tech/exec-python:3.14 (KnativeRegion::buildBaseImageRef()).
     */
    public const GHCR_NAMESPACE = 'ghcr.io';

    /**
     * How long Zot serves a synced ghcr.io tag without asking ghcr.io again. Without it every
     * manifest request by tag is re-validated upstream (Kaniko asks ~6 times per build).
     */
    public const GHCR_MANIFEST_CHECK_INTERVAL = '1h';

    /**
     * First Zot release that knows manifestCheckInterval. Older ones refuse to start on the
     * unknown key ("has invalid keys: manifestcheckinterval"), so it is only written for them.
     */
    public const MANIFEST_CHECK_INTERVAL_SINCE = '2.1.21';

    /** Keys RunR owns in its sync registry entries; everything else in an entry is left alone. */
    private const OWNED_REGISTRY_KEYS = ['urls', 'onDemand', 'tlsVerify', 'content', 'manifestCheckInterval'];

    public static function mirrorConfig(): array
    {
        return [
            'storage' => ['rootDirectory' => '/var/lib/registry'],
            'http' => ['address' => '0.0.0.0', 'port' => '5000'],
            'log' => ['level' => 'info'],
            'extensions' => [
                'sync' => [
                    // Pinned chart (ZotRegistryService::ZOT_CHART_VERSION) = zot v2.1.21
                    'registries' => self::syncRegistries(true),
                ],
            ],
        ];
    }

    /**
     * On-demand upstreams of the pull-through cache, in lookup order:
     *  - ghcr.io under {zot}/ghcr.io/… — RunR's runtime and build base images (Kaniko FROM lines);
     *  - Docker Hub for everything else ({zot}/library/…, KnativeRegion::registryImageRef()).
     * ghcr.io comes first: its content only matches ghcr.io/** so other repositories fall through
     * to Docker Hub, while Docker Hub's "**" would also be asked for ghcr.io/… paths.
     */
    public static function syncRegistries(bool $manifestCheckInterval): array
    {
        $ghcr = [
            'urls' => [self::GHCR_UPSTREAM],
            'onDemand' => true,
            'tlsVerify' => true,
            'content' => [['prefix' => '**', 'destination' => '/'.self::GHCR_NAMESPACE]],
        ];
        if ($manifestCheckInterval) {
            $ghcr['manifestCheckInterval'] = self::GHCR_MANIFEST_CHECK_INTERVAL;
        }

        return [
            $ghcr,
            [
                'urls' => [self::DOCKER_HUB_UPSTREAM],
                'onDemand' => true,
                'tlsVerify' => true,
                'content' => [['prefix' => '**']],
            ],
        ];
    }

    /**
     * Whether the Zot image (e.g. ghcr.io/project-zot/zot:v2.1.21) accepts manifestCheckInterval.
     * Unknown (digest-only or unparsable reference) → false, the key would stop an older Zot.
     */
    public static function supportsManifestCheckInterval(?string $zotImage): bool
    {
        if (! $zotImage || preg_match('~:v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)(?:@sha256:[0-9a-f]+)?$~', $zotImage, $m) !== 1) {
            return false;
        }

        return version_compare($m[1], self::MANIFEST_CHECK_INTERVAL_SINCE, '>=');
    }

    /**
     * Zot config.json (the chart's runr-zot-config ConfigMap) brought to RunR's sync upstreams.
     * RunR's entries — Docker Hub (by URL) and the ghcr.io entry syncing into {zot}/ghcr.io/ —
     * get RunR's keys (OWNED_REGISTRY_KEYS); the ghcr.io entry sits right before the Docker Hub
     * one (inserted when missing), a missing Docker Hub entry is appended. Everything else —
     * storage (S3 credentials), http, log, other extensions, operator-added upstreams (another
     * ghcr.io entry included) and extra keys inside RunR's entries — is kept as it is.
     *
     * @return string|null the new config.json, null when it is already up to date
     *
     * @throws \JsonException on an unparsable config.json
     */
    public static function reconcileSyncConfig(string $json, bool $manifestCheckInterval): ?string
    {
        // Objects stay objects: an operator's empty {} must not come back as []
        $config = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (! $config instanceof \stdClass) {
            throw new \JsonException('config.json is not a JSON object');
        }

        $config->extensions ??= new \stdClass;
        $config->extensions->sync ??= new \stdClass;
        $existing = array_values(is_array($config->extensions->sync->registries ?? null) ? $config->extensions->sync->registries : []);

        [$wantGhcr, $wantHub] = self::syncRegistries($manifestCheckInterval);

        $ghcrAt = self::firstIndex($existing, fn (\stdClass $e) => self::isRunrGhcrEntry(self::toArray($e)));
        $hubAt = self::firstIndex($existing, fn (\stdClass $e) => in_array(self::DOCKER_HUB_UPSTREAM, self::normalizedUrls((array) ($e->urls ?? [])), true));

        $ghcr = $ghcrAt !== null ? self::withOwnedKeys($existing[$ghcrAt], $wantGhcr) : (object) $wantGhcr;
        $result = [];

        foreach ($existing as $i => $entry) {
            if ($i === $ghcrAt && $hubAt !== null) {
                continue; // moved in front of Docker Hub
            }
            if ($i === $hubAt) {
                $result[] = $ghcr;
                $result[] = self::withOwnedKeys($entry, $wantHub);

                continue;
            }
            $result[] = $i === $ghcrAt ? $ghcr : $entry;
        }

        if ($hubAt === null) {
            if ($ghcrAt === null) {
                $result[] = $ghcr;
            }
            $result[] = (object) $wantHub;
        }

        $config->extensions->sync->registries = $result;

        $after = json_decode(json_encode($config), true);

        return self::canonical(json_decode($json, true)) === self::canonical($after) ? null : json_encode($config);
    }

    /**
     * Whether config.json syncs ghcr.io on demand into the {zot}/ghcr.io/ namespace — the
     * precondition for build FROM lines through the registry (KnativeRegion::buildBaseImageRef()).
     */
    public static function hasGhcrUpstream(?string $json): bool
    {
        $config = $json ? json_decode($json, true) : null;

        foreach ($config['extensions']['sync']['registries'] ?? [] as $entry) {
            if (is_array($entry) && ! empty($entry['onDemand']) && self::isRunrGhcrEntry($entry)) {
                return true;
            }
        }

        return false;
    }

    /** A ghcr.io upstream whose content syncs every repository into {zot}/ghcr.io/. */
    private static function isRunrGhcrEntry(array $entry): bool
    {
        if (! in_array(self::GHCR_UPSTREAM, self::normalizedUrls((array) ($entry['urls'] ?? [])), true)) {
            return false;
        }

        foreach ((array) ($entry['content'] ?? []) as $content) {
            if (is_array($content)
                && trim((string) ($content['destination'] ?? ''), '/') === self::GHCR_NAMESPACE
                && trim((string) ($content['prefix'] ?? ''), '/') === '**'
                && empty($content['stripPrefix'])) {
                return true;
            }
        }

        return false;
    }

    /** @param list<mixed> $entries */
    private static function firstIndex(array $entries, \Closure $matches): ?int
    {
        foreach ($entries as $i => $entry) {
            if ($entry instanceof \stdClass && $matches($entry)) {
                return $i;
            }
        }

        return null;
    }

    private static function toArray(\stdClass $entry): array
    {
        return json_decode(json_encode($entry), true);
    }

    /** @return list<string> */
    private static function normalizedUrls(array $urls): array
    {
        return array_map(fn ($u) => rtrim(strtolower((string) $u), '/'), $urls);
    }

    private static function withOwnedKeys(\stdClass $entry, array $desired): \stdClass
    {
        $entry = clone $entry;
        foreach (self::OWNED_REGISTRY_KEYS as $key) {
            if (array_key_exists($key, $desired)) {
                $entry->{$key} = $desired[$key];
            } elseif ($key === 'manifestCheckInterval' && $desired['urls'][0] === self::GHCR_UPSTREAM) {
                // RunR's own ghcr.io key, dropped for a Zot that does not know it
                unset($entry->{$key});
            }
        }

        return $entry;
    }

    /** Recursively key-sorted copy for an order-insensitive comparison of JSON objects. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn ($v) => self::canonical($v), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    public static function testPvc(string $storageClass, string $size, string $namespace): array
    {
        return ZotTestManifests::testPvc($storageClass, $size, $namespace);
    }

    public static function pvcConsumerPod(string $pvcName, string $namespace): array
    {
        return ZotTestManifests::pvcConsumerPod($pvcName, $namespace);
    }

    public static function s3TestPod(array $s3, string $namespace): array
    {
        return ZotTestManifests::s3TestPod($s3, $namespace);
    }

    /**
     * DaemonSet that points every node's containerd at the Zot mirror through hosts.toml
     * (read on every pull, no restart) and keeps it current when the Zot ClusterIP changes.
     *
     * - containerd: hosts.toml under /etc/containerd/certs.d; config_path is set INSIDE the
     *   CRI registry section of /etc/containerd/config.toml (replaced, never appended twice).
     *   A changed config.toml needs `php artisan registry:rolling-restart <region-id>` (the log
     *   adds --in-place when the nodes cannot be drained, see restartCommandHint()).
     * - k3s / rke2: config.toml is generated by k3s/rke2 and already points config_path at
     *   <agent>/etc/containerd/certs.d → only hosts.toml is written there. Hosts generated by
     *   k3s/rke2 from /etc/rancher/<flavor>/registries.yaml are left untouched.
     *
     * @param  string  $flavor  ContainerRuntimeHelper::FLAVOR_* of the target nodes
     */
    public static function mirrorDaemonSet(
        string $registry,
        string $svcName,
        string $svcNs,
        bool $workersOnly = false,
        string $flavor = ContainerRuntimeHelper::FLAVOR_CONTAINERD,
        bool $restartInPlace = false,
        ?string $regionId = null,
    ): array {
        $k3sFamily = ContainerRuntimeHelper::isK3sFamily($flavor);
        $hostDir = ContainerRuntimeHelper::configDir($flavor);
        $mountPath = $k3sFamily ? '/host/containerd' : '/host/etc/containerd';

        $script = strtr(self::MIRROR_SCRIPT, [
            '__PORT__' => '5000',
            '__SVC_FQDN__' => "{$svcName}.{$svcNs}.svc.cluster.local",
            '__CURRENT_IP__' => explode(':', $registry)[0],
            '__FLAVOR__' => $k3sFamily ? $flavor : ContainerRuntimeHelper::FLAVOR_CONTAINERD,
            '__CERTS_ROOT__' => "{$mountPath}/certs.d",
            '__CONFIG__' => "{$mountPath}/config.toml",
            '__CERTS_PATH__' => "{$hostDir}/certs.d",
            '__HOSTS_FN__' => self::hostsTomlScript(),
            '__CONFIG_PATH_FN__' => self::containerdConfigPathScript(),
            '__RESTART_CMD__' => self::restartCommandHint($restartInPlace, $regionId),
        ]);

        $podSpec = [
            'hostPID' => true,
            'containers' => [[
                'name' => 'mirror-config',
                'image' => 'ghcr.io/brf-tech/reqs/busybox:latest',
                'command' => ['sh', '-c', $script],
                'securityContext' => ['privileged' => true],
                'resources' => [
                    'requests' => ['cpu' => '10m', 'memory' => '16Mi'],
                    'limits' => ['cpu' => '10m', 'memory' => '32Mi'],
                ],
                'volumeMounts' => [[
                    'name' => 'containerd-conf',
                    'mountPath' => $mountPath,
                ]],
            ]],
            'volumes' => [[
                'name' => 'containerd-conf',
                // k3s/rke2: the agent dir exists on every node → Directory (no stray dirs on other hosts)
                'hostPath' => ['path' => $hostDir, 'type' => $k3sFamily ? 'Directory' : 'DirectoryOrCreate'],
            ]],
        ];

        self::applyWorkerAffinity($podSpec, $workersOnly);

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'DaemonSet',
            'metadata' => [
                'name' => 'runr-registry-mirror',
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/runtime-flavor' => $flavor],
            ],
            'spec' => [
                'selector' => ['matchLabels' => ['app' => 'runr-registry-mirror']],
                'template' => [
                    'metadata' => ['labels' => ['app' => 'runr-registry-mirror']],
                    'spec' => $podSpec,
                ],
            ],
        ];
    }

    /**
     * Command the mirror log suggests after it changed config.toml. On the first install the
     * region does not exist yet → <region-id> placeholder. --in-place when the nodes cannot be
     * drained: RunR runs in this cluster (draining evicts RunR itself) or it has a single
     * schedulable node (evicted pods have nowhere to go).
     */
    public static function restartCommandHint(bool $inPlace, ?string $regionId = null): string
    {
        return 'php artisan registry:rolling-restart '.($regionId ?: '<region-id>').($inPlace ? ' --in-place' : '');
    }

    /**
     * Runtime flavor of the nodes the mirror DaemonSet targets (all nodes, or only the
     * runr.dev/dedicated=projects workers when $workersOnly).
     */
    public static function mirrorFlavorForNodes(array $nodes, bool $workersOnly = false): string
    {
        if ($workersOnly) {
            $dedicated = array_filter($nodes, fn ($n) => ($n['metadata']['labels']['runr.dev/dedicated'] ?? '') === 'projects');
            $nodes = $dedicated ?: $nodes;
        }

        $flavor = ContainerRuntimeHelper::resolveCluster($nodes)['flavor'];

        return ContainerRuntimeHelper::isK3sFamily($flavor) ? $flavor : ContainerRuntimeHelper::FLAVOR_CONTAINERD;
    }

    private const MIRROR_SCRIPT = <<<'SH'
#!/bin/sh
PORT=__PORT__
SVC_FQDN=__SVC_FQDN__
CURRENT_IP="__CURRENT_IP__"
FLAVOR=__FLAVOR__
CERTS_ROOT="__CERTS_ROOT__"
CFG="__CONFIG__"
CERTS_PATH="__CERTS_PATH__"
RESTART_CMD="__RESTART_CMD__"

__HOSTS_FN__

__CONFIG_PATH_FN__

# Step 1: Write hosts.toml (hot-reloaded, zero downtime)
write_configs "$CURRENT_IP"

# Step 2: Make containerd read certs.d.
# NEVER restart containerd / k3s from this DaemonSet — it crashes the cluster.
if [ "$FLAVOR" = "containerd" ]; then
  ensure_config_path
elif [ ! -f "$CFG" ]; then
  echo "runr: WARNING: $FLAVOR containerd config ($CFG) not found on $(hostname) — hosts.toml written, check the $FLAVOR data dir"
elif grep -Eq '^[[:space:]]*config_path[[:space:]]*=.*certs\.d' "$CFG"; then
  echo "runr: $FLAVOR containerd reads $CERTS_PATH — hosts.toml hot-reloaded, no restart needed"
else
  echo "runr: WARNING: $FLAVOR containerd config has no config_path (old $FLAVOR) — configure the mirror in /etc/rancher/$FLAVOR/registries.yaml and restart $FLAVOR"
fi

# Step 3: Monitor Zot ClusterIP changes — only writes hosts.toml (no restart)
while true; do
  sleep 60
  NEW_IP=$(nslookup "$SVC_FQDN" 2>/dev/null | grep -A1 "Name:" | grep "Address" | awk '{print $2}' | head -1)
  [ -z "$NEW_IP" ] && continue
  [ "$NEW_IP" = "$CURRENT_IP" ] && continue
  echo "runr: Zot IP changed $CURRENT_IP -> $NEW_IP, updating configs"
  write_configs "$NEW_IP"
done
SH;

    /**
     * Shell functions writing hosts.toml under $CERTS_ROOT for the Zot address and docker.io.
     * Expects: PORT, CURRENT_IP, FLAVOR, CERTS_ROOT. Hosts whose hosts.toml was generated by
     * k3s/rke2 (from registries.yaml) are never overwritten or removed.
     */
    public static function hostsTomlScript(): string
    {
        return <<<'SH'
distro_managed() {
  [ -f "$1/hosts.toml" ] && head -n 1 "$1/hosts.toml" | grep -Eq '^# File generated by (k3s|rke2)'
}

write_host() {
  if distro_managed "$1"; then
    echo "runr: $1/hosts.toml is generated by $FLAVOR (registries.yaml) — left untouched"
    return 0
  fi
  mkdir -p "$1"
  cat > "$1/hosts.toml" <<EOF
server = "$2"
[host."http://$3"]
  capabilities = ["pull", "resolve"]
  skip_verify = true
EOF
}

write_configs() {
  ADDR="$1:$PORT"
  OLD_DIR="$CERTS_ROOT/$CURRENT_IP:$PORT"
  if [ "$1" != "$CURRENT_IP" ] && [ -d "$OLD_DIR" ] && ! distro_managed "$OLD_DIR"; then
    rm -f "$OLD_DIR/hosts.toml" && rmdir "$OLD_DIR" 2>/dev/null
  fi
  CURRENT_IP="$1"
  write_host "$CERTS_ROOT/$ADDR" "http://$ADDR" "$ADDR"
  write_host "$CERTS_ROOT/docker.io" "https://registry-1.docker.io" "$ADDR"
  echo "runr: mirror configured for $ADDR + docker.io in $CERTS_ROOT"
}
SH;
    }

    /**
     * Shell function `ensure_config_path` for plain containerd. Expects: CFG (config.toml
     * as seen in the pod), CERTS_PATH (certs.d path as seen by containerd on the host).
     *
     * Sets config_path inside the CRI registry section — v2: [plugins."io.containerd.grpc.v1.cri".registry],
     * v3 (containerd 2.x): [plugins.'io.containerd.cri.v1.images'.registry]. The section's existing
     * config_path lines (also duplicates left by RunR ≤ 0.3.2) are replaced by one; a non-empty
     * value is kept behind ours (colon-separated list). Refuses when registry.mirrors /
     * registry.configs.*.tls are set (containerd rejects them together with config_path) or the
     * config version is unknown. Prints "containerd restart required" only when it changed the file.
     */
    public static function containerdConfigPathScript(): string
    {
        return <<<'SH'
ensure_config_path() {
  NODE=$(hostname)
  if [ ! -f "$CFG" ]; then
    echo "runr: WARNING: containerd config.toml not found on $NODE — containerd uses built-in defaults and ignores certs.d; add config_path = \"$CERTS_PATH\" to its CRI registry section to activate the mirror"
    return 0
  fi
  VER=$(awk '/^[[:space:]]*\[/ { exit } /^[[:space:]]*version[[:space:]]*=/ { v = $0; sub(/^[^=]*=/, "", v); gsub(/[^0-9]/, "", v); print v; exit }' "$CFG")
  case "$VER" in
    3) SECTION="plugins.io.containerd.cri.v1.images.registry"; HEADER="[plugins.'io.containerd.cri.v1.images'.registry]" ;;
    2) SECTION="plugins.io.containerd.grpc.v1.cri.registry"; HEADER='[plugins."io.containerd.grpc.v1.cri".registry]' ;;
    *)
      echo "runr: ERROR: unsupported containerd config version '${VER:-1}' on $NODE — config_path not set (convert it with: containerd config migrate)"
      return 0 ;;
  esac
  if grep -Eq '^[[:space:]]*\[[^]]*registry\.mirrors\.' "$CFG" || grep -Eq '^[[:space:]]*\[[^]]*registry\.configs\..*\.tls\]' "$CFG"; then
    echo "runr: ERROR: config.toml on $NODE sets registry.mirrors / registry.configs.*.tls — containerd rejects config_path together with them; move them to hosts.toml under $CERTS_PATH, then run: php artisan registry:redeploy-mirror"
    return 0
  fi
  AWK_NORM='function norm(s) { sub(/#.*/, "", s); gsub("[[:space:]\"" q "]", "", s); return s }'
  CURRENT=$(awk -v want="$SECTION" -v q="'" "$AWK_NORM"'
    /^[[:space:]]*\[/ { insec = (norm($0) == "[" want "]"); next }
    insec && /^[[:space:]]*config_path[[:space:]]*=/ { v = $0; sub(/^[^=]*=/, "", v); sub(/#.*/, "", v); gsub("[[:space:]\"" q "]", "", v); print v; exit }
  ' "$CFG")
  COUNT=$(awk -v want="$SECTION" -v q="'" "$AWK_NORM"'
    /^[[:space:]]*\[/ { insec = (norm($0) == "[" want "]"); next }
    insec && /^[[:space:]]*config_path[[:space:]]*=/ { n++ }
    END { print n + 0 }
  ' "$CFG")
  case ":$CURRENT:" in
    *":$CERTS_PATH:"*) HAS=1 ;;
    *) HAS=0 ;;
  esac
  if [ "$HAS" = 1 ] && [ "$COUNT" = 1 ]; then
    echo "runr: config_path already points to $CERTS_PATH on $NODE — hosts.toml hot-reloaded, no restart needed"
    return 0
  fi
  if [ "$HAS" = 1 ]; then NEW="$CURRENT"; elif [ -n "$CURRENT" ]; then NEW="$CERTS_PATH:$CURRENT"; else NEW="$CERTS_PATH"; fi
  TMP="$CFG.runr.tmp"
  awk -v want="$SECTION" -v q="'" -v header="$HEADER" -v val="$NEW" "$AWK_NORM"'
    /^[[:space:]]*\[/ {
      insec = (norm($0) == "[" want "]")
      print
      if (insec && !done) { print "  config_path = \"" val "\""; done = 1 }
      next
    }
    insec && /^[[:space:]]*config_path[[:space:]]*=/ { next }
    { print }
    END { if (!done) { print ""; print header; print "  config_path = \"" val "\"" } }
  ' "$CFG" > "$TMP" || { rm -f "$TMP"; echo "runr: ERROR: could not rewrite config.toml on $NODE"; return 0; }
  CHECK=$(awk -v want="$SECTION" -v q="'" "$AWK_NORM"'
    /^[[:space:]]*\[/ { insec = (norm($0) == "[" want "]"); next }
    insec && /^[[:space:]]*config_path[[:space:]]*=/ { n++ }
    END { print n + 0 }
  ' "$TMP")
  if [ "$CHECK" != 1 ]; then
    rm -f "$TMP"
    echo "runr: ERROR: config_path rewrite check failed on $NODE ($CHECK entries) — config.toml left unchanged"
    return 0
  fi
  cp "$CFG" "$CFG.runr.bak" && cat "$TMP" > "$CFG" && rm -f "$TMP"
  echo "runr: config_path = \"$NEW\" set in $HEADER on $NODE (backup: config.toml.runr.bak) — containerd restart required: ${RESTART_CMD:-php artisan registry:rolling-restart <region-id>}"
}
SH;
    }

    /**
     * Common worker-only affinity: nodeSelector + toleration for runr.dev/dedicated nodes.
     * When $workersOnly is false, uses broad tolerations (all nodes).
     */
    public static function applyWorkerAffinity(array &$podSpec, bool $workersOnly): void
    {
        if ($workersOnly) {
            $podSpec['nodeSelector'] = ['runr.dev/dedicated' => 'projects'];
            $podSpec['tolerations'] = [[
                'key' => 'runr.dev/dedicated',
                'operator' => 'Equal',
                'value' => 'projects',
                'effect' => 'NoSchedule',
            ]];
        } else {
            $podSpec['tolerations'] = [['operator' => 'Exists']];
        }
    }

    /**
     * Zot Helm nodeSelector + tolerations values for worker-only scheduling.
     */
    public static function workerAffinityValues(bool $workersOnly): array
    {
        if (! $workersOnly) {
            return [];
        }

        return [
            'nodeSelector' => ['runr.dev/dedicated' => 'projects'],
            'tolerations' => [[
                'key' => 'runr.dev/dedicated',
                'operator' => 'Equal',
                'value' => 'projects',
                'effect' => 'NoSchedule',
            ]],
        ];
    }

    public static function registryTestPod(string $url, ?string $user, ?string $pass, string $namespace): array
    {
        return ZotTestManifests::registryTestPod($url, $user, $pass, $namespace);
    }
}
