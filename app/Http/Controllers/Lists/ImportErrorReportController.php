<?php

declare(strict_types=1);

namespace App\Http\Controllers\Lists;

use App\Http\Controllers\Controller;
use App\Models\ImportJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the downloadable error CSV of an import run (spec F-2.2.7-012),
 * policy-checked so only the owning tenant can fetch it.
 */
class ImportErrorReportController extends Controller
{
    public function __invoke(Request $request, string $uid): StreamedResponse
    {
        /** @var ImportJob $import */
        $import = ImportJob::withoutTenancy()->where('uid', $uid)->firstOrFail();

        Gate::forUser($request->user('customer'))->authorize('download', $import);

        abort_if($import->error_file_path === null || ! Storage::disk('local')->exists($import->error_file_path), 404);

        return Storage::disk('local')->download(
            $import->error_file_path,
            'import-errors-'.$import->uid.'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
