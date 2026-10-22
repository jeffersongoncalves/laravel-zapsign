<?php

namespace JeffersonGoncalves\ZapSign\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

class ZapSignException extends RuntimeException
{
    public function __construct(string $message, public readonly ?Response $response = null)
    {
        parent::__construct($message, $response?->status() ?? 0);
    }

    public static function fromResponse(Response $response): self
    {
        $body = $response->json();
        $message = is_array($body) ? ($body['detail'] ?? $body['message'] ?? $body['error'] ?? null) : null;

        return new self(is_string($message) ? $message : "ZapSign request failed with status {$response->status()}: {$response->body()}", $response);
    }

    /**
     * Decoded error body, e.g. ['signers' => ['...']] for validation errors.
     *
     * @return array<string, mixed>
     */
    public function errors(): array
    {
        return (array) $this->response?->json();
    }
}
