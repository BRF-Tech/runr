<?php

/*
 * ci.sh test-upgrade (runtime/lib/test-upgrade.sh): runs inside the API container of the release
 * being upgraded from. Creates what a cluster connection and `executor:sync` create on a real
 * install — a region and a runtime version — so that release can hold projects without
 * Kubernetes; with tenancy on, the first administrator becomes the platform administrator.
 * Prints {"region_id": "..."}; only models and columns every 0.4.x release has.
 */

use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$region = new KnativeRegion;
$columns = array_flip(Schema::connection($region->getConnectionName())->getColumnListing($region->getTable()));
$region->forceFill(array_intersect_key([
    'name' => 'upgrade-region',
    'is_active' => true,
    'region_mode' => 'managed',          // a column of the Tenancy module
    'total_cpu_m' => 8000,
    'total_memory_mb' => 16384,
    'cluster_status' => 'online',
    // never contacted: the suite stays at the API
    'kubeconfig' => 'upgrade-test-dummy-kubeconfig',
], $columns))->save();

// Multi-tenant: the first administrator is the platform administrator (ci.sh deploy sets the same)
$users = new User;
if (Schema::connection($users->getConnectionName())->hasColumn($users->getTable(), 'is_admin')) {
    User::query()->where('username', config('app.admin.username', 'admin'))->update(['is_admin' => true]);
}

ExecutorImage::query()->firstOrCreate(
    ['runtime' => 'python', 'version' => '3.12'],
    ['docker_image' => 'ghcr.io/brf-tech/runr/exec-python:3.12', 'is_default' => true, 'is_active' => true, 'sort_order' => 1],
);

echo json_encode(['region_id' => $region->id]), "\n";
