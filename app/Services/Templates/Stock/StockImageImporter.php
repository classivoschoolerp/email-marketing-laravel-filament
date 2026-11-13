<?php

declare(strict_types=1);

namespace App\Services\Templates\Stock;

use App\Models\Customer;
use App\Support\Templates\CustomerLibrary;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Picked a stock photo" → a real, self-hosted tenant asset
 * (spec §2.5.4 F-2.5.4-010, WP-42).
 *
 * The picked image is DOWNLOADED, not hot-linked. Hot-linking a stock CDN
 * from a bulk email means every open depends on a third party's rate limits
 * and lifetime, and it leaks the recipient's IP to that third party. The bytes
 * are copied once into the tenant's own library (D-26 public disk, D-86 shape:
 * tenant-owned, no document foreign key, so the same photo is usable from both
 * builders).
 *
 * SSRF FENCE — the URL comes from the browser, so it is never trusted:
 *   • https only;
 *   • host must be one the provider itself serves images from
 *     ({@see StockImageProvider::imageHosts()});
 *   • the response must be an image, and under the configured byte ceiling.
 * Anything else is a validation error, not a fetch.
 */
class StockImageImporter
{
    /** content-type → file extension. Nothing else is stored. */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * @return array{path: string, url: string}
     *
     * @throws ValidationException
     */
    public function import(Customer $customer, StockImageProvider $provider, string $url): array
    {
        $this->assertAllowed($provider, $url);

        try {
            $response = Http::timeout((int) config('emp-templates.stock.timeout', 10))
                ->get($url);
        } catch (Throwable) {
            throw ValidationException::withMessages(['url' => __('builder.stock.failed')]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages(['url' => __('builder.stock.failed')]);
        }

        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $extension = self::EXTENSIONS[$contentType] ?? null;

        if ($extension === null) {
            throw ValidationException::withMessages(['url' => __('builder.stock.failed')]);
        }

        $body = $response->body();
        $max = (int) config('emp-templates.stock.max_bytes', 8_388_608);

        if ($body === '' || strlen($body) > $max) {
            throw ValidationException::withMessages(['url' => __('builder.stock.failed')]);
        }

        return CustomerLibrary::put($customer, 'stock-'.$provider->key(), $extension, $body);
    }

    /** @throws ValidationException */
    private function assertAllowed(StockImageProvider $provider, string $url): void
    {
        $parts = parse_url($url);

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || ! in_array($host, $provider->imageHosts(), true)) {
            throw ValidationException::withMessages(['url' => __('builder.stock.failed')]);
        }
    }
}
