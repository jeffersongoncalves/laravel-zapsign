<?php

namespace JeffersonGoncalves\ZapSign\Exceptions;

use Illuminate\Http\Client\Response;

class RateLimitException extends ZapSignException
{
    public function __construct(string $message, public readonly int $retryAfter, ?Response $response = null)
    {
        parent::__construct($message, $response);
    }

    public static function throttled(int $retryAfter): self
    {
        return new self("ZapSign rate limit reached, retry in {$retryAfter}s.", $retryAfter);
    }

    public static function fromTooManyRequests(Response $response): self
    {
        $retryAfter = (int) $response->header('Retry-After');

        return new self('ZapSign API returned 429 Too Many Requests.', $retryAfter > 0 ? $retryAfter : 60, $response);
    }
}
