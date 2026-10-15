<?php

namespace Tests\Feature\Workflows;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every workflow API route requires authentication; only the signed queue bridge does not. */
class WorkflowApiGuestTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_get_401_on_every_authenticated_route(): void
    {
        $project = Project::create(['name' => 'guest-check', 'service_type' => 'caas']);
        $p = "/api/projects/{$project->id}";
        $run = '01JAAAAAAAAAAAAAAAAAAAAAAA';

        $routes = [
            ['GET', "{$p}/workflows"],
            ['PUT', "{$p}/workflows"],
            ['POST', "{$p}/workflows/sync"],
            ['GET', "{$p}/workflows/app"],
            ['PATCH', "{$p}/workflows/app"],
            ['POST', "{$p}/workflows/app/rotate-key"],
            ['GET', "{$p}/workflows/app/signing-key"],
            ['GET', "{$p}/workflows/orders"],
            ['PATCH', "{$p}/workflows/orders"],
            ['GET', "{$p}/workflows/orders/runs"],
            ['POST', "{$p}/workflows/orders/runs"],
            ['GET', "{$p}/workflow-runs"],
            ['GET', "{$p}/workflow-runs/{$run}"],
            ['POST', "{$p}/workflow-runs/{$run}/cancel"],
            ['POST', "{$p}/workflow-runs/{$run}/retry"],
            ['POST', "{$p}/workflow-runs/{$run}/replay"],
            ['GET', "{$p}/workflow-usage"],
            ['GET', '/api/workflow-usage'],
            ['POST', '/api/events'],
            ['GET', '/api/events'],
            ['GET', "/api/events/{$run}"],
        ];

        foreach ($routes as [$method, $uri]) {
            $this->json($method, $uri, ['name' => 'x'])->assertUnauthorized();
        }
    }
}
