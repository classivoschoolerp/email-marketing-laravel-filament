<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Reports\AdminOverviewExporter;
use App\Support\Roles\RoleChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a finished §3.9 / §3.10 / §3.12 overview export (WP-40).
 *
 * Defense in depth, mirroring the WP-18 lists-overview download: a valid
 * expiring signature AND an authenticated admin-guard user holding the SAME
 * permission the overview screen requires. A leaked signed URL is useless to
 * a customer, an anonymous visitor, or an admin who may not see that module.
 */
class ModuleOverviewExportDownloadController extends Controller
{
    public function __invoke(Request $request, string $type, string $token): StreamedResponse
    {
        $user = $request->user('admin');

        abort_unless($user instanceof User, 403);
        abort_unless(in_array($type, AdminOverviewExporter::TYPES, true) && AdminOverviewExporter::available($type), 404);

        $checker = app(RoleChecker::class);

        $permission = match ($type) {
            // The ADMIN_VIEW permissions of SurveyPolicy, LandingPagePolicy
            // and TransactionalEmailLogPolicy.
            AdminOverviewExporter::TYPE_SURVEYS => 'surveys.view',
            AdminOverviewExporter::TYPE_LANDING_PAGES => 'landing_pages.view',
            default => 'transactional.view',
        };

        abort_unless(
            $checker->hasActivePermission($user, $permission)
                || $checker->hasActiveRole($user, (string) config('emp.roles.super_admin')),
            403,
        );

        abort_unless(preg_match('/^[A-Za-z0-9]{16,64}$/', $token) === 1, 404);

        $path = AdminOverviewExporter::relativePath($type, $token);

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download(
            $path,
            str_replace('_', '-', $type).'-overview.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
