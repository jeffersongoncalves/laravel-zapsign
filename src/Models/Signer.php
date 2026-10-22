<?php

namespace JeffersonGoncalves\ZapSign\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $document_id
 * @property string $token
 * @property string|null $external_id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $status
 * @property string|null $sign_url
 * @property int $times_viewed
 * @property Carbon|null $last_view_at
 * @property Carbon|null $signed_at
 * @property array<string, mixed>|null $payload
 */
class Signer extends Model
{
    protected $table = 'zapsign_signers';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'times_viewed' => 'integer',
            'last_view_at' => 'datetime',
            'signed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function sync(Document $document, array $data): self
    {
        $phone = trim(($data['phone_country'] ?? '').($data['phone_number'] ?? ''));

        // Only overwrite what this payload actually carries (webhooks may omit sign_url).
        $attributes = array_filter([
            'external_id' => $data['external_id'] ?? null,
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $phone,
            'status' => $data['status'] ?? null,
            'sign_url' => $data['sign_url'] ?? $data['signing_url'] ?? null,
            'times_viewed' => $data['times_viewed'] ?? null,
            'last_view_at' => $data['last_view_at'] ?? null,
            'signed_at' => $data['signed_at'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return static::updateOrCreate(
            ['token' => (string) $data['token']],
            ['document_id' => $document->id, 'payload' => $data, ...$attributes],
        );
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function hasSigned(): bool
    {
        return $this->signed_at !== null;
    }
}
