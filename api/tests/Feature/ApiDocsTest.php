<?php

namespace Tests\Feature;

use App\Http\Middleware\NormalizeApiTags;
use App\Support\ApiDocument;
use Dedoc\Scramble\Generator;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * The API reference (GET /api/docs) and its OpenAPI document (GET /api/docs.json). Generating the
 * document takes longer than a request may run (0.6.4: a 500 at max_execution_time), so the API
 * image builds it (`php artisan runr:api-docs`) and the routes serve that file; only a checkout
 * without the file generates it, per request.
 *
 * Every operation has a one-line summary, an operationId of its own and one group
 * (ApiDocument::problems): checked here for the routes of the test app, and by CI for the document
 * the image builds with every module (api/docker/api-docs.sh --check, job api of ci.yml).
 *
 * No database: none of this reads one, and without RefreshDatabase the test runs whichever modules
 * modules_statuses.json enables (their migrations need the "central" connection, which only
 * TENANCY_ENABLED configures).
 */
class ApiDocsTest extends TestCase
{
    private string $dir;

    /** @var array<string, mixed>|null the test app's document, generated once (it takes seconds) */
    private static ?array $generated = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/runr-api-docs-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['scramble.document_path' => $this->dir.'/openapi.json']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /**
     * The test app's document as Scramble generates it, for every route this app has
     *
     * @return array<string, mixed>
     */
    private function generatedDocument(): array
    {
        return self::$generated ??= ApiDocument::generate();
    }

    /** The bytes a response sends (a file response sends its file) */
    private function body(TestResponse $response): string
    {
        if (! $response->baseResponse instanceof BinaryFileResponse) {
            return (string) $response->getContent();
        }
        ob_start();
        $response->baseResponse->sendContent();

        return (string) ob_get_clean();
    }

    /** Generating the document fails the test: the route must not do it */
    private function generatingIsAFailure(): void
    {
        $this->mock(Generator::class, fn ($mock) => $mock->shouldNotReceive('__invoke'));
    }

