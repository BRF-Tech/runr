<?php

namespace App\Mcp\Console;

use App\Mcp\Models\HostedServerCall;
use App\Mcp\Models\OAuthClient;
use App\Mcp\Models\OAuthRequest;
use App\Mcp\Server\SessionStore;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Daily cleanup of the MCP tables (scheduled by McpServiceProvider): idle sessions, finished or
 * expired authorization requests, registered clients nobody connected with, and the call log of
 * hosted servers past its retention.
 */
class McpPruneCommand extends Command
{
    protected $signature = 'mcp:prune';

    protected $description = 'Remove idle MCP sessions, old OAuth requests, unused OAuth clients and old hosted-server calls';

    public function handle(SessionStore $sessions): int
    {
        $sessionsRemoved = $sessions->prune();

        $requestsRemoved = OAuthRequest::query()->where('expires_at', '<', now()->subDay())->delete();

        $unusedDays = max(1, (int) config('mcp.oauth.unused_client_days', 30));
        $clientsRemoved = 0;
        OAuthClient::query()
            ->where('created_at', '<', now()->subDays($unusedDays))
            ->where(fn ($q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', now()->subDays($unusedDays)))
            ->chunkById(200, function ($clients) use (&$clientsRemoved) {
                foreach ($clients as $client) {
                    if (! PersonalAccessToken::query()->where('oauth_client_id', $client->id)->exists()) {
                        $client->delete();
                        $clientsRemoved++;
                    }
                }
            });

        $callsRemoved = 0;
        $days = (int) config('mcp.gateway.calls_retention_days', 30);
        if ($days > 0) {
            $callsRemoved = HostedServerCall::query()->where('occurred_at', '<', now()->subDays($days))->delete();
        }

        $this->info("Removed {$sessionsRemoved} session(s), {$requestsRemoved} authorization request(s), {$clientsRemoved} client(s), {$callsRemoved} call(s).");

        return 0;
    }
}
