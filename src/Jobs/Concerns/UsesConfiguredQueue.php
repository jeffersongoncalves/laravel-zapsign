<?php

namespace JeffersonGoncalves\ZapSign\Jobs\Concerns;

trait UsesConfiguredQueue
{
    protected function useConfiguredQueue(): void
    {
        $this->onConnection(config('zapsign.queue.connection'));
        $this->onQueue(config('zapsign.queue.name'));
    }
}
