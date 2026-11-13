<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lists;

use App\Enums\ExportFormat;
use App\Http\Controllers\Controller;
use App\Models\ExportJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a finished export CSV (spec F-2.2.8-004/005). Defense in depth:
 * the route requires a valid (expiring) signature AND an authenticated
 * customer AND ExportJobPolicy::download — a leaked signed URL is useless
 * to anyone but the owning tenant. Expired exports 410.
 */
class ExportDownloadController extends Controller
{
    public function __invoke(Request $request, string $uid): StreamedResponse
    {
        /** @var ExportJob $export */
        $export = ExportJob::withoutTenancy()->where('uid', $uid)->firstOrFail();

        Gate::forUser($request->user('customer'))->authorize('download', $export);

        abort_unless($export->isDownloadable(), 410, __('imports.export.expired'));

        // WP-17: campaign-report exports (scope=report), WP-31 survey responder
        // exports (scope=survey) and WP-46 custom report-builder exports
        // (scope=report_builder) share this signed route; name + content type
        // follow the export kind/extension (D-101).
        $extension = strtolower(pathinfo((string) $export->file_path, PATHINFO_EXTENSION) ?: 'csv');
        $prefix = match ($export->scope) {
            'report' => 'campaign-report-',
            'report_builder' => 'custom-report-',
            'survey' => 'survey-responders-',
            default => 'subscribers-',
        };

        return Storage::disk('local')->download(
            (string) $export->file_path,
            $prefix.$export->uid.'.'.$extension,
            // WP-26 (F-2.3.8-018): the stored extension decides the type —
            // csv / zip / xlsx.
            ['Content-Type' => ExportFormat::mimeForExtension($extension)],
        );
    }
}
