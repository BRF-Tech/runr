<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knative_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version')->unique();       // chart version: "1.21.0"
            $table->string('app_version')->nullable();  // knative version: "1.21.0"
            $table->string('min_kubernetes_version')->nullable(); // "v1.25.0"
            $table->unsignedSmallInteger('min_k8s_minor')->nullable()->index(); // 25
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knative_versions');
    }
};
