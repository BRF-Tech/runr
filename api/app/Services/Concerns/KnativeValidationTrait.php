<?php

namespace App\Services\Concerns;

use App\Models\KnativeRegion;
use Illuminate\Http\JsonResponse;

trait KnativeValidationTrait
{
    private function validateNotDemo(KnativeRegion $region): ?JsonResponse
    {
        if ($region->is_demo) {
            return response()->json(['message' => __('api.region.demo_not_modifiable')], 422);
        }

        return null;
    }

    private function validateHasKubeconfig(KnativeRegion $region): ?JsonResponse
    {
        if (! $region->kubeconfig) {
            return response()->json(['message' => __('api.region.kubeconfig_missing')], 422);
        }

        return null;
    }

    private function validateRegion(KnativeRegion $region): ?JsonResponse
    {
        return $this->validateNotDemo($region) ?? $this->validateHasKubeconfig($region);
    }

    private function validateComponentNotInstalling(KnativeRegion $region, string $component): ?JsonResponse
    {
        $statusField = 'knative_'.$component.'_status';
        if (($region->$statusField ?? '') === 'installing') {
            return response()->json(['message' => __('api.region.install_in_progress')], 409);
        }

        return null;
    }
}
