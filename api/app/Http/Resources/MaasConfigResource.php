<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaasConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'env_vars' => $this->env_vars,
            'timeout_seconds' => $this->timeout_seconds,
            'gpu_type' => $this->gpu_type,
            'gpu_count' => $this->gpu_count,
            'scale_to_zero' => $this->scale_to_zero,
            'min_scale' => $this->min_scale,
            'max_scale' => $this->max_scale,
        ];
    }
}
