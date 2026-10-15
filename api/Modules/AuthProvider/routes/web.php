<?php

use Illuminate\Support\Facades\Route;
use Modules\AuthProvider\Controllers\OAuthController;

// OAuth callback — GET redirect from provider (not API, no auth)
Route::get('/oauth/{provider}/callback', [OAuthController::class, 'callback'])
    ->whereIn('provider', array_values(array_diff(config('authprovider.drivers', []), ['saml'])))
    ->name('oauth.callback');
