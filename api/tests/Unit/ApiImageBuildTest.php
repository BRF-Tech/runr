<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * docker/entrypoint.sh serves the API with Octane on FrankenPHP, which needs the frankenphp
 * binary in the image: every API image starts from the FrankenPHP base, and the scripts that
 * build an image to run (compose/build.sh for air-gapped installs, compose/deploy.sh, ci.sh
 * deploy, the GitLab build:api job) build Dockerfile.prod, which also bakes vendor/ in. The
 * compose build once built the php-fpm development Dockerfile: no frankenphp, no vendor/.
 */
class ApiImageBuildTest extends TestCase
{
    private function apiFile(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    private function repoFile(string $path): ?string
    {
        $file = dirname(__DIR__, 3).'/'.$path;

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /** The FROM line of the stage the image is built from (the last stage). */
    private function runtimeBase(string $dockerfile): string
    {
        preg_match_all('/^FROM\s+(\S+)/m', $this->apiFile($dockerfile), $m);
        $this->assertNotEmpty($m[1], "{$dockerfile} has no FROM");

        return (string) end($m[1]);
    }

    public function test_the_entrypoint_runs_octane_on_frankenphp(): void
    {
        $this->assertStringContainsString('octane:start --server=frankenphp', $this->apiFile('docker/entrypoint.sh'));
    }

    public function test_both_dockerfiles_start_from_the_frankenphp_image(): void
    {
        foreach (['Dockerfile', 'Dockerfile.prod'] as $dockerfile) {
            $this->assertStringContainsString('frankenphp', $this->runtimeBase($dockerfile), "{$dockerfile}: the runtime stage needs the frankenphp binary");
        }
    }

    public function test_the_production_image_carries_vendor(): void
    {
        $this->assertMatchesRegularExpression('#^COPY --from=vendor /app/vendor \./vendor#m', $this->apiFile('Dockerfile.prod'));
    }

    public function test_image_builds_for_running_use_the_production_dockerfile(): void
    {
        $checked = 0;
        foreach (['compose/build.sh', 'compose/deploy.sh', 'runtime/lib/deploy-images.sh'] as $script) {
            $text = $this->repoFile($script);
            if ($text === null) {
                continue; // tests copied without the repository around api/
            }
            $checked++;
            $this->assertMatchesRegularExpression('#--file\s+"?\$\{?[A-Za-z_]+\}?/api/Dockerfile\.prod"?#', $text, "{$script} builds the API image from Dockerfile.prod");
        }

        $ci = $this->repoFile('.gitlab-ci.yml');
        if ($ci !== null) {
            $checked++;
            $job = substr($ci, (int) strpos($ci, "\nbuild:api:"));
            $job = substr($job, 0, (int) strpos($job, "\nbuild:ui:"));
            $this->assertStringContainsString('--file api/Dockerfile.prod', $job, '.gitlab-ci.yml build:api builds Dockerfile.prod');
        }

        if ($checked === 0) {
            $this->markTestSkipped('repository files around api/ are not available');
        }
    }
}
