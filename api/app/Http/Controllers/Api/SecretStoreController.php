<?php

namespace App\Http\Controllers\Api;

use App\Models\KnativeRegion;
use App\Models\RegionSecretSetting;
use App\Models\SecretStore;
use App\Services\KubernetesClient;
use App\Services\Secrets\External\ClusterIssuer;
use App\Services\Secrets\External\ExternalSecretManifests;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The organization's secret stores (docs/secret-stores.md): HashiCorp Vault / OpenBao (Kubernetes
 * or JWT auth), AWS Secrets Manager, GCP Secret Manager and Azure Key Vault (workload identity
 * federation). A store never gets a credential from RunR: it trusts the region cluster's service
 * account issuer and a service account RunR names (GET …/trust shows what to trust per region).
 * Route rules: GET secret_stores.view, the rest secret_stores.manage.
 */
class SecretStoreController extends Controller
{
    /**
     * The API document's shape of a store (SecretStore::toApiArray() with the number of project
     * secrets referring to it); regions: region id => the store's status there
     */
    private const STORE_TYPE = 'array{id: string, name: string, provider: "vault"|"aws"|"gcp"|"azure", auth: "kubernetes"|"jwt"|"workload_identity", settings: array<string, string>, refresh_interval: int, kubernetes_name: string, audience: string|null, regions: array<string, array{status: string, message: string|null, checked_at: string}>, created_at: string|null, updated_at: string|null, references: int}';

    #[OpenApiResponse(200, 'The organization\'s secret stores and, per provider, the ways it authenticates and the settings it takes (name => required)', type: 'array{data: list<'.self::STORE_TYPE.'>, providers: list<array{provider: "vault"|"aws"|"gcp"|"azure", auth: list<string>, settings: array<string, bool>}>}')]
    public function index(): JsonResponse
    {
        $stores = SecretStore::query()->withCount('secrets')->orderBy('name')->get();

        return response()->json([
            'data' => $stores->map(fn (SecretStore $s) => $s->toApiArray() + ['references' => (int) $s->secrets_count])->values(),
            'providers' => collect(SecretStore::PROVIDERS)->map(fn (array $auth, string $provider) => [
                'provider' => $provider,
                'auth' => $auth,
                'settings' => SecretStore::SETTINGS[$provider],
            ])->values(),
        ]);
    }

    #[OpenApiResponse(200, 'The secret store', type: 'array{data: '.self::STORE_TYPE.'}')]
    public function show(SecretStore $secretStore): JsonResponse
    {
        $secretStore->loadCount('secrets');

        return response()->json(['data' => $secretStore->toApiArray() + ['references' => (int) $secretStore->secrets_count]]);
    }

