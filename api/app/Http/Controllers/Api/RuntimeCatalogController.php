<?php

namespace App\Http\Controllers\Api;

use App\Models\RuntimeSource;
use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Runtimes\Sources\BuiltinOffer;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The built-in runtimes (docs/runtimes.md#built-in-runtimes), runtimes.manage: which catalog is in
 * use — RunR's signed catalog as last read, or the API image's offline fallback — and the platform
 * setting Built-in runtimes (every runtime of the catalog, also those it gains later, or a
 * selection), which replaces EXECUTOR_RUNTIMES.
 */
class RuntimeCatalogController extends Controller
{
    public function __construct(
        private readonly BuiltinCatalog $catalog,
        private readonly RuntimeRegistry $registry,
    ) {}

    /** Show the built-in catalog and the Built-in runtimes setting */
    #[OpenApiResponse(200, 'The catalog in use, RunR\'s catalog source, the setting', type: 'array{data: array{mode: "source"|"bundle", source: '.RuntimeSource::OPENAPI_TYPE.', bundle: list<string>, builtin_runtimes: list<string>|null, saved: bool, runtimes: list<array{id: string, name: string, offered: bool, in_bundle: bool}>}}')]
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->state()]);
    }

    /**
     * Change the Built-in runtimes setting
     *
     * `builtin_runtimes`: null offers every runtime of the catalog (also one it gains later), a list
     * only those. A built-in left out is not offered for new projects; its projects keep deploying.
     */
    #[OpenApiResponse(200, 'The catalog and the saved setting', type: 'array{data: array{mode: "source"|"bundle", source: '.RuntimeSource::OPENAPI_TYPE.', bundle: list<string>, builtin_runtimes: list<string>|null, saved: bool, runtimes: list<array{id: string, name: string, offered: bool, in_bundle: bool}>}}')]
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'builtin_runtimes' => ['present', 'nullable', 'array', 'max:200'],
            'builtin_runtimes.*' => ['string', 'max:32'],
        ]);

        $ids = $data['builtin_runtimes'];
        if ($ids !== null) {
            $known = array_unique(array_merge(array_keys($this->catalog->all()), array_keys($this->catalog->bundled())));
            $unknown = array_values(array_diff($ids, $known));
            if ($unknown !== []) {
                throw ValidationException::withMessages(['builtin_runtimes' => [__('api.runtimes.unknown', ['id' => $unknown[0]])]]);
            }
        }

        BuiltinOffer::save($ids);
        $this->registry->forget();

        return response()->json(['data' => $this->state()]);
    }

    private function state(): array
    {
        $offered = $this->registry->builtinIds();
        $bundle = array_keys($this->catalog->bundled());

        return [
            'mode' => $this->catalog->mode(),
            'source' => RuntimeSource::default()->toApi(),
            'bundle' => $bundle,
            'builtin_runtimes' => BuiltinOffer::ids(),
            'saved' => BuiltinOffer::saved(),
            'runtimes' => collect($this->catalog->all())->map(fn ($m, $id) => [
                'id' => (string) $id,
                'name' => $m->name(),
                'offered' => in_array($id, $offered, true),
                'in_bundle' => in_array($id, $bundle, true),
            ])->values()->all(),
        ];
    }
}
