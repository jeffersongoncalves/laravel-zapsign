<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use JeffersonGoncalves\ZapSign\Events\WebhookReceived;
use JeffersonGoncalves\ZapSign\Jobs\ProcessWebhookCall;
use JeffersonGoncalves\ZapSign\Jobs\StoreSignedFile;
use JeffersonGoncalves\ZapSign\Models\Document;
use JeffersonGoncalves\ZapSign\Models\WebhookCall;

/**
 * @param  array<string, mixed>  $document
 */
function zapSignWebhook(string $type, array $document, ?string $secret = 'webhook-secret'): TestResponse
{
    $body = (string) json_encode(['event_type' => $type, ...$document]);

    return test()->call('POST', '/zapsign/webhook', [], [], [], array_filter([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ZAPSIGN_SECRET' => $secret,
    ]), $body);
}

it('stores the call, syncs the document and dispatches the event', function () {
    Event::fake([WebhookReceived::class]);

    zapSignWebhook('doc_created', zapSignDocument())->assertOk();

    $document = Document::query()->where('token', 'doc-1')->firstOrFail();

    expect($document->name)->toBe('Contract')
        ->and($document->status)->toBe(Document::STATUS_PENDING)
        ->and($document->signers()->count())->toBe(2)
        ->and(WebhookCall::query()->firstOrFail())
        ->event_type->toBe('doc_created')
        ->document_token->toBe('doc-1')
        ->processed_at->not->toBeNull();

    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->type() === 'doc_created'
        && $event->documentToken() === 'doc-1');
});

it('marks the document signed and queues the signed file download', function () {
    Queue::fake([StoreSignedFile::class]);
    config()->set('zapsign.files.disk', 'local');

    zapSignWebhook('doc_signed', zapSignDocument('signed', '2025-01-31T12:22:30Z'))->assertOk();

    $document = Document::query()->firstOrFail();

    expect($document->isSigned())->toBeTrue()
        ->and($document->signed_file)->toBe('https://files/signed.pdf')
        ->and($document->finished_at)->not->toBeNull()
        ->and($document->signers()->where('token', 'sig-2')->first()?->hasSigned())->toBeTrue();

    Queue::assertPushed(StoreSignedFile::class);
});

it('marks refused documents and soft deletes deleted ones', function () {
    zapSignWebhook('doc_refused', [...zapSignDocument('recusado'), 'rejected_reason' => 'No'])->assertOk();
    expect(Document::query()->firstOrFail()->isRefused())->toBeTrue();

    zapSignWebhook('doc_deleted', [...zapSignDocument(), 'deleted' => true])->assertOk();
    expect(Document::query()->count())->toBe(0)
        ->and(Document::withTrashed()->count())->toBe(1);
});

it('processes a redelivered body only once', function () {
    Queue::fake([ProcessWebhookCall::class]);

    zapSignWebhook('doc_signed', zapSignDocument())->assertOk();
    zapSignWebhook('doc_signed', zapSignDocument())->assertOk();

    expect(WebhookCall::query()->count())->toBe(1);
    Queue::assertPushed(ProcessWebhookCall::class, 1);
});

it('rejects requests without the secret header', function (?string $secret) {
    Event::fake([WebhookReceived::class]);

    zapSignWebhook('doc_signed', zapSignDocument(), $secret)->assertForbidden();

    expect(WebhookCall::query()->count())->toBe(0);
    Event::assertNotDispatched(WebhookReceived::class);
})->with(['wrong' => 'nope', 'missing' => null]);

it('rejects bodies without event_type', function () {
    test()->call('POST', '/zapsign/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ZAPSIGN_SECRET' => 'webhook-secret'], '{"token":"doc-1"}')
        ->assertStatus(400);
});