    #[BodyParameter('name', 'Lowercase letters, digits and dashes, unique in the organization', required: true, type: 'string')]
    #[BodyParameter('provider', 'vault, aws, gcp or azure', required: true, type: 'string')]
    #[BodyParameter('auth', 'vault: kubernetes (default) or jwt; the others: workload_identity', type: 'string|null')]
    #[BodyParameter('settings', 'The provider\'s settings (GET /api/secret-stores, providers[].settings); never a credential', required: true, type: 'array<string, string>')]
    #[BodyParameter('refresh_interval', 'Seconds between syncs (60–86400); default: the installation\'s', type: 'int|null')]
    #[OpenApiResponse(201, 'The new secret store', type: 'array{data: '.self::STORE_TYPE.'}')]
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);

        $store = SecretStore::create([
            'name' => $data['name'],
            'provider' => $data['provider'],
            'auth' => $data['auth'] ?? SecretStore::PROVIDERS[$data['provider']][0],
            'settings' => $this->settings($data['provider'], (array) $data['settings']),
            'refresh_interval' => $data['refresh_interval'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $store->toApiArray() + ['references' => 0]], 201);
    }

    #[BodyParameter('name', 'Lowercase letters, digits and dashes, unique in the organization', type: 'string')]
    #[BodyParameter('auth', 'vault: kubernetes or jwt; the others: workload_identity', type: 'string|null')]
    #[BodyParameter('settings', 'The settings to change, merged into the store\'s (the provider cannot change)', type: 'array<string, string|null>')]
    #[BodyParameter('refresh_interval', 'Seconds between syncs (60–86400); null: the installation\'s', type: 'int|null')]
    #[OpenApiResponse(200, 'The secret store', type: 'array{data: '.self::STORE_TYPE.'}')]
    public function update(Request $request, SecretStore $secretStore): JsonResponse
    {
        $data = $this->validated($request, $secretStore);

        $secretStore->fill(array_filter([
            'name' => $data['name'] ?? null,
            'auth' => $data['auth'] ?? null,
        ]));
        if (array_key_exists('settings', $data)) {
            $secretStore->settings = $this->settings($secretStore->provider, array_merge((array) $secretStore->settings, (array) $data['settings']));
        }
        if (array_key_exists('refresh_interval', $data)) {
            $secretStore->refresh_interval = $data['refresh_interval'];
        }
        $secretStore->save();

        // The SecretStores in the regions follow with the next deploy of a project that uses it,
        // or with POST …/verify
        return response()->json(['data' => $secretStore->toApiArray() + ['references' => $secretStore->secrets()->count()]]);
    }

    /** Refused while a project secret refers to the store (409, the projects named). */
    public function destroy(SecretStore $secretStore): JsonResponse
    {
        $projects = $secretStore->secrets()->with('project:id,name')->get()
            ->map(fn ($s) => $s->project->name)->filter()->unique()->values();
        if ($projects->isNotEmpty()) {
            return response()->json([
                'message' => __('api.secret_store.in_use', ['projects' => $projects->take(10)->implode(', ')]),
                'projects' => $projects,
            ], 409);
        }

        $this->removeFromRegions($secretStore);
        $secretStore->delete();

        return response()->json(null, 204);
    }

    /**
     * The store's SecretStore and service account go from the regions External Secrets Operator
     * runs in (best effort: a region that does not answer keeps two objects that hold nothing).
     */
    private function removeFromRegions(SecretStore $store): void
    {
        foreach (KnativeRegion::query()->get() as $region) {
            if (! $region->kubeconfig || $region->is_demo || ! RegionSecretSetting::forRegion($region)->esoReady()) {
                continue;
            }
            $namespace = (string) ($region->project_namespace ?? $region->name);
            try {
                $k8s = new KubernetesClient($region->kubeconfig);
                $k8s->deleteResource(ExternalSecretManifests::API_VERSION, 'SecretStore', $store->kubernetesName(), $namespace);
                $k8s->deleteResource('v1', 'ServiceAccount', $store->kubernetesName(), $namespace);
                if (ExternalSecretManifests::tokenReviewBinding($store, $namespace) !== null) {
                    $k8s->deleteClusterResource('rbac.authorization.k8s.io/v1', 'ClusterRoleBinding', ExternalSecretManifests::tokenReviewBindingName($store, $namespace));
                }
            } catch (\Throwable $e) {
                Log::info('Secret store not removed from a region', ['store' => $store->name, 'region' => $region->name, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * GET /secret-stores/{secretStore}/trust?region_id=… — what the store has to trust for the
     * region: its cluster's issuer (URL, JWKS, PEM keys), the service account and namespace, the
     * subject and audience.
     */
    #[QueryParameter('region_id', 'The region (ULID)', required: true, type: 'string')]
    #[OpenApiResponse(200, 'What the store has to trust for the region', type: 'array{data: array{issuer: string, jwks_uri: string|null, jwks: array{keys: list<array<string, mixed>>}, pem_keys: list<string>, publicly_reachable: bool, region: array{id: string, name: string}, namespace: string, service_account: string, subject: string, audience: string|null, eso_status: "not_installed"|"installing"|"ready"|"uninstalling"|"error"}}')]
    #[OpenApiResponse(503, 'The region\'s cluster did not answer or names no issuer', type: 'array{message: string}')]
    public function trust(Request $request, SecretStore $secretStore): JsonResponse
    {
        $region = $this->region($request);

        try {
            $trust = app(ClusterIssuer::class)->trust($secretStore, $region);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(['data' => $trust + [
            'eso_status' => RegionSecretSetting::forRegion($region)->eso_status,
        ]]);
    }

    /**
     * POST /secret-stores/{secretStore}/verify {region_id} — applies the store's service account
     * and SecretStore in the region now and returns what External Secrets Operator says of it.
     */
    #[BodyParameter('region_id', 'The region (ULID)', required: true, type: 'string')]
    #[OpenApiResponse(200, 'The Ready condition of the store\'s SecretStore in the region', type: 'array{data: array{status: "ready"|"failed"|"pending", message: string|null, region_id: string}}')]
    #[OpenApiResponse(422, 'External Secrets Operator does not run in the region', type: 'array{message: string}')]
    #[OpenApiResponse(503, 'The region did not answer', type: 'array{message: string}')]
    public function verify(Request $request, SecretStore $secretStore): JsonResponse
    {
        $region = $this->region($request);
        if (! RegionSecretSetting::forRegion($region)->esoReady()) {
            return response()->json(['message' => __('api.secret.eso_not_installed', ['region' => $region->name])], 422);
        }

        try {
            $ready = app(ClusterIssuer::class)->verify($secretStore, $region);
        } catch (\Throwable $e) {
            return response()->json(['message' => __('api.secret.region_unreachable', ['region' => $region->name])], 503);
        }
        $secretStore->recordStatus((string) $region->id, $ready['status'], $ready['message']);

        return response()->json(['data' => $ready + ['region_id' => $region->id]]);
    }

    private function region(Request $request): KnativeRegion
    {
        $id = $request->input('region_id', $request->query('region_id'));
        Validator::make(['region_id' => $id], ['region_id' => ['required', 'string']])->validate();

        return KnativeRegion::query()->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?SecretStore $store): array
    {
        $provider = $store->provider ?? (string) $request->input('provider');
        $known = SecretStore::SETTINGS[$provider] ?? [];

        $rules = [
            'name' => [$store ? 'sometimes' : 'required', 'string', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/',
                Rule::unique('secret_stores', 'name')->ignore($store?->id)],
            'provider' => $store ? ['prohibited'] : ['required', Rule::in(array_keys(SecretStore::PROVIDERS))],
            'auth' => ['sometimes', 'nullable', Rule::in(SecretStore::PROVIDERS[$provider] ?? [])],
            'settings' => [$store ? 'sometimes' : 'required', 'array'],
            'refresh_interval' => ['sometimes', 'nullable', 'integer', 'min:60', 'max:86400'],
        ];
        foreach ($known as $key => $required) {
            $rules["settings.{$key}"] = [$required && ! $store ? 'required' : 'nullable', 'string', 'max:'.($key === 'ca_bundle' ? 16384 : 512)];
        }
        $rules['settings.server'][] = 'url:http,https';
        $rules['settings.vault_url'][] = 'url:https';
        $rules['settings.kv_version'][] = Rule::in(['v1', 'v2']);
        $rules['settings.role_arn'][] = 'regex:/^arn:aws[a-z-]*:iam::\d{12}:role\/.+$/';

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request, $known, $provider) {
            // Only the provider's settings: a store authenticates with the region's identity,
            // RunR takes no credential (token, password, key) for it
            foreach (array_keys((array) $request->input('settings', [])) as $key) {
                if (! array_key_exists((string) $key, $known)) {
                    $validator->errors()->add("settings.{$key}", __('api.secret_store.unknown_setting', ['key' => $key, 'provider' => $provider]));
                }
            }
            $bundle = $request->input('settings.ca_bundle');
            if (is_string($bundle) && $bundle !== '' && (! str_contains($bundle, 'BEGIN CERTIFICATE') || str_contains($bundle, 'PRIVATE KEY'))) {
                $validator->errors()->add('settings.ca_bundle', __('api.secret_store.ca_bundle_invalid'));
            }
        });

        return $validator->validate();
    }

    /**
     * The provider's settings, trimmed, empty ones dropped.
     *
     * @return array<string, string>
     */
    private function settings(string $provider, array $settings): array
    {
        $out = [];
        foreach (array_keys(SecretStore::SETTINGS[$provider] ?? []) as $key) {
            $value = $settings[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $out[$key] = trim($value);
            }
        }

        return $out;
    }
}
