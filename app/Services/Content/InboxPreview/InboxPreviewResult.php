<?php

declare(strict_types=1);

namespace App\Services\Content\InboxPreview;

/**
 * The state of one inbox-preview run as the provider reports it (spec §2.3.5
 * F-2.3.5-006, WP-46).
 *
 * `complete` is true only when EVERY screen has left the processing state —
 * a rendering farm answers per client over several minutes, and reporting a
 * half-finished run as finished would show the customer a matrix with silent
 * holes in it.
 */
final readonly class InboxPreviewResult
{
    /**
     * @param  list<InboxPreviewScreen>  $screens
     */
    public function __construct(
        public bool $complete,
        public array $screens = [],
        public ?string $error = null,
    ) {}

    public static function failed(string $error): self
    {
        return new self(complete: true, screens: [], error: $error);
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (InboxPreviewScreen $screen): array => $screen->toArray(), $this->screens);
    }

    /** @param list<InboxPreviewScreen> $screens */
    public static function from(array $screens, ?string $error = null): self
    {
        $pending = false;

        foreach ($screens as $screen) {
            if ($screen->state === InboxPreviewScreen::STATE_PROCESSING) {
                $pending = true;
            }
        }

        return new self(complete: ! $pending, screens: $screens, error: $error);
    }
}
