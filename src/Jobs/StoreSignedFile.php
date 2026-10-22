<?php

namespace JeffersonGoncalves\ZapSign\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\ZapSign\Exceptions\RateLimitException;
use JeffersonGoncalves\ZapSign\Jobs\Concerns\UsesConfiguredQueue;
use JeffersonGoncalves\ZapSign\Models\Document;
use JeffersonGoncalves\ZapSign\ZapSign;

/**
 * Download the signed PDF (ZapSign links expire after 60 minutes) to the configured disk.
 */
class StoreSignedFile implements ShouldQueue
{
    use Queueable;
    use UsesConfiguredQueue;

    /** Rate-limit releases count as attempts. */
    public int $tries = 10;

    public int $maxExceptions = 3;

    public function __construct(public Document $document)
    {
        $this->useConfiguredQueue();
    }

    public function handle(ZapSign $zapSign): void
    {
        // Always ask for a fresh link: the stored one may already be expired.
        try {
            $document = Document::sync($zapSign->document($this->document->token));
        } catch (RateLimitException $e) {
            $this->release($e->retryAfter);

            return;
        }

        if (blank($document->signed_file)) {
            return;
        }

        $path = trim((string) config('zapsign.files.directory', 'zapsign'), '/').'/'.$document->token.'.pdf';

        Storage::disk((string) config('zapsign.files.disk'))->put($path, Http::timeout(120)->get((string) $document->signed_file)->throw()->body());

        $document->forceFill(['signed_file_path' => $path])->save();
    }
}
