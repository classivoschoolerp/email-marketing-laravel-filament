<?php

declare(strict_types=1);

namespace App\Http\Controllers\Blacklist;

use App\Http\Controllers\Controller;
use App\Jobs\Blacklist\ExportBlacklistCsvJob;
use App\Models\GlobalBlacklistEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the admin-wide queued blacklist export (§3.17 F-3.17-002) behind
 * the `blacklist.manage` admin permission (GlobalBlacklistEmailPolicy).
 */
class AdminBlacklistExportDownloadController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        Gate::forUser($request->user('admin'))->authorize('viewAny', GlobalBlacklistEmail::class);

        abort_unless(Storage::disk('local')->exists(ExportBlacklistCsvJob::ADMIN_PATH), 404);

        return Storage::disk('local')->download(ExportBlacklistCsvJob::ADMIN_PATH, 'blacklist.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
