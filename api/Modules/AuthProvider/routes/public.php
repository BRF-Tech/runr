<?php

use Illuminate\Support\Facades\Route;
use Modules\AuthProvider\Controllers\OAuthController;
use Modules\AuthProvider\Controllers\SamlController;

// Public — no auth required
Route::get('/auth-providers/discover', [OAuthController::class, 'discover']);
Route::get('/oauth/{provider}/redirect', [OAuthController::class, 'redirect'])
    ->whereIn('provider', array_values(array_diff(config('authprovider.drivers', []), ['saml'])))
    ->middleware('throttle:auth');

// SSO sign-in handoff: the one-time code of an OAuth / OIDC / SAML callback → API token
Route::post('/oauth/exchange', [OAuthController::class, 'exchange'])->middleware('throttle:auth');

// SAML 2.0 service provider ({provider} = auth provider ID; optional ?tenant=<slug>).
// Throttled by the `api` limiter per IP (no password is posted here; the ACS only accepts a
// signed answer to an AuthnRequest issued by login) — many users behind one office IP can sign in.
Route::get('/saml/{provider}/metadata', [SamlController::class, 'metadata'])->name('saml.metadata');
Route::get('/saml/{provider}/login', [SamlController::class, 'login'])->name('saml.login');
Route::post('/saml/{provider}/acs', [SamlController::class, 'acs'])->name('saml.acs');
