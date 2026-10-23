<?php

namespace App\Http\Controllers\Api\Triggers;

use App\Models\ProjectTrigger;
use App\Triggers\Inbox\InboxService;
use App\Triggers\TriggerContexts;
use App\Triggers\Types\WebhookTrigger;
use App\Triggers\Webhooks\WebhookVerifier;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook inbox: POST|PUT /api/hooks/{context}/{key} (docs/triggers.md#webhook-inbox).
 *
 * The sender gets 200 as soon as the request is verified and stored — or 200 again for a
 * delivery id it already sent; the project's event gateway delivers the stored event to the
 * function afterwards (retried, replayable). Answers: 404 unknown / disabled inbox, 401 the
 * signature does not verify, 409 the project is stopped, 413 the body is larger than the
 * trigger's max_body_kb. Slack's url_verification handshake is answered with its challenge.
 */
class WebhookInboxController
{
    public function __construct(
        private WebhookVerifier $verifier,
        private InboxService $inbox,
    ) {}

    /**
     * Webhook inbox of a trigger: the body is any content the sender posts, kept as it came.
     */
    #[OpenApiResponse(200, 'The event is stored (duplicate: the delivery id was stored before); Slack\'s url_verification gets {challenge} instead', type: 'array{id: string, status: "stored"|"duplicate"}|array{challenge: string}')]
    #[OpenApiResponse(401, 'The signature does not verify', type: 'array{message: string}')]
    #[OpenApiResponse(404, 'Unknown or disabled inbox', type: 'array{message: string}')]
    #[OpenApiResponse(409, 'The project is stopped', type: 'array{message: string}')]
    #[OpenApiResponse(413, 'The body is larger than the trigger\'s max_body_kb', type: 'array{message: string}')]
    public function receive(Request $request, string $context, string $key): JsonResponse
    {
        return $this->store($request, $key, $context);
    }

    /**
     * Webhook inbox of a trigger, for senders that PUT: the same inbox as POST — the body is kept as
     * it came, the answers are the same.
     */
    #[OpenApiResponse(200, 'The event is stored (duplicate: the delivery id was stored before); Slack\'s url_verification gets {challenge} instead', type: 'array{id: string, status: "stored"|"duplicate"}|array{challenge: string}')]
    #[OpenApiResponse(401, 'The signature does not verify', type: 'array{message: string}')]
    #[OpenApiResponse(404, 'Unknown or disabled inbox', type: 'array{message: string}')]
    #[OpenApiResponse(409, 'The project is stopped', type: 'array{message: string}')]
    #[OpenApiResponse(413, 'The body is larger than the trigger\'s max_body_kb', type: 'array{message: string}')]
    public function receivePut(Request $request, string $context, string $key): JsonResponse
    {
        return $this->store($request, $key, $context);
    }

    private function store(Request $request, string $key, string $context): JsonResponse
    {
        $response = TriggerContexts::run($context, function () use ($request, $key) {
            $trigger = ProjectTrigger::query()
                ->where('ingress_key', $key)
                ->where('type', 'webhook')
                ->with('project')
                ->first();
            if (! $trigger || ! $trigger->enabled || ! $trigger->project) {
                return null;
            }
            if ($trigger->project->isStopped()) {
                return response()->json(['message' => __('triggers.webhook.project_stopped')], 409);
            }

            $declared = (int) $request->header('Content-Length', '0');
            $max = WebhookTrigger::maxBodyBytes($trigger);
            if ($declared > $max) {
                return response()->json(['message' => __('triggers.webhook.too_large', ['max' => intdiv($max, 1024)])], 413);
            }
            $body = (string) $request->getContent();
            if (strlen($body) > $max) {
                return response()->json(['message' => __('triggers.webhook.too_large', ['max' => intdiv($max, 1024)])], 413);
            }

            if ($error = $this->verifier->verify($trigger, $request, $body)) {
                return response()->json(['message' => __('triggers.webhook.'.$error)], 401);
            }

            if (($challenge = $this->verifier->slackChallenge($trigger, $body)) !== null) {
                return response()->json(['challenge' => $challenge]);
            }

            [$event, $duplicate] = $this->inbox->storeWebhook(
                $trigger,
                $request,
                $body,
                $this->verifier->deliveryId($trigger, $request, $body),
                $this->verifier->subject($trigger, $request, $body),
            );

            return response()->json([
                'id' => $event->id,
                'status' => $duplicate ? 'duplicate' : 'stored',
            ]);
        });

        return $response ?? response()->json(['message' => __('triggers.webhook.not_found')], 404);
    }
}
