<?php

namespace App\Http\Middleware;

use App\Services\Helpers\RegionCallbackHosts;
use Closure;
use Illuminate\Http\Middleware\TrustHosts as Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Host header protection (bootstrap/app.php): RunR answers for the console's host (APP_URL and
 * its subdomains) and the hosts named there; any other Host or trusted X-Forwarded-Host is
 * answered with 400.
 *
 * The region callbacks under /api/internal/ (a region agent's uptime checks and results, the
 * replay captures of function pods, the workflow queue bridge) come from inside the regions,
 * addressed to the URL RunR handed them, which need not be the console's: the agent of RunR's own
 * cluster gets the in-cluster gateway (ws://runr-gateway.<namespace>.svc.cluster.local), whose
 * name the gateway passes on as X-Forwarded-Host. There RunR also answers for those hosts
 * (RegionCallbackHosts). Each callback carries its own credential and builds no URL from the
 * request's host; every other route keeps to the console's hosts.
 */
class TrustHosts extends Middleware
{
    /**
     * @param  Closure  $next
     * @return Response
     */
    public function handle(Request $request, $next)
    {
        if ($this->shouldSpecifyTrustedHosts()) {
            $hosts = $this->hosts();
            if ($request->is('api/internal/*')) {
                $hosts = [...$hosts, ...app(RegionCallbackHosts::class)->patterns()];
            }
            Request::setTrustedHosts(array_values(array_filter($hosts)));
        }

        return $next($request);
    }
}
