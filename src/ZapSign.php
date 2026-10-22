<?php

namespace JeffersonGoncalves\ZapSign;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Sleep;
use JeffersonGoncalves\ZapSign\Exceptions\RateLimitException;
use JeffersonGoncalves\ZapSign\Exceptions\ZapSignException;
use Throwable;

class ZapSign
{
    /**
     * @param  array<string, mixed>  $config  the `zapsign` config array
     */
    public function __construct(
        protected Factory $http,
        protected RateLimiter $limiter,
        protected array $config,
    ) {}

    /**
     * Create a document from `url_pdf`, `base64_pdf`, `url_docx`, `base64_docx` or `markdown_text`.
     *
     * @param  array<string, mixed>  $data  e.g. ['name' => 'Contract', 'url_pdf' => '...', 'signers' => [['name' => 'Ana']]]
     * @return array<string, mixed>
     */
    public function createDocument(array $data, bool $async = false): array
    {
        return $this->request('post', $async ? 'docs/async/' : 'docs/', $data);
    }

    /**
     * @param  array<string, mixed>  $data  e.g. ['template_id' => '...', 'signer_name' => 'Ana', 'data' => [['de' => '{{NAME}}', 'para' => 'Ana']]]
     * @return array<string, mixed>
     */
    public function createDocumentFromTemplate(array $data, bool $async = false): array
    {
        return $this->request('post', $async ? 'models/create-doc/async/' : 'models/create-doc/', $data);
    }

    /**
     * @param  array<string, mixed>  $data  e.g. ['name' => 'Annex', 'url_pdf' => '...']
     * @return array<string, mixed>
     */
    public function addExtraDocument(string $token, array $data): array
    {
        return $this->request('post', "docs/{$token}/upload-extra-doc/", $data);
    }

    /** @return array<string, mixed> */
    public function document(string $token): array
    {
        return $this->request('get', "docs/{$token}/");
    }

    /**
     * @param  array<string, mixed>  $filters  status, folder_path, deleted, signer_email, created_from, created_to, sort_order, include_signers
     * @return array<string, mixed>
     */
    public function documents(int $page = 1, array $filters = []): array
    {
        return $this->request('get', 'docs/', ['page' => $page, ...$filters]);
    }

    /** @return array<string, mixed> */
    public function deleteDocument(string $token): array
    {
        return $this->request('delete', "docs/{$token}/");
    }

    /**
     * Refuse (cancel) a document.
     *
     * @return array<string, mixed>
     */
    public function cancelDocument(string $token, string $reason, bool $notifySigners = false): array
    {
        return $this->request('post', 'refuse/', ['doc_token' => $token, 'rejected_reason' => $reason, 'notify_signer' => $notifySigners]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rubricas  e.g. [['page' => 0, 'relative_position_bottom' => 52.5, ..., 'type' => 'signature', 'signer_token' => '...']]
     * @return array<string, mixed>
     */
    public function placeSignatures(string $token, array $rubricas): array
    {
        return $this->request('post', "docs/{$token}/place-signatures/", ['rubricas' => $rubricas]);
    }

    /** @return array<string, mixed> */
    public function signer(string $token): array
    {
        return $this->request('get', "signers/{$token}/");
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function addSigner(string $documentToken, array $data): array
    {
        return $this->request('post', "docs/{$documentToken}/add-signer/", $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateSigner(string $token, array $data): array
    {
        return $this->request('post', "signers/{$token}/", $data);
    }

    /** @return array<string, mixed> */
    public function deleteSigner(string $token): array
    {
        return $this->request('delete', "signer/{$token}/remove/");
    }

    /**
     * @param  array<int, string>  $signerTokens
     * @return array<string, mixed>
     */
    public function signInBatch(string $userToken, array $signerTokens): array
    {
        return $this->request('post', 'sign/', ['user_token' => $userToken, 'signer_tokens' => array_values($signerTokens)]);
    }

    /**
     * Call any endpoint under /api/v1/, e.g. `request('get', 'templates/')`.
     *
     * @param  array<string, mixed>  $data  JSON body, or query string for GET
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $data = []): array
    {
        $request = $this->pendingRequest();

        $this->throttle();

        $url = rtrim((string) ($this->config['url'] ?? 'https://api.zapsign.com.br'), '/').'/api/v1/'.ltrim($path, '/');

        $response = $request->send(strtoupper($method), $url, $method === 'get' ? ['query' => $data] : ($data === [] ? [] : ['json' => $data]));

        if ($response->status() === 429) {
            throw RateLimitException::fromTooManyRequests($response);
        }

        if ($response->failed()) {
            throw ZapSignException::fromResponse($response);
        }

        return (array) $response->json();
    }

    protected function pendingRequest(): PendingRequest
    {
        $token = (string) ($this->config['token'] ?? '');

        if ($token === '') {
            throw new ZapSignException('ZapSign token is not configured (ZAPSIGN_TOKEN).');
        }

        return $this->http
            ->withToken($token)
            ->acceptJson()
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->retry(
                (int) ($this->config['rate_limit']['retry_times'] ?? 3) + 1,
                fn (int $attempt, Throwable $e): int => $this->retryDelay($attempt, $e),
                // Only statuses where the request was surely not processed: retrying a timeout/502
                // of createDocument could create (and bill) the document twice.
                fn (Throwable $e): bool => $e instanceof RequestException && in_array($e->response->status(), [429, 503], true),
                throw: false,
            );
    }

    protected function retryDelay(int $attempt, Throwable $e): int
    {
        $retryAfter = $e instanceof RequestException ? (int) $e->response->header('Retry-After') : 0;

        if ($retryAfter > 0) {
            return $retryAfter * 1000;
        }

        // Exponential backoff, as ZapSign recommends.
        return (int) ($this->config['rate_limit']['retry_sleep_ms'] ?? 1000) * (2 ** ($attempt - 1));
    }

    /**
     * Client-side window shared through the cache, so every worker on the
     * same store respects the per-token limit.
     */
    protected function throttle(): void
    {
        $limit = (array) ($this->config['rate_limit'] ?? []);

        if (! ($limit['enabled'] ?? true)) {
            return;
        }

        $key = 'zapsign:'.sha1((string) ($this->config['token'] ?? ''));
        $maxAttempts = (int) ($limit['max_attempts'] ?? 500);
        $decay = (int) ($limit['decay_seconds'] ?? 60);
        $maxWait = (int) ($limit['max_wait'] ?? 60);
        $waited = 0;

        // ponytail: check-then-hit is not atomic across workers; a rare overshoot gets a 429, which the retry absorbs.
        while (! $this->limiter->attempt($key, $maxAttempts, fn () => true, $decay)) {
            $wait = max(1, $this->limiter->availableIn($key));

            if ($waited + $wait > $maxWait) {
                throw RateLimitException::throttled($wait);
            }

            Sleep::sleep($wait);
            $waited += $wait;
        }
    }
}
