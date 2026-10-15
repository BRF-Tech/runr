<?php

use App\Http\Controllers\Api\ModelServiceSecurityController;
use App\Http\Controllers\Api\ProjectSecurityController;
use App\Http\Controllers\Api\RegionSupplyChainController;
use App\Http\Controllers\Api\ResidencyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Supply chain + data residency (docs/supply-chain.md)
|--------------------------------------------------------------------------
| Loaded by SupplyChainServiceProvider with the `api` middleware group (tenant identification
| included) + auth:sanctum, prefix /api. Abilities are checked in the controllers:
| security.view, security.override_policy (deploy), regions.supply_chain.manage,
| tenants.residency.manage. Region-mutating routes also carry `region.mutable`.
*/

// Project security (versions / deployments of a project)
Route::scopeBindings()->group(function () {
    Route::get('/projects/{project}/security', [ProjectSecurityController::class, 'index']);
    Route::get('/projects/{project}/versions/{version}/security', [ProjectSecurityController::class, 'version']);
    Route::post('/projects/{project}/versions/{version}/security/scan', [ProjectSecurityController::class, 'rescan']);
    Route::get('/projects/{project}/versions/{version}/sbom', [ProjectSecurityController::class, 'sbom']);
    Route::get('/projects/{project}/deployments/{deployment}/security', [ProjectSecurityController::class, 'deployment']);
});

// AI model services: report of the service's (external) image
Route::get('/model-services/{service}/security', [ModelServiceSecurityController::class, 'show']);
Route::get('/model-services/{service}/sbom', [ModelServiceSecurityController::class, 'sbom']);

// Region supply chain settings
Route::get('/knative-regions/{knativeRegion}/supply-chain', [RegionSupplyChainController::class, 'show']);
Route::get('/knative-regions/{knativeRegion}/supply-chain/public-key', [RegionSupplyChainController::class, 'publicKey']);
Route::get('/knative-regions/{knativeRegion}/supply-chain/runtime-images', [RegionSupplyChainController::class, 'runtimeImages']);
Route::get('/knative-regions/{knativeRegion}/supply-chain/runtime-images/{report}/sbom', [RegionSupplyChainController::class, 'runtimeImageSbom']);
Route::get('/knative-regions/{knativeRegion}/supply-chain/admission/preview', [RegionSupplyChainController::class, 'admissionPreview']);

Route::middleware('region.mutable')->group(function () {
    Route::put('/knative-regions/{knativeRegion}/supply-chain', [RegionSupplyChainController::class, 'update']);
    Route::post('/knative-regions/{knativeRegion}/supply-chain/signing-key', [RegionSupplyChainController::class, 'generateKey']);
    Route::delete('/knative-regions/{knativeRegion}/supply-chain/signing-key/retired', [RegionSupplyChainController::class, 'forgetRetiredKeys']);
    Route::post('/knative-regions/{knativeRegion}/supply-chain/scanner-db/refresh', [RegionSupplyChainController::class, 'refreshScannerDb']);
    Route::post('/knative-regions/{knativeRegion}/supply-chain/admission', [RegionSupplyChainController::class, 'installAdmission']);
    Route::delete('/knative-regions/{knativeRegion}/supply-chain/admission', [RegionSupplyChainController::class, 'uninstallAdmission']);
});

// Data residency: the current organization's policy (region picker), and per tenant (platform admin)
Route::get('/residency', [ResidencyController::class, 'current']);
Route::get('/tenants/{tenant}/security-policy', [ResidencyController::class, 'show']);
Route::put('/tenants/{tenant}/security-policy', [ResidencyController::class, 'update']);
