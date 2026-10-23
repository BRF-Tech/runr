<?php

namespace App\Http\Controllers\Api;

use App\Models\ImportedHistoryRecord;
use App\Models\OrgTransfer;
use App\Models\Project;
use App\Services\OrgTransfer\ImportChoices;
use App\Services\OrgTransfer\OrgTransferPaths;
use App\Services\OrgTransfer\OrgTransferService;
use App\Services\OrgTransfer\TransferLimitReached;
use App\Services\OrgTransfer\TransferRequestException;
use App\Support\Rbac\Rbac;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Organization export / import (docs/org-export.md). Every route needs `org.transfer`
 * (config/route_permissions.php) and the Owner role of the organization: a package holds the
 * organization's code, configuration and — when asked for — its data and (encrypted) secrets.
 *
 *   GET    /org-transfers                       the organization's latest transfers
 *   POST   /org-transfers/export/estimate       what an export would hold, sizes
 *   POST   /org-transfers/export                202, queued (progress: org_transfer.progress)
 *   GET    /org-transfers/{transfer}            status, progress, package, plan, report
 *   GET    /org-transfers/{transfer}/download   the package (Range: bytes=N- resumes)
 *   DELETE /org-transfers/{transfer}            cancel / remove the files
 *   POST   /org-transfers/import                {filename, size, sha256?} → 201, chunks next;
 *                                               {source: binding | s3, sha256?} → 202, RunR reads it
 *   PUT    /org-transfers/{transfer}/chunk      ?offset=N, raw bytes
 *   POST   /org-transfers/{transfer}/complete   202: verify + default plan in the background
 *   POST   /org-transfers/{transfer}/fetch      202: resume a fetch that failed (S3: credentials again)
 *   GET    /org-transfers/{transfer}/history    the usage records and bills an import kept as history
 *   POST   /org-transfers/{transfer}/plan       the dry-run plan for the choices
 *   POST   /org-transfers/{transfer}/apply      202: create everything (also resumes a failed apply)
 *
 * @tags Organization transfer
 */
class OrgTransferController extends Controller
{
    /** The API document's fields of a transfer (OrgTransfer::toApi()) before its plan and report */
    private const TRANSFER_FIELDS = 'id: string, kind: "export"|"import", status: "queued"|"running"|"uploading"|"fetching"|"verifying"|"planned"|"applying"|"completed"|"failed"|"cancelled"|"expired", phase: string|null, progress: int, message: string|null, options: array<string, mixed>, estimate: array<string, mixed>|null, destination: array<string, mixed>|null, package: array{filename: string|null, size_bytes: int|null, sha256: string|null, schema_version: int|null, runr_version: string|null, source: array<string, mixed>|null, downloadable: bool}|null, upload: array{size_bytes: int|null, uploaded_bytes: int, chunk_size: int|null, sha256: string|null}|null, source: array<string, mixed>|null';

    /** The fields after plan and report */
    private const TRANSFER_TAIL = 'created_by: array{id: string, name: string|null}|null, created_at: string|null, updated_at: string|null, finished_at: string|null, expires_at: string|null';

    /** The API document's shape of a transfer (OrgTransfer::toApi()) */
    private const TRANSFER_TYPE = 'array{'.self::TRANSFER_FIELDS.', plan: array<string, mixed>|null, report: array<string, mixed>|null, '.self::TRANSFER_TAIL.'}';

    /** The API document's shape of what is exported (OrgExporter::estimate()) */
    private const ESTIMATE_TYPE = 'array{projects: int, versions: int, history: array{deployments: int, workflow_runs: int, trigger_events: int}|null, code_bytes: int, bindings: list<array{id: string, name: string, type: string, plan: string|null, region: string|null, usage_bytes: int, data_supported: bool, data: bool}>, binding_data_bytes: int, images: array{count: int, bytes: null}, total_bytes: int, warnings: list<string>}';

