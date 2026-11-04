<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * docker/entrypoint.sh serves the API with Octane on FrankenPHP, which needs the frankenphp
 * binary in the image: every API image starts from the FrankenPHP base, and the scripts that
 * build an image to run (compose/build.sh for air-gapped installs, compose/deploy.sh, ci.sh
 * deploy, the release workflow .github/workflows/release-images.yml) build Dockerfile.prod,
 * which also bakes vendor/ in. The compose build once built the php-fpm development Dockerfile:
 * no frankenphp, no vendor/.
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

    /**
     * The stages of a Dockerfile in order: the image or stage of each FROM line, the stage's name
     * (AS …, null without one) and its text (from its FROM line to the next).
     *
     * @return list<array{from: string, name: string|null, text: string}>
     */
    private function stages(string $dockerfile): array
    {
        $text = $this->apiFile($dockerfile);
        preg_match_all('/^FROM\s+(\S+)(?:\s+AS\s+(\S+))?[ \t\r]*$/mi', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($m, "{$dockerfile} has no FROM");

        $stages = [];
        foreach ($m as $i => $from) {
            $start = $from[0][1];
            $end = isset($m[$i + 1]) ? $m[$i + 1][0][1] : strlen($text);
            $stages[] = [
                'from' => $from[1][0],
                'name' => isset($from[2]) && $from[2][1] >= 0 ? strtolower($from[2][0]) : null,
                'text' => substr($text, $start, $end - $start),
            ];
        }

        return $stages;
    }

    /**
     * The stages the image is made of: the last stage and the earlier ones it builds on (FROM
     * <stage name>), first the one that starts from an image. Stages the image only copies from
     * (vendor, api-docs) are not part of it.
     *
     * @return non-empty-list<array{from: string, name: string|null, text: string}>
     */
    private function imageStages(string $dockerfile): array
    {
        $stages = $this->stages($dockerfile);
        $index = count($stages) - 1;
        $chain = [$stages[$index]];

        // FROM names an earlier stage only (Docker resolves it among the stages above)
        while (true) {
            $parent = null;
            for ($i = $index - 1; $i >= 0; $i--) {
                if ($stages[$i]['name'] !== null && $stages[$i]['name'] === strtolower($chain[0]['from'])) {
                    $parent = $i;
                    break;
                }
            }
            if ($parent === null) {
                return $chain;
            }
            array_unshift($chain, $stages[$parent]);
            $index = $parent;
        }
    }

    /** The image the image is built from: the FROM of its first stage (imageStages). */
    private function runtimeBase(string $dockerfile): string
    {
        return $this->imageStages($dockerfile)[0]['from'];
    }

    /** The default value of an ARG of a Dockerfile, null when it has none. */
    private function argDefault(string $dockerfile, string $name): ?string
    {
        if (! preg_match('/^ARG\s+'.preg_quote($name, '/').'=(\S+)\s*$/m', $this->apiFile($dockerfile), $m)) {
            return null;
        }

        return trim($m[1], '"\'');
    }

    /**
     * The text of the stages the image is made of (imageStages), first stage first: the runtime
     * stage that starts from the FrankenPHP image and what the last stage adds on top of it.
     */
    private function runtimeStage(string $dockerfile): string
    {
        return implode('', array_column($this->imageStages($dockerfile), 'text'));
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

    /**
     * Dockerfile.prod builds the OpenAPI document in a stage of its own on the runtime stage (with
     * the modules enabled and a throw-away database): the image is the runtime stage again with
     * only the document copied in, never the api-docs stage.
     */
    public function test_the_production_image_builds_on_the_runtime_stage_not_on_the_api_docs_stage(): void
    {
        $stages = $this->imageStages('Dockerfile.prod');

        $this->assertSame(['base', null], array_column($stages, 'name'), 'Dockerfile.prod: the image is the runtime stage "base" and the last stage on it');
        $this->assertDoesNotMatchRegularExpression('#^RUN .*docker/api-docs\.sh#m', $this->runtimeStage('Dockerfile.prod'), 'Dockerfile.prod: the image does not run the document build');
        $this->assertContains('api-docs', array_column($this->stages('Dockerfile.prod'), 'name'));
        $this->assertMatchesRegularExpression(
            '#^COPY --from=api-docs /tmp/api-docs/openapi\.json resources/api-docs/openapi\.json$#m',
            end($stages)['text'],
            'Dockerfile.prod: the last stage copies the document from the api-docs stage',
        );
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

        $workflow = $this->repoFile('.github/workflows/release-images.yml');
        if ($workflow !== null) {
            $checked++;
            $this->assertStringContainsString('--file api/Dockerfile.prod', $workflow, 'the release workflow builds the API image from Dockerfile.prod');
        }

        if ($checked === 0) {
            $this->markTestSkipped('repository files around api/ are not available');
        }
    }
}
