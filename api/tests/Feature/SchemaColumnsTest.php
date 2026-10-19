<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\Project;
use App\Support\SchemaColumns;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SchemaColumns remembers only columns that exist: a long-lived worker (Octane, Horizon) that asked
 * before a tenant's migration ran sees the column once it has run — a platform stop is then stored
 * with its level, not as an organization's.
 */
class SchemaColumnsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SchemaColumns::flush();
        parent::tearDown();
    }

    public function test_a_missing_column_is_asked_again_and_an_existing_one_remembered(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('stop_level'));
        SchemaColumns::flush();
        $project = new Project;

        $this->assertFalse(SchemaColumns::has($project, 'stop_level'), 'the migration has not run yet');

        // The tenant's migration runs while the worker keeps going
        Schema::table('projects', fn (Blueprint $table) => $table->string('stop_level', 16)->nullable());
        $this->assertTrue(SchemaColumns::has($project, 'stop_level'), 'not the remembered "no" of before');

        // Remembered from now on: no schema query
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertTrue(SchemaColumns::has($project, 'stop_level'));
        $this->assertSame([], DB::getQueryLog());
        $this->assertTrue(SchemaColumns::has(new Deployment, 'ended_at'));
    }
}
