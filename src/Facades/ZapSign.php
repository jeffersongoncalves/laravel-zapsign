<?php

namespace JeffersonGoncalves\ZapSign\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array<string, mixed> createDocument(array<string, mixed> $data, bool $async = false)
 * @method static array<string, mixed> createDocumentFromTemplate(array<string, mixed> $data, bool $async = false)
 * @method static array<string, mixed> addExtraDocument(string $token, array<string, mixed> $data)
 * @method static array<string, mixed> document(string $token)
 * @method static array<string, mixed> documents(int $page = 1, array<string, mixed> $filters = [])
 * @method static array<string, mixed> deleteDocument(string $token)
 * @method static array<string, mixed> cancelDocument(string $token, string $reason, bool $notifySigners = false)
 * @method static array<string, mixed> placeSignatures(string $token, array<int, array<string, mixed>> $rubricas)
 * @method static array<string, mixed> signer(string $token)
 * @method static array<string, mixed> addSigner(string $documentToken, array<string, mixed> $data)
 * @method static array<string, mixed> updateSigner(string $token, array<string, mixed> $data)
 * @method static array<string, mixed> deleteSigner(string $token)
 * @method static array<string, mixed> signInBatch(string $userToken, array<int, string> $signerTokens)
 * @method static array<string, mixed> request(string $method, string $path, array<string, mixed> $data = [])
 *
 * @see \JeffersonGoncalves\ZapSign\ZapSign
 */
class ZapSign extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \JeffersonGoncalves\ZapSign\ZapSign::class;
    }
}
