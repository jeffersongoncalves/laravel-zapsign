<?php

namespace JeffersonGoncalves\ZapSign\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use JeffersonGoncalves\ZapSign\Events\WebhookReceived;
use JeffersonGoncalves\ZapSign\Jobs\Concerns\UsesConfiguredQueue;
use JeffersonGoncalves\ZapSign\Models\Document;
use JeffersonGoncalves\ZapSign\Models\WebhookCall;
use Throwable;

/**
 * Apply a stored webhook to the local document/signers, then dispatch WebhookReceived.
 */
class ProcessWebhookCall implements ShouldQueue
{
    use Queueable;
    use UsesConfiguredQueue;

    public int $tries = 3;

    public function __construct(public WebhookCall $call)
    {
        $this->useConfiguredQueue();
    }

    public function handle(): void
    {
        $event = new WebhookReceived($this->call->payload);

        try {
            $this->apply((string) $event->type(), $this->call->payload);
        } catch (Throwable $e) {
            $this->call->update(['exception' => (string) $e]);

            throw $e;
        }

        $this->call->update(['processed_at' => now(), 'exception' => null]);

        event($event);
    }

    /**
     * Every ZapSign event carries the whole document at the root of the body.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function apply(string $type, array $payload): void
    {
        if (! isset($payload['token'])) {
            return;
        }

        if ($type === 'doc_deleted') {
            Document::query()->where('token', $payload['token'])->first()?->delete();

            return;
        }

        $document = Document::sync($payload);

        if ($document->isSigned() && $document->signed_file_path === null && filled(config('zapsign.files.disk'))) {
            StoreSignedFile::dispatch($document);
        }
    }
}
