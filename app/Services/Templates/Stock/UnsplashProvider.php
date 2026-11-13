<?php

declare(strict_types=1);

namespace App\Services\Templates\Stock;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Unsplash stock image library (spec §2.5.4 F-2.5.4-010, WP-42).
 *
 * Documented API:
 *   GET https://api.unsplash.com/search/photos?query=…&page=…&per_page=…
 *   Authorization: Client-ID <access key>
 *
 * Attribution: Unsplash's API guidelines require crediting the photographer
 * and Unsplash, which is why `author`/`author_url` are carried on every
 * result and the picker renders a credit line (builder.stock.credit).
 */
class UnsplashProvider implements StockImageProvider
{
    public const BASE_URL = 'https://api.unsplash.com';

    public function __construct(private readonly StockImageCredentials $credentials) {}

    public function key(): string
    {
        return 'unsplash';
    }

    public function label(): string
    {
        return __('builder.stock.providers.unsplash');
    }

    public function isConfigured(): bool
    {
        return $this->credentials->has($this->key());
    }

    /** @return list<string> */
    public function imageHosts(): array
    {
        return ['images.unsplash.com', 'plus.unsplash.com'];
    }

    /** @return list<StockPhoto> */
    public function search(string $query, int $page = 1, int $perPage = 24): array
    {
        $apiKey = $this->credentials->get($this->key());

        if ($apiKey === null) {
            return [];
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'Client-ID '.$apiKey])
                ->timeout((int) config('emp-templates.stock.timeout', 10))
                ->get(self::BASE_URL.'/search/photos', [
                    'query' => $query,
                    'page' => $page,
                    'per_page' => $perPage,
                ]);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $out = [];

        foreach ((array) $response->json('results', []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $thumb = (string) data_get($item, 'urls.thumb', '');
            $full = (string) data_get($item, 'urls.regular', '');

            if ($thumb === '' || $full === '') {
                continue;
            }

            $out[] = new StockPhoto(
                provider: $this->key(),
                id: (string) data_get($item, 'id', ''),
                thumbUrl: $thumb,
                fullUrl: $full,
                author: (string) data_get($item, 'user.name', ''),
                authorUrl: (string) data_get($item, 'user.links.html', ''),
                alt: (string) (data_get($item, 'alt_description') ?? ''),
                width: (int) data_get($item, 'width', 0),
                height: (int) data_get($item, 'height', 0),
            );
        }

        return $out;
    }
}
