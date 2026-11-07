<?php

namespace App\Services\Runtimes\Sources;

use App\Jobs\DeleteRuntimeSourceSecretsJob;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\RuntimePatch;
use App\Models\RuntimePatchFeed;
use App\Models\RuntimeSource;
use App\Services\Runtimes\RuntimeLifecycle;
use App\Services\Runtimes\RuntimeManifest;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Connecting, changing and removing runtime sources (RuntimeSourceController, MCP, docs/runtimes.md#runtime-sources):
 *
 *  - holders of runtimes.manage connect platform sources (every organization gets their runtimes);
 *  - holders of runtimes.use_custom — while the platform allows custom runtimes — connect sources
 *    of their organization (its members only);
 *  - RunR's catalog (the default source) is the installation's: only whether it is read and how
 *    often can change; it can not be removed.
 *
 * Removing a source removes its runtimes that no project uses; the others stay, orphaned — hidden
 * from new projects, running and deploying until their sunset (RuntimeLifecycle).
 */
class RuntimeSources
{
    /** Fields of a source the API writes (credentials: SourceCredentials::FIELDS) */
    public const FIELDS = ['name', 'kind', 'url', 'ref', 'path', 'public_key', 'patch_feed', 'registry_host', 'interval_minutes', 'is_enabled'];

    public function __construct(private readonly RuntimeRegistry $registry) {}

    /** The scope the current user connects sources in, null when they may not. */
    public static function scopeForUser(): ?string
    {
        return match (true) {
            Gate::allows('runtimes.manage') => RuntimeSource::SCOPE_PLATFORM,
            Gate::allows('runtimes.use_custom') => RuntimeSource::SCOPE_ORGANIZATION,
            default => null,
        };
    }

    public static function canView(RuntimeSource $source): bool
    {
        if (Gate::allows('runtimes.manage')) {
            return true;
        }

        return $source->isOrganization() && Gate::allows('runtimes.use_custom')
            && $source->tenant_id === RuntimeRegistry::currentTenantId();
    }

    /** The sources the current user sees: all of them for runtimes.manage, else the organization's. */
    public static function visible()
    {
        $query = RuntimeSource::query()->orderByDesc('is_default')->orderBy('scope')->orderBy('name');
        if (! Gate::allows('runtimes.manage')) {
            $query->where('scope', RuntimeSource::SCOPE_ORGANIZATION)
                ->where(fn ($q) => ($tenant = RuntimeRegistry::currentTenantId()) === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $tenant));
        }

        return $query->get();
    }

    /**
     * Connect a source (not read yet: RuntimeSourceSync does that).
     *
     * @param  array<string, mixed>  $input  FIELDS and the credential fields
     *
     * @throws ValidationException
     */
    public function create(array $input, string $scope, ?string $userId): RuntimeSource
    {
        $tenantId = $scope === RuntimeSource::SCOPE_ORGANIZATION ? RuntimeRegistry::currentTenantId() : null;

        if ($scope === RuntimeSource::SCOPE_ORGANIZATION) {
            $max = (int) config('runtime_sources.max_per_organization', 10);
            $count = RuntimeSource::query()->where('scope', $scope)->where('tenant_id', $tenantId)->count();
            if ($count >= $max) {
                throw ValidationException::withMessages(['name' => [__('api.runtime_sources.too_many', ['max' => $max])]]);
            }
        }

        $source = new RuntimeSource([
            'scope' => $scope,
            'tenant_id' => $tenantId,
            'created_by' => $userId,
            'interval_minutes' => (int) config('runtime_sources.interval_minutes', 60),
            'path' => null,
        ]);
        $this->fill($source, $input, true);
        $this->checkName($source);
        $source->save();

        return $source;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(RuntimeSource $source, array $input): RuntimeSource
    {
        if ($source->is_default) {
            $other = array_diff(array_keys(array_intersect_key($input, array_flip(array_merge(self::FIELDS, array_keys(SourceCredentials::FIELDS))))), ['is_enabled', 'interval_minutes']);
            if ($other !== []) {
                throw ValidationException::withMessages([$other[array_key_first($other)] => [__('api.runtime_sources.default_readonly')]]);
            }
        }

        $before = [$source->kind, $source->url, $source->ref, $source->path, $source->public_key, $source->registry_host, $source->credentials];
        $hadSecrets = $source->hasRegistryCredentials() || $source->credential('git', 'token') !== null;
        $this->fill($source, $input, false);
        $this->checkName($source);

        // A new location, key or credentials: the next sync reads the source anew
        if ($before !== [$source->kind, $source->url, $source->ref, $source->path, $source->public_key, $source->registry_host, $source->credentials]) {
            $source->revision = null;
            $source->rejected_revision = null;
        }
        $source->save();

        // credentials removed: their pull Secrets leave the regions (new ones are applied before the next pull)
        if ($hadSecrets && (! $source->hasRegistryCredentials() || $source->credential('git', 'token') === null)) {
            self::dispatchCleanup($source->id);
        }

        return $source;
    }

    /**
     * Remove a source: its runtimes without projects go, the others stay orphaned; its patches go.
     *
     * @throws ValidationException RunR's catalog
     */
    public function delete(RuntimeSource $source): void
    {
        if ($source->is_default) {
            throw ValidationException::withMessages(['source' => [__('api.runtime_sources.default_delete')]]);
        }

        foreach (Runtime::query()->where('source_id', $source->id)->get() as $runtime) {
            if (Project::query()->where('runtime', $runtime->id)->exists()) {
                $runtime->forceFill(['source_id' => null, 'orphaned_at' => $runtime->orphaned_at ?? now()])->save();
                $this->registry->forget($runtime->id);
                if ($manifest = ($runtime->manifest ? new RuntimeManifest($runtime->manifest) : null)) {
                    app(RuntimeLifecycle::class)->apply($runtime, $manifest);
                }
            } else {
                $this->registry->delete($runtime);
            }
        }

        DB::transaction(function () use ($source) {
            RuntimePatch::query()->where('source_id', $source->id)->delete();
            RuntimePatchFeed::query()->where('source_id', $source->id)->delete();
            $source->delete();
        });

        if ($source->hasRegistryCredentials() || $source->credential('git', 'token') !== null) {
            self::dispatchCleanup($source->id);
        }
    }

    /** The source's pull Secrets out of every region (DeleteRuntimeSourceSecretsJob). */
    private static function dispatchCleanup(string $sourceId): void
    {
        try {
            DeleteRuntimeSourceSecretsJob::dispatch($sourceId);
        } catch (\Throwable $e) {
            Log::warning('Runtime source pull secret cleanup not dispatched', ['source' => $sourceId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * A source as given, for Test connection: the input over the stored source ($base) or a new
     * one; never saved.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function draft(array $input, ?RuntimeSource $base, string $scope): RuntimeSource
    {
        $draft = $base ? $base->replicate(['id']) : new RuntimeSource(['scope' => $scope, 'tenant_id' => $scope === RuntimeSource::SCOPE_ORGANIZATION ? RuntimeRegistry::currentTenantId() : null]);
        if ($base) {
            $draft->id = $base->id;
            $draft->exists = false;
            $draft->status = RuntimeSource::NEVER;
            $draft->revision = null;
        }
        $this->fill($draft, $input + ($base ? [] : ['name' => 'test']), $base === null, $base !== null && $base->is_default);

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function fill(RuntimeSource $source, array $input, bool $creating, bool $readonlyDefault = false): void
    {
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $input) || ($readonlyDefault && ! in_array($field, ['is_enabled', 'interval_minutes'], true))) {
                continue;
            }
            $value = $input[$field];
            $source->{$field} = match ($field) {
                'interval_minutes' => max((int) config('runtime_sources.min_interval_minutes', 5), (int) $value),
                'is_enabled' => (bool) $value,
                'url', 'name' => trim((string) $value),
                default => $value === null || $value === '' ? null : trim(str_replace("\r\n", "\n", (string) $value)),
            };
        }

        if (! $source->is_default) {
            $source->credentials = SourceCredentials::merge($source->credentials, $input);
            $this->check($source, $creating);
        }
    }

    /**
     * @throws ValidationException
     */
    private function check(RuntimeSource $source, bool $creating): void
    {
        $errors = [];
        if (! in_array($source->kind, RuntimeSource::KINDS, true)) {
            $errors['kind'][] = 'must be oci-catalog or git';
        } elseif ($problem = SourceLocation::problem($source->kind, (string) $source->url)) {
            $errors['url'][] = $problem;
        }
        if ($source->kind === RuntimeSource::KIND_GIT) {
            if ($problem = SourceLocation::refProblem($source->ref)) {
                $errors['ref'][] = $problem;
            }
            if ($problem = SourceLocation::pathProblem($source->path)) {
                $errors['path'][] = $problem;
            }
            $ssh = SourceLocation::isSsh((string) $source->url);
            if (($ssh && $source->credential('git', 'token') !== null) || (! $ssh && $source->credential('git', 'ssh_key') !== null)) {
                $errors['credentials'][] = __('api.runtime_sources.credentials_kind');
            }
        } else {
            $source->ref = null;
            $source->path = null;
            if ($source->credential('git', 'token') !== null || $source->credential('git', 'ssh_key') !== null) {
                $errors['credentials'][] = 'Git credentials only apply to a git source';
            }
        }
        if ($source->public_key !== null && openssl_pkey_get_public($source->public_key) === false) {
            $errors['public_key'][] = 'is not a PEM public key (cosign.pub)';
        }
        if ($source->patch_feed !== null && ($problem = SourceLocation::problem(RuntimeSource::KIND_OCI, $source->patch_feed))) {
            $errors['patch_feed'][] = $problem;
        }
        if ($source->patch_feed !== null && $source->public_key === null) {
            $errors['patch_feed'][] = 'needs the public key it is signed with';
        }
        if ($source->registry_host !== null && ! preg_match('/^[a-z0-9.-]+(:[0-9]+)?$/i', $source->registry_host)) {
            $errors['registry_host'][] = 'must be a registry host (ghcr.io, registry.example.com:5000)';
        }
        $user = $source->credential('registry', 'username');
        $password = $source->credential('registry', 'password');
        if (($user === null) !== ($password === null)) {
            $errors[$user === null ? 'registry_username' : 'registry_password'][] = 'is required with the other registry credential';
        }
        if ($source->hasRegistryCredentials() && $source->kind === RuntimeSource::KIND_GIT && $source->registry_host === null) {
            $errors['registry_host'][] = 'is required for registry credentials of a git source (the registry of its images)';
        }
        if ($creating && trim((string) $source->name) === '') {
            $errors['name'][] = 'is required';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @throws ValidationException
     */
    private function checkName(RuntimeSource $source): void
    {
        $taken = RuntimeSource::query()
            ->where('name', $source->name)
            ->where('scope', $source->scope)
            ->where(fn ($q) => $source->tenant_id === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $source->tenant_id))
            ->when($source->exists, fn ($q) => $q->whereKeyNot($source->getKey()))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => [__('api.runtime_sources.name_taken', ['name' => $source->name])]]);
        }
    }
}
