<?php

declare(strict_types=1);

use App\Services\Content\PlainTextGenerator;

// §2.3.3 F-2.3.3-006 — auto-generated plain-text version.

beforeEach(function (): void {
    $this->generator = new PlainTextGenerator;
});

it('converts block elements to line breaks and strips tags', function (): void {
    $html = '<html><body><h1>Title</h1><p>First paragraph.</p><p>Second<br>line</p></body></html>';

    expect($this->generator->generate($html))
        ->toBe("Title\n\nFirst paragraph.\n\nSecond\nline");
});

it('preserves link destinations as text (url)', function (): void {
    $html = '<p>Read <a href="https://example.com/post">our post</a> now.</p>';

    expect($this->generator->generate($html))
        ->toBe('Read our post (https://example.com/post) now.');
});

it('keeps merge-tag links intact for send-time personalization', function (): void {
    $html = '<p><a href="{UNSUBSCRIBE_URL}">Unsubscribe</a></p>';

    expect($this->generator->generate($html))
        ->toBe('Unsubscribe ({UNSUBSCRIBE_URL})');
});

it('drops style, script and comments entirely', function (): void {
    $html = '<style>p { color: red; }</style><script>alert(1)</script><!-- hidden --><p>Visible</p>';

    expect($this->generator->generate($html))->toBe('Visible');
});

it('renders list items with markers', function (): void {
    $html = '<ul><li>One</li><li>Two</li></ul>';

    expect($this->generator->generate($html))->toBe("- One\n- Two");
});

it('decodes entities and normalizes whitespace', function (): void {
    $html = "<p>Fish&nbsp;&amp;\u{00A0}Chips &lt;fresh&gt;</p>\n\n\n<p>Next</p>";

    expect($this->generator->generate($html))->toBe("Fish & Chips <fresh>\n\nNext");
});

it('passes CJK and emoji through untouched', function (): void {
    $html = '<p>こんにちは 世界</p><p>你好，世界 🎉</p>';

    expect($this->generator->generate($html))->toBe("こんにちは 世界\n\n你好，世界 🎉");
});

it('returns an empty string for empty input', function (): void {
    expect($this->generator->generate(null))->toBe('')
        ->and($this->generator->generate('   '))->toBe('');
});