    /** The API document's shape of the export options of the body (exportRules()) */
    private const DESTINATION_TYPE = 'array{type: "download"|"binding"|"s3", binding_id?: string|null, prefix?: string|null, endpoint?: string|null, bucket?: string|null, region?: string|null, path_style?: bool, access_key_id?: string|null, secret_access_key?: string|null}';

    public function __construct(private OrgTransferService $transfers) {}

    #[OpenApiResponse(200, 'The organization\'s transfers, their plan and report summed up', type: 'array{data: list<array{'.self::TRANSFER_FIELDS.', plan: array{blocking: int, warnings: int, can_apply: bool}|null, report: array{counts: array<string, mixed>|null, entries: list<array<string, mixed>>|null}|null, '.self::TRANSFER_TAIL.'}>}')]
    public function index(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        return response()->json(['data' => $this->transfers->list()->map(fn (OrgTransfer $t) => self::summary($t))->values()]);
    }

    #[BodyParameter('projects', 'Project ids to export; left out: every project', type: 'list<string>|null')]
    #[BodyParameter('include_members', type: 'bool')]
    #[BodyParameter('include_images', type: 'bool')]
    #[BodyParameter('binding_data', 'all, or the ids of the bindings whose data goes along', type: '"all"|list<string>|null')]
    #[BodyParameter('include_secrets', 'Secret values, encrypted with the passphrase', type: 'bool')]
    #[BodyParameter('include_history', 'Deployments, workflow runs, trigger events, usage and bills', type: 'bool')]
    #[OpenApiResponse(200, 'What the export would hold and its size', type: 'array{data: '.self::ESTIMATE_TYPE.'}')]
    public function estimate(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);
        $data = $request->validate($this->exportRules(false));

