<?php

declare(strict_types=1);

namespace App\Http\Controllers\Blacklist;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\GlobalBlacklistEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the customer's queued blacklist export (§2.9.10 F-2.9.10-003).
 * Auth'd customer + GlobalBlacklistEmailPolicy::viewAny; 404 until the
 * queued ExportBlacklistCsvJob has produced the file.
 */
class BlacklistExportDownloadController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        Gate::forUser($customer)->authorize('viewAny', GlobalBlacklistEmail::class);

        $path = 'customers/'.$customer->uid.'/exports/blacklist.csv';

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, 'blacklist.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
