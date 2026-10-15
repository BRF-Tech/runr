<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * wikimedia/composer-merge-plugin merges the Modules' composer.json files. On a fresh install
 * (CI, the image build: no vendor/ yet) it then "updates to apply merge settings" every
 * requirement it merged — to the newest release, past composer.lock: CI tested laravel/socialite,
 * socialiteproviders/microsoft and stancl/tenancy versions the lock (and the image, where that
 * update fails without pcntl) does not have. The root composer.json requires every module
 * dependency itself and the plugin ignores the duplicates, so a fresh install installs the lock.
 */
class ComposerModulesTest extends TestCase
{
    public function test_every_module_requirement_is_a_root_requirement_and_not_merged_again(): void
    {
        $base = dirname(__DIR__, 2);
        $root = json_decode((string) file_get_contents($base.'/composer.json'), true);

        $this->assertTrue(
            $root['extra']['merge-plugin']['ignore-duplicates'] ?? false,
            'merge-plugin.ignore-duplicates: a merged requirement is updated past the lock on a fresh install',
        );

        $files = glob($base.'/Modules/*/composer.json') ?: [];
        $this->assertNotEmpty($files, 'no module composer.json found');

        foreach ($files as $file) {
            $module = json_decode((string) file_get_contents($file), true);
            foreach ($module['require'] ?? [] as $package => $constraint) {
                if ($package === 'php' || str_starts_with($package, 'ext-')) {
                    continue;
                }
                $this->assertArrayHasKey($package, $root['require'], "{$file} requires {$package}: require it in api/composer.json too");
                $this->assertSame($constraint, $root['require'][$package], "{$package}: the constraint of {$file} differs from api/composer.json (the module's is ignored)");
            }
        }
    }
}
