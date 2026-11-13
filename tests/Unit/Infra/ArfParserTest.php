<?php

declare(strict_types=1);

use App\Services\Fbl\ArfParser;

// RFC 5965 multipart/report; report-type=feedback-report
it('parses an arf abuse report with original message attribution', function (): void {
    $raw = str_replace(
        ['{{MESSAGE_ID}}', '{{CAMPAIGN_UID}}', '{{SUBSCRIBER_UID}}'],
        ['msg-arf-1@platform.test', 'camp-uid-9', 'sub-uid-9'],
        (string) file_get_contents(base_path('tests/Fixtures/bounces/arf-abuse.eml')),
    );

    $complaint = (new ArfParser)->parse($raw);

    expect($complaint->isArf)->toBeTrue()
        ->and($complaint->feedbackType)->toBe('abuse')
        ->and($complaint->recipient)->toBe('complainer@dest.example')
        ->and($complaint->originalMessageId)->toBe('msg-arf-1@platform.test')
        ->and($complaint->campaignRef)->toBe('camp-uid-9')
        ->and($complaint->subscriberRef)->toBe('sub-uid-9');
});

it('flags ordinary mail in the fbl mailbox as non-arf', function (): void {
    $complaint = (new ArfParser)->parse(
        (string) file_get_contents(base_path('tests/Fixtures/bounces/auto-reply.eml')),
    );

    expect($complaint->isArf)->toBeFalse();
});
