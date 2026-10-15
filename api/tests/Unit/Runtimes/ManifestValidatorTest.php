<?php

namespace Tests\Unit\Runtimes;

use App\Services\Runtimes\InvalidManifestException;
use App\Services\Runtimes\ManifestValidator;
use App\Services\Runtimes\RuntimeManifest;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Runtime manifest validation: YAML / JSON input, the JSON Schema with JSON-pointer problems,
 * the rules beyond the schema and the normalized defaults.
 */
class ManifestValidatorTest extends TestCase
{
    use RegistersRuntimes;

    private function parse(string $text, array $files = [], bool $builtin = false): RuntimeManifest
    {
        return app(ManifestValidator::class)->parse($text, $files, $builtin);
    }

    /** @return list<array{path: string, message: string}> */
    private function problems(string $text, array $files = [], bool $builtin = false): array
    {
        try {
            $this->parse($text, $files, $builtin);
        } catch (InvalidManifestException $e) {
            return $e->problems;
        }
        $this->fail('manifest was accepted');
    }

    private function paths(string $text, array $files = []): array
    {
        return array_column($this->problems($text, $files), 'path');
    }

    public function test_a_minimal_manifest_gets_every_default(): void
    {
        $m = $this->parse(<<<'YAML'
apiVersion: runr.sh/v1
kind: Runtime
id: q
name: Q
extensions: [q]
versions:
  - version: "1.0"
    image: ghcr.io/acme/q:1.0
run:
  mode: persistent
  command: ["q", "/app/code/{file}"]
YAML);

        $this->assertSame('q', $m->id());
        $this->assertSame('plaintext', $m->language());
        $this->assertSame('general', $m->category());
        $this->assertSame('main.q', $m->defaultFilename());
        $this->assertSame('handler', $m->handlerFunction());
        $this->assertNull($m->handlerPattern());
        $this->assertSame(['main.q' => ''], $m->templateFiles('main.q', 'handler', true));
        $this->assertSame(['http', 'schedule', 'queue'], $m->triggers());
        $this->assertSame(['runc', 'gvisor', 'kata'], $m->sandbox());
        $this->assertSame(1, $m->workers());
        $this->assertSame([], $m->runEnv());
        $this->assertFalse($m->isCompiled());
        $this->assertNull($m->runtimeClass());
        $this->assertNull($m->gpu());
        // the only version becomes the default
        $this->assertTrue($m->versions()[0]['default']);
        $this->assertSame(64, strlen($m->digest()));
    }

    public function test_json_text_is_accepted_like_yaml(): void
    {
        $json = json_encode([
            'apiVersion' => 'runr.sh/v1', 'kind' => 'Runtime', 'id' => 'bash', 'name' => 'Bash', 'extensions' => ['sh'],
            'versions' => [['version' => '5.2', 'image' => 'docker.io/library/bash:5.2']],
            'run' => ['mode' => 'exec', 'command' => ['bash', '/app/code/{file}']],
        ]);

        $m = $this->parse($json);
        $this->assertSame('exec', $m->runMode());
        $this->assertSame('json', $m->input());
    }

    public function test_the_example_manifests_are_valid(): void
    {
        $this->assertSame('c', $this->parse($this->cManifest())->id());
        $this->assertSame('bash', $this->parse($this->bashManifest())->id());
        $this->assertSame('q', $this->parse($this->qManifest(), $this->qFiles())->id());
        $this->assertSame('srv', $this->parse($this->serverManifest())->id());

        $dir = base_path('../examples/runtimes');
        if (! is_dir($dir)) {
            $this->markTestSkipped('examples/runtimes is not part of this checkout');
        }
        foreach (['c', 'rust', 'bash'] as $id) {
            $this->assertSame($id, $this->parse(file_get_contents("{$dir}/{$id}/runtime.yaml"))->id(), $id);
        }
        $files = [];
        foreach (glob("{$dir}/q/interpreter/*") as $path) {
            $files[basename($path)] = file_get_contents($path);
        }
        $this->assertSame('persistent', $this->parse(file_get_contents("{$dir}/q/runtime.yaml"), $files)->runMode());
    }

    public function test_broken_text(): void
    {
        $this->assertSame([['path' => '/', 'message' => 'manifest is empty']], $this->problems('  '));
        $this->assertSame('/', $this->problems("id: [unclosed\n")[0]['path']);
        $this->assertStringContainsString('not valid YAML', $this->problems("id: [unclosed\n")[0]['message']);
        $this->assertSame([['path' => '/', 'message' => 'must be a mapping (runtime.yaml)']], $this->problems("- a\n- b\n"));
    }