        return response()->json(['data' => $this->transfers->estimate($this->transfers->exportOptions($data))]);
    }

    /**
     * Starts an export; the options as for …/export/estimate, with the passphrase of the secrets
     * and where the package goes (default: download).
     *
     * @response JsonResponse<array{data: array<string, mixed>}, 202>
     */
    #[BodyParameter('projects', 'Project ids to export; left out: every project', type: 'list<string>|null')]
    #[BodyParameter('include_members', type: 'bool')]
    #[BodyParameter('include_images', type: 'bool')]
    #[BodyParameter('binding_data', 'all, or the ids of the bindings whose data goes along', type: '"all"|list<string>|null')]
    #[BodyParameter('include_secrets', 'Secret values, encrypted with the passphrase', type: 'bool')]
    #[BodyParameter('include_history', 'Deployments, workflow runs, trigger events, usage and bills', type: 'bool')]
    #[BodyParameter('passphrase', 'Required with include_secrets', type: 'string|null')]
    #[BodyParameter('destination', 'Where the package goes: download (default), an object binding of the organization, or an S3-compatible bucket', type: self::DESTINATION_TYPE)]
    #[OpenApiResponse(202, 'The export started', type: 'array{data: '.self::TRANSFER_TYPE.'}')]
    #[OpenApiResponse(429, 'Too many transfers run', type: 'array{message: string, code: "too_many_transfers"}')]
    public function export(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);
        $data = $request->validate($this->exportRules(true));

        [$destination, $credentials] = $this->transfers->destination((array) ($data['destination'] ?? ['type' => 'download']));
        try {
            $transfer = $this->transfers->startExport(
                $this->transfers->exportOptions($data),
                $destination,
                $credentials,
                ! empty($data['include_secrets']) ? (string) $data['passphrase'] : null,
                $request->user(),
            );
        } catch (TransferLimitReached $e) {
            return $e->toResponse();
        }

        return response()->json(['data' => $transfer->fresh()?->toApi()], 202);
    }

    public function show(Request $request, string $transfer): JsonResponse
    {
        $this->authorizeOwner($request);

        return response()->json(['data' => $this->transfer($transfer)->toApi()]);
    }

    /**
     * The package, streamed; `Range: bytes=N-` (or N-M) answers 206 from N.
     *
     * @response \Illuminate\Http\Response<"", 200>
     */
    #[OpenApiResponse(200, 'The package', mediaType: 'application/zstd', type: 'string', format: 'binary')]
    #[OpenApiResponse(206, 'The package from the requested range on', mediaType: 'application/zstd', type: 'string', format: 'binary')]
    #[OpenApiResponse(409, 'The transfer has no package to download', type: 'array{message: string, code: "not_downloadable"}')]
    public function download(Request $request, string $transfer): Response
    {
        $this->authorizeOwner($request);
        $transfer = $this->transfer($transfer);
        $path = OrgTransferPaths::package($transfer);
        if (! $transfer->isDownloadable() || ! is_file($path)) {
            return response()->json(['message' => __('org_transfer.not_downloadable'), 'code' => 'not_downloadable'], 409);
        }

        $size = (int) filesize($path);
        $start = 0;
        $end = $size - 1;
        $status = 200;
        if (preg_match('/^bytes=(\d+)-(\d*)$/', (string) $request->header('Range'), $m)) {
            $start = (int) $m[1];
            $end = $m[2] !== '' ? min((int) $m[2], $size - 1) : $size - 1;
            if ($start >= $size || $start > $end) {
                return response('', 416, ['Content-Range' => "bytes */{$size}"]);
            }
            $status = 206;
        }

        $headers = [
            'Content-Type' => 'application/zstd',
            'Content-Length' => (string) ($end - $start + 1),
            'Content-Disposition' => 'attachment; filename="'.addslashes((string) $transfer->package_filename).'"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-store, private',
            'X-Content-SHA256' => (string) $transfer->package_sha256,
        ];
        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return new StreamedResponse(function () use ($path, $start, $end) {
            $in = fopen($path, 'rb');
            if ($in === false) {
                return;
            }
            fseek($in, $start);
            $left = $end - $start + 1;
            while ($left > 0 && ! feof($in)) {
                $chunk = fread($in, min(1024 * 1024, $left));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $left -= strlen($chunk);
                flush();
            }
            fclose($in);
        }, $status, $headers);
    }

    public function destroy(Request $request, string $transfer): Response
    {
        $this->authorizeOwner($request);
        $this->transfers->cancel($this->transfer($transfer));

        return response()->noContent();
    }

    /**
     * POST …/import: {filename, size, sha256?} opens an upload (201, chunks next); {source,
     * sha256?} lets RunR read the package from an object binding of the organization or an
     * S3-compatible bucket — the export's destinations — in the background, resumable (202).
     *
     * @response JsonResponse<array{data: array<string, mixed>}, 201>
     */
    #[BodyParameter('size', 'The package\'s size in bytes (an upload)', type: 'int|null')]
    #[BodyParameter('source', 'Instead of an upload: the object binding or S3-compatible bucket RunR reads the package from (202)', type: 'array{type: "binding"|"s3", binding_id?: string|null, key: string, endpoint?: string|null, bucket?: string|null, region?: string|null, path_style?: bool, access_key_id?: string|null, secret_access_key?: string|null}')]
    #[OpenApiResponse(201, 'The upload is open: send the chunks (PUT …/chunk), then POST …/complete', type: 'array{data: '.self::TRANSFER_TYPE.'}')]
    #[OpenApiResponse(202, 'RunR reads the package from the source', type: 'array{data: '.self::TRANSFER_TYPE.'}')]
    #[OpenApiResponse(429, 'Too many transfers run', type: 'array{message: string, code: "too_many_transfers"}')]
    public function import(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);
        $max = (int) config('org_transfer.max_package_gb', 50) * 1024 * 1024 * 1024;
        $data = $request->validate([
            'filename' => 'required_without:source|nullable|string|max:255',
            'size' => "required_without:source|nullable|integer|min:1|max:{$max}",
            'sha256' => 'nullable|string|regex:/^[A-Fa-f0-9]{64}$/',
            'source' => 'sometimes|array',
            'source.type' => ['required_with:source', Rule::in(['binding', 's3'])],
            'source.binding_id' => ['required_if:source.type,binding', 'nullable', 'string', Rule::exists('bindings', 'id')->where('type', 'object')],
            'source.key' => ['required_with:source', 'nullable', 'string', 'max:1024', 'regex:/^[^\x00-\x1f\x7f]+$/', 'not_regex:#(^|/)\.\.(/|$)#'],
            'source.endpoint' => 'required_if:source.type,s3|nullable|url:https,http|max:500',
            'source.bucket' => 'required_if:source.type,s3|nullable|string|max:63|regex:/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/',
            'source.region' => 'sometimes|nullable|string|max:64',
            'source.path_style' => 'sometimes|boolean',
            'source.access_key_id' => 'required_if:source.type,s3|nullable|string|max:256',
            'source.secret_access_key' => 'required_if:source.type,s3|nullable|string|max:512',
        ]);

        try {
            if (isset($data['source'])) {
                [$source, $credentials] = $this->transfers->source((array) $data['source']);
                $transfer = $this->transfers->startFetch($source, $credentials, $data['sha256'] ?? null, $request->user());

                return response()->json(['data' => $transfer->fresh()?->toApi()], 202);
            }
            $transfer = $this->transfers->startImport((string) $data['filename'], (int) $data['size'], $data['sha256'] ?? null, $request->user());
        } catch (TransferLimitReached $e) {
            return $e->toResponse();
        }

        return response()->json(['data' => $transfer->toApi()], 201);
    }

    /**
     * POST …/fetch — resume reading the package of an import from its source.
     *
     * @response JsonResponse<array{data: array<string, mixed>}, 202>
     */
    #[OpenApiResponse(202, 'RunR reads the package again', type: 'array{data: '.self::TRANSFER_TYPE.'}')]
    #[OpenApiResponse(409, 'Only an import whose fetch failed or stalled is resumed', type: 'array{message: string, code: "not_resumable"}')]
    #[OpenApiResponse(422, 'The bucket\'s credentials are needed again', type: 'array{message: string, code: "s3_credentials"}')]
    #[OpenApiResponse(429, 'Too many transfers run', type: 'array{message: string, code: "too_many_transfers"}')]
    public function fetch(Request $request, string $transfer): JsonResponse
    {
        $this->authorizeOwner($request);
        $data = $request->validate([
            'access_key_id' => 'sometimes|nullable|string|max:256',
            'secret_access_key' => 'sometimes|nullable|string|max:512',
        ]);
        $credentials = ! empty($data['access_key_id']) || ! empty($data['secret_access_key'])
            ? ['access_key_id' => (string) ($data['access_key_id'] ?? ''), 'secret_access_key' => (string) ($data['secret_access_key'] ?? '')]
            : null;

        try {
            $transfer = $this->transfers->resumeFetch($this->transfer($transfer), $credentials, $request->user());
        } catch (TransferRequestException $e) {
            return $e->toResponse();
        } catch (TransferLimitReached $e) {
            return $e->toResponse();
        }

        return response()->json(['data' => $transfer->toApi()], 202);
    }

    /**
     * GET …/history?kind=usage|bill&project=&per_page= — the usage records and bills of the source
     * organization an import kept, read-only (docs/org-export.md#run-history): never metered or
     * billed here.
     */
    #[OpenApiResponse(200, 'A page of the kept records (an export, or an import without history: none)', type: 'array{data: list<array{id: string, kind: "usage"|"bill", source_table: string|null, project_id: string|null, source_project_id: string|null, subject: string|null, period_date: string|null, data: array<string, mixed>}>, meta: array{total: int, current_page?: int, last_page?: int, per_page?: int}}')]
    public function history(Request $request, string $transfer): JsonResponse
    {
        $this->authorizeOwner($request);
        $data = $request->validate([
            'kind' => ['sometimes', Rule::in([ImportedHistoryRecord::KIND_USAGE, ImportedHistoryRecord::KIND_BILL])],
            'page' => 'sometimes|integer|min:1',
            'project' => 'sometimes|string|max:64',
            'per_page' => 'sometimes|integer|min:1|max:500',
        ]);
        $transfer = $this->transfer($transfer);
        if ($transfer->isExport() || ! Schema::hasTable('imported_history')) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $page = ImportedHistoryRecord::query()->where('org_transfer_id', $transfer->id)
            ->when($data['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($data['project'] ?? null, fn ($q, $project) => $q->where(fn ($w) => $w->where('project_id', $project)->orWhere('source_project_id', $project)))
            ->orderBy('period_date')->orderBy('id')
            ->paginate((int) ($data['per_page'] ?? 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (ImportedHistoryRecord $r) => $r->toApi())->values(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()],
        ]);
    }

    /**
     * PUT …/chunk?offset=N — raw body, at most chunk_size bytes
     *
     * @response JsonResponse<array{uploaded_bytes: int, size_bytes: int}, 200>
     */
    #[OpenApiResponse(409, 'The transfer does not take an upload', type: 'array{message: string, code: "not_uploading", status: string}')]
    #[OpenApiResponse(422, 'The chunk does not start where the upload stands', type: 'array{message: string, code: "bad_chunk", uploaded_bytes: int}')]
    #[OpenApiResponse(500, 'The chunk was not written', type: 'array{message: string, code: "write_failed"}')]
    public function chunk(Request $request, string $transfer): JsonResponse
    {
        $this->authorizeOwner($request);
        $data = $request->validate([
            /** @query */
            'offset' => 'required|integer|min:0',
        ]);
        $transfer = $this->transfer($transfer);
        if ($transfer->status !== OrgTransfer::STATUS_UPLOADING) {
            return response()->json(['message' => __('org_transfer.upload.not_uploading', ['status' => $transfer->status]), 'code' => 'not_uploading', 'status' => $transfer->status], 409);
        }

        try {
            return response()->json($this->transfers->chunk($transfer, (int) $data['offset'], $request->getContent()));
        } catch (TransferRequestException $e) {
            return $e->toResponse();
        }
    }

    /**
     * The upload is complete: the package is verified and planned in the background.
     *
     * @response JsonResponse<array{data: array<string, mixed>}, 202>
     */
    #[OpenApiResponse(202, 'The package is being verified', type: 'array{data: '.self::TRANSFER_TYPE.'}')]
    #[OpenApiResponse(409, 'The transfer does not take an upload', type: 'array{message: string, code: "not_uploading", status: string}')]
    #[OpenApiResponse(422, 'The upload is incomplete or its checksum does not match', type: 'array{message: string, code: "upload_incomplete"|"checksum_mismatch", uploaded_bytes: int}')]
    public function complete(Request $request, string $transfer): JsonResponse
    {
        $this->authorizeOwner($request);
        $transfer = $this->transfer($transfer);
        if ($transfer->status !== OrgTransfer::STATUS_UPLOADING) {
            return response()->json(['message' => __('org_transfer.upload.not_uploading', ['status' => $transfer->status]), 'code' => 'not_uploading', 'status' => $transfer->status], 409);
        }

        try {
            $transfer = $this->transfers->complete($transfer);
        } catch (TransferRequestException $e) {
            return $e->toResponse();
        }

        return response()->json(['data' => $transfer->toApi()], 202);
    }

    /**
     * Plans the import again with the choices (region map, renames, projects to skip, …).
     *
     * @response JsonResponse<array{data: array<string, mixed>}, 200>
     */
    #[BodyParameter('region_map', 'Source region => region here; default: the same id, else the same name, else the organization\'s default region', type: 'array<string, string|null>')]
    #[BodyParameter('renames', 'Source project slug => new slug', type: 'array<string, string|null>')]
    #[BodyParameter('binding_renames', 'Source binding name => new name', type: 'array<string, string|null>')]
    #[BodyParameter('skip_projects', 'Source projects left out', type: 'list<string>')]
    #[BodyParameter('passphrase', 'The passphrase of the package\'s secrets', type: 'string|null')]
    #[BodyParameter('restore_data', 'Restore the bindings\' data the package holds (default true)', type: 'bool')]
    #[BodyParameter('skip_binding_data', 'Bindings whose data is not restored', type: 'list<string>')]
    #[BodyParameter('history', 'Import the run history as history that never acts (default true)', type: 'bool')]
    #[OpenApiResponse(200, 'The transfer with its new plan', type: 'array{data: '.self::TRANSFER_TYPE.'}')]
    #[OpenApiResponse(409, 'The transfer cannot be planned now', type: 'array{message: string, code: string}')]
    #[OpenApiResponse(422, 'The package or the choices do not plan', type: 'array{message: string, code: string}')]
    public function plan(Request $request, string $transfer): JsonResponse
    {
        $this->authorizeOwner($request);
        $data = $request->validate($this->choiceRules());
        $transfer = $this->transfer($transfer);

        try {
            $transfer = $this->transfers->plan($transfer, $this->choices($transfer, $data), $request->user());
        } catch (TransferRequestException $e) {
            return $e->toResponse();
        }

        return response()->json(['data' => $transfer->toApi()]);
    }

    /**
     * Applies the import with the choices of …/plan and what goes along.
     *
     * @response JsonResponse<array{data: array<string, mixed>}, 202>
     */
    #[BodyParameter('region_map', 'Source region => region here; default: the same id, else the same name, else the organization\'s default region', type: 'array<string, string|null>')]
    #[BodyParameter('renames', 'Source project slug => new slug', type: 'array<string, string|null>')]
    #[BodyParameter('binding_renames', 'Source binding name => new name', type: 'array<string, string|null>')]
    #[BodyParameter('skip_projects', 'Source projects left out', type: 'list<string>')]
    #[BodyParameter('passphrase', 'The passphrase of the package\'s secrets', type: 'string|null')]
    #[BodyParameter('restore_data', 'Restore the bindings\' data the package holds (default true)', type: 'bool')]
    #[BodyParameter('skip_binding_data', 'Bindings whose data is not restored', type: 'list<string>')]
    #[BodyParameter('history', 'Import the run history as history that never acts (default true)', type: 'bool')]
    #[BodyParameter('images', 'Restore the container images the package holds', type: 'bool')]
    #[BodyParameter('invite_members', 'Invite the package\'s members', type: 'bool')]
    #[BodyParameter('deploy', 'Deploy the imported projects', type: 'bool')]
    #[OpenApiResponse(202, 'The import is being applied', type: 'array{data: '.self::TRANSFER_TYPE.'}')]
    #[OpenApiResponse(422, 'The plan has blocking problems', type: 'array{message: string, code: string, data?: '.self::TRANSFER_TYPE.'}')]
    #[OpenApiResponse(429, 'Too many transfers run', type: 'array{message: string, code: "too_many_transfers"}')]
    public function apply(Request $request, string $transfer): JsonResponse
    {
        $this->authorizeOwner($request);
        // in a variable: the API document's generator cannot evaluate choiceRules() + [...] (#[BodyParameter])
        $rules = $this->choiceRules() + [
            'images' => 'sometimes|boolean',
            'invite_members' => 'sometimes|boolean',
            'deploy' => 'sometimes|boolean',
        ];
        $data = $request->validate($rules);
        $transfer = $this->transfer($transfer);

        try {
            $transfer = $this->transfers->apply($transfer, $this->choices($transfer, $data), $request->user());
        } catch (TransferRequestException $e) {
            return $e->toResponse();
        } catch (TransferLimitReached $e) {
            return $e->toResponse();
        }

        return response()->json(['data' => $transfer->toApi()], 202);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Owners only — an export can hold the organization's data; admins hold `org.transfer`
     * through their role too but are refused here.
     */
    private function authorizeOwner(Request $request): void
    {
        abort_unless(app(Rbac::class)->isOwner($request->user()), response()->json([
            'message' => __('org_transfer.owner_only'),
            'code' => 'owner_only',
        ], 403));
    }

    private function transfer(string $id): OrgTransfer
    {
        return $this->transfers->find($id) ?? abort(404);
    }

    private function choices(OrgTransfer $transfer, array $data): ImportChoices
    {
        return ImportChoices::from($data, ImportChoices::from((array) $transfer->options));
    }

    /** @return array<string, mixed> */
    private function exportRules(bool $withDestination): array
    {
        $min = (int) config('org_transfer.passphrase_min_length', 12);
        $rules = [
            'projects' => 'sometimes|nullable|array|max:1000',
            'projects.*' => 'string|max:64',
            'include_members' => 'sometimes|boolean',
            'include_images' => 'sometimes|boolean',
            'binding_data' => ['sometimes', 'nullable', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== 'all' && ! is_array($value)) {
                    $fail(__('org_transfer.validation.binding_data'));
                }
            }],
            'binding_data.*' => 'string|max:64',
            'include_secrets' => 'sometimes|boolean',
            'include_history' => 'sometimes|boolean',
        ];
        if (! $withDestination) {
            return $rules;
        }

        return $rules + [
            'passphrase' => "required_if_accepted:include_secrets|nullable|string|min:{$min}|max:1024",
            'destination' => 'sometimes|array',
            'destination.type' => ['required_with:destination', Rule::in(['download', 'binding', 's3'])],
            'destination.binding_id' => ['required_if:destination.type,binding', 'nullable', 'string', Rule::exists('bindings', 'id')->where('type', 'object')],
            'destination.prefix' => 'sometimes|nullable|string|max:200|regex:/^[A-Za-z0-9._\/-]*$/',
            'destination.endpoint' => 'required_if:destination.type,s3|nullable|url:https,http|max:500',
            'destination.bucket' => 'required_if:destination.type,s3|nullable|string|max:63|regex:/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/',
            'destination.region' => 'sometimes|nullable|string|max:64',
            'destination.path_style' => 'sometimes|boolean',
            'destination.access_key_id' => 'required_if:destination.type,s3|nullable|string|max:256',
            'destination.secret_access_key' => 'required_if:destination.type,s3|nullable|string|max:512',
        ];
    }

    /** @return array<string, mixed> */
    private function choiceRules(): array
    {
        return [
            'region_map' => 'sometimes|array',
            'region_map.*' => 'nullable|string|max:64',
            'renames' => 'sometimes|array',
            'renames.*' => 'nullable|string|max:'.Project::MAX_SLUG_LENGTH,
            'binding_renames' => 'sometimes|array',
            'binding_renames.*' => 'nullable|string|max:30',
            'skip_projects' => 'sometimes|array',
            'skip_projects.*' => 'string|max:64',
            'passphrase' => 'sometimes|nullable|string|max:1024',
            // Plan and apply alike: the plan checks the data it would restore (KV Redis versions)
            'restore_data' => 'sometimes|boolean',
            'skip_binding_data' => 'sometimes|array',
            'skip_binding_data.*' => 'string|max:64',
            'history' => 'sometimes|boolean',
        ];
    }

    /** List rows without plan and report (they can be large). */
    private static function summary(OrgTransfer $transfer): array
    {
        $data = $transfer->toApi();
        $data['plan'] = $data['plan'] !== null ? ['blocking' => $data['plan']['blocking'] ?? 0, 'warnings' => $data['plan']['warnings'] ?? 0, 'can_apply' => $data['plan']['can_apply'] ?? false] : null;
        $data['report'] = $data['report'] !== null ? ['counts' => $data['report']['counts'] ?? null, 'entries' => $data['report']['entries'] ?? null] : null;

        return $data;
    }
}
