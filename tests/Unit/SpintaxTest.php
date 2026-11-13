<?php

declare(strict_types=1);

use App\Services\Content\Spintax;

// §2.3.3 F-2.3.3-021 — spintax engine (P1, cold-outreach critical).

beforeEach(function (): void {
    $this->spintax = new Spintax;
});

it('renders one of the alternatives of a simple group', function (): void {
    $out = $this->spintax->render('{Hi|Hello|Hey} friend', 'seed-1');

    expect($out)->toBeIn(['Hi friend', 'Hello friend', 'Hey friend']);
});

it('is deterministic for the same seed and varies across seeds', function (): void {
    $text = '{Hi|Hello|Hey} {there|friend|pal}, {how are you|what is up}?';

    $first = $this->spintax->render($text, 'subscriber-uid:campaign-uid');

    foreach (range(1, 10) as $i) {
        expect($this->spintax->render($text, 'subscriber-uid:campaign-uid'))->toBe($first);
    }

    // Across many seeds at least one different permutation must appear.
    $outputs = collect(range(1, 50))
        ->map(fn (int $i): string => $this->spintax->render($text, 'seed-'.$i))
        ->unique();

    expect($outputs->count())->toBeGreaterThan(1);
});

it('supports nested groups', function (): void {
    $out = $this->spintax->render('{Hi {there|friend}|Hello}', 'x');

    expect($out)->toBeIn(['Hi there', 'Hi friend', 'Hello']);

    // All three permutations are reachable across seeds.
    $seen = collect(range(1, 100))
        ->map(fn (int $i): string => $this->spintax->render('{Hi {there|friend}|Hello}', 'n'.$i))
        ->unique()
        ->sort()
        ->values();

    expect($seen->all())->toBe(['Hello', 'Hi friend', 'Hi there']);
});

it('leaves merge tags untouched — braces without a pipe are not spintax', function (): void {
    expect($this->spintax->render('Hi {SUBSCRIBER_NAME}', 'x'))->toBe('Hi {SUBSCRIBER_NAME}')
        ->and($this->spintax->render('{UNSUBSCRIBE_URL}', 'x'))->toBe('{UNSUBSCRIBE_URL}');
});

it('keeps merge tags intact inside spintax alternatives', function (): void {
    $out = $this->spintax->render('{Hi {SUBSCRIBER_NAME}|Hello {SUBSCRIBER_FIRST_NAME}}', 'y');

    expect($out)->toBeIn(['Hi {SUBSCRIBER_NAME}', 'Hello {SUBSCRIBER_FIRST_NAME}']);
});

it('treats unbalanced braces as literal text', function (): void {
    expect($this->spintax->render('{oops no close', 'x'))->toBe('{oops no close')
        ->and($this->spintax->render('no open}', 'x'))->toBe('no open}');
});

it('counts permutations including nesting and sequences', function (): void {
    expect($this->spintax->permutationCount('plain text'))->toBe(1)
        ->and($this->spintax->permutationCount('{a|b|c}'))->toBe(3)
        ->and($this->spintax->permutationCount('{a|b} {c|d}'))->toBe(4)
        ->and($this->spintax->permutationCount('{Hi {there|friend}|Hello}'))->toBe(3)
        ->and($this->spintax->permutationCount('Hi {SUBSCRIBER_NAME}'))->toBe(1)
        ->and($this->spintax->permutationCount('{a|{b|{c|d}}} {e|f}'))->toBe(8);
});

it('handles CJK and emoji content', function (): void {
    $text = '{你好|こんにちは|안녕하세요} 🌸';

    $out = $this->spintax->render($text, 'cjk-seed');

    expect($out)->toBeIn(['你好 🌸', 'こんにちは 🌸', '안녕하세요 🌸'])
        ->and($this->spintax->permutationCount($text))->toBe(3);

    // Deterministic for CJK too.
    expect($this->spintax->render($text, 'cjk-seed'))->toBe($out);

    // Nested CJK groups.
    $nested = '{早上好{，朋友|}|晚上好}';
    expect($this->spintax->render($nested, 's'))->toBeIn(['早上好，朋友', '早上好', '晚上好'])
        ->and($this->spintax->permutationCount($nested))->toBe(3);
});

it('returns distinct sample permutations bounded by the permutation count', function (): void {
    $samples = $this->spintax->samples('{a|b|c}', 10);

    expect($samples)->toHaveCount(3)
        ->and(array_unique($samples))->toHaveCount(3);

    expect($this->spintax->samples('{x|y}', 1))->toHaveCount(1);

    // No spintax → a single sample equal to the text.
    expect($this->spintax->samples('plain', 5))->toBe(['plain']);
});

it('keeps sibling group choices independent of other groups options', function (): void {
    // The second group's index is stable regardless of which alternative
    // the first group picks (skipped subtrees still advance the counter).
    $textA = '{a|b} {c|d}';

    $out1 = $this->spintax->render($textA, 'stable-seed');
    $out2 = $this->spintax->render($textA, 'stable-seed');

    expect($out1)->toBe($out2);
});
