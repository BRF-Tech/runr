<?php

/*
 * The values RunR sets that a chart ignores: each one is changed alone (a boolean flipped, a
 * number raised, a string extended, a list given one more element) and the chart rendered
 * again; a value whose change leaves the render as it was does nothing in that chart. A key the
 * chart renamed or never read (Traefik's service.type, which the chart takes as service.spec.type)
 * shows here, also where the chart has no schema to refuse it. A change the chart refuses (schema
 * or template error) counts as read.
 *
 *   php ci/deps/dead-values.php CHART_DIR VALUES.yaml RELEASE NAMESPACE KUBE [SET_JSON_FILE]
 *
 * Prints JSON {"values": n, "dead": [path, …], "refused": [path, …]}: n the values tried, a path its
 * keys joined by dots; refused: the changes the chart refused (read, but worth a look when many are).
 * Needs helm and the API's dependencies (symfony/yaml, symfony/process; composer install in api/).
 */

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__, 2).'/api/vendor/autoload.php';

[$chart, $valuesFile, $release, $namespace, $kube] = array_slice($argv, 1, 5) + array_fill(0, 5, '');
$setJsonFile = $argv[6] ?? '';
if ($chart === '' || $kube === '') {
    fwrite(STDERR, "usage: dead-values.php CHART_DIR VALUES.yaml RELEASE NAMESPACE KUBE [SET_JSON_FILE]\n");
    exit(1);
}
$values = Yaml::parse((string) file_get_contents($valuesFile)) ?? [];
$setJson = [];
if ($setJsonFile !== '' && is_file($setJsonFile)) {
    foreach (array_filter(explode("\n", (string) file_get_contents($setJsonFile))) as $line) {
        $setJson[] = '--set-json';
        $setJson[] = $line;
    }
}

/** the chart rendered with $values: its output, or null when helm refuses them */
function render(array $values, string $chart, string $release, string $namespace, string $kube, array $setJson): ?string
{
    $file = tempnam(sys_get_temp_dir(), 'values');
    // as HelmService::valuesYaml() writes the install's values file: an empty array is a map
    file_put_contents($file, Yaml::dump($values, 20, 2));
    $process = new Process(['helm', 'template', $release, $chart, '-n', $namespace, '--kube-version', $kube, '-f', $file, ...$setJson]);
    $process->setTimeout(120);
    $process->run();
    unlink($file);

    return $process->isSuccessful() ? $process->getOutput() : null;
}

/** @return list<list<string|int>> the paths of the leaves of $values (a list counts as a leaf) */
function leaves(array $values, array $prefix = []): array
{
    $paths = [];
    foreach ($values as $key => $value) {
        $path = [...$prefix, $key];
        if (is_array($value) && $value !== [] && ! array_is_list($value)) {
            array_push($paths, ...leaves($value, $path));
        } else {
            $paths[] = $path;
        }
    }

    return $paths;
}

function changed(mixed $value): mixed
{
    return match (true) {
        is_bool($value) => ! $value,
        is_int($value) => $value + 7,
        is_float($value) => $value + 7.5,
        is_string($value) => $value.'x',
        is_array($value) && $value === [] => ['runr-dead-values'],
        // a list one element longer
        is_array($value) => [...$value, is_array($value[0] ?? null) ? $value[0] : 'runr-dead-values'],
        default => 'runr-dead-values',
    };
}

$baseline = render($values, $chart, $release, $namespace, $kube, $setJson);
if ($baseline === null) {
    fwrite(STDERR, "the chart does not render with these values\n");
    exit(1);
}
$dead = [];
$refused = [];
$paths = leaves($values);
foreach ($paths as $path) {
    $mutated = $values;
    $ref = &$mutated;
    foreach ($path as $key) {
        $ref = &$ref[$key];
    }
    if ($ref === null) {
        unset($ref);

        continue;
    }
    $ref = changed($ref);
    unset($ref);
    $out = render($mutated, $chart, $release, $namespace, $kube, $setJson);
    if ($out === null) {
        $refused[] = implode('.', $path);
    } elseif ($out === $baseline) {
        $dead[] = implode('.', $path);
    }
}
echo json_encode(['values' => count($paths), 'dead' => $dead, 'refused' => $refused], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
