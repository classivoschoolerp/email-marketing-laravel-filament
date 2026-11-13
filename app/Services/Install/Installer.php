<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Enums\CustomerStatus;
use App\Enums\RoleScope;
use App\Http\Controllers\Auth\RegisterController;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Support\Files;
use Database\Seeders\BillingGatewaySeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PDO;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * §6.9 F-6.9-001 installer wizard engine (WP-24).
 *
 * Everything the wizard does that touches the filesystem or the database
 * lives here so it can be driven from tests without going near the real
 * environment: BOTH the `.env` path and the "installed" marker path are
 * injectable ({@see useEnvPath()} / {@see useMarkerPath()}).
 *
 * The marker file — not a settings row — is the installed flag, because the
 * wizard has to be blockable before any table exists.
 */
class Installer
{
    /**
     * Baseline reference data for a production install, seeded EXPLICITLY
     * rather than through DatabaseSeeder, for two reasons:
     *
     *  1. DatabaseSeeder creates a `test@example.com` user — acceptable for a
     *     dev database, never for a real installation;
     *  2. DatabaseSeeder uses `WithoutModelEvents`, which mutes the spatie
     *     `RefreshesPermissionCache` hook. On an EMPTY database the permission
     *     registrar then keeps the empty collection it loaded before the rows
     *     were created, and RoleSeeder's `syncPermissions()` fails with
     *     "There is no permission named `roles.view`". Running each seeder
     *     directly keeps the model events — and the cache — honest.
     *
     * @var list<class-string>
     */
    private const SEEDERS = [
        RoleSeeder::class,
        LanguageSeeder::class,
        CurrencySeeder::class,
        BillingGatewaySeeder::class,
    ];

    private ?string $envPath = null;

    private ?string $markerPath = null;

    /* ================================================================ *
     *  Paths / state
     * ================================================================ */

    public function useEnvPath(string $path): self
    {
        $this->envPath = $path;

        return $this;
    }

    public function useMarkerPath(string $path): self
    {
        $this->markerPath = $path;

        return $this;
    }

    public function envPath(): string
    {
        return $this->envPath ?? base_path('.env');
    }

    public function markerPath(): string
    {
        return $this->markerPath ?? (string) config('emp-ops.install.marker', storage_path('app/installed'));
    }

    /**
     * An installation is "done" when the marker file exists **or** an
     * administrator account already exists.
     *
     * The second condition is a safety net, not a convenience: an existing
     * deployment upgraded to a version that ships this wizard has no marker
     * file, and without the account check `/install` would be publicly
     * reachable on a live system — letting anyone mint a Super Admin.
     */
    public function installed(): bool
    {
        return $this->markerExists() || $this->hasAdministrator();
    }

    public function markerExists(): bool
    {
        $path = $this->markerPath();

        return Files::accessible($path) && is_file($path);
    }

    public function hasAdministrator(): bool
    {
        try {
            return Schema::hasTable('users')
                && DB::table('users')->exists();
        } catch (Throwable) {
            return false; // No database yet — a genuinely fresh install.
        }
    }

