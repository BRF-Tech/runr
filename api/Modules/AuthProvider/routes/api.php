<?php

use Illuminate\Support\Facades\Route;
use Modules\AuthProvider\Controllers\AuthProviderController;
use Modules\AuthProvider\Controllers\OAuthController;
use Modules\AuthProvider\Controllers\SocialAccountController;
use Modules\AuthProvider\Controllers\SsoGroupMappingController;

// Protected — admin CRUD
Route::get('/auth-providers', [AuthProviderController::class, 'index']);
Route::post('/auth-providers', [AuthProviderController::class, 'store']);
Route::put('/auth-providers/{authProvider}', [AuthProviderController::class, 'update']);
Route::delete('/auth-providers/{authProvider}', [AuthProviderController::class, 'destroy']);
Route::patch('/auth-providers/{authProvider}/toggle', [AuthProviderController::class, 'toggle']);

// Protected — SSO group mapping (external group → group / role, applied on every SSO sign-in)
Route::get('/sso/group-mappings', [SsoGroupMappingController::class, 'index']);
Route::post('/sso/group-mappings', [SsoGroupMappingController::class, 'store']);
Route::put('/sso/group-mappings/{mapping}', [SsoGroupMappingController::class, 'update']);
Route::delete('/sso/group-mappings/{mapping}', [SsoGroupMappingController::class, 'destroy']);

// Protected — user social accounts
Route::get('/social-accounts', [SocialAccountController::class, 'index']);
Route::delete('/social-accounts/{socialAccount}', [SocialAccountController::class, 'destroy']);

// Protected — link new provider to current user
Route::get('/oauth/{provider}/link', [OAuthController::class, 'link'])
    ->whereIn('provider', array_values(array_diff(config('authprovider.drivers', []), ['saml'])));
