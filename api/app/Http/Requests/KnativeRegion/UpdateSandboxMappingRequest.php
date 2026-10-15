<?php

namespace App\Http\Requests\KnativeRegion;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSandboxMappingRequest extends FormRequest
{
    public function rules(): array
    {
        $region = $this->route('knativeRegion');

        $allowedRuntimes = ['runc'];
        if ($region && ($region->sandbox_kata_status ?? 'not_installed') === 'ready') {
            $hypervisors = $region->sandbox_kata_hypervisor ?? [];
            foreach ((array) $hypervisors as $hv) {
                $allowedRuntimes[] = "kata-{$hv}";
            }
            if (empty($hypervisors)) {
                $allowedRuntimes[] = 'kata'; // legacy fallback
            }
        }
        if ($region && ($region->sandbox_gvisor_status ?? 'not_installed') === 'ready') {
            $allowedRuntimes[] = 'gvisor';
        }

        $in = 'in:'.implode(',', $allowedRuntimes);

        return [
            'sandbox_xaas_runtime' => ['required', 'string', $in],
            'sandbox_faas_runtime' => ['required', 'string', $in],
            'sandbox_caas_runtime' => ['required', 'string', $in],
            'sandbox_build_runtime' => ['required', 'string', $in],
        ];
    }
}
