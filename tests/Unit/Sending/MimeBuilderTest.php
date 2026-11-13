<?php

declare(strict_types=1);

use App\Models\DeliveryServer;
use App\Sending\Message;
use App\Sending\MimeBuilder;

const TEST_DKIM_KEY = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQDUlMSjMJl+a2kO
iSuJe/wuHWzB//l/KqeSs9iqrZqAxaOTaXAInaI5mL0jYF5QmjADnDm7cgmILU+e
caNstptyVVCyFtLR2S9xiygBwX1alqijUWyozuPSulmOXOvxlDtrl3EiDUHu27oX
AoQzJ/A5OR2FhfNuNT2tf2u2ugX1R7J6JgkpJkzvGML3wfZiZ8rJLglHuIgqp4ev
/yl2nA2ajZMFCUp7oRBzS2ohhi3DQ7QcwwNpIoAh0tedgrNSmDEffeckMr78mp9M
DSI0KES56E4bhLBCOXaSTVg/Fw/7KJ4C7deAeNKpDJt4VFMjpMx9jRngb8DTcyMK
uogdeusBAgMBAAECggEATCveZccVSPo906qNAh9+FhwPC1ioUsu67dMpkMbR9NBc
s4Z1UXECXvb+1r1CLkmBaNnCygqrpbLus4xujZbFDmvJlXaeJflXiazeMfrd6ubP
GG4lrDst4SVKz7s2BAMkY4IJVLVBadAr1vDc4+OsrSRtEpoTxuqm5hMhSoFPfzqB
TXdXkeh38knLJciucC+u5+x+ntEWyNaDwZ9pAV8gau05SgzqH12VyzjQJD6UPEum
9BuGt09gYTquiTLAev1yfZJ07Vt6s9G0yRvgcP7DVJ54J+eehjLjMVIO0LC4S1A3
qgYDzX7TAMHwqaUp3Xi8fLQpoYZxbZmHy2jcxTXFrQKBgQDxpmo5TfGYTRQimTWU
9a6L9Xb3kOHI9Ilj63WC0/bg7CCQNLw9iL+Nf8SZKXifed3QWEEj0ZjgpL/YoStn
ZN2DpPBRsh4j/Xf607BKhg4LYkH4TFfQ7J68YLKy/PlZKIJN3fzPxDzTvBXJCgoC
CBasE1271NSmnn7MeYLrlPtVhwKBgQDhNHHbEjmwbXQwC3sT1Elcvrmwxk+ugAxo
SwcZO/v8Sfu4YA6Pr/tH6/cb1DY8J/moKSqkOWFbMehc4bSbJxUy0Jaa0nTMtxJq
GDnahBBjJT5N+lkCBmO8Yfiom5b7jnSGsVs8C8lkzgPxXOeAAoeY2Yw2Ob1/Q2kI
0YHTsardNwKBgHGyHdp2bMx+v5GDym0apxRc2quidEkrnRTxLOnwNwEeuDeBSGwf
UhkOW37Av2sxWIb1bzieHjLb8RPIyPYtw/dzfErD5mCCmpfeT9zQc/0xLt7XdJ3t
JsZ1LL8APYWQhLaEUrWinAf3P7zGR20rRxeRSRHeEmuOuRQVu96ByzszAoGAdmFt
IC1aaeV/V+oRDoFe9wPC9KuqIx5oZ0JZPuiLMzlouqizvtBRFKNzSAurXr/cuoq5
QY5wINIBRSHYIF7RA98l68iJYTsy0NZfQwB85MElGTRqKWhWcSZG4cq3+RAPLOZ6
APxpJzYsWGnvvxiVUsG4tKBxfj5fLiWDS1ZvbM0CgYBduL47eO5vG0feZzrZhoeX
ZH6qYlcA/eDVBFnwHH4VU2c54a6aBy2Nk61Xu90LC4OrcxG9rpbTohep0PRBOzvZ
xKzpv/D/yj9GeK+K+As4HeATQYiIRF3unHZBj0oym939YBwfPfhwmBy7OnlXjwZY
OueEvpqT8BDEH2PoFN2wUA==
-----END PRIVATE KEY-----
PEM;

function mimeServer(array $attributes = []): DeliveryServer
{
    return new DeliveryServer(array_merge([
        'type' => 'smtp',
        'name' => 'Relay',
        'from_email' => 'server@example.com',
        'from_name' => 'Server Identity',
        'reply_to_email' => 'server-replies@example.com',
        'force_from' => false,
        'force_reply_to' => false,
        'dkim_signing_enabled' => false,
    ], $attributes));
}

