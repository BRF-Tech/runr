<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->string('build_image')->nullable()->after('build_token');
        });
    }

    public function down(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->dropColumn('build_image');
        });
    }
};