    public function test_schema_problems_point_at_the_field(): void
    {
        $this->assertContains('/name', $this->paths($this->bashManifest(['name' => ''])));
        $this->assertContains('/id', $this->paths($this->bashManifest(['id' => 'Bash_1'])));
        $this->assertContains('/run/mode', $this->paths($this->bashManifest(['run' => ['mode' => 'cgi']])));
        $this->assertContains('/versions/0/version', $this->paths($this->bashManifest(['versions' => [['version' => 5.2]]])));
        $this->assertContains('/extensions/0', $this->paths($this->bashManifest(['extensions' => ['.sh']])));
        $this->assertContains('/triggers/0', $this->paths($this->bashManifest(['triggers' => ['event']])));
        $this->assertContains('/sandbox/0', $this->paths($this->bashManifest(['sandbox' => ['firecracker']])));
        $this->assertContains('/resources/gpu/count', $this->paths($this->bashManifest(['resources' => ['gpu' => ['count' => 9]]])));
        $this->assertContains('/icon', $this->paths($this->bashManifest(['icon' => 'http://insecure.example/icon.svg'])));

        $unknown = $this->problems($this->bashManifest(['flavor' => 'x']));
        $this->assertContains(['path' => '/flavor', 'message' => 'is not a known field'], $unknown);

        $missing = $this->problems("apiVersion: runr.sh/v1\nkind: Runtime\n");
        foreach (['/id', '/name', '/extensions', '/run'] as $path) {
            $this->assertContains(['path' => $path, 'message' => 'is required'], $missing);
        }
    }

    public function test_single_letter_ids_and_reserved_ids(): void
    {
        $this->assertSame('c', $this->parse($this->cManifest())->id());
        $this->assertContains(['path' => '/id', 'message' => 'is reserved'], $this->problems($this->bashManifest(['id' => 'docker'])));
        $this->assertContains(['path' => '/id', 'message' => 'is reserved'], $this->problems($this->bashManifest(['id' => 'validate'])));
    }

    public function test_version_sources(): void
    {
        $both = $this->problems($this->bashManifest(['versions' => [['version' => '1', 'image' => 'a/b:1', 'dockerfile' => 'Dockerfile']]]), ['Dockerfile' => 'FROM x']);
        $this->assertContains(['path' => '/versions/0', 'message' => 'needs exactly one of image, dockerfile, git or upload'], $both);

        $none = $this->problems($this->bashManifest(['versions' => [['version' => '1', 'image' => null]]]));
        $this->assertNotSame([], $none);

        $this->assertContains(
            ['path' => '/versions/0/dockerfile', 'message' => 'names Dockerfile, which is not among the uploaded files'],
            $this->problems($this->qManifest()),
        );
        $this->assertSame('1.0', $this->parse($this->qManifest(), $this->qFiles())->versions()[0]['version']);

        $args = $this->problems($this->bashManifest(['versions' => [['version' => '5.2', 'image' => 'bash:5.2', 'buildArgs' => ['A' => 'b']]]]));
        $this->assertContains(['path' => '/versions/0/buildArgs', 'message' => 'only applies to a dockerfile or git version'], $args);

        $git = $this->parse($this->bashManifest(['versions' => [['version' => '6', 'image' => null, 'git' => ['url' => 'https://github.com/acme/bash.git', 'ref' => 'main']]]]));
        $this->assertSame(['url' => 'https://github.com/acme/bash.git', 'ref' => 'main', 'path' => '', 'dockerfile' => 'Dockerfile'], $git->versions()[0]['git']);

        $this->assertContains('/versions/0/git/url', $this->paths($this->bashManifest(['versions' => [['version' => '6', 'image' => null, 'git' => ['url' => 'git@github.com:acme/bash.git']]]])));
    }

