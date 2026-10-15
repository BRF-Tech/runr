<?php

namespace App\Http\Requests\KnativeRegion;

use App\Services\Helpers\KataRuntime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InstallSandboxRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'runtime' => ['required', 'string', 'in:kata,gvisor'],
            // all four at once (e.g. clh, qemu, dragonball + fc on one cluster)
            'hypervisors' => ['nullable', 'array', 'min:1', 'max:'.count(KataRuntime::HYPERVISORS)],
            // Whether the cluster can run them is checked by SandboxController (node capabilities)
            'hypervisors.*' => ['string', 'distinct', Rule::in(KataRuntime::HYPERVISORS)],
            // Firecracker (fc): data size of the devmapper thin-pool RunR creates (sparse file)
            'devmapper_pool_gb' => ['nullable', 'integer', 'min:'.KataRuntime::DEVMAPPER_POOL_MIN_GB, 'max:'.KataRuntime::DEVMAPPER_POOL_MAX_GB],
        ];
    }

    public function messages(): array
    {
        return [
            'hypervisors.*.in' => 'Unsupported Kata hypervisor ":input". Allowed: '.implode(', ', KataRuntime::HYPERVISORS).'.',
        ];
    }
}
