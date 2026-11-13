<?php

declare(strict_types=1);

namespace App\Support\Templates;

use App\Models\Customer;
use App\Models\StoreConnection;
use App\Services\Ai\Images\AiImageGenerator;
use App\Services\Ecommerce\ProductRecommender;
use App\Services\Templates\BrandKit\BrandKitStore;
use App\Services\Templates\Stock\StockImageRegistry;

/**
 * The WP-42 half of the builder page config: brand kit (F-2.5.4-004), stock
 * image library (F-2.5.4-010) and image editor (F-2.5.4-009).
 *
 * The exact sibling of {@see BuilderBlocksConfig}: ONE source for both builder
 * pages, because the template builder and the campaign content builder mount
 * the SAME resources/js/template-builder.js and share the same tenant-owned
 * brand kit and media library — so the config handed to it must not drift.
 *
 * `stock.providers` is [] when no API key is configured anywhere, and the
 * picker is then simply not rendered: no key, no feature, no error.
 */
final class BuilderStudioConfig
{
    /**
     * @param  mixed  $customer  the authenticated customer, if any
     * @return array<string, mixed>
     */
    public static function payload(mixed $customer): array
    {
        $registry = app(StockImageRegistry::class);

        $payload = [
            'stock' => [
                'searchUrl' => route('builder-media.stock.search'),
                'importUrl' => route('builder-media.stock.import'),
                'providers' => $registry->options(),
            ],
            'imageEditor' => [
                'saveUrl' => route('builder-media.images.store'),
            ],
        ];

        // Optional modules contribute their part of the payload only when
        // they are installed; the builder treats a missing key as "off".
        if (class_exists(BrandKitStore::class)) {
            $brandStore = app(BrandKitStore::class);

            $payload['brandKit'] = [
                'showUrl' => route('brand-kit.show'),
                'saveUrl' => route('brand-kit.update'),
                'logoUrl' => route('brand-kit.logo.store'),
                'fontStacks' => BrandKitStore::FONT_STACKS,
                // Preloaded so opening the builder costs no extra round-trip.
                'data' => $customer instanceof Customer
                    ? $brandStore->present($brandStore->find($customer))
                    : null,
            ];
        }

        if (class_exists(AiImageGenerator::class)) {
            /*
             * WP-46 §2.5.4 F-2.5.4-011 — AI image generation.
             *
             * `generateUrl` is null when no configured engine can draw, and
             * the toolbar button is then never rendered — the same
             * "unconfigured means unavailable" contract `stock.providers`
             * follows, and the same one the server re-enforces on the
             * endpoint. Strings ride along from lang/en/ai.php rather than
             * lang/en/builder.php: this is an AI surface that happens to be
             * mounted in a builder, so its wording lives with the rest of the
             * AI wording.
             */
            $payload['aiImage'] = [
                'generateUrl' => $customer instanceof Customer
                    && app(AiImageGenerator::class)->available($customer)
                        ? route('ai.image')
                        : null,
                'sizes' => array_keys(app(AiImageGenerator::class)->sizeOptions()),
                'cost' => app(AiImageGenerator::class)->imageCost(),
                'i18n' => (array) __('ai.images'),
            ];
        }

        if (class_exists(ProductRecommender::class)) {
            /*
             * WP-45 §2.12 F-2.12-003/004/005 — the in-builder product
             * catalogue browser and the dynamic ecommerce blocks.
             *
             * `enabled` is false until the tenant has connected a store, and
             * neither the browser button nor the ecommerce block category is
             * then rendered — the same "no configuration, no feature, no
             * error" contract `stock.providers` and `aiImage.generateUrl`
             * follow above.
             */
            $payload['ecommerce'] = [
                'enabled' => $customer instanceof Customer && StoreConnection::query()
                    ->where('customer_id', $customer->id)
                    ->where('is_active', true)
                    ->exists(),
                'productsUrl' => route('ecommerce.products.index'),
                'strategies' => ProductRecommender::strategyOptions(),
                'i18n' => [
                    'catalogue' => (array) __('ecommerce.catalogue'),
                    'blocks' => (array) __('ecommerce.blocks'),
                ],
            ];
        }

        return $payload;
    }

    /**
     * Every WP-42 builder string, as the nested `builder` lang array.
     *
     * @return array<string, mixed>
     */
    public static function strings(): array
    {
        /** @var array<string, mixed> $strings */
        $strings = (array) __('builder');

        return $strings;
    }
}
