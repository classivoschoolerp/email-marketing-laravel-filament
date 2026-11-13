<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Services\Install\Installer;
use App\Services\License\LicenseService;
use App\Settings\LicenseSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * §6.9 F-6.9-001 installer wizard (WP-24).
 *
 * Five steps: requirements → database → admin account → license → services.
 * Progress lives in the session, the "installed" flag is the marker file
 * ({@see Installer}), and the whole route group 404s once that file exists
 * ({@see \App\Http\Middleware\EnsureNotInstalled}).
 *
 * The wizard writes to the .env path the Installer is configured with, which
 * tests point at a scratch file — the real environment is never touched by a
 * test run.
 */
class InstallController extends Controller
{
    public function __construct(private readonly Installer $installer) {}

    /** Step 1 — requirements check. */
    public function requirements(): View
    {
        return view('install.requirements', [
            'requirements' => $this->installer->requirements(),
            'step' => 1,
        ]);
    }

    /** Step 2 — database configuration form. */
    public function database(Request $request): View
    {
        $env = $this->installer->readEnv();

        return view('install.database', [
            'step' => 2,
            'values' => [
                'driver' => $env['DB_CONNECTION'] ?? 'mysql',
                'host' => $env['DB_HOST'] ?? '127.0.0.1',
                'port' => $env['DB_PORT'] ?? '3306',
                'database' => $env['DB_DATABASE'] ?? 'emp',
                'username' => $env['DB_USERNAME'] ?? 'emp',
                'password' => '',
            ],
        ]);
    }

    /** Step 2 POST — test the connection, write .env, run migrations. */
    public function storeDatabase(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'driver' => ['required', 'in:mysql,mariadb,pgsql,sqlite'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'database' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'app_url' => ['nullable', 'url', 'max:255'],
        ]);

        $result = $this->installer->testConnection($data);

        if (! $result['ok']) {
            return back()
                ->withInput($request->except('password'))
                ->withErrors(['database' => __('install.database.failed', ['error' => (string) $result['error']])]);
        }

        $this->installer->writeEnv(array_filter([
            'DB_CONNECTION' => $data['driver'],
            'DB_HOST' => $data['host'] ?? null,
            'DB_PORT' => isset($data['port']) ? (string) $data['port'] : null,
            'DB_DATABASE' => $data['database'],
            'DB_USERNAME' => $data['username'] ?? null,
            'DB_PASSWORD' => $data['password'] ?? null,
            'APP_URL' => $data['app_url'] ?? null,
        ], static fn (mixed $value): bool => $value !== null));

        // Apply to the running process so the next steps can migrate.
        $this->applyConnection($data);

        try {
            $this->installer->migrate();
        } catch (Throwable $exception) {
            return back()
                ->withInput($request->except('password'))
                ->withErrors(['database' => __('install.database.migrate_failed', ['error' => $exception->getMessage()])]);
        }

        $request->session()->put('install.database', true);

        return redirect()->route('install.admin');
    }

    /** Step 3 — administrator account. */
    public function admin(Request $request): View|RedirectResponse
    {
        if (! $request->session()->get('install.database')) {
            return redirect()->route('install.database');
        }

        return view('install.admin', ['step' => 3]);
    }

    public function storeAdmin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $this->installer->createAdmin($data);

        $request->session()->put('install.admin', $data['email']);

        return redirect()->route('install.license');
    }

    /** Step 4 — license key (D-09: empty = self-hosted, always valid). */
    public function license(Request $request): View|RedirectResponse
    {
        if (! $request->session()->get('install.admin')) {
            return redirect()->route('install.admin');
        }

        return view('install.license', ['step' => 4]);
    }

    public function storeLicense(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'license_key' => ['nullable', 'string', 'max:255'],
        ]);

        $key = $data['license_key'] ?? null;

        if ($key !== null && trim($key) !== '') {
            $settings = app(LicenseSettings::class);
            $settings->license_key = $key;

            $result = app(LicenseService::class)->validate($key);
            $settings->status = $result->status;
            $settings->save();

            if (! $result->valid()) {
                return back()->withErrors([
                    'license_key' => $result->message ?? __('install.license.invalid'),
                ]);
            }
        }

        $request->session()->put('install.license', true);

        return redirect()->route('install.services');
    }

    /** Step 5 — cron + queue setup instructions, then finish. */
    public function services(Request $request): View|RedirectResponse
    {
        if (! $request->session()->get('install.license')) {
            return redirect()->route('install.license');
        }

        return view('install.services', [
            'step' => 5,
            'instructions' => $this->installer->serviceInstructions(),
        ]);
    }

    public function complete(Request $request): RedirectResponse
    {
        if (! $request->session()->get('install.license')) {
            return redirect()->route('install.license');
        }

        $this->installer->markInstalled();
        $request->session()->forget('install');

        return redirect('/admin')->with('status', __('install.completed'));
    }

    /**
     * Point the running process at the submitted database for the rest of
     * this request, so `migrate` can run before the new .env is ever loaded.
     *
     * A DEDICATED connection name (`install`) is configured rather than
     * mutating — and purging — the driver's stock connection: purging the
     * connection the process is already using would destroy it, which matters
     * enormously for an in-memory SQLite (the test harness) and is simply
     * safer everywhere else.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyConnection(array $data): void
    {
        $driver = (string) $data['driver'];
        $template = (array) Config::get('database.connections.'.$driver, []);

        $connection = array_merge($template, [
            'driver' => $driver,
            'database' => $data['database'],
        ]);

        if ($driver !== 'sqlite') {
            $connection['host'] = $data['host'] ?? '127.0.0.1';
            $connection['port'] = $data['port'] ?? 3306;
            $connection['username'] = $data['username'] ?? '';
            $connection['password'] = $data['password'] ?? '';
        }

        Config::set('database.connections.install', $connection);

        DB::purge('install');
    }
}
