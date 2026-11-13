<?php

declare(strict_types=1);

namespace App\Http\Controllers\Verification;

use App\Http\Controllers\Controller;
use App\Models\VerificationJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a finished verification run's result CSV (§2.9.12 F-2.9.12-008).
 * Auth'd customer + VerificationJobPolicy::download (owner only).
 */
class VerificationResultDownloadController extends Controller
{
    public function __invoke(Request $request, string $uid): StreamedResponse
    {
        /** @var VerificationJob $job */
        $job = VerificationJob::withoutTenancy()->where('uid', $uid)->firstOrFail();

        Gate::forUser($request->user('customer'))->authorize('download', $job);

        abort_unless(
            $job->result_path !== null && Storage::disk('local')->exists($job->result_path),
            404,
        );

        return Storage::disk('local')->download((string) $job->result_path, 'verification-'.$job->uid.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
