<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * config/ide-helper.php hooked `ide-helper:models --write --reset` onto every migrate. The dev
 * stack runs with APP_ENV=local and the checkout mounted, so each migrate in a test container
 * rewrote the docblocks of ~90 models and the local lint failed on the changed files. The hook
 * now needs APP_ENV=local and the opt-in IDE_HELPER_POST_MIGRATE=true.
 */
class IdeHelperConfigTest extends TestCase
{
    private const KEYS = ['APP_ENV', 'IDE_HELPER_POST_MIGRATE'];

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::KEYS as $key) {
            $this->saved[$key] = $_SERVER[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        parent::tearDown();
    }

    /** post_migrate of the config file under the given environment (env() reads $_SERVER first). */
    private function postMigrate(string $appEnv, ?string $optIn): array
    {
        $_SERVER['APP_ENV'] = $appEnv;
        if ($optIn === null) {
            unset($_SERVER['IDE_HELPER_POST_MIGRATE']);
        } else {
            $_SERVER['IDE_HELPER_POST_MIGRATE'] = $optIn;
        }

        return (require config_path('ide-helper.php'))['post_migrate'];
    }

    public function test_migrate_leaves_the_models_alone_by_default(): void
    {
        $this->assertSame([], $this->postMigrate('local', null));
        $this->assertSame([], $this->postMigrate('local', 'false'));
        $this->assertSame([], $this->postMigrate('testing', null));
        $this->assertSame([], $this->postMigrate('production', null));
    }

    public function test_the_opt_in_only_counts_on_a_local_checkout(): void
    {
        $this->assertSame(['ide-helper:models --write --reset'], $this->postMigrate('local', 'true'));
        $this->assertSame([], $this->postMigrate('testing', 'true'));
        $this->assertSame([], $this->postMigrate('production', 'true'));
    }
}
