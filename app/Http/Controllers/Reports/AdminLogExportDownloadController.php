<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Reports\AdminLogExporter;
use App\Support\Roles\RoleChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a finished §3.27 admin log export (F-3.27-015, WP-17). Defense in
 * depth: the route requires a valid expiring signature AND an authenticated
 * admin-guard user with the `logs.view` permission (or Super Admin) — a
 * leaked signed URL is useless to customers or anonymous visitors.
 */
class AdminLogExportDownloadController extends Controller
{
    public function __invoke(Request $request, string $type, string $token): StreamedResponse
    {
        $user = $request->user('admin');

        abort_unless($user instanceof User, 403);

        $checker = app(RoleChecker::class);

        abort_unless(
            $checker->hasActivePermission($user, 'logs.view')
                || $checker->hasActiveRole($user, (string) config('emp.roles.super_admin')),
            403,
        );

        abort_unless(in_array($type, AdminLogExporter::TYPES, true), 404);
        abort_unless(preg_match('/^[A-Za-z0-9]{16,64}$/', $token) === 1, 404);

        $path = AdminLogExporter::relativePath($type, $token);

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download(
            $path,
            $type.'-log-export.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
