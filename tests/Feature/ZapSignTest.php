<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use JeffersonGoncalves\ZapSign\Exceptions\RateLimitException;
use JeffersonGoncalves\ZapSign\Exceptions\ZapSignException;
use JeffersonGoncalves\ZapSign\Facades\ZapSign;

beforeEach(fn () => Sleep::fake(syncWithCarbon: true));

it('sends bearer-authenticated JSON requests to the v1 endpoints', function () {
    Http::fake(['*' => Http::response(['token' => 'doc-1'])]);

    expect(ZapSign::createDocument(['name' => 'Contract', 'url_pdf' => 'https://x/a.pdf']))->toBe(['token' => 'doc-1']);
    ZapSign::createDocumentFromTemplate(['template_id' => 't-1'], async: true);
    ZapSign::documents(2, ['status' => 'signed']);
    ZapSign::cancelDocument('doc-1', 'Canceled');
    ZapSign::deleteSigner('sig-1');

    $sent = Http::recorded()->map(fn (array $pair) => $pair[0]->method().' '.$pair[0]->url());

    expect($sent->all())->toBe([
        'POST https://api.zapsign.com.br/api/v1/docs/',
        'POST https://api.zapsign.com.br/api/v1/models/create-doc/async/',
        'GET https://api.zapsign.com.br/api/v1/docs/?page=2&status=signed',
        'POST https://api.zapsign.com.br/api/v1/refuse/',
        'DELETE https://api.zapsign.com.br/api/v1/signer/sig-1/remove/',
    ]);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-token')
        && ($request->data()['doc_token'] ?? null) === 'doc-1'
        && $request['rejected_reason'] === 'Canceled');
});

it('throws with the API error message', function () {
    Http::fake(['*' => Http::response(['detail' => 'Documento não encontrado.'], 404)]);

    expect(fn () => ZapSign::document('missing'))->toThrow(ZapSignException::class, 'Documento não encontrado.');
});

it('waits for the window, then gives up past max_wait', function () {
    config()->set('zapsign.rate_limit.max_attempts', 1);
    config()->set('zapsign.rate_limit.max_wait', 0);
    Http::fake(['*' => Http::response([])]);

    ZapSign::document('doc-1');

    expect(fn () => ZapSign::document('doc-1'))->toThrow(RateLimitException::class);
    Http::assertSentCount(1);
});

it('retries a 429 honouring Retry-After', function () {
    Http::fake(['*' => Http::sequence()
        ->push([], 429, ['Retry-After' => '2'])
        ->push(['token' => 'doc-1'])]);

    expect(ZapSign::document('doc-1'))->toBe(['token' => 'doc-1']);
    Http::assertSentCount(2);
    Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds === 2000.0);
});

it('does not retry server errors that may have created the document', function () {
    Http::fake(['*' => Http::response([], 502)]);

    expect(fn () => ZapSign::createDocument(['name' => 'Contract']))->toThrow(ZapSignException::class);
    Http::assertSentCount(1);
});
