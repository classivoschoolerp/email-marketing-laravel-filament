<?php

declare(strict_types=1);

namespace App\Http\Controllers\Campaigns;

use App\Http\Controllers\Controller;
use App\Services\Content\Spintax;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Spintax preview/expander endpoint (spec §2.3.3 F-2.3.3-022): returns the
 * permutation count and N sample permutations for arbitrary text.
 */
class SpintaxController extends Controller
{
    public function preview(Request $request, Spintax $spintax): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:2097152'],
            'samples' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $count = (int) ($validated['samples'] ?? config('emp-campaigns.spintax_preview_samples', 5));

        return response()->json([
            'permutations' => $spintax->permutationCount($validated['text']),
            'samples' => $spintax->samples($validated['text'], $count),
        ]);
    }
}
