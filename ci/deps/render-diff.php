<?php

/*
 * Compares what two versions of a chart render, for ci/deps-bump.sh. Prints JSON.
 *
 *   php ci/deps/render-diff.php objects OLD.yaml NEW.yaml OLD_CHART NEW_CHART [OLD_APP NEW_APP]
 *       the objects of two `helm template` outputs: {"old": n, "new": n, "added": [...],
 *       "removed": [...], "changed": [{"id": ..., "paths": [...]}], "crds": [...]}. An object is
 *       "Kind namespace/name". Before they are compared, the chart's version labels
 *       (helm.sh/chart, chart, app.kubernetes.io/version) and checksum/ annotations are dropped
 *       and the chart and app versions read as placeholders, so that a new image tag or version
 *       label alone is no change. "crds": CustomResourceDefinitions whose content (without labels
 *       and annotations) changed, was added or removed.
 *   php ci/deps/render-diff.php crds OLD_DIR NEW_DIR
 *       the CRDs a chart ships in crds/ directories (Helm installs them once and never upgrades
 *       them): {"added": [...], "removed": [...], "changed": [...]}, by CRD name, without labels
 *       and annotations (a version annotation alone is no change)
 *   php ci/deps/render-diff.php keys VALUES.yaml OLD_VALUES.yaml NEW_VALUES.yaml
 *       the keys RunR sets (VALUES.yaml, ci/deps/helm-values.php) that the old chart's default
 *       values have and the new chart's do not: {"dropped": [...]} — a renamed or removed value
 *
 * Needs the API's dependencies (symfony/yaml, composer install in api/).
 */

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__, 2).'/api/vendor/autoload.php';

/** @return list<array<mixed>> the documents of a multi-document YAML file */
function docs_of(string $file): array
{
    $text = (string) file_get_contents($file);
    $docs = [];
    foreach (preg_split('/^---[^\n]*$/m', $text) as $part) {
        if (trim(preg_replace('/^#.*$/m', '', $part)) === '') {
            continue;
        }
        $doc = Yaml::parse($part);
        if (is_array($doc) && isset($doc['kind'])) {
            $docs[] = $doc;
        }
    }

    return $docs;
}

function id_of(array $doc): string
{
    $ns = $doc['metadata']['namespace'] ?? '';

    return $doc['kind'].' '.($ns !== '' ? "{$ns}/" : '').($doc['metadata']['name'] ?? '?');
}

/** $doc without the chart's version labels and checksum annotations, at every level */
function normalized(mixed $node): mixed
{
    if (! is_array($node)) {
        return $node;
    }
    foreach (['labels', 'matchLabels'] as $key) {
        if (isset($node[$key]) && is_array($node[$key])) {
            unset($node[$key]['helm.sh/chart'], $node[$key]['chart'], $node[$key]['app.kubernetes.io/version']);
        }
    }
    if (isset($node['annotations']) && is_array($node['annotations'])) {
        foreach (array_keys($node['annotations']) as $key) {
            if (str_starts_with((string) $key, 'checksum/')) {
                unset($node['annotations'][$key]);
            }
        }
    }
    foreach ($node as $key => $value) {
        $node[$key] = normalized($value);
    }

    return $node;
}

/** @return list<string> the leaf paths where $a and $b differ (at most $max) */
function diff_paths(mixed $a, mixed $b, string $path = '', int $max = 8): array
{
    if (! is_array($a) || ! is_array($b)) {
        return $a === $b ? [] : [$path === '' ? '.' : $path];
    }
    $paths = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
        $sub = $path === '' ? (string) $key : "{$path}.{$key}";
        if (! array_key_exists($key, $a) || ! array_key_exists($key, $b)) {
            $paths[] = $sub;
        } else {
            array_push($paths, ...diff_paths($a[$key], $b[$key], $sub, $max));
        }
        if (count($paths) >= $max) {
            return array_slice($paths, 0, $max);
        }
    }

    return $paths;
}

function placeholders(string $text, array $from): string
{
    foreach ($from as $value => $placeholder) {
        if ($value !== '') {
            $text = str_replace($value, $placeholder, $text);
        }
    }

    return $text;
}

function crd_body(array $doc): array
{
    unset($doc['metadata']['labels'], $doc['metadata']['annotations']);

    return $doc;
}

