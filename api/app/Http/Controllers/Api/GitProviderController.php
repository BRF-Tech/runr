<?php

namespace App\Http\Controllers\Api;

use App\Models\Git\GitProvider;
use App\Services\GitDeploy\GitAudit;
use App\Services\GitDeploy\GitUrls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Git providers (git_providers.manage):
 *   GET    /git-providers              every provider, secrets as has_* flags only
 *   POST   /git-providers              add a GitHub App, a GitLab instance or a Forgejo/Gitea instance
 *   PUT    /git-providers/{provider}   change it (empty secret fields keep the stored value)
 *   DELETE /git-providers/{provider}   remove it (refused while projects are connected through it)
 * The answer carries the URLs to enter at the provider (webhook, setup / callback URL).
 */
class GitProviderController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorizeManage();

        return response()->json(['data' => GitProvider::withCount('links')->orderBy('type')->orderBy('name')->get()->map(fn ($p) => $this->present($p))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage();

        $data = $this->validated($request, null);
        $data['created_by'] = (string) $request->user()->getKey();

        $provider = GitProvider::create($data);
        GitAudit::record('git_provider.create', null, ['provider_id' => $provider->id, 'type' => $provider->type, 'name' => $provider->name]);

        return response()->json(['data' => $this->present($provider->loadCount('links'))], 201);
    }

    public function update(Request $request, string $provider): JsonResponse
    {
        $this->authorizeManage();

        $row = GitProvider::findOrFail($provider);
        $data = $this->validated($request, $row);

        foreach (GitProvider::SECRETS as $secret) {
            if (array_key_exists($secret, $data) && ($data[$secret] === null || $data[$secret] === '')) {
                unset($data[$secret]); // empty = keep
            }
        }

        $row->fill($data);
        $changed = array_keys($row->getDirty());
        $row->save();

        GitAudit::record('git_provider.update', null, ['provider_id' => $row->id, 'changed' => $changed]);

        return response()->json(['data' => $this->present($row->loadCount('links'))]);
    }

    public function destroy(string $provider): JsonResponse
    {
        $this->authorizeManage();

        $row = GitProvider::withCount('links')->findOrFail($provider);
        if ($row->links_count > 0) {
            throw ValidationException::withMessages(['provider' => __('git.provider_in_use', ['count' => $row->links_count])]);
        }

        $row->installations()->delete();
        $row->delete();
        GitAudit::record('git_provider.delete', null, ['provider_id' => $row->id, 'type' => $row->type]);

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?GitProvider $existing): array
    {
        $type = $existing?->type ?? $request->input('type');
        $new = $existing === null;

        $rules = [
            'type' => [$new ? 'required' : 'prohibited', Rule::in(GitProvider::TYPES)],
            'name' => [$new ? 'required' : 'sometimes', 'string', 'max:100'],
            'base_url' => [$type === GitProvider::FORGEJO && $new ? 'required' : 'nullable', 'string', 'max:255', 'url:http,https'],
            'api_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'is_enabled' => ['sometimes', 'boolean'],
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:500'],
        ];

        if ($type === GitProvider::GITHUB) {
            $rules += [
                'app_id' => [$new ? 'required' : 'sometimes', 'nullable', 'string', 'max:64', 'regex:/^\d+$/'],
                'app_slug' => [$new ? 'required' : 'sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/i'],
                'private_key' => [$new ? 'required' : 'nullable', 'string', 'max:10000'],
                'webhook_secret' => [$new ? 'required' : 'nullable', 'string', 'min:16', 'max:255'],
            ];
            $rules['client_id'][0] = $new ? 'required' : 'nullable';
            $rules['client_secret'][0] = $new ? 'required' : 'nullable';
        }

        $data = $request->validate($rules);

        if (! empty($data['private_key']) && openssl_pkey_get_private($data['private_key']) === false) {
            throw ValidationException::withMessages(['private_key' => __('git.private_key_invalid')]);
        }

        foreach (['base_url', 'api_url'] as $url) {
            if (! empty($data[$url])) {
                $data[$url] = rtrim($data[$url], '/');
            }
        }

        return $data;
    }

    private function present(GitProvider $p): array
    {
        return [
            'id' => $p->id,
            'type' => $p->type,
            'name' => $p->name,
            'base_url' => $p->base_url,
            'api_url' => $p->api_url,
            'web_url' => $p->webUrl(),
            'app_id' => $p->app_id,
            'app_slug' => $p->app_slug,
            'client_id' => $p->client_id,
            'is_enabled' => $p->is_enabled,
            'configured' => $p->isConfigured(),
            'connect_modes' => $p->connectModes(),
            'has_client_secret' => $p->client_secret !== null && $p->client_secret !== '',
            'has_private_key' => $p->private_key !== null && $p->private_key !== '',
            'has_webhook_secret' => $p->webhook_secret !== null && $p->webhook_secret !== '',
            'links_count' => (int) ($p->links_count ?? 0),
            'urls' => match ($p->type) {
                GitProvider::GITHUB => [
                    'webhook' => GitUrls::webhook(GitProvider::GITHUB, $p->id),
                    'setup' => GitUrls::githubSetup(),
                    'callback' => GitUrls::githubSetup(),
                ],
                GitProvider::GITLAB => ['callback' => GitUrls::gitlabCallback()],
                default => (object) [],
            },
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    private function authorizeManage(): void
    {
        abort_unless(Gate::allows('git_providers.manage'), 403, __('git.forbidden', ['permission' => 'git_providers.manage']));
    }
}
