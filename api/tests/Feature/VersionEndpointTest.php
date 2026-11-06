<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GET /api/version shows APP_VERSION as the build set it, with main- in front only for a build
 * from a commit (compose/deploy.sh: the commit id). A label of another build (the public demo's
 * demo-<tag>) read main-demo-0.6.5 before: every version that did not start with v counted as a
 * commit id.
 */
class VersionEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function versions(): array
    {
        return [
            'release tag' => ['v0.6.5', 'v0.6.5'],
            'development build' => ['dev', 'dev'],
            'build label' => ['demo-0.6.5', 'demo-0.6.5'],
            'version without v' => ['0.6.5', '0.6.5'],
            // runtime/ci.sh sets main-<short sha> itself: no second prefix
            'ci build' => ['main-3c2e4106', 'main-3c2e4106'],
            'short commit id' => ['3c2e4106', 'main-3c2e4106'],
            'full commit id' => ['3c2e41066f1a2b3c4d5e6f708192a3b4c5d6e7f8', 'main-3c2e41066f1a2b3c4d5e6f708192a3b4c5d6e7f8'],
        ];
    }

    #[DataProvider('versions')]
    public function test_the_version_reads_as_the_build_set_it(string $appVersion, string $shown): void
    {
        config(['app.version' => $appVersion]);

        $this->getJson('/api/version')->assertOk()->assertJsonPath('version', $shown);
    }
}
