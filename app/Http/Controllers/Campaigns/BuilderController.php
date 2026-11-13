<?php

declare(strict_types=1);

namespace App\Http\Controllers\Campaigns;

use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\BuilderAssetRequest;
use App\Http\Requests\Campaigns\BuilderSaveRequest;
use App\Models\Campaign;
use App\Services\Templates\UnsubscribeTagChecker;
use App\Support\Templates\DarkModeStyles;
use App\Support\Templates\MobileVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * GrapesJS campaign-content endpoints (spec §2.3.3): debounced autosave +
 * image upload to the campaign media directory. Campaigns are resolved by
 * uid without tenancy so authorization is a deterministic policy decision —
 * cross-tenant rows yield 403 (CampaignPolicy::update), unknown uids 404.
 * Editing is refused once sending starts (§2.3.9 edit-until-sending).
 */
class BuilderController extends Controller
{
    public function save(BuilderSaveRequest $request, string $uid, UnsubscribeTagChecker $checker): JsonResponse
    {
        $campaign = $this->resolve($request->user('customer'), $uid);

        $campaign->forceFill([
            // F-2.5.4-006: hide-on-mobile blocks need their media query to be
            // saved with them — it cannot be inlined (MobileVisibility).
            // F-2.5.4-013: same story for the dark-mode rules (DarkModeStyles).
            'content_html' => DarkModeStyles::apply(
                MobileVisibility::apply($request->validated('content_html')),
            ),
            'content_json' => $request->validated('content_json'),
        ])->save();

        $unsubscribeOk = $checker->passes($campaign->content_html);

        return response()->json([
            'saved' => true,
            'saved_at' => $campaign->refresh()->updated_at?->toIso8601String(),
            'unsubscribe_ok' => $unsubscribeOk,
            'warning' => $unsubscribeOk ? null : __('campaigns.warnings.missing_unsubscribe'),
        ]);
    }

    public function uploadAsset(BuilderAssetRequest $request, string $uid): JsonResponse
    {
        $campaign = $this->resolve($request->user('customer'), $uid);

        $file = $request->file('file');
        $name = Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 100, '');
        $fileName = Str::slug($name).'-'.Str::lower(Str::random(8)).'.'.Str::lower($file->getClientOriginalExtension());

        $path = $file->storePubliclyAs($campaign->assetDirectory(), $fileName, 'public');

        return response()->json([
            'url' => Storage::disk('public')->url((string) $path),
            'path' => $path,
        ]);
    }

    private function resolve(mixed $customer, string $uid): Campaign
    {
        $campaign = Campaign::withoutTenancy()->where('uid', $uid)->firstOrFail();

        Gate::forUser($customer)->authorize('update', $campaign);

        abort_unless($campaign->isEditable(), 403, __('campaigns.errors.not_editable'));

        return $campaign;
    }
}