function mimeMessage(array $overrides = []): Message
{
    return new Message(...array_merge([
        'fromEmail' => 'campaign@example.org',
        'fromName' => 'Campaign Sender',
        'to' => ['jane@example.net' => 'Jane Doe'],
        'subject' => 'Hello',
        'html' => '<p>Hi Jane</p>',
        'text' => 'Hi Jane',
        'headers' => [],
    ], $overrides));
}

it('builds an RFC 2822 message with the campaign identity by default', function (): void {
    $mime = (new MimeBuilder)->build(mimeMessage(), mimeServer())->toString();

    expect($mime)->toContain('From: Campaign Sender <campaign@example.org>')
        ->and($mime)->toContain('To: Jane Doe <jane@example.net>')
        ->and($mime)->toContain('Subject: Hello')
        ->and($mime)->toContain('Reply-To: server-replies@example.com');
});

// F-2.9.2-027 — Force FROM / Force REPLY-TO override the message envelope
it('forces the server identity when force flags are set', function (): void {
    $server = mimeServer(['force_from' => true, 'force_reply_to' => true, 'reply_to_email' => 'must-reply@example.com']);

    $builder = new MimeBuilder;
    $mime = $builder->build(mimeMessage(['replyTo' => 'ignored@example.org']), $server)->toString();

    expect($builder->effectiveFrom(mimeMessage(), $server))->toBe(['server@example.com', 'Server Identity'])
        ->and($builder->effectiveReplyTo(mimeMessage(['replyTo' => 'ignored@example.org']), $server))->toBe('must-reply@example.com')
        ->and($mime)->toContain('From: Server Identity <server@example.com>')
        ->and($mime)->toContain('Reply-To: must-reply@example.com')
        ->and($mime)->not->toContain('campaign@example.org');
});

// F-2.9.2-032 — server custom headers stamped on every message (server wins)
it('stamps custom headers with server policy winning collisions', function (): void {
    $server = mimeServer(['custom_headers' => ['X-Mailer-Pool' => 'pool-7', 'X-Origin' => 'server']]);
    $message = mimeMessage(['headers' => ['X-Origin' => 'campaign', 'List-Id' => 'news.example.org']]);

    $mime = (new MimeBuilder)->build($message, $server)->toString();

    expect($mime)->toContain('X-Mailer-Pool: pool-7')
        ->and($mime)->toContain('List-Id: news.example.org')
        ->and($mime)->toContain('X-Origin: server')
        ->and($mime)->not->toContain('X-Origin: campaign');
});

it('attaches binary attachments', function (): void {
    $message = mimeMessage([
        'attachments' => [['name' => 'report.pdf', 'content' => '%PDF-1.4 fake', 'mime' => 'application/pdf']],
    ]);

    $mime = (new MimeBuilder)->build($message, mimeServer())->toString();

    expect($mime)->toContain('Content-Type: application/pdf')
        ->and($mime)->toContain('name=report.pdf')
        ->and($mime)->toContain(base64_encode('%PDF-1.4 fake'));
});

// F-2.9.2-035/036 — DKIM signing with the server's key + selector
it('signs with DKIM when enabled', function (): void {
    $server = mimeServer([
        'dkim_signing_enabled' => true,
        'dkim_private_key' => TEST_DKIM_KEY,
        'dkim_selector' => 's1',
    ]);

    $signed = (new MimeBuilder)->build(mimeMessage(), $server);
    $header = $signed->getHeaders()->get('DKIM-Signature')?->getBodyAsString() ?? '';

    expect($signed->getHeaders()->has('DKIM-Signature'))->toBeTrue()
        ->and($header)->toContain('d=example.org') // effective From domain (not forced)
        ->and($header)->toContain('s=s1');
});

it('does not sign when the toggle is off or the key is missing', function (): void {
    $off = (new MimeBuilder)->build(mimeMessage(), mimeServer());
    $keyless = (new MimeBuilder)->build(mimeMessage(), mimeServer(['dkim_signing_enabled' => true]));

    expect($off->getHeaders()->has('DKIM-Signature'))->toBeFalse()
        ->and($keyless->getHeaders()->has('DKIM-Signature'))->toBeFalse();
});

it('normalises recipient lists and maps', function (): void {
    $list = new Message(fromEmail: 'a@b.c', to: ['x@y.z', 'q@r.s']);
    $map = new Message(fromEmail: 'a@b.c', to: ['x@y.z' => 'X', 'q@r.s' => null]);

    expect($list->recipients())->toBe(['x@y.z' => null, 'q@r.s' => null])
        ->and($map->recipients())->toBe(['x@y.z' => 'X', 'q@r.s' => null])
        ->and($list->firstRecipient())->toBe('x@y.z');
});
