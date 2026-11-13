<?php

declare(strict_types=1);

namespace App\Services\Content;

/**
 * One accessibility problem found in an email body (spec §2.3.5 F-2.3.5-007).
 *
 * `rule` is the stable machine key (never translated — it is what tests,
 * exports and the API assert on); `label` and `fix` are the human strings and
 * come from lang/{locale}/accessibility.php. `excerpt` is the offending markup,
 * truncated, so the customer can find it in their own HTML. `context` carries
 * the rule-specific numbers (measured contrast ratio, required ratio, font
 * size, heading levels) — it is never prose, so a caller can format it.
 */
final readonly class AccessibilityFinding
{
    public function __construct(
        public string $rule,
        public string $severity,
        public string $excerpt,
        public string $label,
        public string $fix,
        /** @var array<string, scalar> */
        public array $context = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'severity' => $this->severity,
            'excerpt' => $this->excerpt,
            'label' => $this->label,
            'fix' => $this->fix,
            'context' => $this->context,
        ];
    }
}
