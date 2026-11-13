<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Exports\AdminListsExportJob;
use App\Models\User;
use App\Support\Roles\RoleChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a finished §3.6 lists-overview export (F-3.6-005, WP-18). Defense
 * in depth mirroring the WP-17 log-export download: valid expiring signature
 * AND an authenticated admin-guard user with the `lists.view` permission (or
 * Super Admin) — a leaked signed URL is useless to customers or anonymous
 * visitors.
 */
class ListsOverviewExportDownloadController extends Controller
{
    public function __invoke(Request $request, string $token): StreamedResponse
    {
        $user = $request->user('admin');

        abort_unless($user instanceof User, 403);

        $checker = app(RoleChecker::class);

        abort_unless(
            $checker->hasActivePermission($user, 'lists.view')
                || $checker->hasActiveRole($user, (string) config('emp.roles.super_admin')),
            403,
        );

        abort_unless(preg_match('/^[A-Za-z0-9]{16,64}$/', $token) === 1, 404);

        $path = AdminListsExportJob::relativePath($token);

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download(
            $path,
            'lists-overview.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
