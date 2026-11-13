<?php

declare(strict_types=1);

namespace App\Services\Templates\Stock;

/**
 * One search result from a stock image library (spec §2.5.4 F-2.5.4-010).
 *
 * Provider-neutral by design: the builder never sees an Unsplash payload or a
 * Pexels payload, only this shape — which is what lets a third library be
 * added later without touching the front end (docs/ARCHITECTURE.md rule 2).
 */
final class StockPhoto
{
    public function __construct(
        public readonly string $provider,
        public readonly string $id,
        /** Small preview used for the picker grid. */
        public readonly string $thumbUrl,
        /** The image actually inserted into the email. */
        public readonly string $fullUrl,
        public readonly string $author,
        public readonly string $authorUrl,
        public readonly string $alt,
        public readonly int $width,
        public readonly int $height,
    ) {}

    /**
     * @return array{
     *     provider: string, id: string, thumb_url: string, full_url: string,
     *     author: string, author_url: string, alt: string, width: int, height: int
     * }
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'id' => $this->id,
            'thumb_url' => $this->thumbUrl,
            'full_url' => $this->fullUrl,
            'author' => $this->author,
            'author_url' => $this->authorUrl,
            'alt' => $this->alt,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
