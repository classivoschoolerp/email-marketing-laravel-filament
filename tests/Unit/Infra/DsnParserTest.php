<?php

declare(strict_types=1);

use App\Services\Bounces\DsnParser;

function bounce_fixture(string $name, array $replacements = []): string
{
    $raw = (string) file_get_contents(base_path('tests/Fixtures/bounces/'.$name));

    return str_replace(array_keys($replacements), array_values($replacements), $raw);
}

// RFC 3464 multipart/report with an embedded message/rfc822 original
it('parses a 550 user-unknown dsn with x-emp attribution headers', function (): void {
    $raw = bounce_fixture('dsn-550-user-unknown.eml', [
        '{{MESSAGE_ID}}' => 'msg-abc-123@platform.test',
        '{{CAMPAIGN_UID}}' => 'camp-uid-1',
        '{{SUBSCRIBER_UID}}' => 'sub-uid-1',
    ]);

    $bounce = (new DsnParser)->parse($raw);

    expect($bounce->isDsn)->toBeTrue()
        ->and($bounce->recipient)->toBe('victim@dest.example')
        ->and($bounce->action)->toBe('failed')
        ->and($bounce->status)->toBe('5.1.1')
        ->and($bounce->diagnosticCode)->toContain('550 5.1.1')
        ->and($bounce->diagnosticCode)->toContain('User unknown') // folded line unwrapped
        ->and($bounce->originalMessageId)->toBe('msg-abc-123@platform.test')
        ->and($bounce->campaignRef)->toBe('camp-uid-1')
        ->and($bounce->subscriberRef)->toBe('sub-uid-1')
        ->and($bounce->subject)->toBe('Undelivered Mail Returned to Sender')
        ->and($bounce->excerpt)->not->toBe('');
});

// RFC 3464 with a text/rfc822-headers original copy
it('parses a 452 mailbox-full dsn with rfc822-headers original', function (): void {
    $raw = bounce_fixture('dsn-452-mailbox-full.eml', [
        '{{MESSAGE_ID}}' => 'msg-def-456@platform.test',
        '{{SUBSCRIBER_UID}}' => 'sub-uid-2',
    ]);

    $bounce = (new DsnParser)->parse($raw);

    expect($bounce->isDsn)->toBeTrue()
        ->and($bounce->recipient)->toBe('full@dest.example')
        ->and($bounce->status)->toBe('4.2.2')
        ->and($bounce->diagnosticCode)->toContain('Mailbox full')
        ->and($bounce->originalMessageId)->toBe('msg-def-456@platform.test')
        ->and($bounce->campaignRef)->toBeNull()
        ->and($bounce->subscriberRef)->toBe('sub-uid-2');
});

// Heuristic path for MTAs that do not send standards-compliant DSNs
it('extracts recipient and status heuristically from a non-dsn greylist bounce', function (): void {
    $bounce = (new DsnParser)->parse(bounce_fixture('greylist-non-dsn.eml'));

    expect($bounce->isDsn)->toBeFalse()
        ->and($bounce->recipient)->toBe('grey@dest.example')
        ->and($bounce->status)->toBe('4.7.1')
        ->and($bounce->originalMessageId)->toBeNull();
});

it('parses an auto-reply as a non-dsn message without a recipient', function (): void {
    $bounce = (new DsnParser)->parse(bounce_fixture('auto-reply.eml'));

    expect($bounce->isDsn)->toBeFalse()
        ->and($bounce->recipient)->toBeNull()
        ->and($bounce->subject)->toContain('Automatic reply');
});

it('truncates the excerpt to the configured length', function (): void {
    config()->set('emp-infra.bounces.excerpt_length', 120);

    $bounce = (new DsnParser)->parse(bounce_fixture('greylist-non-dsn.eml'));

    expect(mb_strlen($bounce->excerpt))->toBeLessThanOrEqual(120);
});
