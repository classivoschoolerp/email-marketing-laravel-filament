<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Services\Install\Installer;
use App\Settings\LicenseSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

// §6.9 F-6.9-001 — the installer wizard, end to end.
//
// SAFETY: the Installer is a singleton bound from routes/modules/install.php,
// so pointing it at a scratch .env and a scratch marker file here guarantees
// the real environment is never written by a test run.

beforeEach(function (): void {
    $this->scratch = storage_path('framework/testing/wp24-install-'.uniqid());
    File::ensureDirectoryExists($this->scratch);

    $this->envPath = $this->scratch.'/.env';
    $this->markerPath = $this->scratch.'/installed';

    File::put($this->envPath, "APP_NAME=Existing\nDB_CONNECTION=sqlite\n# a comment\n");

    app(Installer::class)
        ->useEnvPath($this->envPath)
        ->useMarkerPath($this->markerPath);
});

afterEach(function (): void {
    File::deleteDirectory($this->scratch);
});

/* ================================================================ *
 *  Step 1 — requirements
 * ================================================================ */

it('shows the requirements screen', function (): void {
    $this->get('/install')
        ->assertOk()
        ->assertSee(__('install.requirements.title'))
        ->assertSee(PHP_VERSION)
        ->assertSee('mbstring');
});

it('reports each requirement group with an honest verdict', function (): void {
    $requirements = app(Installer::class)->requirements();

    expect($requirements['php']['ok'])->toBeTrue()
        ->and($requirements['php']['required'])->toBe('8.2.0')
        ->and($requirements['memory'])->toHaveKeys(['ok', 'required_mb', 'current'])
        ->and(collect($requirements['extensions'])->firstWhere('name', 'mbstring')['ok'])->toBeTrue()
        ->and(collect($requirements['extensions'])->firstWhere('name', 'mbstring')['required'])->toBeTrue()
        ->and(collect($requirements['extensions'])->firstWhere('name', 'intl')['required'])->toBeFalse()
        ->and(collect($requirements['paths'])->pluck('path')->all())->toContain('storage', 'bootstrap/cache')
        ->and(collect($requirements['paths'])->every(fn (array $p): bool => $p['allowed']))->toBeTrue()
        ->and($requirements['open_basedir']['enabled'])->toBe(App\Support\Files::openBasedirEnabled())
        ->and($requirements['passed'])->toBeTrue();
});

/* ================================================================ *
 *  Step 2 — database: connection test + .env write + migrations
 * ================================================================ */

it('writes the database configuration into the injected .env only', function (): void {
    $installer = app(Installer::class);

    $installer->writeEnv([
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => 'db.example.test',
        'DB_PASSWORD' => 'p a s s#word',
        'APP_URL' => 'https://emp.example.test',
    ]);

    $contents = File::get($this->envPath);

    expect($contents)->toContain('APP_NAME=Existing')      // untouched keys survive
        ->toContain('# a comment')                          // comments survive
        ->toContain('DB_CONNECTION=mysql')                  // existing key replaced in place
        ->toContain('DB_HOST=db.example.test')
        ->toContain('DB_PASSWORD="p a s s#word"');          // quoted when it needs quoting

    expect(substr_count($contents, 'DB_CONNECTION='))->toBe(1);

    $parsed = $installer->readEnv();

    expect($parsed['DB_HOST'])->toBe('db.example.test')
        ->and($parsed['APP_URL'])->toBe('https://emp.example.test');

    // The real project .env is untouched.
    expect(File::get(base_path('.env')))->not->toContain('db.example.test');
});

it('rejects an unreachable database instead of writing anything', function (): void {
    $before = File::get($this->envPath);

    $this->post('/install/database', [
        'driver' => 'sqlite',
        'database' => $this->scratch.'/does-not-exist.sqlite',
    ])->assertSessionHasErrors('database');

    expect(File::get($this->envPath))->toBe($before);
});

it('tests a connection before accepting it', function (): void {
    $installer = app(Installer::class);
    $file = $this->scratch.'/probe.sqlite';
    File::put($file, '');

    expect($installer->testConnection(['driver' => 'sqlite', 'database' => $file])['ok'])->toBeTrue()
        ->and($installer->testConnection(['driver' => 'sqlite', 'database' => $this->scratch.'/nope.sqlite'])['ok'])->toBeFalse()
        ->and($installer->testConnection([
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => 'x',
        ])['ok'])->toBeFalse();
});

/* ================================================================ *
 *  Steps 2–5 — the full flow
 * ================================================================ */

