<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use JeffersonGoncalves\ZapSign\Jobs\CreateDocument;
use JeffersonGoncalves\ZapSign\Jobs\StoreSignedFile;
use JeffersonGoncalves\ZapSign\Jobs\SyncDocument;
use JeffersonGoncalves\ZapSign\Models\Document;
use JeffersonGoncalves\ZapSign\ZapSign;

beforeEach(fn () => Sleep::fake(syncWithCarbon: true));

it('creates the document from a disk file and stores it linked to a model', function () {
    Storage::fake('local');
    Storage::disk('local')->put('contracts/contract.pdf', '%PDF-1.4');
    Http::fake(['*' => Http::response(zapSignDocument())]);

    $user = User::query()->forceCreate(['name' => 'Owner']);

    CreateDocument::dispatch(['name' => 'Contract', 'signers' => [['name' => 'Ana']]], $user, 'contracts/contract.pdf', 'local');

    $document = Document::query()->forModel($user)->firstOrFail();

    expect($document->token)->toBe('doc-1')
        ->and($document->external_id)->toBe('order-9')
        ->and($document->sandbox)->toBeTrue()
        ->and($document->documentable)->toBeInstanceOf(User::class)
        ->and($document->signers()->where('token', 'sig-1')->first())
        ->phone->toBe('5511999990000')
        ->sign_url->toBe('https://app.zapsign.co/verificar/sig-1')
        ->signed_at->not->toBeNull();

    Http::assertSent(fn (Request $request) => $request['base64_pdf'] === base64_encode('%PDF-1.4') && $request['name'] === 'Contract');
});

it('creates documents from templates', function () {
    Http::fake(['*' => Http::response(zapSignDocument())]);

    CreateDocument::dispatch(['template_id' => 't-1', 'signer_name' => 'Ana'], fromTemplate: true);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/models/create-doc/'));
    expect(Document::query()->count())->toBe(1);
});

it('releases the job when the rate limit is reached', function () {
    config()->set('zapsign.rate_limit.max_attempts', 1);
    config()->set('zapsign.rate_limit.max_wait', 0);
    Http::fake(['*' => Http::response(zapSignDocument())]);

    (new SyncDocument('doc-1'))->handle(app(ZapSign::class));

    $job = (new SyncDocument('doc-1'))->withFakeQueueInteractions();
    $job->handle(app(ZapSign::class));

    $job->assertReleased();
    Http::assertSentCount(1);
});

it('syncs status and removes signers deleted at ZapSign', function () {
    $refused = [...zapSignDocument('recusado'), 'rejected_reason' => 'Wrong value'];
    $refused['signers'] = [$refused['signers'][0]];

    Http::fake(['*' => Http::sequence()->push(zapSignDocument())->push($refused)]);

    SyncDocument::dispatchSync('doc-1');
    expect(Document::query()->firstOrFail()->signers()->count())->toBe(2);

    SyncDocument::dispatchSync('doc-1');
    $document = Document::query()->firstOrFail();

    expect($document->status)->toBe(Document::STATUS_REFUSED)
        ->and($document->rejected_reason)->toBe('Wrong value')
        ->and($document->finished_at)->not->toBeNull()
        ->and($document->signers()->pluck('token')->all())->toBe(['sig-1']);
});

it('stores the signed file from a fresh link', function () {
    Storage::fake('s3');
    config()->set('zapsign.files.disk', 's3');
    Http::fake([
        'api.zapsign.com.br/*' => Http::response(zapSignDocument('signed', '2025-01-31T10:00:00Z')),
        'files/*' => Http::response('%PDF-signed'),
    ]);

    $document = Document::sync(zapSignDocument());

    StoreSignedFile::dispatch($document);

    expect($document->refresh()->signed_file_path)->toBe('zapsign/doc-1.pdf');
    Storage::disk('s3')->assertExists('zapsign/doc-1.pdf');
});
