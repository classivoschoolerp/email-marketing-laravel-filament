<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automations;

use App\Models\Automation;
use App\Services\Automations\AutomationStats;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * F-2.4.3-007 — CSV export of the automation report (summary + per-node
 * stats). Policy-checked + tenancy-scoped (the global scope 404s foreign
 * uids before the policy runs).
 */
class AutomationReportExportController
{
    public function __invoke(string $uid, AutomationStats $stats): StreamedResponse
    {
        /** @var Automation $automation */
        $automation = Automation::query()->where('uid', $uid)->firstOrFail();

        Gate::authorize('view', $automation);

        $rows = $stats->exportRows($automation);
        $filename = 'automation-report-'.$automation->uid.'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for spreadsheet apps

            foreach ($rows as $row) {
                fputcsv($out, $row === [] ? [''] : $row);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
