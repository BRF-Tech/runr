<?php

namespace App\Http\Controllers\Api\Observability;

use App\Http\Controllers\Api\Controller;
use App\Models\Observability\UptimeCheck;
use App\Rules\CustomDomain;
use App\Services\Observability\StatusPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The organization's public status page: settings, components (uptime checks shown on the
 * page) and a preview; the public view is PublicStatusPageController.
 *
 * @tags Observability
 */
class StatusPageController extends Controller
{
    public function __construct(private StatusPageService $pages) {}

    /** GET /api/observability/status-page */
    public function show(): JsonResponse
    {
        $page = $this->pages->current();

        $components = UptimeCheck::query()->with('project:id,name')->orderBy('status_page_order')->orderBy('name')->get()
            ->map(fn (UptimeCheck $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'project_id' => $c->project_id,
                'project_name' => $c->project?->name,
                'show_on_status_page' => $c->show_on_status_page,
                'status_page_name' => $c->status_page_name,
                'status_page_order' => $c->status_page_order,
                'status' => StatusPageService::componentStatus($c),
            ])->all();

        return response()->json(['data' => [
            'page' => $page->toApi() + ['exists' => $page->exists],
            'components' => $components,
            'preview' => $page->exists ? $this->pages->payload($page) : null,
            'public_path' => '/status/'.$page->slug,
        ]]);
    }

    /**
     * PUT /api/observability/status-page — page settings plus, optionally, the components:
     * components: [{id, show_on_status_page, status_page_name, status_page_order}]
     */
    public function update(Request $request): JsonResponse
    {
        if (is_string($request->input('custom_domain'))) {
            $domain = rtrim(strtolower(trim($request->input('custom_domain'))), '.');
            $request->merge(['custom_domain' => $domain === '' ? null : $domain]);
        }

        $data = $request->validate([
            'slug' => ['required', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'logo_url' => ['nullable', 'string', 'url:https', 'max:500'],
            'custom_domain' => ['nullable', 'string', 'max:253', new CustomDomain],
            'is_public' => ['required', 'boolean'],
            'show_incident_history' => ['nullable', 'boolean'],
            'history_days' => ['nullable', 'integer', 'min:7', 'max:90'],
            'components' => ['nullable', 'array', 'max:200'],
            'components.*.id' => ['required', 'string', 'size:26'],
            'components.*.show_on_status_page' => ['required', 'boolean'],
            'components.*.status_page_name' => ['nullable', 'string', 'max:100'],
            'components.*.status_page_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $current = $this->pages->current();
        $conflicts = $this->pages->conflicts($data['slug'], $data['custom_domain'] ?? null, $current->exists ? $current->id : null);
        if ($conflicts) {
            throw ValidationException::withMessages(array_map(fn ($m) => [$m], $conflicts));
        }

        $page = $this->pages->save([
            'slug' => $data['slug'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'logo_url' => $data['logo_url'] ?? null,
            'custom_domain' => isset($data['custom_domain']) ? strtolower($data['custom_domain']) : null,
            'is_public' => (bool) $data['is_public'],
            'show_incident_history' => (bool) ($data['show_incident_history'] ?? true),
            'history_days' => (int) ($data['history_days'] ?? 90),
        ]);

        foreach ((array) ($data['components'] ?? []) as $c) {
            UptimeCheck::query()->whereKey($c['id'])->update([
                'show_on_status_page' => (bool) $c['show_on_status_page'],
                'status_page_name' => $c['status_page_name'] ?? null,
                'status_page_order' => (int) ($c['status_page_order'] ?? 0),
            ]);
        }

        return $this->show();
    }
}
