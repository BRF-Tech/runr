<?php

use Illuminate\Support\Facades\Route;
use Modules\Tenancy\Http\Controllers\WhiteLabel\PlanController;

// Public plans for the signup page (plans off → [])
Route::get('/plans/public', [PlanController::class, 'publicIndex'])->name('plans.public');