    /**
     * Scramble's document as the generator returns it, small: one route, one resource
     *
     * @param  array<string, mixed>  $operation  more of the route's operation (summary, operationId)
     */
    private function generatesTheDocument(array $operation = []): void
    {
        $this->mock(Generator::class, fn ($mock) => $mock->shouldReceive('__invoke')->andReturn([
            'openapi' => '3.1.0',
            'info' => ['title' => 'RunR API Docs', 'version' => '1.0.0'],
            'servers' => [['url' => 'http://build-host/api']],
            'paths' => [
                '/projects/{project}' => [
                    'get' => $operation + [
                        'tags' => ['Project'],
                        'responses' => ['200' => ['content' => ['application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/ProjectResource'],
                        ]]]],
                    ],
                ],
            ],
            'components' => ['schemas' => [
                'ProjectResource' => [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string'], 'meta' => new \stdClass],
                ],
            ]],
        ])->once());
    }

    public function test_the_document_is_the_built_file_and_is_not_generated(): void
    {
        $document = ['openapi' => '3.1.0', 'info' => ['title' => 'RunR API Docs'], 'paths' => ['/ping' => ['get' => []]]];
        File::put(ApiDocument::path(), json_encode($document));
        $this->generatingIsAFailure();

        $response = $this->get('/api/docs.json');

        $response->assertOk();
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $served = json_decode($this->body($response), true);
        $this->assertSame($document, $served);
        $this->assertArrayHasKey('openapi', $served);
        $this->assertArrayHasKey('/ping', $served['paths']);
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
        $etag = (string) $response->headers->get('ETag');
        $this->assertNotSame('', $etag);

        // The same document again: its ETag answers with 304 and no body
        $this->get('/api/docs.json', ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_without_the_built_file_the_document_is_generated_for_the_request(): void
    {
        $this->generatesTheDocument();

        $response = $this->get('/api/docs.json');

        $response->assertOk();
        $served = $response->json();
        $this->assertSame('3.1.0', $served['openapi']);
        // ApiDocument::finish(): grouped schema names and their references, the tag's group, an
        // empty schema as a string, the server relative to the host
        $this->assertSame(['Response.Project'], array_keys($served['components']['schemas']));
        $this->assertSame(
            '#/components/schemas/Response.Project',
            $served['paths']['/projects/{project}']['get']['responses']['200']['content']['application/json']['schema']['$ref'],
        );
        $this->assertSame(['Projects'], $served['paths']['/projects/{project}']['get']['tags']);
        $this->assertSame([['name' => 'Projects']], $served['tags']);
        $this->assertSame(['type' => 'string'], $served['components']['schemas']['Response.Project']['properties']['meta']);
        $this->assertSame([['url' => '/api']], $served['servers']);
        $this->assertFileDoesNotExist(ApiDocument::path());
    }

    public function test_the_built_document_is_what_the_route_generates(): void
    {
        $this->generatesTheDocument();

        $this->artisan('runr:api-docs')
            ->expectsOutputToContain('1 paths, 1 operations')
            ->assertSuccessful();

        $this->assertFileExists(ApiDocument::path());
        $this->assertSame([], glob($this->dir.'/*.tmp'));
        $built = File::get(ApiDocument::path());
        $this->assertSame('/api', json_decode($built, true)['servers'][0]['url']);

        // Served as built, without generating it again (the generator answers once)
        $this->assertSame($built, $this->body($this->get('/api/docs.json')->assertOk()));
    }

    /**
     * Every documented route (Scramble's: api/…, not api/internal/…, a controller) is in exactly one
     * group of the reference, one of NormalizeApiTags::GROUPS or a module's: not also in its class
     * name's (Scramble's own resolver added it to every @tags), and not in a piece of the
     * controller's description (the Sandbox API's read as comma-separated tags)
     */
    public function test_every_operation_is_in_one_group_of_the_reference(): void
    {
        $groups = NormalizeApiTags::groups();
        $seen = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $action = $route->getAction('controller');
            if (! is_string($action) || ! str_starts_with($route->uri(), 'api/') || str_starts_with($route->uri(), 'api/internal/')) {
                continue;
            }
            [$class, $method] = array_pad(explode('@', $action), 2, '__invoke');
            $tags = NormalizeApiTags::forController($class, $method);
            $this->assertCount(1, $tags, "{$route->uri()} ({$class}): ".implode(' | ', $tags));
            $this->assertContains($tags[0], $groups, "{$route->uri()} ({$class})");
            $seen[$tags[0]] = true;
        }

        foreach (['Projects', 'Regions > Install', 'Sandboxes', 'Sandboxes > Commands', 'Sandboxes > Settings', 'AI > Models'] as $group) {
            $this->assertArrayHasKey($group, $seen);
        }

        // The document's tag list, as the reference lists the groups: alphabetical, whatever the case
        $spec = ['paths' => [
            '/a' => ['get' => ['tags' => ['API product']]],
            '/b' => ['get' => ['tags' => ['Account']]],
            '/c' => ['get' => ['tags' => ['AI > GPU']]],
        ]];
        NormalizeApiTags::normalizeSpecArray($spec);
        $this->assertSame(['Account', 'AI > GPU', 'API product'], array_column($spec['tags'], 'name'));
    }

    /** --check: the image build and CI fail on an operation that breaks a rule of the reference */
    public function test_the_build_fails_on_an_operation_without_a_summary_or_an_operation_id(): void
    {
        $this->generatesTheDocument();

        $this->artisan('runr:api-docs', ['--check' => true])
            ->expectsOutputToContain('GET /projects/{project}: no summary')
            ->expectsOutputToContain('GET /projects/{project}: no operationId')
            ->expectsOutputToContain('2 problems in the API reference')
            ->assertFailed();

        // Written all the same, to look at
        $this->assertFileExists(ApiDocument::path());
    }

    public function test_the_build_passes_a_document_that_keeps_the_rules(): void
    {
        $this->generatesTheDocument(['summary' => 'Get a project', 'operationId' => 'projects.show']);

        $this->artisan('runr:api-docs', ['--check' => true])
            ->expectsOutputToContain('Every operation has a one-line summary, an operationId of its own and one group.')
            ->assertSuccessful();
    }

    /** The rules of the reference, each on an operation that breaks it */
    public function test_the_rules_of_the_reference_find_every_gap(): void
    {
        $operation = fn (string $operationId, ?string $summary, array $tags = ['Projects']) => array_filter(
            ['operationId' => $operationId, 'summary' => $summary, 'tags' => $tags],
            fn ($value) => $value !== null,
        );
        $spec = ['paths' => [
            // Kept: 80 characters, a slash between words, an HTTP method in sentence case
            '/kept' => [
                'get' => $operation('kept.show', 'Get '.str_repeat('a', 76)),
                'post' => $operation('kept.store', 'Add a Forgejo/Gitea provider and read its stdout / stderr'),
                'parameters' => [['name' => 'kept', 'in' => 'query']],
            ],
            '/broken' => [
                'get' => ['operationId' => 'broken.show', 'tags' => ['Projects']],
                'put' => $operation('broken.update', "Change a project\nand its regions"),
                'post' => $operation('broken.store', 'Get '.str_repeat('a', 77)),
                'delete' => $operation('broken.destroy', 'Delete /api/projects/{project}.'),
                'patch' => $operation('broken.patch', 'PATCH a project'),
            ],
            '/ids' => [
                'get' => $operation('kept.show', 'Get the same operationId'),
                'post' => ['summary' => 'Create without an operationId', 'tags' => ['Projects']],
            ],
            '/groups' => [
                'get' => $operation('groups.show', 'Get an operation in two groups', ['Projects', 'Regions']),
                'put' => $operation('groups.update', 'Change an operation in no group', []),
                'post' => $operation('groups.store', 'Create an operation in an unknown group', ['Nowhere']),
            ],
        ]];

        $this->assertSame([
            'GET /broken: no summary',
            'PUT /broken: the summary is more than one line',
            'POST /broken: the summary is longer than 80 characters (81)',
            'DELETE /broken: the summary names a path',
            'DELETE /broken: the summary ends with a period',
            'PATCH /broken: the summary names an HTTP method',
            "GET /ids: the operationId kept.show is GET /kept's too",
            'POST /ids: no operationId',
            'GET /groups: in 2 groups of the reference, not one (Projects | Regions)',
            'PUT /groups: in 0 groups of the reference, not one ()',
            'POST /groups: in the group Nowhere, not one of NormalizeApiTags::groups()',
        ], ApiDocument::problems($spec));
    }

    /**
     * The document of the test app (Scramble, as the image runs it) keeps the rules of the
     * reference; CI applies them to the document of every module (api/docker/api-docs.sh --check)
     */
    public function test_every_operation_has_a_summary_its_own_operation_id_and_one_group(): void
    {
        $document = $this->generatedDocument();

        $this->assertNotEmpty($document['paths']);
        $this->assertSame([], ApiDocument::problems($document));
    }

    /**
     * A route without a sign-in (no auth middleware: webhooks, public pages, invitations) is public
     * in the reference (`@unauthenticated` in the method's PHPDoc), every other one needs a token
     */
    public function test_the_reference_shows_which_operations_need_a_sign_in(): void
    {
        $operations = [];
        foreach ($this->generatedDocument()['paths'] as $path => $item) {
            foreach ((array) $item as $method => $operation) {
                // the reference names some path parameters otherwise ({user} → {userId})
                $operations[strtoupper($method).' '.preg_replace('/\{[^}]+\}/', '{}', $path)] = $operation;
            }
        }

        $router = app('router');
        $public = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! is_string($route->getAction('controller')) || ! str_starts_with($route->uri(), 'api/') || str_starts_with($route->uri(), 'api/internal/')) {
                continue;
            }
            $signIn = collect($router->gatherRouteMiddleware($route))
                ->contains(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, Authenticate::class));
            // Scramble documents one method of a route (PUT of PUT|PATCH)
            $documented = 0;
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $operation = $operations[$method.' '.preg_replace('/\{[^}]+\}/', '{}', substr($route->uri(), 3))] ?? null;
                if ($operation === null) {
                    continue;
                }
                $documented++;
                $this->assertSame(! $signIn, ($operation['security'] ?? null) === [], "{$method} {$route->uri()}: "
                    .($signIn ? 'needs a sign-in, the reference shows it public' : 'needs no sign-in: @unauthenticated'));
                if (! $signIn) {
                    $public[] = "{$method} {$route->uri()}";
                }
            }
            $this->assertGreaterThan(0, $documented, "{$route->uri()} is not in the reference");
        }

        $this->assertContains('POST api/auth/login', $public);
        $this->assertContains('POST api/git/webhooks/github/{provider}', $public);
    }

    public function test_the_api_reference_page_reads_the_document_and_does_not_generate_it(): void
    {
        File::put(ApiDocument::path(), json_encode(['openapi' => '3.1.0', 'paths' => []]));
        $this->generatingIsAFailure();

        $response = $this->get('/api/docs');

        $response->assertOk();
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        $response->assertSee("url: '/api/docs.json'", false);
        $response->assertSee("Scalar.createApiReference('#app'", false);
        // The bundle mounts its legacy viewer on an element with this id by itself, and the two
        // viewers left the page at its loading skeleton
        $response->assertDontSee('id="api-reference"', false);
        // The viewer: the image's copy, or (a checkout without it) the pinned bundle with its integrity
        $html = $response->getContent();
        $this->assertTrue(
            str_contains($html, 'src="/api/vendor/scalar/api-reference.js"')
                || str_contains($html, 'integrity="sha384-'),
            'the page loads the Scalar viewer',
        );
    }
}
