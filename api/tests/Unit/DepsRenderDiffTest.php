<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * ci/deps/render-diff.php tells the dependency test bot whether a new chart renders what the
 * pinned one renders with RunR's values (ci/deps-bump.sh): the chart's version labels, checksum
 * annotations and a new image tag are no change; another object, a changed CRD or a value RunR
 * sets that the new chart no longer has are.
 */
class DepsRenderDiffTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/deps-render-diff-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
        parent::tearDown();
    }

    private function diff(string ...$args): array
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/ci/deps/render-diff.php', ...$args]);
        $process->mustRun();

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function file(string $name, string $yaml): string
    {
        $path = "{$this->dir}/{$name}";
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $yaml);

        return $path;
    }

    private static function deployment(string $chart, string $app, array $args): string
    {
        $argsYaml = implode("\n", array_map(fn ($a) => "            - {$a}", $args));

        return <<<YAML
---
# Source: traefik/templates/deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: traefik
  namespace: knative-serving
  labels:
    helm.sh/chart: traefik-{$chart}
    app.kubernetes.io/version: {$app}
    app.kubernetes.io/name: traefik
spec:
  template:
    metadata:
      annotations:
        checksum/config: {$chart}abc
    spec:
      containers:
        - name: traefik
          image: ghcr.io/brf-tech/runr/reqs/traefik:{$app}
          args:
{$argsYaml}
---
apiVersion: v1
kind: Service
metadata:
  name: traefik
  namespace: knative-serving
  labels:
    helm.sh/chart: traefik-{$chart}
spec:
  ports:
    - port: 80

YAML;
    }

    public function test_version_labels_checksums_and_the_image_tag_are_no_change(): void
    {
        $old = $this->file('old.yaml', self::deployment('42.3.0', 'v3.7.17', ['--ping']));
        $new = $this->file('new.yaml', self::deployment('42.3.1', 'v3.7.18', ['--ping']));

        $this->assertSame(['old' => 2, 'new' => 2, 'added' => [], 'removed' => [], 'changed' => [], 'crds' => []],
            $this->diff('objects', $old, $new, '42.3.0', '42.3.1', 'v3.7.17', 'v3.7.18'));
    }

    public function test_a_changed_field_is_named_and_another_object_counts(): void
    {
        $old = $this->file('old.yaml', self::deployment('42.3.0', 'v3.7.17', ['--ping']));
        $new = $this->file('new.yaml', self::deployment('42.3.1', 'v3.7.17', ['--ping', '--log.level=INFO'])
            ."---\napiVersion: v1\nkind: ConfigMap\nmetadata:\n  name: traefik-hub\n  namespace: knative-serving\n");

        $result = $this->diff('objects', $old, $new, '42.3.0', '42.3.1', 'v3.7.17', 'v3.7.17');

        $this->assertSame(['ConfigMap knative-serving/traefik-hub'], $result['added']);
        $this->assertSame([], $result['removed']);
        $this->assertSame([['id' => 'Deployment knative-serving/traefik', 'paths' => ['spec.template.spec.containers.0.args.1']]], $result['changed']);
    }

    public function test_crds_compare_without_labels_and_annotations(): void
    {
        $crd = fn (string $annotation, string $type) => "apiVersion: apiextensions.k8s.io/v1\nkind: CustomResourceDefinition\nmetadata:\n  name: middlewares.traefik.io\n  annotations:\n    controller-gen.kubebuilder.io/version: {$annotation}\nspec:\n  group: traefik.io\n  versions:\n    - name: v1alpha1\n      schema:\n        type: {$type}\n";
        foreach (['old', 'new'] as $side) {
            $this->file("{$side}/traefik/Chart.yaml", "name: traefik\n");
            // a subchart named crds: its templates are no CRD files (kube-prometheus-stack's charts/crds/)
            $this->file("{$side}/traefik/charts/crds/Chart.yaml", "name: crds\n");
            $this->file("{$side}/traefik/charts/crds/templates/job.yaml", "{{- if .Values.upgradeJob.enabled }}\nkind: Job\n{{- end }}\n");
        }
        $this->file('old/traefik/crds/middlewares.yaml', $crd('v0.17.0', 'object'));
        $this->file('new/traefik/crds/middlewares.yaml', $crd('v0.18.0', 'object'));
        $this->assertSame(['added' => [], 'removed' => [], 'changed' => []], $this->diff('crds', "{$this->dir}/old", "{$this->dir}/new"));

        $this->file('new/traefik/crds/middlewares.yaml', $crd('v0.18.0', 'string'));
        $this->file('new/traefik/crds/tlsstores.yaml', str_replace('middlewares.traefik.io', 'tlsstores.traefik.io', $crd('v0.18.0', 'object')));
        $this->assertSame(['added' => ['tlsstores.traefik.io'], 'removed' => [], 'changed' => ['middlewares.traefik.io']],
            $this->diff('crds', "{$this->dir}/old", "{$this->dir}/new"));
    }

    public function test_a_value_runr_sets_that_the_new_chart_dropped(): void
    {
        $values = $this->file('values.yaml', "logs:\n  access:\n    enabled: true\nports:\n  web:\n    port: 8000\nadditionalArguments:\n  - --x\npodAnnotations:\n  runr.dev/own: 'yes'\n");
        $old = $this->file('old-values.yaml', "logs:\n  access:\n    enabled: false\nports:\n  web:\n    port: 80\nadditionalArguments: []\npodAnnotations: {}\n");
        $new = $this->file('new-values.yaml', "log: {}\naccessLog:\n  enabled: false\nports:\n  web:\n    port: 80\nadditionalArguments: []\npodAnnotations: {}\n");

        // a key below an open map (podAnnotations) is RunR's own, not the chart's
        $this->assertSame(['dropped' => ['logs.access.enabled']], $this->diff('keys', $values, $old, $new));
        $this->assertSame(['dropped' => []], $this->diff('keys', $values, $old, $old));
    }

    /**
     * ci/deps/dead-values.php: a value whose change renders nothing new is one the chart does not
     * read (Traefik 42.3.0 never read service.type: it takes the type from service.spec). A change
     * the chart refuses is a read value.
     */
    public function test_a_value_the_chart_does_not_read_is_dead(): void
    {
        if ((new Process(['helm', 'version', '--short']))->run() !== 0) {
            $this->markTestSkipped('needs helm');
        }
        $this->file('chart/Chart.yaml', 'apiVersion: v2
name: c
version: 1.0.0
');
        $this->file('chart/templates/svc.yaml', <<<'TPL'
            apiVersion: v1
            kind: Service
            metadata:
              name: {{ .Release.Name }}
            spec:
              type: {{ .Values.service.spec.type }}
              {{- if .Values.service.single }}
              sessionAffinity: ClientIP
              {{- end }}
              {{- if not (has .Values.mode (list "a" "b")) }}
              {{- fail "mode is a or b" }}
              {{- end }}
            TPL);
        $values = $this->file('values.yaml', 'service:
  type: LoadBalancer
  single: true
  spec:
    type: LoadBalancer
mode: a
');

        $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/ci/deps/dead-values.php', "{$this->dir}/chart", $values, 'r', 'ns', '1.35.0']);
        $process->mustRun();

        $this->assertSame(['values' => 4, 'dead' => ['service.type'], 'refused' => ['mode']], json_decode($process->getOutput(), true));
    }
}
