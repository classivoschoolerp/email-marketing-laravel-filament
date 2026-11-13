<?php

declare(strict_types=1);

namespace App\Http\Controllers\Suppression;

use App\Http\Controllers\Controller;
use App\Jobs\Suppression\ExportSuppressionListJob;
use App\Models\SuppressionList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a queued suppression-list export (§2.9.11 F-2.9.11-005). Auth'd
 * customer + SuppressionListPolicy::download (owner only); 404 until the
 * queued export produced the file.
 */
class SuppressionExportDownloadController extends Controller
{
    public function __invoke(Request $request, string $uid): StreamedResponse
    {
        /** @var SuppressionList $list */
        $list = SuppressionList::withoutTenancy()->where('uid', $uid)->firstOrFail();

        Gate::forUser($request->user('customer'))->authorize('download', $list);

        $path = ExportSuppressionListJob::pathFor($list);

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, 'suppression-'.$list->uid.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
