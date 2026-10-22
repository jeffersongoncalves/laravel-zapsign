<?php

namespace JeffersonGoncalves\ZapSign\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use JeffersonGoncalves\ZapSign\Exceptions\RateLimitException;
use JeffersonGoncalves\ZapSign\Jobs\Concerns\UsesConfiguredQueue;
use JeffersonGoncalves\ZapSign\Models\Document;
use JeffersonGoncalves\ZapSign\ZapSign;

/**
 * Fetch a document from the API and upsert it (with its signers) in the database.
 */
class SyncDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use UsesConfiguredQueue;

    /** Rate-limit releases count as attempts. */
    public int $tries = 10;

    public int $maxExceptions = 3;

    /** Bursts for one document collapse into a single sync. */
    public int $uniqueFor = 60;

    public function __construct(public string $token)
    {
        $this->useConfiguredQueue();
    }

    public function uniqueId(): string
    {
        return $this->token;
    }

    public function handle(ZapSign $zapSign): void
    {
        try {
            $data = $zapSign->document($this->token);
        } catch (RateLimitException $e) {
            $this->release($e->retryAfter);

            return;
        }

        Document::sync($data);
    }
}
