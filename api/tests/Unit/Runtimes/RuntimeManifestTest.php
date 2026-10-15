<?php

namespace Tests\Unit\Runtimes;

use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\ManifestValidator;
use App\Services\Runtimes\Placeholders;
use App\Services\Runtimes\ProjectEntrypoint;
use App\Services\Runtimes\RuntimeManifest;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Placeholders, the wrapper decision per run mode and trigger, sandbox compatibility, starter
 * templates and handler resolution of a manifest.
 */
class RuntimeManifestTest extends TestCase
{
    use RegistersRuntimes;

    private function manifest(string $yaml, array $files = []): RuntimeManifest
    {
        return app(ManifestValidator::class)->parse($yaml, $files);
    }

    public function test_placeholders(): void
    {
        $values = ['version' => '8.4', 'file' => 'main.php', 'handler' => 'run', 'mode' => 'http', 'trigger' => 'queue', 'image' => 'php:8.4'];

        $this->assertSame('php -f main.php run http queue php:8.4 8.4', Placeholders::render('php -f {file} {handler} {mode} {trigger} {image} {version}', $values));
        $this->assertSame('func Run', Placeholders::render('func {Handler}', $values));
        // only the known names are replaced
        $this->assertSame('echo ${HOME} {"statusCode": 200} {result}', Placeholders::render('echo ${HOME} {"statusCode": 200} {result}', $values));
        // {semver}: a plain X.Y / X.Y.Z version, else empty; {if:..}{end} keeps its text only then
        $cmd = 'cd /build/code && {if:semver}composer config platform.php {semver} && {end}composer install';
        $this->assertSame('cd /build/code && composer config platform.php 8.4 && composer install', Placeholders::render($cmd, ['version' => '8.4']));
        $this->assertSame('cd /build/code && composer config platform.php 8.3.12 && composer install', Placeholders::render($cmd, ['version' => '8.3.12']));
        $this->assertSame('cd /build/code && composer install', Placeholders::render($cmd, ['version' => '8.4-gpu']));
        $this->assertSame('cd /build/code && composer install', Placeholders::render($cmd, ['version' => null]));
    }

    public function test_which_triggers_run_through_the_wrapper(): void
    {
        $exec = $this->manifest($this->bashManifest());
        $persistent = $this->manifest($this->qManifest(), $this->qFiles());
        $server = $this->manifest($this->serverManifest());
        $native = $this->manifest($this->serverManifest(['run' => ['schedule' => 'native']]));

        foreach (['http', 'schedule', 'queue'] as $trigger) {
            $this->assertTrue($exec->needsWrapper($trigger));
            $this->assertTrue($persistent->needsWrapper($trigger));
            $this->assertFalse($native->needsWrapper($trigger));
        }
        $this->assertFalse($server->needsWrapper('http'));
        $this->assertFalse($server->needsWrapper('queue'));
        $this->assertTrue($server->needsWrapper('schedule'));

        $this->assertTrue($server->usesWrapper());
        $this->assertFalse($native->usesWrapper());
        $this->assertSame('exec', $exec->wrapperMode());
        $this->assertSame('persistent', $persistent->wrapperMode());
        $this->assertSame('http', $server->wrapperMode());
    }

    public function test_sandbox_compatibility_follows_the_runtime_class(): void
    {
        $runcOnly = $this->manifest($this->bashManifest(['sandbox' => ['runc']]));
        $all = $this->manifest($this->bashManifest());

        $this->assertTrue($runcOnly->supportsRuntimeClass(null));
        $this->assertTrue($runcOnly->supportsRuntimeClass('runc'));
        $this->assertFalse($runcOnly->supportsRuntimeClass('gvisor'));
        $this->assertFalse($runcOnly->supportsRuntimeClass('kata-clh'));
        $this->assertTrue($all->supportsRuntimeClass('kata-qemu'));
        $this->assertTrue($all->supportsRuntimeClass('gvisor'));
        $this->assertFalse($all->supportsRuntimeClass('wasmtime'));
    }

    public function test_templates_and_rendered_commands(): void
    {
        $go = app(BuiltinCatalog::class)->get('go');
        $this->assertStringContainsString('func Run(event interface{}', $go->templateFiles('main.go', 'run', false)['main.go']);

        $python = app(BuiltinCatalog::class)->get('python');
        $this->assertSame(['app.py', 'requirements.txt'], array_keys($python->templateFiles('app.py', 'handler', true)));
        $this->assertSame(['app.py'], array_keys($python->templateFiles('app.py', 'handler', false)));

        $bash = $this->manifest($this->bashManifest());
        $this->assertSame(['bash', '/app/code/main.sh'], $bash->renderedCommand(['file' => 'main.sh']));
    }

    public function test_handler_pattern_and_build_need(): void
    {
        $custom = $this->manifest($this->bashManifest(['handler' => ['pattern' => '^{handler}\(\)'], 'dependencies' => ['file' => 'deps.txt', 'install' => 'true']]));

        $this->assertSame(1, preg_match($custom->handlerPattern('greet'), 'greet() { echo hi; }'));
        $this->assertSame(0, preg_match($custom->handlerPattern('greet'), 'other() { echo hi; }'));
        // a handler name is quoted into the regex
        $this->assertSame(0, preg_match($custom->handlerPattern('a.b'), 'axb() {}'));

        $this->assertFalse($custom->needsBuild(['main.sh' => 'x']));
        $this->assertTrue($custom->needsBuild(['main.sh' => 'x', 'deps.txt' => '']));
        $this->assertTrue($this->manifest($this->cManifest())->needsBuild(['main.c' => 'x']));
    }

    public function test_project_entrypoints(): void
    {
        $m = $this->manifest($this->cManifest());

        $this->assertSame(['main.c', 'handler'], [ProjectEntrypoint::fromHandlerString('main.handler', $m)->filename, ProjectEntrypoint::fromHandlerString('main.handler', $m)->handler]);
        $this->assertSame('app.c', ProjectEntrypoint::fromHandlerString('app.run', $m)->filename);
        $this->assertSame('run', ProjectEntrypoint::fromHandlerString('app.run', $m)->handler);
        // a handler without a dot names the function; the file is the default one
        $this->assertSame('main.c', ProjectEntrypoint::fromHandlerString('run', $m)->filename);
        $this->assertSame('run', ProjectEntrypoint::fromHandlerString('run', $m)->handler);
        $this->assertSame('main.handler', ProjectEntrypoint::defaultHandlerString($m));
    }
}