function cmd_objects(string $oldFile, string $newFile, string $oldChart, string $newChart, string $oldApp, string $newApp): array
{
    $tmp = sys_get_temp_dir();
    $oldText = placeholders((string) file_get_contents($oldFile), [ltrim($oldApp, 'v') => '<app>', $oldChart => '<chart>']);
    $newText = placeholders((string) file_get_contents($newFile), [ltrim($newApp, 'v') => '<app>', $newChart => '<chart>']);
    file_put_contents($o = tempnam($tmp, 'old'), $oldText);
    file_put_contents($n = tempnam($tmp, 'new'), $newText);
    $old = $new = [];
    foreach (docs_of($o) as $doc) {
        $old[id_of($doc)] = $doc;
    }
    foreach (docs_of($n) as $doc) {
        $new[id_of($doc)] = $doc;
    }
    unlink($o);
    unlink($n);

    $result = ['old' => count($old), 'new' => count($new), 'added' => [], 'removed' => [], 'changed' => [], 'crds' => []];
    foreach ($new as $id => $doc) {
        if (! isset($old[$id])) {
            $result['added'][] = $id;
            if ($doc['kind'] === 'CustomResourceDefinition') {
                $result['crds'][] = $id;
            }
        }
    }
    foreach ($old as $id => $doc) {
        if (! isset($new[$id])) {
            $result['removed'][] = $id;
            if ($doc['kind'] === 'CustomResourceDefinition') {
                $result['crds'][] = $id;
            }

            continue;
        }
        if ($doc['kind'] === 'CustomResourceDefinition') {
            if (crd_body($doc) !== crd_body($new[$id])) {
                $result['crds'][] = $id;
            }

            continue;
        }
        $paths = diff_paths(normalized($doc), normalized($new[$id]));
        if ($paths !== []) {
            $result['changed'][] = ['id' => $id, 'paths' => $paths];
        }
    }

    return $result;
}

/**
 * Whether $path is in the crds/ directory of a chart (a directory with Chart.yaml): Helm installs
 * those files as they are. A subchart named crds (kube-prometheus-stack's charts/crds/) is a chart,
 * its templates/ are no CRD files.
 */
function in_crds_dir(string $path): bool
{
    $offset = 0;
    while (($at = strpos($path, '/crds/', $offset)) !== false) {
        if (is_file(substr($path, 0, $at).'/Chart.yaml')) {
            return true;
        }
        $offset = $at + 1;
    }

    return false;
}

/** @return array<string, array<mixed>> the CRDs of the crds/ directories under $dir, by name */
function crds_in(string $dir): array
{
    $crds = [];
    if (! is_dir($dir)) {
        return $crds;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (! preg_match('#\.ya?ml$#', $path) || ! in_crds_dir($path)) {
            continue;
        }
        foreach (docs_of($path) as $doc) {
            if (($doc['kind'] ?? '') === 'CustomResourceDefinition') {
                $crds[$doc['metadata']['name'] ?? $path] = crd_body($doc);
            }
        }
    }

    return $crds;
}

function cmd_crds(string $oldDir, string $newDir): array
{
    $old = crds_in($oldDir);
    $new = crds_in($newDir);
    $result = ['added' => array_values(array_diff(array_keys($new), array_keys($old))), 'removed' => array_values(array_diff(array_keys($old), array_keys($new))), 'changed' => []];
    foreach ($old as $name => $body) {
        if (isset($new[$name]) && $new[$name] !== $body) {
            $result['changed'][] = $name;
        }
    }

    return $result;
}

/** @return list<string> the leaf paths of $values (a list counts as a leaf) */
function leaf_paths(array $values, string $prefix = ''): array
{
    $paths = [];
    foreach ($values as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
        if (is_array($value) && $value !== [] && ! array_is_list($value)) {
            array_push($paths, ...leaf_paths($value, $path));
        } else {
            $paths[] = $path;
        }
    }

    return $paths;
}

function has_path(mixed $values, string $path): bool
{
    foreach (explode('.', $path) as $key) {
        if (! is_array($values) || ! array_key_exists($key, $values)) {
            return false;
        }
        $values = $values[$key];
    }

    return true;
}

function cmd_keys(string $valuesFile, string $oldDefaults, string $newDefaults): array
{
    $values = Yaml::parse((string) file_get_contents($valuesFile)) ?? [];
    $old = Yaml::parse((string) file_get_contents($oldDefaults)) ?? [];
    $new = Yaml::parse((string) file_get_contents($newDefaults)) ?? [];
    $dropped = [];
    foreach (leaf_paths($values) as $path) {
        // the longest prefix of the path the old defaults know: a key below it is RunR's own
        // (a map the chart leaves open), and only a known key can be dropped
        $parts = explode('.', $path);
        for ($n = count($parts); $n > 0; $n--) {
            $prefix = implode('.', array_slice($parts, 0, $n));
            if (has_path($old, $prefix)) {
                if (! has_path($new, $prefix)) {
                    $dropped[] = $prefix;
                }

                break;
            }
        }
    }

    return ['dropped' => array_values(array_unique($dropped))];
}

$mode = $argv[1] ?? '';
try {
    $result = match ($mode) {
        'objects' => cmd_objects($argv[2], $argv[3], $argv[4], $argv[5], $argv[6] ?? '', $argv[7] ?? ''),
        'crds' => cmd_crds($argv[2], $argv[3]),
        'keys' => cmd_keys($argv[2], $argv[3], $argv[4]),
        default => throw new InvalidArgumentException('usage: render-diff.php objects|crds|keys …'),
    };
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
