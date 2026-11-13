<?php

declare(strict_types=1);

namespace App\Support\Templates;

use App\Models\Customer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The tenant's shared builder media directory (WP-42).
 *
 * Builder assets uploaded while editing one document land under that
 * document's folder (BuilderController@uploadAsset). Images that are NOT tied
 * to a document — a stock photo picked from the library (F-2.5.4-010) or an
 * image edited on the canvas (F-2.5.4-009) — belong to the tenant instead, the
 * same way saved blocks and the brand kit do (D-86), so they live one level up
 * in a per-customer library folder.
 *
 * Path shape is D-26: the `public` disk, under `customers/{uid}/`.
 */
final class CustomerLibrary
{
    public static function directory(Customer $customer): string
    {
        return 'customers/'.$customer->uid.'/library';
    }

    /**
     * Write bytes into the tenant library and return [path, url].
     *
     * @return array{path: string, url: string}
     */
    public static function put(Customer $customer, string $prefix, string $extension, string $contents): array
    {
        $name = Str::slug($prefix !== '' ? $prefix : 'image').'-'
            .Str::lower(Str::random(8)).'.'.Str::lower($extension);

        $path = self::directory($customer).'/'.$name;

        Storage::disk('public')->put($path, $contents, 'public');

        return ['path' => $path, 'url' => Storage::disk('public')->url($path)];
    }
}
