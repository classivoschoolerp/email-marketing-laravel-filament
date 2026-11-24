<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landing\BuilderAssetRequest;
use App\Http\Requests\Landing\BuilderSaveRequest;
use App\Models\LandingPage;
use App\Models\LandingPageVariant;
use App\Services\Landing\LandingPageContentSaver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Landing builder endpoints (spec §2.6 F-2.6-002): debounced autosave + image
 * upload — the §2.5.4 template-builder contract, so one JavaScript bundle
 * drives both editors.
 *
 * Pages are resolved by uid WITHOUT tenancy so authorization is a
 * deterministic policy decision: a cross-tenant uid yields 403
 * (LandingPagePolicy::update), an unknown one 404.
 */
class BuilderController extends Controller
{
    public function save(BuilderSaveRequest $request, string $uid, LandingPageContentSaver $saver): JsonResponse
    {
        $page = $this->resolve($request->user('customer'), $uid);
        $variant = $this->resolveVariant($page, $request->validated('variant_uid'));

        $saver->save($page, [
            'content_html' => $request->validated('content_html'),
            'content_json' => $request->validated('content_json'),
        ], $variant);

        return response()->json([
            'saved' => true,
            'saved_at' => $page->refresh()->updated_at?->toIso8601String(),
        ]);
    }

    public function uploadAsset(BuilderAssetRequest $request, string $uid): JsonResponse
    {
        $page = $this->resolve($request->user('customer'), $uid);

        $file = $request->file('file');
        $name = Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 100, '');
        $fileName = Str::slug($name).'-'.Str::lower(Str::random(8)).'.'.Str::lower($file->getClientOriginalExtension());

        $path = $file->storePubliclyAs($page->assetDirectory(), $fileName, 'public');

        return response()->json([
            'url' => Storage::disk('public')->url((string) $path),
            'path' => $path,
        ]);
    }

    private function resolve(mixed $customer, string $uid): LandingPage
    {
        $page = LandingPage::withoutTenancy()->where('uid', $uid)->firstOrFail();

        Gate::forUser($customer)->authorize('update', $page);

        return $page;
    }

    /** A variant uid that is not one of THIS page's arms is a 404, never a silent page save. */
    private function resolveVariant(LandingPage $page, ?string $uid): ?LandingPageVariant
    {
        if ($uid === null || trim($uid) === '') {
            return null;
        }

        return LandingPageVariant::withoutTenancy()
            ->where('landing_page_id', $page->id)
            ->where('uid', $uid)
            ->firstOrFail();
    }
}
