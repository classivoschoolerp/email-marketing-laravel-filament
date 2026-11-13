<?php

declare(strict_types=1);

use App\Enums\VerificationStatus;
use App\Services\Dns\DnsResolverInterface;
use App\Services\Verification\Providers\BuiltInVerifier;
use Tests\Fakes\FakeDnsResolver;

/*
 * WP-16 §2.9.12 F-2.9.12-002 (D-12) — the built-in engine: syntax, MX
 * (via the DnsResolverInterface seam), disposable-domain, role-account;
 * catch-all/SMTP handshake honestly reported as skipped.
 */

function wp16_dns(array $mx = []): void
{
    app()->instance(DnsResolverInterface::class, new FakeDnsResolver(mx: $mx));
}

it('fails syntax before touching DNS', function (): void {
    wp16_dns();

    $result = (new BuiltInVerifier)->verify('not-an-email');

    expect($result->status)->toBe(VerificationStatus::Undeliverable)
        ->and($result->reason)->toBe('invalid_syntax')
        ->and($result->raw['checks']['syntax'])->toBe('failed');
});

it('marks domains without MX records undeliverable', function (): void {
    wp16_dns(); // no MX anywhere

    $result = (new BuiltInVerifier)->verify('user@no-mx.example');

    expect($result->status)->toBe(VerificationStatus::Undeliverable)
        ->and($result->reason)->toBe('no_mx_records')
        ->and($result->raw['checks']['syntax'])->toBe('passed');
});

it('flags disposable domains as risky', function (): void {
    wp16_dns(['10minutemail.com' => ['mx.10minutemail.com']]);

    $result = (new BuiltInVerifier)->verify('temp@10minutemail.com');

    expect($result->status)->toBe(VerificationStatus::Risky)
        ->and($result->reason)->toBe('disposable_domain');
});

it('flags role accounts as risky', function (): void {
    wp16_dns(['company.example' => ['mx.company.example']]);

    $result = (new BuiltInVerifier)->verify('info@company.example');

    expect($result->status)->toBe(VerificationStatus::Risky)
        ->and($result->reason)->toBe('role_account');
});

it('tags a clean address deliverable, disclosing the skipped SMTP handshake', function (): void {
    wp16_dns(['company.example' => ['mx.company.example']]);

    $result = (new BuiltInVerifier)->verify('Jane.Doe@Company.example');

    expect($result->status)->toBe(VerificationStatus::Deliverable)
        ->and($result->reason)->toBe('passed_checks')
        ->and($result->raw['checks'])->toMatchArray([
            'syntax' => 'passed',
            'mx' => 'passed',
            'disposable' => 'passed',
            'role_account' => 'passed',
            'smtp' => 'skipped',
            'catch_all' => 'skipped',
        ]);
});
