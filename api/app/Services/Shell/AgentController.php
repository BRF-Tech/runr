<?php

namespace App\Services\Shell;

use App\Models\KnativeRegion;
use App\Services\AgentMessageHandler;
use App\Services\Helpers\AgentToken;
use Illuminate\Support\Facades\Log;
use Laravel\Reverb\Servers\Reverb\Connection;
use Psr\Http\Message\RequestInterface;

/**
 * WebSocket handler for Go agent connections.
 * Accepts raw WS from the agent, validates the per-region token,
 * and feeds messages into AgentMessageHandler.
 */
class AgentController
{
    public function __invoke(RequestInterface $request, Connection $connection, string $regionId = ''): void
    {
        if (! $regionId) {
            $connection->send(json_encode(['error' => 'Missing region ID']));
            $connection->close();

            return;
        }

        // Validate token from Authorization header
        if (! $this->authorizeAgent($request->getHeaderLine('Authorization'), $regionId)) {
            Log::warning("Agent rejected: region={$regionId} (missing or invalid token)");
            $connection->send(json_encode(['error' => 'Unauthorized']));
            $connection->close();

            return;
        }

        Log::info("Agent connected: region={$regionId}");

        $handler = app(AgentMessageHandler::class);

        $connection->onMessage(function ($message) use ($handler, $regionId) {
            Log::debug("Agent msg: region={$regionId} ".substr((string) $message, 0, 300));
            $data = json_decode((string) $message, true);
            if (! $data) {
                Log::warning('Agent message invalid JSON: '.substr((string) $message, 0, 200));

                return;
            }

            try {
                $handler->handle($data, $regionId);
            } catch (\Throwable $e) {
                Log::error("Agent message error: {$e->getMessage()}", ['trace' => $e->getTraceAsString()]);
            }
        });

        $connection->onClose(function () use ($regionId) {
            Log::info("Agent disconnected: region={$regionId}");
        });

        $connection->openBuffer();
    }

    /**
     * Bearer token must match the per-region hash stored at agent deploy time
     * (KnativeAgentTrait::deployAgent). Unknown region, region without a stored
     * hash (agent not redeployed since 0.3.3: `php artisan agent:redeploy`),
     * missing or wrong token → rejected.
     */
    public function authorizeAgent(string $authorizationHeader, string $regionId): bool
    {
        $token = str_starts_with($authorizationHeader, 'Bearer ') ? substr($authorizationHeader, 7) : null;
        if (! $token) {
            return false;
        }

        $region = KnativeRegion::find($regionId);

        return $region !== null && AgentToken::verify($region->agent_token_hash, $token);
    }
}
