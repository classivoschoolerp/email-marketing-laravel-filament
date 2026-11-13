<?php

declare(strict_types=1);

namespace App\Http\Controllers\Templates;

use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\EditedImageRequest;
use App\Http\Requests\Templates\StockImportRequest;
use App\Http\Requests\Templates\StockSearchRequest;
use App\Models\Customer;
use App\Services\Templates\Stock\StockImageImporter;
use App\Services\Templates\Stock\StockImageRegistry;
use App\Services\Templates\Stock\StockPhoto;
use App\Support\Templates\CustomerLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared builder media endpoints (WP-42):
 *   F-2.5.4-010  stock image search + import
 *   F-2.5.4-009  save an image edited on the canvas
 *
 * Both are driven by resources/js/template-builder.js, which the template
 * builder and the campaign content builder both mount — so, like saved blocks
 * and the brand kit, the assets they produce belong to the TENANT and carry no
 * document foreign key (D-86's shape, D-26's path).
 *
 * Tenancy: the owner is the authenticated customer, taken from the session.
 * No route here carries a document uid, so there is no cross-tenant path — a
 * customer can only ever write into their own library.
 */
class BuilderMediaController extends Controller
{
    public function searchStock(StockSearchRequest $request, StockImageRegistry $registry): JsonResponse
    {
        $this->customer($request);

        $provider = $registry->get((string) $request->validated('provider'));

        // Unreachable through validation (Rule::in on the configured keys) —
        // kept so a race with a key being cleared cannot 500.
        abort_if($provider === null, 404);

        $photos = $provider->search(
            (string) $request->validated('q'),
            (int) ($request->validated('page') ?? 1),
            (int) config('emp-templates.stock.per_page', 24),
        );

        return response()->json([
            'provider' => $provider->key(),
            'photos' => array_map(static fn (StockPhoto $photo): array => $photo->toArray(), $photos),
        ]);
    }

    public function importStock(
        StockImportRequest $request,
        StockImageRegistry $registry,
        StockImageImporter $importer,
    ): JsonResponse {
        $customer = $this->customer($request);

        $provider = $registry->get((string) $request->validated('provider'));

        abort_if($provider === null, 404);

        $stored = $importer->import($customer, $provider, (string) $request->validated('url'));

        return response()->json($stored, 201);
    }

    public function storeEditedImage(EditedImageRequest $request): JsonResponse
    {
        $customer = $this->customer($request);

        $file = $request->file('file');

        abort_if($file === null, 422);

        $name = (string) ($request->validated('name') ?? 'edited');

        $stored = CustomerLibrary::put(
            $customer,
            $name,
            $file->guessExtension() ?: 'png',
            (string) $file->get(),
        );

        return response()->json($stored, 201);
    }

    private function customer(Request $request): Customer
    {
        $customer = $request->user('customer');

        abort_if(! $customer instanceof Customer, 403);

        return $customer;
    }
}
