<?php

namespace App\Http\Requests\KnativeRegion;

use Illuminate\Foundation\Http\FormRequest;

class ApplyWorkersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // [] = no dedicated workers: every node is released and projects run on every
            // schedulable node (KnativeNodeService::applyWorkerSelection)
            'nodes' => 'present|array',
            'nodes.*' => 'string',
        ];
    }
}
