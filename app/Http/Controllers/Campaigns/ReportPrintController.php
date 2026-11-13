<?php

declare(strict_types=1);

namespace App\Http\Controllers\Campaigns;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\Reports\CampaignComparisonService;
use App\Services\Reports\CampaignReportService;
use Illuminate\Http\Response;

/**
 * Print / PDF campaign report (spec §2.3.8 F-2.3.8-020).
 *
 * A standalone, print-styled HTML page the browser prints or saves as PDF.
 * HONEST NOTE, repeated in the page itself: no PDF library is vendored and no
 * headless browser exists on this platform (feature
 * F-2.5.5-003) — so this ships the print path, not a server-rendered PDF.
 *
 * Authenticated + tenant-scoped: the route sits behind auth:customer and the
 * campaign is resolved through the CustomerScope, so one tenant cannot print
 * another's report. (The unauthenticated read-only view is F-2.3.4-020's
 * secret share URL, which already exists.)
 */
class ReportPrintController extends Controller
{
    public function __construct(
        private readonly CampaignReportService $reports,
        private readonly CampaignComparisonService $comparison,
    ) {}

    public function __invoke(string $campaign): Response
    {
        /** @var Campaign|null $model */
        $model = Campaign::query()->where('uid', $campaign)->orWhere('id', (int) $campaign)->first();

        abort_if($model === null, 404);

        return response()->view('campaigns.report-print', [
            'campaign' => $model,
            'stats' => $this->reports->headerStats($model),
            'topLinks' => $this->reports->topLinks($model, 20),
            'devices' => $this->reports->breakdown($model, 'device'),
            'clients' => $this->reports->breakdown($model, 'client'),
            'geo' => $this->reports->geoBreakdown($model, 20),
            'benchmarks' => $this->comparison->benchmarks($model),
        ]);
    }
}
