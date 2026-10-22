<?php

use JeffersonGoncalves\ZapSign\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * Document as returned by the API and sent by webhooks (same shape).
 *
 * @return array<string, mixed>
 */
function zapSignDocument(string $status = 'pending', ?string $secondSignedAt = null): array
{
    return [
        'token' => 'doc-1',
        'open_id' => 23,
        'name' => 'Contract',
        'status' => $status,
        'sandbox' => true,
        'external_id' => 'order-9',
        'original_file' => 'https://files/original.pdf',
        'signed_file' => $status === 'signed' ? 'https://files/signed.pdf' : null,
        'signers' => [
            ['token' => 'sig-1', 'name' => 'Ana', 'email' => 'ana@example.com', 'status' => 'signed', 'phone_country' => '55', 'phone_number' => '11999990000', 'sign_url' => 'https://app.zapsign.co/verificar/sig-1', 'times_viewed' => 1, 'last_view_at' => '2025-01-30T12:49:01Z', 'signed_at' => '2025-01-30T12:50:00Z'],
            ['token' => 'sig-2', 'name' => 'Bia', 'email' => 'bia@example.com', 'status' => $secondSignedAt ? 'signed' : 'new', 'phone_country' => '', 'phone_number' => '', 'times_viewed' => 0, 'last_view_at' => null, 'signed_at' => $secondSignedAt],
        ],
    ];
}
