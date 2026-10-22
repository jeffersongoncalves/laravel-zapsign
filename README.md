<div class="filament-hidden">

![Laravel ZapSign](https://raw.githubusercontent.com/jeffersongoncalves/laravel-zapsign/main/art/jeffersongoncalves-laravel-zapsign.png)

</div>

# Laravel ZapSign

[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-support-FFDD00?style=flat-square&logo=buy-me-a-coffee&logoColor=black)](https://buymeacoffee.com/jeffersongoncalves)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jeffersongoncalves/laravel-zapsign.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-zapsign)
[![Tests](https://img.shields.io/github/actions/workflow/status/jeffersongoncalves/laravel-zapsign/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/jeffersongoncalves/laravel-zapsign/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/jeffersongoncalves/laravel-zapsign.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-zapsign)
[![License](https://img.shields.io/github/license/jeffersongoncalves/laravel-zapsign?style=flat-square)](LICENSE.md)

Laravel client for the [ZapSign](https://zapsign.com.br) e-signature [REST API](https://docs.zapsign.com.br): documents, templates, signers and webhooks — **rate-limit aware** out of the box, with database persistence and queued jobs.

ZapSign allows **500 requests per minute** per token/IP and answers `429` above that. This package:

- throttles requests client-side through your cache store, so every worker sharing the store stays under the limit;
- waits for a free slot (up to `max_wait` seconds) instead of failing, or throws a `RateLimitException` you can use to `release()` a queued job;
- retries any `429`/`503` that still reaches the API with exponential backoff, honouring `Retry-After` (never timeouts/`502`, which could create a document twice);
- queued jobs release themselves on a rate limit instead of failing.

Documents and signers can be stored in your database and kept up to date by queued jobs and webhooks, and signed PDFs copied to any filesystem disk before ZapSign's 60-minute links expire.

## Installation

```bash
composer require jeffersongoncalves/laravel-zapsign
```

Publish the config and migrations, then migrate (creates `zapsign_documents`, `zapsign_signers` and `zapsign_webhook_calls`):

```bash
php artisan zapsign:install
# or
php artisan vendor:publish --tag="zapsign-config"
php artisan vendor:publish --tag="zapsign-migrations"
php artisan migrate
```

Add your API token (ZapSign > Settings > Integration > ZapSign API):

```dotenv
ZAPSIGN_TOKEN=your-token
# Sandbox account:
# ZAPSIGN_URL=https://sandbox.api.zapsign.com.br
```

## Usage

```php
use JeffersonGoncalves\ZapSign\Facades\ZapSign;

// Create a document from a PDF URL (or base64_pdf, url_docx, base64_docx, markdown_text)
$document = ZapSign::createDocument([
    'name' => 'Service contract',
    'url_pdf' => 'https://example.com/contract.pdf',
    'lang' => 'pt-br',
    'external_id' => 'order-42',
    'signers' => [
        ['name' => 'Client', 'email' => 'client@example.com', 'auth_mode' => 'assinaturaTela', 'send_automatic_email' => true],
    ],
]);

$document['token'];
$document['signers'][0]['sign_url'];

// From a template
ZapSign::createDocumentFromTemplate([
    'template_id' => 'your-template-id',
    'signer_name' => 'Client',
    'data' => [['de' => '{{NOME COMPLETO}}', 'para' => 'Client Name']],
]);

// Async variants (large files): ZapSign answers right away and notifies via webhook
ZapSign::createDocument($data, async: true);
ZapSign::createDocumentFromTemplate($data, async: true);

// Documents
ZapSign::document($token);
ZapSign::documents(page: 1, filters: ['status' => 'signed', 'include_signers' => true]);
ZapSign::addExtraDocument($token, ['name' => 'Annex', 'url_pdf' => '...']);
ZapSign::placeSignatures($token, [['page' => 0, 'relative_position_bottom' => 52.5, 'relative_position_left' => 75.7, 'relative_size_x' => 19.55, 'relative_size_y' => 9.42, 'type' => 'signature', 'signer_token' => $signerToken]]);
ZapSign::cancelDocument($token, reason: 'Canceled by the company', notifySigners: true);
ZapSign::deleteDocument($token);

// Signers
ZapSign::addSigner($token, ['name' => 'Witness', 'email' => 'witness@example.com']);
ZapSign::signer($signerToken);
ZapSign::updateSigner($signerToken, ['name' => 'New name']);
ZapSign::deleteSigner($signerToken);
ZapSign::signInBatch($userToken, [$signerToken1, $signerToken2]);

// Any other endpoint under /api/v1/
ZapSign::request('get', 'templates/');
```

Every method returns the decoded JSON response as an array. Request bodies follow the [ZapSign docs](https://docs.zapsign.com.br) field names as-is.

### Errors

```php
use JeffersonGoncalves\ZapSign\Exceptions\RateLimitException;
use JeffersonGoncalves\ZapSign\Exceptions\ZapSignException;

try {
    ZapSign::document('missing');
} catch (RateLimitException $e) {
    $e->retryAfter; // seconds
} catch (ZapSignException $e) {
    $e->getMessage(); // ZapSign's `detail`/`message`
    $e->getCode();    // HTTP status
    $e->errors();     // decoded error body
}
```

## Database and jobs

### Models

| Model | Table | Holds |
|---|---|---|
| `Models\Document` | `zapsign_documents` | `token`, `external_id`, `name`, `status` (`pending`/`signed`/`refused`), `sandbox`, `original_file`, `signed_file`, `signed_file_path`, `rejected_reason`, `finished_at`, raw `payload`, optional `documentable` (any model) — soft deletes |
| `Models\Signer` | `zapsign_signers` | `token`, `external_id`, `name`, `email`, `phone`, `status`, `sign_url`, `times_viewed`, `last_view_at`, `signed_at`, raw `payload` |
| `Models\WebhookCall` | `zapsign_webhook_calls` | every received webhook (body hash unique), `event_type`, `document_token`, `processed_at`, `exception` |

```php
use JeffersonGoncalves\ZapSign\Models\Document;

$documents = Document::query()->forModel($contract)->with('signers')->get();

$document->isSigned();
$document->isRefused();
$document->documentable; // the model you linked
Document::query()->where('status', Document::STATUS_PENDING)->get();
```

`Document::sync(array $data, ?Model $documentable = null)` upserts any document array returned by the API or a webhook (same shape) with its signers — signers missing from the payload are removed — so you can store results of your own calls too.

### Jobs

```php
use JeffersonGoncalves\ZapSign\Jobs\CreateDocument;
use JeffersonGoncalves\ZapSign\Jobs\SyncDocument;

// Send a file (local path, or a path on any filesystem disk — S3 included) as base64, store and link to a model
CreateDocument::dispatch(
    data: ['name' => 'Service contract', 'signers' => [['name' => 'Client', 'email' => 'client@example.com']]],
    documentable: $contract,
    path: 'contracts/42.pdf', // .pdf or .docx
    disk: 's3',
);

// Or with url_pdf / a template
CreateDocument::dispatch(['template_id' => '...', 'signer_name' => 'Client'], $contract, fromTemplate: true);

// Refresh a document (and its signers) from the API
SyncDocument::dispatch($token);
```

- On a rate limit the jobs `release()` themselves for the `Retry-After` time (`$tries = 10`).
- `CreateDocument` is **not** retried after an error (`$maxExceptions = 1`): the document may already exist at ZapSign and credits may be spent.
- `SyncDocument` is unique per document for 60 s, so bursts collapse into one request.
- `StoreSignedFile` downloads the signed PDF to `ZAPSIGN_FILES_DISK` (`ZAPSIGN_FILES_DIRECTORY/{token}.pdf`) and saves `signed_file_path`; it is queued automatically when a webhook marks a document signed, or dispatch it yourself: `StoreSignedFile::dispatch($document)`.
- Set `ZAPSIGN_QUEUE_CONNECTION` / `ZAPSIGN_QUEUE` to run them on a dedicated queue.

### Rate limit in your own jobs

The client already waits for a free slot. In your own jobs, keep `max_wait` small and release the job instead of blocking the worker:

```php
public function handle(): void
{
    try {
        ZapSign::createDocument([...]);
    } catch (RateLimitException $e) {
        $this->release($e->retryAfter);
    }
}
```

| Option (`config/zapsign.php`) | Env | Default |
|---|---|---|
| `rate_limit.enabled` | `ZAPSIGN_RATE_LIMIT_ENABLED` | `true` |
| `rate_limit.max_attempts` | `ZAPSIGN_RATE_LIMIT_MAX_ATTEMPTS` | `500` |
| `rate_limit.decay_seconds` | `ZAPSIGN_RATE_LIMIT_DECAY_SECONDS` | `60` |
| `rate_limit.max_wait` | `ZAPSIGN_RATE_LIMIT_MAX_WAIT` | `60` |
| `rate_limit.cache_store` | `ZAPSIGN_RATE_LIMIT_CACHE_STORE` | default store |
| `rate_limit.retry_times` | `ZAPSIGN_RETRY_TIMES` | `3` |
| `rate_limit.retry_sleep_ms` | `ZAPSIGN_RETRY_SLEEP_MS` | `1000` (doubles each retry) |

Use a shared store (Redis, database) when you run several servers/workers — the `array` or `file` store only limits a single process/host.

## Webhooks

ZapSign does not sign webhook requests, so the endpoint is protected by a secret header that you register together with the webhook:

```dotenv
ZAPSIGN_WEBHOOK_SECRET=a-long-random-string
ZAPSIGN_WEBHOOK_HEADER=X-ZapSign-Secret
ZAPSIGN_WEBHOOK_PATH=zapsign/webhook
```

Register `https://your-app.com/zapsign/webhook` in ZapSign (Settings > Integration > Webhooks) with a custom header `X-ZapSign-Secret: a-long-random-string`, or via the API:

```php
ZapSign::request('post', 'user/company/webhook/', [
    'url' => route('zapsign.webhook'),
    'type' => '', // all events
    'headers' => [['name' => 'X-ZapSign-Secret', 'value' => config('zapsign.webhook.secret')]],
]);
```

The `POST /zapsign/webhook` route (name `zapsign.webhook`) is registered only when a secret is set. It:

1. checks the secret header (`403` otherwise);
2. stores the call in `zapsign_webhook_calls` — once per body (ZapSign sends no event id and redelivers until it gets a `200`), so duplicate deliveries are ignored;
3. answers `200` right away and queues `ProcessWebhookCall`, which upserts the document and its signers from the body, soft-deletes on `doc_deleted`, queues `StoreSignedFile` when the document is signed and a disk is configured, and finally dispatches `WebhookReceived`.

The route has no `web` middleware, so CSRF does not apply. Schedule `php artisan model:prune` to remove stored calls older than `ZAPSIGN_WEBHOOK_PRUNE_AFTER_DAYS` (30).

```php
use Illuminate\Support\Facades\Event;
use JeffersonGoncalves\ZapSign\Events\WebhookReceived;
use JeffersonGoncalves\ZapSign\Models\Document;

Event::listen(function (WebhookReceived $event) {
    $document = Document::query()->where('token', $event->documentToken())->first();

    match ($event->type()) {
        'doc_signed' => /* $event->payload['signer_who_signed'] */ null,
        'doc_refused', 'email_bounce' => /* ... */ null,
        default => null,
    };
});
```

The listener runs after the local tables are updated. `$event->payload` holds the full body.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
