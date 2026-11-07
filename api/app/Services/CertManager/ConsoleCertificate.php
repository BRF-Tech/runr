<?php

namespace App\Services\CertManager;

use App\Services\Helpers\RunrClusterIdentity;
use App\Services\KubernetesClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Can cert-manager issue the console's own certificate?
 * (docs/install.md#when-the-console-certificate-is-not-issued)
 *
 * With ingress.tls.mode letsencrypt (or an ingress.tls.clusterIssuer of the older keys) the chart
 * puts the annotation cert-manager.io/cluster-issuer on the console Ingress; cert-manager's
 * ingress-shim then makes a Certificate named like the TLS Secret in RunR's namespace. When it
 * cannot be issued — the ClusterIssuer does not exist, the ACME order fails — the ingress controller
 * serves its default certificate and nothing else says so. This reads, with RunR's own service
 * account (helm/templates/rbac.yaml: get on that Certificate, list on the CertificateRequests of
 * the namespace), the Certificate's Ready / Issuing conditions and the message of its newest
 * CertificateRequest, for the platform administrators' banner (GET /runtime-patches/banners) and
 * GET /admin/console-tls.
 *
 * The ClusterIssuer itself is not read: a cluster-scoped read would need a ClusterRole rule for
 * clusterissuers, which the identity that installs the chart has to hold itself to grant it
 * (Kubernetes' RBAC escalation check), and cert-manager gives namespace administrators read access
 * to its namespaced kinds only. A missing issuer is read from the CertificateRequest instead:
 * cert-manager leaves it pending with "Referenced "ClusterIssuer" not found: …".
 *
 * States: ok (issued and Ready), pending (being issued), failed (cert-manager cannot issue or
 * renew it), not_applicable (cert-manager does not issue the console certificate, or the cluster
 * has no cert-manager API) and unknown (the check could not read the objects: not in a pod, no
 * permission, API server unreachable). The result is cached for CACHE_TTL seconds; nothing here
 * ever throws.
 */
class ConsoleCertificate
{
    public const OK = 'ok';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public const NOT_APPLICABLE = 'not_applicable';

    public const UNKNOWN = 'unknown';

    /** Reasons (the console words each one, vue/src/locales/<lang>/runtimePatches.js consoleTls) */
    public const ISSUED = 'issued';

    public const ISSUING = 'issuing';

    public const ISSUER_NOT_FOUND = 'issuer_not_found';

    public const REQUEST_FAILED = 'request_failed';

    public const REQUEST_DENIED = 'request_denied';

    public const REQUEST_INVALID = 'request_invalid';

    public const ISSUANCE_FAILED = 'issuance_failed';

    public const CERTIFICATE_MISSING = 'certificate_missing';

    public const NO_ISSUER = 'no_issuer';

    public const CERT_MANAGER_MISSING = 'cert_manager_missing';

    public const CHECK_DISABLED = 'check_disabled';

    public const NO_CLUSTER_ACCESS = 'no_cluster_access';

    public const FORBIDDEN = 'forbidden';

    public const UNREACHABLE = 'unreachable';

    public const CACHE_KEY = 'runr:console-tls:status';

    public const CACHE_TTL = 300;

    /** A certificate still pending after this many seconds is shown to the platform administrators */
    public const PENDING_WARN_AFTER = 600;

    private const FIRST_SEEN_KEY = 'runr:console-tls:first-seen:';

    /** Seconds per API call: the check runs in the banners request, at most once per CACHE_TTL */
    private const TIMEOUT = 5;

    /** Ready reasons of a Certificate whose Secret the ingress controller cannot serve (cert-manager's readiness policies) */
    private const NO_SECRET_REASONS = ['DoesNotExist', 'MissingData', 'InvalidKeyPair', 'InvalidCertificate'];

    public const OPENAPI_FIELDS = 'mode: string, issuer: string|null, secret: string|null, namespace: string|null, state: "ok"|"pending"|"failed"|"not_applicable"|"unknown", reason: string, message: string|null, since: string|null, renewal: bool, default_certificate: bool, not_after: string|null, checked_at: string';

    public function __construct(private readonly RunrClusterIdentity $identity) {}

    /**
     * The console certificate's state, cached for CACHE_TTL seconds.
     *
     * @return array{mode: string, issuer: string|null, secret: string|null, namespace: string|null, state: string, reason: string, message: string|null, since: string|null, renewal: bool, default_certificate: bool, not_after: string|null, checked_at: string}
     */
    public function status(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn (): array => $this->check());
        } catch (Throwable) {
            // no cache: read it now
            return $this->check();
        }
    }

    /**
     * The platform administrators' banner (kind console_tls), or null: a certificate cert-manager
     * cannot issue or renew, or one still pending after PENDING_WARN_AFTER seconds. Level critical
     * while the console serves the ingress controller's default certificate, else security.
     *
     * @return array<string, mixed>|null
     */
    public function banner(): ?array
    {
        $status = $this->status();
        $minutes = null;

        if ($status['state'] === self::PENDING) {
            $since = $status['since'] ? Carbon::parse($status['since']) : null;
            if ($since === null || $since->diffInSeconds(now(), true) < self::PENDING_WARN_AFTER) {
                return null;
            }
            $minutes = (int) floor($since->diffInSeconds(now(), true) / 60);
        } elseif ($status['state'] !== self::FAILED) {
            return null;
        }

        return [
            'kind' => 'console_tls',
            'level' => $status['default_certificate'] ? 'critical' : 'security',
            'minutes' => $minutes,
        ] + $status;
    }

    /**
     * Read the state now (uncached). Never throws.
     *
     * @return array{mode: string, issuer: string|null, secret: string|null, namespace: string|null, state: string, reason: string, message: string|null, since: string|null, renewal: bool, default_certificate: bool, not_after: string|null, checked_at: string}
     */
    public function check(): array
    {
        $mode = (string) config('knative.console_tls.mode', '');
        $issuer = (string) config('knative.console_tls.issuer', '');
        $secret = (string) config('knative.console_tls.secret_name', '');
        $namespace = null;

        $result = function (string $state, string $reason, array $more = []) use ($mode, $issuer, $secret, &$namespace): array {
            return array_merge([
                'mode' => $mode,
                'issuer' => $issuer !== '' ? $issuer : null,
                'secret' => $secret !== '' ? $secret : null,
                'namespace' => $namespace,
                'state' => $state,
                'reason' => $reason,
                'message' => null,
                'since' => null,
                'renewal' => false,
                'default_certificate' => false,
                'not_after' => null,
                'checked_at' => now()->toIso8601String(),
            ], $more);
        };

        // cert-manager does not issue it: an uploaded or own Secret, none, or letsencrypt in a
        // cluster that had no cert-manager when the chart was rendered (the chart then left the
        // Ingress without annotation and tls section)
        if ($issuer === '' || $secret === '') {
            return $result(self::NOT_APPLICABLE, $mode === 'letsencrypt' ? self::CERT_MANAGER_MISSING : self::NO_ISSUER);
        }

        try {
            $k8s = $this->identity->inClusterClient();
            $namespace = $this->identity->namespace();
            if (! $k8s || ! $namespace) {
                return $result(self::UNKNOWN, self::NO_CLUSTER_ACCESS);
            }

            $discovery = $k8s->getPathResponse('/apis/'.CertManager::API, self::TIMEOUT);
            if ($discovery->status() === 404) {
                return $result(self::NOT_APPLICABLE, self::CERT_MANAGER_MISSING);
            }
            if (! $discovery->successful()) {
                return $result(self::UNKNOWN, ...self::refusal($discovery));
            }

            if (! config('knative.console_tls.check')) {
                return $result(self::UNKNOWN, self::CHECK_DISABLED);
            }

            $response = $k8s->getPathResponse(self::path($namespace, 'certificates', $secret), self::TIMEOUT);
            if ($response->status() === 404) {
                // ingress-shim makes it within seconds of the Ingress: a lasting absence means
                // cert-manager is not acting on the annotation (its controller not running)
                return $result(self::PENDING, self::CERTIFICATE_MISSING, [
                    'since' => $this->firstSeen(self::CERTIFICATE_MISSING),
                    'default_certificate' => true,
                ]);
            }
            if (! $response->successful()) {
                return $result(self::UNKNOWN, ...self::refusal($response));
            }

            // Only an issuance in progress has a request worth reading
            $certificate = (array) $response->json();
            $request = (self::condition($certificate, 'Issuing')['status'] ?? '') === 'True'
                ? $this->latestRequest($k8s, $namespace, $secret)
                : null;

            $evaluated = self::evaluate($certificate, $request);
            if ($evaluated['state'] === self::OK) {
                $this->forgetFirstSeen();
            } elseif ($evaluated['since'] === null) {
                $evaluated['since'] = $this->firstSeen($evaluated['reason']);
            }

            return $result($evaluated['state'], $evaluated['reason'], array_diff_key($evaluated, ['state' => 1, 'reason' => 1]));
        } catch (Throwable $e) {
            Log::debug('Console certificate check failed', ['error' => $e->getMessage()]);

            return $result(self::UNKNOWN, self::UNREACHABLE, ['message' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }

    /**
     * The state of a Certificate and its newest CertificateRequest (null: none, or not readable).
     *
     * An issuance in progress (Issuing True) is judged by its request: a missing issuer, a failed,
     * denied or invalid request. A failed issuance waiting for its retry is Issuing False with
     * reason Failed. Ready True with such a problem is a renewal that does not come through, while
     * the current certificate is still served.
     *
     * @return array{state: string, reason: string, message: string|null, since: string|null, renewal: bool, default_certificate: bool, not_after: string|null}
     */
    public static function evaluate(array $certificate, ?array $request): array
    {
        $ready = self::condition($certificate, 'Ready');
        $issuing = self::condition($certificate, 'Issuing');
        $isReady = ($ready['status'] ?? '') === 'True';
        $notAfter = self::time($certificate['status']['notAfter'] ?? null);

        $problem = null;
        if (($issuing['status'] ?? '') === 'True' && $request !== null) {
            $requestReady = self::condition($request, 'Ready');
            $message = self::message($requestReady);
            $since = self::time($requestReady['lastTransitionTime'] ?? null);

            if (($requestReady['status'] ?? '') !== 'True') {
                if ($message !== null && preg_match('/^Referenced "(Cluster)?Issuer" not found/i', $message)) {
                    $problem = [self::ISSUER_NOT_FOUND, $message, $since];
                } elseif (($requestReady['reason'] ?? '') === 'Failed') {
                    $problem = [self::REQUEST_FAILED, $message, $since];
                } elseif (($requestReady['reason'] ?? '') === 'Denied' || (self::condition($request, 'Denied')['status'] ?? '') === 'True') {
                    $denied = self::condition($request, 'Denied');
                    $problem = [self::REQUEST_DENIED, self::message($denied) ?? $message, self::time($denied['lastTransitionTime'] ?? null) ?? $since];
                } elseif ((self::condition($request, 'InvalidRequest')['status'] ?? '') === 'True') {
                    $invalid = self::condition($request, 'InvalidRequest');
                    $problem = [self::REQUEST_INVALID, self::message($invalid) ?? $message, self::time($invalid['lastTransitionTime'] ?? null) ?? $since];
                }
            }
        } elseif (($issuing['status'] ?? '') === 'False' && ($issuing['reason'] ?? '') === 'Failed') {
            $problem = [
                self::ISSUANCE_FAILED,
                self::message($issuing),
                self::time($certificate['status']['lastFailureTime'] ?? null) ?? self::time($issuing['lastTransitionTime'] ?? null),
            ];
        }

        $defaultCertificate = ! $isReady && in_array($ready['reason'] ?? 'DoesNotExist', self::NO_SECRET_REASONS, true);

        if ($problem !== null) {
            return [
                'state' => self::FAILED,
                'reason' => $problem[0],
                'message' => $problem[1],
                'since' => $problem[2],
                'renewal' => $isReady,
                'default_certificate' => $defaultCertificate,
                'not_after' => $notAfter,
            ];
        }

        if ($isReady) {
            return [
                'state' => self::OK,
                'reason' => self::ISSUED,
                'message' => null,
                'since' => self::time($ready['lastTransitionTime'] ?? null),
                'renewal' => false,
                'default_certificate' => false,
                'not_after' => $notAfter,
            ];
        }

        // the request of the issuance in progress says what it waits for (an ACME order, approval)
        $requestMessage = ($issuing['status'] ?? '') === 'True' && $request !== null ? self::message(self::condition($request, 'Ready')) : null;

        return [
            'state' => self::PENDING,
            'reason' => self::ISSUING,
            'message' => $requestMessage ?? self::message($ready),
            'since' => self::time($ready['lastTransitionTime'] ?? null),
            'renewal' => false,
            'default_certificate' => $defaultCertificate,
            'not_after' => $notAfter,
        ];
    }

    /** Forget the cached state: the next status() reads it again. */
    public function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // nothing cached
        }
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * The Certificate's newest CertificateRequest (annotation cert-manager.io/certificate-name,
     * highest cert-manager.io/certificate-revision); null when there is none or the list is refused.
     */
    private function latestRequest(KubernetesClient $k8s, string $namespace, string $certificate): ?array
    {
        $response = $k8s->getPathResponse(self::path($namespace, 'certificaterequests'), self::TIMEOUT);
        if (! $response->successful()) {
            return null;
        }

        $latest = null;
        foreach ((array) ($response->json('items') ?? []) as $item) {
            $annotations = (array) ($item['metadata']['annotations'] ?? []);
            if (($annotations['cert-manager.io/certificate-name'] ?? null) !== $certificate) {
                continue;
            }
            $rank = [(int) ($annotations['cert-manager.io/certificate-revision'] ?? 0), (string) ($item['metadata']['creationTimestamp'] ?? '')];
            if ($latest === null || $rank > $latest[0]) {
                $latest = [$rank, (array) $item];
            }
        }

        return $latest[1] ?? null;
    }

    private static function path(string $namespace, string $resource, ?string $name = null): string
    {
        return '/apis/'.CertManager::API.'/namespaces/'.rawurlencode($namespace).'/'.$resource.($name !== null ? '/'.rawurlencode($name) : '');
    }

    /** @return array{0: string, 1: array{message: string}} reason and message of a refused or failed read */
    private static function refusal(Response $response): array
    {
        $message = is_array($response->json()) ? (string) ($response->json('message') ?? '') : '';
        $message = mb_substr($message !== '' ? $message : 'HTTP '.$response->status(), 0, 300);

        return [in_array($response->status(), [401, 403], true) ? self::FORBIDDEN : self::UNREACHABLE, ['message' => $message]];
    }

    private static function condition(array $object, string $type): ?array
    {
        foreach ((array) ($object['status']['conditions'] ?? []) as $condition) {
            if (($condition['type'] ?? '') === $type) {
                return (array) $condition;
            }
        }

        return null;
    }

    private static function message(?array $condition): ?string
    {
        $message = trim((string) ($condition['message'] ?? ''));

        return $message !== '' ? mb_substr($message, 0, 500) : null;
    }

    private static function time(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /** When a state without a time of its own was first seen (kept a week, cleared once the certificate is issued) */
    private function firstSeen(string $reason): string
    {
        $key = self::FIRST_SEEN_KEY.$reason;
        try {
            $seen = Cache::get($key);
            if (is_string($seen) && $seen !== '') {
                return $seen;
            }
            $seen = now()->toIso8601String();
            Cache::put($key, $seen, now()->addWeek());

            return $seen;
        } catch (Throwable) {
            return now()->toIso8601String();
        }
    }

    private function forgetFirstSeen(): void
    {
        try {
            foreach ([self::CERTIFICATE_MISSING, self::ISSUING, self::ISSUANCE_FAILED, self::ISSUER_NOT_FOUND, self::REQUEST_FAILED, self::REQUEST_DENIED, self::REQUEST_INVALID] as $reason) {
                Cache::forget(self::FIRST_SEEN_KEY.$reason);
            }
        } catch (Throwable) {
            // nothing kept
        }
    }
}
