<?php

declare(strict_types=1);

namespace App\Services\Content\InboxPreview;

/**
 * One rendered client screenshot in an inbox-preview run (spec §2.3.5
 * F-2.3.5-006, WP-46).
 *
 * `client` is the PROVIDER'S key (litmus `ol2016`, Email on Acid
 * `OL2016`) — never normalised across vendors, because a normalisation table
 * would be this build inventing an equivalence between two vendors' device
 * matrices. `label` is whatever the vendor calls it, shown verbatim.
 */
final readonly class InboxPreviewScreen
{
    public const STATE_COMPLETE = 'complete';

    public const STATE_PROCESSING = 'processing';

    public const STATE_FAILED = 'failed';

    public function __construct(
        public string $client,
        public string $label,
        public ?string $os = null,
        public ?string $category = null,
        public string $state = self::STATE_PROCESSING,
        public ?string $imageUrl = null,
        public ?string $thumbnailUrl = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            client: (string) ($row['client'] ?? ''),
            label: (string) ($row['label'] ?? ($row['client'] ?? '')),
            os: isset($row['os']) ? (string) $row['os'] : null,
            category: isset($row['category']) ? (string) $row['category'] : null,
            state: (string) ($row['state'] ?? self::STATE_PROCESSING),
            imageUrl: isset($row['image_url']) ? (string) $row['image_url'] : null,
            thumbnailUrl: isset($row['thumbnail_url']) ? (string) $row['thumbnail_url'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'client' => $this->client,
            'label' => $this->label,
            'os' => $this->os,
            'category' => $this->category,
            'state' => $this->state,
            'image_url' => $this->imageUrl,
            'thumbnail_url' => $this->thumbnailUrl,
        ];
    }
}
