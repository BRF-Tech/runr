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

    /** The default value of an ARG of a Dockerfile, null when it has none. */
    private function argDefault(string $dockerfile, string $name): ?string
    {
        if (! preg_match('/^ARG\s+'.preg_quote($name, '/').'=(\S+)\s*$/m', $this->apiFile($dockerfile), $m)) {
            return null;
        }

        return trim($m[1], '"\'');
    }

    /** The text of the stage the image is built from (from its FROM line on). */
    private function runtimeStage(string $dockerfile): string
    {
        $text = $this->apiFile($dockerfile);

        return substr($text, (int) strrpos($text, "\nFROM "));
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

    /**
     * CVE-2026-109618 and CVE-2026-109619 (remote code execution in PHP's request handling) are
     * fixed in PHP 8.3.36: the runtime stage of both images refuses a base whose PHP is older, or
     * on another branch than the floor, before it installs anything.
     */
    public function test_both_dockerfiles_refuse_a_php_older_than_the_fixed_release(): void
    {
        foreach (['Dockerfile', 'Dockerfile.prod'] as $dockerfile) {
            $min = $this->argDefault($dockerfile, 'PHP_MIN_VERSION');
            $this->assertNotNull($min, "{$dockerfile}: ARG PHP_MIN_VERSION=<the fixed PHP release>");
            $this->assertMatchesRegularExpression('/^8\.3\.\d+$/', $min, "{$dockerfile}: PHP_MIN_VERSION is a PHP 8.3 release");
            $this->assertTrue(version_compare($min, '8.3.36', '>='), "{$dockerfile}: PHP_MIN_VERSION {$min} is older than 8.3.36");

            $stage = $this->runtimeStage($dockerfile);
            $this->assertMatchesRegularExpression('/^ARG PHP_MIN_VERSION=/m', $stage, "{$dockerfile}: the runtime stage declares PHP_MIN_VERSION");
            $this->assertMatchesRegularExpression(
                '/^RUN php -r \'.*version_compare\(PHP_VERSION, \$min, ">="\).*\s+"\$PHP_MIN_VERSION" \\\\\n\s+\|\| \{ .*exit 1; \}$/msU',
                $stage,
                "{$dockerfile}: the runtime stage fails its build below PHP_MIN_VERSION",
            );
            $this->assertLessThan(
                strpos($stage, 'install-php-extensions'),
                strpos($stage, '"$PHP_MIN_VERSION"'),
                "{$dockerfile}: the guard runs before the extensions are installed",
            );
        }

        $this->assertSame($this->argDefault('Dockerfile', 'PHP_MIN_VERSION'), $this->argDefault('Dockerfile.prod', 'PHP_MIN_VERSION'));
    }

    /**
     * The FrankenPHP base tag is the same in both images and on the branch of PHP_MIN_VERSION: the
     * floating 1-php8.3-alpine, or an exact upstream tag whose PHP is the floor or later.
     */
    public function test_the_frankenphp_tag_matches_the_php_floor(): void
    {
        $tag = $this->argDefault('Dockerfile.prod', 'FRANKENPHP_TAG');
        $this->assertNotNull($tag, 'Dockerfile.prod: ARG FRANKENPHP_TAG before the first FROM');
        $this->assertSame($tag, $this->argDefault('Dockerfile', 'FRANKENPHP_TAG'), 'both images start from the same FrankenPHP tag');

        foreach (['Dockerfile', 'Dockerfile.prod'] as $dockerfile) {
            $this->assertSame('ghcr.io/brf-tech/runr/reqs/frankenphp:${FRANKENPHP_TAG}', $this->runtimeBase($dockerfile));
        }

        $this->assertMatchesRegularExpression('/^\d+(\.\d+){0,2}-php(\d+\.\d+)(\.\d+)?-alpine$/', $tag);
        preg_match('/-php(\d+\.\d+)(\.\d+)?-alpine$/', $tag, $m);
        $min = (string) $this->argDefault('Dockerfile.prod', 'PHP_MIN_VERSION');
        $this->assertStringStartsWith($m[1].'.', $min, "FRANKENPHP_TAG {$tag} is on another PHP branch than PHP_MIN_VERSION {$min}");
        if (($m[2] ?? '') !== '') {
            $this->assertTrue(version_compare($m[1].$m[2], $min, '>='), "FRANKENPHP_TAG {$tag} pins a PHP older than {$min}");
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
