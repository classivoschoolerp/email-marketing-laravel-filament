<?php

declare(strict_types=1);

use App\Sending\Support\GoogleOAuth;

// F-2.9.2-017 — SASL XOAUTH2 initial client response, verified against the
// published example in Google's XOAUTH2 protocol documentation.
it('builds the documented XOAUTH2 SASL string', function (): void {
    $sasl = GoogleOAuth::xoauth2String(
        'someuser@example.com',
        'ya29.vF9dft4qmTc2Nvb3RlckBhdHRhdmlzdGEuY29tCg',
    );

    expect($sasl)->toBe(
        'dXNlcj1zb21ldXNlckBleGFtcGxlLmNvbQFhdXRoPUJlYXJlciB5YTI5LnZGOWRmdDRxbVRjMk52YjNSbGNrQmhkSFJoZG1semRHRXVZMjl0Q2cBAQ=='
    );
});

it('separates fields with 0x01 control bytes', function (): void {
    $decoded = base64_decode(GoogleOAuth::xoauth2String('a@b.c', 'tok'), true);

    expect($decoded)->toBe("user=a@b.c\x01auth=Bearer tok\x01\x01");
});
