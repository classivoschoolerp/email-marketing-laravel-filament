<?php

declare(strict_types=1);

use App\Services\SpamCheck\NullChecker;
use App\Services\SpamCheck\SpamAssassinChecker;
use Tests\Fakes\FakeSpamdClient;

/*
 * F-2.9.2-024 / D-08 — spamd protocol framing + parsing, tested against
 * transcripts authored from the SpamAssassin protocol spec (protocol.txt:
 * SPAMC/1.5 requests, SPAMD/1.x responses).
 */

function spamdSpamTranscript(): string
{
    $body = implode("\n", [
        'Spam detection software, running on the system "mail.example.com",',
        'has identified this incoming email as possible spam.',
        '',
        'Content analysis details:   (7.5 points, 5.0 required)',
        '',
        ' pts rule name              description',
        '---- ---------------------- --------------------------------------------------',
        ' 2.5 BAYES_99               BODY: Bayes spam probability is 99 to 100%',
        ' 1.2 MISSING_HEADERS        Missing To: header',
        ' 3.8 URIBL_BLACK            Contains an URL listed in the URIBL blacklist',
        '',
    ]);

    return "SPAMD/1.1 0 EX_OK\r\n"
        .'Content-length: '.strlen($body)."\r\n"
        ."Spam: True ; 7.5 / 5.0\r\n"
        ."\r\n"
        .$body;
}

function spamdHamTranscript(): string
{
    $body = implode("\n", [
        'Content analysis details:   (1.2 points, 5.0 required)',
        '',
        ' pts rule name              description',
        '---- ---------------------- --------------------------------------------------',
        '-0.1 NO_RELAYS              Informational: message was not relayed via SMTP',
        ' 1.3 MISSING_SUBJECT        Missing Subject: header',
        '',
    ]);

    return "SPAMD/1.1 0 EX_OK\r\n"
        .'Content-length: '.strlen($body)."\r\n"
        ."Spam: False ; 1.2 / 5.0\r\n"
        ."\r\n"
        .$body;
}

// Request framing per protocol.txt: command line, Content-length, blank, body
it('frames the SPAMC request exactly', function (): void {
    $checker = new SpamAssassinChecker(new FakeSpamdClient(null));

    expect($checker->buildRequest('REPORT', 'hello'))
        ->toBe("REPORT SPAMC/1.5\r\nContent-length: 5\r\n\r\nhello");
});

it('sends byte-accurate Content-length for multibyte messages', function (): void {
    $client = new FakeSpamdClient(spamdHamTranscript());
    $message = "Subject: 你好 🎉\r\n\r\nBody";

    (new SpamAssassinChecker($client))->check($message);

    expect($client->requests)->toHaveCount(1)
        ->and($client->requests[0])->toStartWith("REPORT SPAMC/1.5\r\nContent-length: ".strlen($message)."\r\n\r\n")
        ->and($client->requests[0])->toEndWith($message);
});

// F-2.9.2-024 — score + rule breakdown + block-if-over-threshold
it('parses a spam verdict with score and rule breakdown and blocks it', function (): void {
    $result = (new SpamAssassinChecker(new FakeSpamdClient(spamdSpamTranscript())))->check('raw');

    expect($result->checked)->toBeTrue()
        ->and($result->isSpam)->toBeTrue()
        ->and($result->score)->toBe(7.5)
        ->and($result->requiredScore)->toBe(5.0)
        ->and($result->blocked)->toBeTrue()   // 7.5 >= default threshold 5.0
        ->and($result->passed())->toBeFalse()
        ->and($result->rules)->toHaveCount(3)
        ->and($result->rules[0])->toBe(['points' => 2.5, 'rule' => 'BAYES_99', 'description' => 'BODY: Bayes spam probability is 99 to 100%'])
        ->and($result->rules[1]['rule'])->toBe('MISSING_HEADERS')
        ->and($result->rules[2]['points'])->toBe(3.8)
        ->and($result->report)->toContain('URIBL_BLACK');
});

it('passes a ham verdict below the threshold', function (): void {
    $result = (new SpamAssassinChecker(new FakeSpamdClient(spamdHamTranscript())))->check('raw');

    expect($result->checked)->toBeTrue()
        ->and($result->isSpam)->toBeFalse()
        ->and($result->score)->toBe(1.2)
        ->and($result->blocked)->toBeFalse()
        ->and($result->rules[0]['points'])->toBe(-0.1); // negative scores parse too
});

// Platform threshold is independent from spamd's required_score
it('blocks against the configured platform threshold', function (): void {
    $strict = new SpamAssassinChecker(new FakeSpamdClient(spamdHamTranscript()), blockThreshold: 1.0);

    expect($strict->check('raw')->blocked)->toBeTrue();

    config(['emp-sending.spamassassin.threshold' => 8.0]);
    $lenient = new SpamAssassinChecker(new FakeSpamdClient(spamdSpamTranscript()));

    expect($lenient->check('raw')->blocked)->toBeFalse(); // 7.5 < 8.0
});

it('reports spamd protocol errors as unavailable', function (): void {
    $error = (new SpamAssassinChecker(new FakeSpamdClient("SPAMD/1.0 76 EX_PROTOCOL\r\n\r\n")))->check('raw');
    $garbage = (new SpamAssassinChecker(new FakeSpamdClient('not a spamd response')))->check('raw');
    $headerless = (new SpamAssassinChecker(new FakeSpamdClient("SPAMD/1.1 0 EX_OK\r\nContent-length: 0\r\n\r\n")))->check('raw');

    foreach ([$error, $garbage, $headerless] as $result) {
        expect($result->checked)->toBeFalse()
            ->and($result->blocked)->toBeFalse()
            ->and($result->error)->not->toBeNull();
    }
});

// D-08 — an unreachable daemon fails open with the error recorded
it('fails open when the daemon is unreachable', function (): void {
    $result = (new SpamAssassinChecker(FakeSpamdClient::unreachable()))->check('raw');

    expect($result->checked)->toBeFalse()
        ->and($result->blocked)->toBeFalse()
        ->and($result->error)->toContain('unreachable');
});

it('passes everything through the null checker', function (): void {
    $result = (new NullChecker)->check('raw');

    expect($result->checked)->toBeTrue()
        ->and($result->score)->toBe(0.0)
        ->and($result->blocked)->toBeFalse()
        ->and($result->rules)->toBe([]);
});
