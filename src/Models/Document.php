<?php

namespace JeffersonGoncalves\ZapSign\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $token
 * @property string|null $documentable_type
 * @property string|null $documentable_id
 * @property string|null $external_id
 * @property string|null $name
 * @property string $status
 * @property bool $sandbox
 * @property string|null $original_file
 * @property string|null $signed_file
 * @property string|null $signed_file_path
 * @property string|null $rejected_reason
 * @property Carbon|null $finished_at
 * @property array<string, mixed>|null $payload
 */
class Document extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_REFUSED = 'refused';

    protected $table = 'zapsign_documents';

    protected $guarded = [];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'documentable_id' => 'string',
            'sandbox' => 'boolean',
            'finished_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * Store a document as returned by the API or a webhook (same shape), with its signers.
     *
     * @param  array<string, mixed>  $data
     */
    public static function sync(array $data, ?Model $documentable = null): self
    {
        /** @var self $document */
        $document = static::withTrashed()->firstOrNew(['token' => (string) $data['token']]);

        if ($documentable !== null) {
            $document->documentable_type = $documentable->getMorphClass();
            $document->documentable_id = (string) $documentable->getKey();
        }

        // Only overwrite what this payload actually carries (async creation answers with little more than the token).
        $document->fill(array_filter([
            'name' => $data['name'] ?? null,
            'external_id' => $data['external_id'] ?? null,
            'status' => isset($data['status']) ? self::normalizeStatus((string) $data['status']) : null,
            'original_file' => $data['original_file'] ?? null,
            'signed_file' => $data['signed_file'] ?? null,
            'rejected_reason' => $data['rejected_reason'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));

        if (array_key_exists('sandbox', $data)) {
            $document->sandbox = (bool) $data['sandbox'];
        }

        if ($document->status !== self::STATUS_PENDING) {
            $document->finished_at ??= now();
        }

        $document->payload = $data;
        $document->save();

        if (is_array($data['signers'] ?? null)) {
            $tokens = [];

            foreach ($data['signers'] as $signer) {
                $tokens[] = Signer::sync($document, (array) $signer)->token;
            }

            // The payload lists every signer: drop the ones removed at ZapSign.
            $document->signers()->whereNotIn('token', $tokens)->delete();
        }

        return $document;
    }

    /**
     * ZapSign mixes languages (`refused`/`recusado`).
     */
    public static function normalizeStatus(string $status): string
    {
        return match ($status) {
            'signed', 'assinado' => self::STATUS_SIGNED,
            'refused', 'recusado', 'rejected' => self::STATUS_REFUSED,
            default => self::STATUS_PENDING,
        };
    }

    /** @return MorphTo<Model, $this> */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<Signer, $this> */
    public function signers(): HasMany
    {
        return $this->hasMany(Signer::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForModel(Builder $query, Model $model): void
    {
        $query->where('documentable_type', $model->getMorphClass())->where('documentable_id', (string) $model->getKey());
    }

    public function isSigned(): bool
    {
        return $this->status === self::STATUS_SIGNED;
    }

    public function isRefused(): bool
    {
        return $this->status === self::STATUS_REFUSED;
    }
}
