<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime plugins: every language runtime is a manifest (runtime.yaml). Built-in runtimes keep
 * their manifest in the release (runtime/<id>/runtime.yaml) and get a row here for their state
 * (enabled, regions); platform and custom runtimes store the manifest. Existing projects keep
 * their runtime id (projects.runtime = 'python', ...) and the executor_images versions — the
 * built-in rows below are what they resolve to.
 */
return new class extends Migration
{
    private const BUILTINS = [
        'python' => 'Python',
        'nodejs' => 'Node.js',
        'go' => 'Go',
        'php' => 'PHP',
        'tensorflow' => 'TensorFlow',
        'pytorch' => 'PyTorch',
    ];

    public function up(): void
    {
        Schema::create('runtimes', function (Blueprint $table) {
            $table->string('id', 32)->primary();       // runtime id = projects.runtime
            $table->string('name', 64);
            $table->string('source', 16);               // builtin | platform | custom
            $table->string('tenant_id')->nullable();    // custom runtimes created in a tenant context
            $table->string('created_by')->nullable();
            $table->json('manifest')->nullable();       // normalized manifest (null for builtin: release catalog)
            $table->longText('manifest_source')->nullable(); // manifest as submitted (YAML / JSON)
            $table->json('files')->nullable();          // files uploaded with the runtime (Dockerfile, sources)
            $table->string('digest', 64)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->json('regions')->nullable();        // null = every region
            $table->timestamps();

            $table->index(['source', 'tenant_id']);
        });

        Schema::create('runtime_builds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('runtime_id', 32);
            $table->string('version', 32);
            $table->string('knative_region_id');
            $table->string('status', 16)->default('pending'); // pending | building | ready | failed
            $table->string('image')->nullable();
            $table->string('digest', 64);
            $table->string('job_name')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['runtime_id', 'version', 'knative_region_id']);
        });

        $now = now();
        foreach (self::BUILTINS as $id => $name) {
            DB::table('runtimes')->insert([
                'id' => $id,
                'name' => $name,
                'source' => 'builtin',
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_builds');
        Schema::dropIfExists('runtimes');
    }
};