it('walks the whole wizard and marks the installation complete', function (): void {
    $installer = app(Installer::class);
    $database = $this->scratch.'/install.sqlite';
    File::put($database, '');

    // Step 2. The test database stays :memory: (RefreshDatabase); the wizard
    // proves it can connect, write .env and run migrations against the target.
    $this->post('/install/database', [
        'driver' => 'sqlite',
        'database' => $database,
        'app_url' => 'https://emp.example.test',
    ])->assertRedirect(route('install.admin'));

    expect($installer->readEnv()['DB_DATABASE'])->toBe($database);

    // Step 3 — administrator account with the locked Super Admin role.
    $this->get('/install/admin')->assertOk()->assertSee(__('install.admin.title'));

    $this->post('/install/admin', [
        'name' => 'First Admin',
        'email' => 'admin@example.test',
        'password' => 'a-very-long-password',
        'password_confirmation' => 'a-very-long-password',
    ])->assertRedirect(route('install.license'));

    $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
    $superAdmin = Role::query()->where('name', config('emp.roles.super_admin'))->firstOrFail();

    expect($admin->name)->toBe('First Admin')
        ->and($superAdmin->locked)->toBeTrue()
        ->and($superAdmin->users()->where('users.id', $admin->id)->exists())->toBeTrue();

    // Step 4 — license (D-09: empty key = self-hosted, always valid).
    $this->get('/install/license')->assertOk();
    $this->post('/install/license', ['license_key' => ''])->assertRedirect(route('install.services'));

    // Step 5 — cron/queue instructions.
    $this->get('/install/services')
        ->assertOk()
        ->assertSee('schedule:run')
        ->assertSee('queue:work')
        ->assertSee('queue:heartbeat')
        ->assertSee('[program:emp-worker]');

    // The marker is only written by the final step.
    expect($installer->markerExists())->toBeFalse();

    $this->post('/install/complete')->assertRedirect('/admin');

    expect($installer->markerExists())->toBeTrue()
        ->and($installer->installed())->toBeTrue()
        ->and(File::exists($this->markerPath))->toBeTrue();
});

it('validates the administrator step', function (): void {
    session(['install.database' => true]);

    $this->post('/install/admin', [
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'short',
        'password_confirmation' => 'mismatch',
    ])->assertSessionHasErrors(['name', 'email', 'password']);

    expect(User::query()->where('email', 'not-an-email')->exists())->toBeFalse();
});

it('stores a validated license key', function (): void {
    Http::fake(['licenses.example.test/*' => Http::response(['valid' => true, 'type' => 'pro'])]);
    config(['license.endpoint' => 'https://licenses.example.test/verify']);

    session(['install.database' => true, 'install.admin' => 'admin@example.test']);

    $this->post('/install/license', ['license_key' => 'KEY-123'])
        ->assertRedirect(route('install.services'));

    expect(app(LicenseSettings::class)->refresh()->license_key)->toBe('KEY-123');
});

it('rejects a license the server refuses', function (): void {
    Http::fake(['licenses.example.test/*' => Http::response(['valid' => false, 'message' => 'revoked'])]);
    config(['license.endpoint' => 'https://licenses.example.test/verify']);

    session(['install.database' => true, 'install.admin' => 'admin@example.test']);

    $this->post('/install/license', ['license_key' => 'BAD'])
        ->assertSessionHasErrors('license_key');
});

/* ================================================================ *
 *  Step guards + the installed lock-out
 * ================================================================ */

it('keeps the wizard steps in order', function (): void {
    $this->get('/install/admin')->assertRedirect(route('install.database'));

    session(['install.database' => true]);
    $this->get('/install/license')->assertRedirect(route('install.admin'));

    session(['install.admin' => 'admin@example.test']);
    $this->get('/install/services')->assertRedirect(route('install.license'));

    $this->post('/install/complete')->assertRedirect(route('install.license'));
});

it('locks the wizard out when an administrator already exists, even without a marker', function (): void {
    // An existing deployment upgraded to a version shipping the wizard has no
    // marker file — /install must NOT be a public Super-Admin factory.
    expect(app(Installer::class)->installed())->toBeFalse();

    User::factory()->create();

    expect(app(Installer::class)->hasAdministrator())->toBeTrue()
        ->and(app(Installer::class)->installed())->toBeTrue();

    $this->get('/install')->assertNotFound();
});

it('404s every installer route once the marker exists', function (): void {
    app(Installer::class)->markInstalled();

    foreach (['/install', '/install/database', '/install/admin', '/install/license', '/install/services'] as $url) {
        $this->get($url)->assertNotFound();
    }

    $this->post('/install/database', [])->assertNotFound();
    $this->post('/install/complete')->assertNotFound();

    app(Installer::class)->clearInstalled();

    $this->get('/install')->assertOk();
});