    public function test_duplicate_and_default_versions(): void
    {
        $dup = $this->problems($this->bashManifest(['versions' => [
            ['version' => '1', 'image' => 'a/b:1'], ['version' => '1', 'image' => 'a/b:2'],
        ]]));
        $this->assertContains(['path' => '/versions/1/version', 'message' => 'is listed twice'], $dup);

        $defaults = $this->problems($this->bashManifest(['versions' => [
            ['version' => '1', 'image' => 'a/b:1', 'default' => true], ['version' => '2', 'image' => 'a/b:2', 'default' => true],
        ]]));
        $this->assertContains(['path' => '/versions', 'message' => 'has more than one default version'], $defaults);

        // without a default flag the last version is the default
        $m = $this->parse($this->bashManifest(['versions' => [
            ['version' => '1', 'image' => 'a/b:1', 'default' => false], ['version' => '2', 'image' => 'a/b:2', 'default' => false],
        ]]));
        $this->assertSame([false, true], array_column($m->versions(), 'default'));
    }

    public function test_run_mode_rules(): void
    {
        $exec = $this->problems($this->bashManifest(['run' => ['command' => null]]));
        $this->assertContains(['path' => '/run/command', 'message' => 'is required in exec mode'], $exec);

        $http = $this->problems($this->serverManifest(['run' => ['command' => null]]));
        $this->assertSame('/run/command', $http[0]['path']);
        $this->assertStringContainsString('schedule trigger', $http[0]['message']);

        // an http image that runs scheduled invocations itself, or one without the schedule trigger
        $this->assertSame('native', $this->parse($this->serverManifest(['run' => ['command' => null, 'schedule' => 'native']]))->scheduleMode());
        $this->assertSame('invoke', $this->parse($this->serverManifest(['run' => ['command' => null], 'triggers' => ['http', 'queue']]))->scheduleMode());

        $this->assertContains(['path' => '/run/schedule', 'message' => 'only applies to http mode'], $this->problems($this->bashManifest(['run' => ['schedule' => 'native']])));
        $this->assertContains(['path' => '/run/input', 'message' => 'only applies to exec mode'], $this->problems($this->serverManifest(['run' => ['input' => 'raw']])));
        $this->assertContains(['path' => '/run/workers', 'message' => 'only applies to persistent mode'], $this->problems($this->bashManifest(['run' => ['workers' => 2]])));
        $this->assertContains('/run/workers', $this->paths($this->qManifest(['run' => ['workers' => 17]]), $this->qFiles()));
    }

    public function test_handler_pattern_and_dependency_file(): void
    {
        $this->assertContains(['path' => '/handler/pattern', 'message' => 'is not a valid regular expression'], $this->problems($this->bashManifest(['handler' => ['pattern' => 'echo (']])));

        $deps = $this->problems($this->bashManifest(['dependencies' => ['file' => 'main.sh', 'install' => 'true']]));
        $this->assertContains(['path' => '/dependencies/file', 'message' => 'must differ from the handler file'], $deps);
    }

    public function test_built_in_only_features(): void
    {
        $discovery = $this->bashManifest(['versions' => null, 'versionDiscovery' => ['repository' => 'runrsh/exec-bash', 'image' => 'ghcr.io/runrsh/exec-bash:{version}']]);
        $this->assertContains(
            ['path' => '/versionDiscovery', 'message' => 'is only available to the built-in catalog; list versions instead'],
            $this->problems($discovery),
        );
        $this->assertNull($this->parse($discovery, [], true)->versions()[0] ?? null);

        $refs = $this->cManifest(['build' => ['files' => ['wrapper.c' => '@wrapper.c']]]);
        $this->assertContains(['path' => '/build/files/wrapper.c', 'message' => 'file references (@path) are only resolved in the built-in catalog'], $this->problems($refs));
    }

    public function test_gpu_and_runtime_class(): void
    {
        $m = $this->parse($this->bashManifest(['resources' => ['gpu' => ['count' => 1]], 'runtimeClass' => 'wasmtime-spin']));

        $this->assertSame(['count' => 1, 'resource' => 'nvidia.com/gpu'], $m->gpu());
        $this->assertSame('wasmtime-spin', $m->runtimeClass());
        $this->assertContains('/runtimeClass', $this->paths($this->bashManifest(['runtimeClass' => 'Not_A_Name'])));
    }

    public function test_problems_render_as_a_422_with_paths(): void
    {
        try {
            $this->parse($this->bashManifest(['run' => ['mode' => 'cgi']]));
            $this->fail('accepted');
        } catch (InvalidManifestException $e) {
            $response = $e->render();
            $this->assertSame(422, $response->getStatusCode());
            $body = $response->getData(true);
            $this->assertSame('/run/mode', $body['problems'][0]['path']);
            $this->assertStringStartsWith('/run/mode: must be one of', $body['errors']['manifest'][0]);
        }
    }
}
