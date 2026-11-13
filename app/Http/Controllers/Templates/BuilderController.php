<?php

declare(strict_types=1);

namespace App\Http\Controllers\Templates;

use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\BuilderAssetRequest;
use App\Http\Requests\Templates\BuilderSaveRequest;
use App\Models\EmailTemplate;
use App\Services\Templates\TemplateContentSaver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * GrapesJS builder endpoints (spec §2.5.4): debounced autosave + image upload.
 * Templates are resolved by uid without tenancy so authorization is a
 * deterministic policy decision — cross-tenant and gallery rows yield 403
 * (EmailTemplatePolicy::update), unknown uids 404.
 */
class BuilderController extends Controller
{
    public function save(BuilderSaveRequest $request, string $uid, TemplateContentSaver $saver): JsonResponse
    {
        $template = $this->resolve($request->user('customer'), $uid);

        $result = $saver->save($template, [
            'content_html' => $request->validated('content_html'),
            'content_json' => $request->validated('content_json'),
            'google_fonts' => $request->validated('google_fonts'),
        ]);

        return response()->json([
            'saved' => true,
            'saved_at' => $template->refresh()->updated_at?->toIso8601String(),
            'unsubscribe_ok' => $result['unsubscribe_ok'],
            'warning' => $result['unsubscribe_ok'] ? null : __('templates.warnings.missing_unsubscribe'),
        ]);
    }

    public function uploadAsset(BuilderAssetRequest $request, string $uid): JsonResponse
    {
        $template = $this->resolve($request->user('customer'), $uid);

        $file = $request->file('file');
        $name = Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 100, '');
        $fileName = Str::slug($name).'-'.Str::lower(Str::random(8)).'.'.Str::lower($file->getClientOriginalExtension());

        $path = $file->storePubliclyAs($template->assetDirectory(), $fileName, 'public');

        return response()->json([
            'url' => Storage::disk('public')->url((string) $path),
            'path' => $path,
        ]);
    }

    private function resolve(mixed $customer, string $uid): EmailTemplate
    {
        $template = EmailTemplate::withoutTenancy()->where('uid', $uid)->firstOrFail();

        Gate::forUser($customer)->authorize('update', $template);

        return $template;
    }
}
