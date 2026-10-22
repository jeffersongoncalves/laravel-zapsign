<?php

namespace JeffersonGoncalves\ZapSign\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JeffersonGoncalves\ZapSign\Jobs\ProcessWebhookCall;
use JeffersonGoncalves\ZapSign\Models\WebhookCall;

class WebhookController
{
    public function __invoke(Request $request): Response
    {
        $secret = (string) config('zapsign.webhook.secret');
        $received = (string) $request->header((string) config('zapsign.webhook.header', 'X-ZapSign-Secret'));

        if ($secret === '' || ! hash_equals($secret, $received)) {
            return response('Invalid secret.', 403);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! is_string($payload['event_type'] ?? null)) {
            return response('Invalid payload.', 400);
        }

        // ZapSign redelivers until it gets a 200 and sends no event id: the body hash makes it idempotent.
        $call = WebhookCall::query()->firstOrCreate(['hash' => sha1($request->getContent())], [
            'event_type' => $payload['event_type'],
            'document_token' => is_string($payload['token'] ?? null) ? $payload['token'] : null,
            'payload' => $payload,
        ]);

        // ZapSign expects a fast 200: the work happens on the queue.
        if ($call->wasRecentlyCreated) {
            ProcessWebhookCall::dispatch($call);
        }

        return response('', 200);
    }
}
