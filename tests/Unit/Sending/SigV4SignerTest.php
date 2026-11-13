<?php

declare(strict_types=1);

use App\Sending\Support\SigV4Signer;

/*
 * F-2.9.2-003 — minimal SigV4 signer verified against the published AWS
 * documentation vector ("Signature Version 4 signing process — complete
 * example": IAM ListUsers, credentials AKIDEXAMPLE /
 * wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY, date 20150830T123600Z).
 */

const SIGV4_ACCESS_KEY = 'AKIDEXAMPLE';
const SIGV4_SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
const SIGV4_DATE = '20150830T123600Z';
const SIGV4_URI = 'https://iam.amazonaws.com/?Action=ListUsers&Version=2010-05-08';
const SIGV4_HEADERS = ['content-type' => 'application/x-www-form-urlencoded; charset=utf-8'];

it('reproduces the documented canonical request hash', function (): void {
    $hash = (new SigV4Signer)->canonicalRequestHash('GET', SIGV4_URI, SIGV4_HEADERS, '', SIGV4_DATE);

    expect($hash)->toBe('f536975d06c0309214f805bb90ccff089219ecd68b2577efef23edd43b7e1a59');
});

it('reproduces the documented signature and authorization header', function (): void {
    $headers = (new SigV4Signer)->sign(
        'GET',
        SIGV4_URI,
        SIGV4_HEADERS,
        '',
        SIGV4_ACCESS_KEY,
        SIGV4_SECRET_KEY,
        'us-east-1',
        'iam',
        SIGV4_DATE,
    );

    expect($headers['X-Amz-Date'])->toBe(SIGV4_DATE)
        ->and($headers['Authorization'])->toBe(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/iam/aws4_request, '
            .'SignedHeaders=content-type;host;x-amz-date, '
            .'Signature=5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7'
        );
});

it('sorts query parameters and headers canonically', function (): void {
    $signer = new SigV4Signer;

    // Same request with query params + headers deliberately out of order must
    // produce the identical canonical hash.
    $shuffled = $signer->canonicalRequestHash(
        'GET',
        'https://iam.amazonaws.com/?Version=2010-05-08&Action=ListUsers',
        ['Content-Type' => '  application/x-www-form-urlencoded;  charset=utf-8  '],
        '',
        SIGV4_DATE,
    );

    expect($shuffled)->toBe('f536975d06c0309214f805bb90ccff089219ecd68b2577efef23edd43b7e1a59');
});

it('signs the payload hash into the canonical request', function (): void {
    $signer = new SigV4Signer;

    $withBody = $signer->canonicalRequestHash('POST', 'https://email.us-east-1.amazonaws.com/v2/email/outbound-emails', [], '{"a":1}', SIGV4_DATE);
    $withOtherBody = $signer->canonicalRequestHash('POST', 'https://email.us-east-1.amazonaws.com/v2/email/outbound-emails', [], '{"a":2}', SIGV4_DATE);

    expect($withBody)->not->toBe($withOtherBody);
});