    public function markInstalled(): void
    {
        $path = $this->markerPath();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, Carbon::now()->toIso8601String().PHP_EOL);
    }

    public function clearInstalled(): void
    {
        $path = $this->markerPath();

        if (is_file($path)) {
            File::delete($path);
        }
    }

    /* ================================================================ *
     *  Step 1 — requirements (F-6.9-001/002)
     * ================================================================ */

    /**
     * @return array{
     *     php: array{ok: bool, required: string, current: string},
     *     extensions: list<array{name: string, ok: bool, required: bool}>,
     *     paths: list<array{path: string, ok: bool, allowed: bool}>,
     *     memory: array{ok: bool, required_mb: int, current: string},
     *     open_basedir: array{enabled: bool, paths: list<string>},
     *     passed: bool
     * }
     */
    public function requirements(): array
    {
        $minPhp = (string) config('emp-ops.install.min_php', '8.2.0');

        $php = [
            'ok' => version_compare(PHP_VERSION, $minPhp, '>='),
            'required' => $minPhp,
            'current' => PHP_VERSION,
        ];

        $extensions = [];

        foreach ((array) config('emp-ops.install.required_extensions', []) as $name) {
            $extensions[] = ['name' => (string) $name, 'ok' => extension_loaded((string) $name), 'required' => true];
        }

        foreach ((array) config('emp-ops.install.recommended_extensions', []) as $name) {
            $extensions[] = ['name' => (string) $name, 'ok' => extension_loaded((string) $name), 'required' => false];
        }

        $paths = [];

        foreach ((array) config('emp-ops.install.writable_paths', []) as $relative) {
            $absolute = base_path((string) $relative);
            $inspection = Files::inspect($absolute);

            $paths[] = [
                'path' => (string) $relative,
                'ok' => $inspection['exists'] && $inspection['writable'],
                'allowed' => $inspection['allowed'],
            ];
        }

        $memoryLimit = (string) ini_get('memory_limit');
        $requiredMb = (int) config('emp-ops.install.min_memory_limit_mb', 256);

        $memory = [
            'ok' => $memoryLimit === '-1' || $this->memoryLimitMb($memoryLimit) >= $requiredMb,
            'required_mb' => $requiredMb,
            'current' => $memoryLimit,
        ];

        $passed = $php['ok']
            && $memory['ok']
            && ! in_array(false, array_column(array_filter($extensions, static fn (array $e): bool => $e['required']), 'ok'), true)
            && ! in_array(false, array_column($paths, 'ok'), true);

        return [
            'php' => $php,
            'extensions' => $extensions,
            'paths' => $paths,
            'memory' => $memory,
            'open_basedir' => [
                'enabled' => Files::openBasedirEnabled(),
                'paths' => Files::openBasedirPaths(),
            ],
            'passed' => $passed,
        ];
    }

    /* ================================================================ *
     *  Step 2 — database (F-6.9-001)
     * ================================================================ */

    /**
     * Try a real connection with the submitted credentials.
     *
     * @param  array<string, mixed>  $config
     * @return array{ok: bool, error: ?string}
     */
    public function testConnection(array $config): array
    {
        $driver = (string) ($config['driver'] ?? 'mysql');

        try {
            if ($driver === 'sqlite') {
                $database = (string) ($config['database'] ?? '');

                if ($database !== ':memory:' && ! is_file($database)) {
                    return ['ok' => false, 'error' => __('install.database.sqlite_missing', ['path' => $database])];
                }

                new PDO('sqlite:'.$database);

                return ['ok' => true, 'error' => null];
            }

            $dsn = sprintf(
                '%s:host=%s;port=%s;dbname=%s',
                $driver === 'pgsql' ? 'pgsql' : 'mysql',
                (string) ($config['host'] ?? '127.0.0.1'),
                (string) ($config['port'] ?? 3306),
                (string) ($config['database'] ?? ''),
            );

            new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            return ['ok' => true, 'error' => null];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * Merge values into the target .env, preserving comments and ordering.
     *
     * @param  array<string, string|int|bool|null>  $values
     */
    public function writeEnv(array $values): void
    {
        $path = $this->envPath();

        if (! Files::accessible($path)) {
            throw new \RuntimeException(__('ops.files.open_basedir_denied', ['path' => $path]));
        }

        $contents = is_file($path) ? (string) File::get($path) : '';
        $lines = $contents === '' ? [] : (preg_split('/\r\n|\r|\n/', $contents) ?: []);

        foreach ($values as $key => $value) {
            $encoded = $key.'='.$this->encodeEnvValue($value);
            $replaced = false;

            foreach ($lines as $index => $line) {
                if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', $line) === 1) {
                    $lines[$index] = $encoded;
                    $replaced = true;

                    break;
                }
            }

            if (! $replaced) {
                $lines[] = $encoded;
            }
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode(PHP_EOL, $lines).PHP_EOL);
    }

    /** @return array<string, string> parsed key => value of the target .env */
    public function readEnv(): array
    {
        $path = $this->envPath();

        if (! Files::accessible($path) || ! is_file($path)) {
            return [];
        }

        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) File::get($path)) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/i', $line, $matches) === 1) {
                $values[$matches[1]] = trim($matches[2], "\"' ");
            }
        }

        return $values;
    }

    /* ================================================================ *
     *  Step 3 — migrations + admin account (F-6.9-001)
     * ================================================================ */

    /**
     * Run migrations (and optionally seeders) against a named connection.
     *
     * `--database` keeps the process' DEFAULT connection untouched — the
     * installer must not tear down the connection it is currently serving the
     * wizard from. `emp:geo-import` has no such switch, so the default is
     * swapped around that single call and restored in a finally block.
     *
     * The spatie permission cache is dropped either side of seeding: it may
     * hold rows from a different database entirely (that is exactly the
     * situation an installer is in), and a stale cache makes the role seeder
     * write role→permission links pointing at ids that do not exist.
     */
    public function migrate(bool $seed = true, string $connection = 'install'): void
    {
        $options = ['--force' => true];

        if (Config::get('database.connections.'.$connection) !== null) {
            $options['--database'] = $connection;
        }

        Artisan::call('migrate', $options);

        if ($seed) {
            foreach (self::SEEDERS as $seeder) {
                if (! class_exists($seeder)) {
                    continue;
                }

                $this->forgetPermissionCache();
                Artisan::call('db:seed', $options + ['--class' => $seeder]);
            }

            $this->forgetPermissionCache();
        }

        $previous = (string) Config::get('database.default');

        if (isset($options['--database'])) {
            Config::set('database.default', $connection);
        }

        try {
            // §3.23 reference data — idempotent, safe on every install/upgrade.
            Artisan::call('emp:geo-import');
        } finally {
            Config::set('database.default', $previous);
        }
    }

    private function forgetPermissionCache(): void
    {
        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (Throwable) {
            // Permission tables not present yet — nothing to forget.
        }
    }

    /**
     * Create the first administrator and give them the locked Super Admin
     * role (spec §0.3). Idempotent on the email address.
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function createAdmin(array $data): User
    {
        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => $data['email']]);

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        if (Schema::hasColumn('users', 'email_verified_at')) {
            $user->email_verified_at ??= Carbon::now();
        }

        $user->save();

        $role = Role::query()->firstOrCreate(
            ['name' => (string) config('emp.roles.super_admin'), 'guard_name' => 'admin', 'customer_id' => null],
            ['scope' => RoleScope::System, 'locked' => true],
        );

        if (! $role->users()->where('users.id', $user->getKey())->exists()) {
            $role->users()->attach($user->getKey());
        }

        $this->createWorkspace($data);

        return $user;
    }

    /**
     * Single-company installs have no customer sign-up, so the installer
     * creates the one workspace (customer panel account) with the same
     * credentials as the first administrator. Skipped when public
     * registration is available or a workspace already exists.
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    private function createWorkspace(array $data): void
    {
        if (class_exists(RegisterController::class)
            || Customer::query()->exists()) {
            return;
        }

        $parts = preg_split('/\s+/', trim($data['name']), 2) ?: [];

        Customer::query()->create([
            'first_name' => $parts[0] ?? $data['name'],
            'last_name' => $parts[1] ?? '',
            'email' => $data['email'],
            'password' => $data['password'],
            'timezone' => (string) config('app.timezone', 'UTC'),
            'locale' => (string) config('app.locale', 'en'),
            'status' => CustomerStatus::Active,
            'email_verified_at' => Carbon::now(),
        ]);
    }

    /* ================================================================ *
     *  Step 5 — cron + queue instructions (F-6.9-001)
     * ================================================================ */

    /** @return array{cron: string, worker: string, heartbeat: string, supervisor: string} */
    public function serviceInstructions(): array
    {
        $php = PHP_BINARY;
        $root = base_path();

        return [
            'cron' => "* * * * * {$php} {$root}/artisan schedule:run >> /dev/null 2>&1",
            'worker' => "{$php} {$root}/artisan queue:work --queue=transactional,campaigns-high,campaigns-send,automation,imports,bounces,webhooks,exports,maintenance --tries=3 --max-time=3600",
            'heartbeat' => "{$php} {$root}/artisan queue:heartbeat --queues=campaigns-send",
            'supervisor' => "[program:emp-worker]\ncommand={$php} {$root}/artisan queue:work --tries=3 --max-time=3600\ndirectory={$root}\nautostart=true\nautorestart=true\nnumprocs=4\nprocess_name=%(program_name)s_%(process_num)02d\nstopwaitsecs=3600",
        ];
    }

    /** True when the app can already talk to its configured database. */
    public function databaseReachable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function memoryLimitMb(string $limit): int
    {
        $unit = strtolower(substr(trim($limit), -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1024,
            'm' => $value,
            'k' => (int) round($value / 1024),
            default => (int) round($value / 1048576),
        };
    }

    private function encodeEnvValue(string|int|bool|null $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $string = (string) ($value ?? '');

        if ($string === '' || preg_match('/[\s#"\'=]/', $string) === 1) {
            return '"'.str_replace('"', '\"', $string).'"';
        }

        return $string;
    }
}
