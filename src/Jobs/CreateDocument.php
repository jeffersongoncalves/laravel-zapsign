<?php

namespace JeffersonGoncalves\ZapSign\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\ZapSign\Exceptions\RateLimitException;
use JeffersonGoncalves\ZapSign\Exceptions\ZapSignException;
use JeffersonGoncalves\ZapSign\Jobs\Concerns\UsesConfiguredQueue;
use JeffersonGoncalves\ZapSign\Models\Document;
use JeffersonGoncalves\ZapSign\ZapSign;

/**
 * Create a document (from a file, URL or template) at ZapSign and store it with its signers.
 */
class CreateDocument implements ShouldQueue
{
    use Queueable;
    use UsesConfiguredQueue;

    /** Rate-limit releases count as attempts. */
    public int $tries = 10;

    /** Never retry after an error: the document may already exist at ZapSign (and credits be spent). */
    public int $maxExceptions = 1;

    /**
     * @param  array<string, mixed>  $data  createDocument / createDocumentFromTemplate body
     * @param  string|null  $path  .pdf/.docx sent as base64 (local path, or a path on `$disk`), instead of url_pdf/url_docx
     */
    public function __construct(
        public array $data,
        public ?Model $documentable = null,
        public ?string $path = null,
        public ?string $disk = null,
        public bool $fromTemplate = false,
    ) {
        $this->useConfiguredQueue();
    }

    public function handle(ZapSign $zapSign): void
    {
        $data = $this->path === null ? $this->data : [...$this->data, ...$this->file()];

        try {
            $response = $this->fromTemplate ? $zapSign->createDocumentFromTemplate($data) : $zapSign->createDocument($data);
        } catch (RateLimitException $e) {
            $this->release($e->retryAfter);

            return;
        }

        Document::sync($response, $this->documentable);
    }

    /**
     * Read the file only when the job runs, so the queue payload stays small.
     *
     * @return array<string, string>
     */
    protected function file(): array
    {
        $path = (string) $this->path;
        $contents = $this->disk === null ? @file_get_contents($path) : Storage::disk($this->disk)->get($path);

        if (! is_string($contents)) {
            throw new ZapSignException("File [{$path}] is not readable.");
        }

        $key = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'docx' ? 'base64_docx' : 'base64_pdf';

        return [$key => base64_encode($contents)];
    }
}
