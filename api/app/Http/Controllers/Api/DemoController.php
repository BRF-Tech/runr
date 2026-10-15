<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RemoveDemoJob;
use App\Jobs\SeedDemoJob;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

class DemoController extends Controller
{
    public function store(): JsonResponse
    {
        Setting::set('demo_status', 'seeding');
        SeedDemoJob::dispatch(auth()->id());

        return response()->json(['demo_status' => 'seeding']);
    }

    public function destroy(): JsonResponse
    {
        Setting::set('demo_status', 'removing');
        RemoveDemoJob::dispatch(auth()->id());

        return response()->json(['demo_status' => 'removing']);
    }
}
