<?php

namespace JeffersonGoncalves\ZapSign\Events;

use Illuminate\Foundation\Events\Dispatchable;

class WebhookReceived
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload  webhook body: the document plus `event_type` (and e.g. `signer_who_signed`)
     */
    public function __construct(public readonly array $payload) {}

    /**
     * Event type, e.g. `doc_signed`, `doc_refused`, `created_signer`, `email_bounce`.
     */
    public function type(): ?string
    {
        $type = $this->payload['event_type'] ?? null;

        return is_string($type) ? $type : null;
    }

    public function documentToken(): ?string
    {
        $token = $this->payload['token'] ?? null;

        return is_string($token) ? $token : null;
    }
}
