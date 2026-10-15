<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnativeRegionShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_the_region_like_index(): void
    {
        $user = User::factory()->create(['username' => 'viewer']);
        $region = KnativeRegion::create(['name' => 'op']);

        $index = $this->actingAs($user, 'sanctum')->getJson('/api/knative-regions')->assertOk();
        $show = $this->actingAs($user, 'sanctum')->getJson("/api/knative-regions/{$region->id}")->assertOk();

        $show->assertJsonPath('data.id', $region->id)
            ->assertJsonPath('data.name', 'op');
        $this->assertSame($index->json('data.0'), $show->json('data'));
    }

    public function test_show_unknown_region_is_404(): void
    {
        $user = User::factory()->create(['username' => 'viewer']);

        $this->actingAs($user, 'sanctum')->getJson('/api/knative-regions/01zzzzzzzzzzzzzzzzzzzzzzzz')->assertNotFound();
    }
}
