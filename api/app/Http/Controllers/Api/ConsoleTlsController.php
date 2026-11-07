<?php

namespace App\Http\Controllers\Api;

use App\Services\CertManager\ConsoleCertificate;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/console-tls (@operator: platform administrators; single-tenant, holders of
 * platform.manage) — the console's own certificate: whether cert-manager issued it
 * (App\Services\CertManager\ConsoleCertificate,
 * docs/install.md#when-the-console-certificate-is-not-issued). Their banner
 * (GET /runtime-patches/banners, kind console_tls) shows the same state when it cannot be issued.
 */
class ConsoleTlsController extends Controller
{
    /** Show whether cert-manager issued the console's certificate */
    #[OpenApiResponse(200, 'The console certificate: ingress.tls.mode, the ClusterIssuer and TLS Secret, and the state of the cert-manager Certificate (checked at most every five minutes)', type: 'array{data: array{'.ConsoleCertificate::OPENAPI_FIELDS.'}}')]
    public function show(ConsoleCertificate $certificate): JsonResponse
    {
        return response()->json(['data' => $certificate->status()])->header('Cache-Control', 'no-store, private');
    }
}
