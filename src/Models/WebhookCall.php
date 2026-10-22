<?php

namespace JeffersonGoncalves\ZapSign\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $hash
 * @property string $event_type
 * @property string|null $document_token
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 * @property string|null $exception
 */
class WebhookCall extends Model
{
    use MassPrunable;

    protected $table = 'zapsign_webhook_calls';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Used by `php artisan model:prune`.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('zapsign.webhook.prune_after_days', 30)));
    }
}
