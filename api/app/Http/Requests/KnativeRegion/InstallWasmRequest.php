<?php

namespace App\Http\Requests\KnativeRegion;

use App\Services\Wasm\WasmShim;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InstallWasmRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // spin (the shim of the wasm-* runtimes), optionally wasmtime
            'shims' => ['required', 'array', 'min:1', 'max:'.count(WasmShim::IDS)],
            'shims.*' => ['string', 'distinct', Rule::in(WasmShim::IDS)],
            // node names; null / absent = every eligible node (checked against the cluster by WasmController)
            'nodes' => ['nullable', 'array', 'min:1', 'max:500'],
            'nodes.*' => ['string', 'distinct', 'max:253', 'regex:/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'shims.*.in' => 'Unsupported WebAssembly shim ":input". Allowed: '.implode(', ', WasmShim::IDS).'.',
        ];
    }
}
