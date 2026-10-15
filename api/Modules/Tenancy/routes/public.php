<?php

use Illuminate\Support\Facades\Route;
use Modules\Tenancy\Http\Controllers\InvitationController;
use Modules\Tenancy\Models\Tenant;

// Public invitation routes (no auth required — token is the authorization)
Route::get('/invitations/{token}', [InvitationController::class, 'show']);
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:auth');

// Public tenant lookup (login page tenancy validation)
Route::get('/tenant/check/{slug}', function (string $slug) {
    $tenant = Tenant::where('slug', $slug)->first(['id', 'name', 'slug']);

    if (! $tenant) {
        return response()->json(['message' => __('api.tenant.not_found')], 404);
    }

    return response()->json(['exists' => true, 'name' => $tenant->name, 'slug' => $tenant->slug]);
});
