<?php

declare(strict_types=1);

use App\Mail\Upgrade\NewReleaseAvailableMail;
use App\Services\Upgrade\ReleaseChecker;
use App\Services\Upgrade\ReleaseInfo;
use App\Services\Upgrade\ReleaseNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AdminTestHelpers;
use Tests\Support\UpgradeTestHelpers;

/*
 * WP-41 §6.2 F-6.2-001 (current version / latest version / changelog) and
 * F-6.2-008 (optional new-release email). Every fetch is Http::fake'd — the
 * release feed is a network dependency and no test may touch one.
 */

const FEED = 'https://releases.example.test/latest.json';

beforeEach(function (): void {
    config([
        'emp-upgrade.release.endpoint' => FEED,
        'emp-upgrade.release.channel' => 'stable',
        'emp.core_version' => '1.0.0',
    ]);
});

afterEach(fn () => UpgradeTestHelpers::cleanup());

// --------------------------------------------------------- F-6.2-001 feed

it('reports "no feed configured" and never claims you are up to date (F-6.2-001)', function (): void {
    config(['emp-upgrade.release.endpoint' => null]);

    $result = app(ReleaseChecker::class)->check();

    expect($result['status'])->toBe(ReleaseChecker::STATUS_DISABLED)
        ->and($result['release'])->toBeNull()
        ->and($result['current'])->toBe('1.0.0');
});

it('reports the current version, the latest version and the changelog (F-6.2-001)', function (): void {
    Http::fake([
        'releases.example.test/latest.json*' => Http::response(
            UpgradeTestHelpers::feed('1.2.0', str_repeat('a', 64))
        ),
    ]);

    $result = app(ReleaseChecker::class)->check();

    expect($result['status'])->toBe(ReleaseChecker::STATUS_AVAILABLE)
        ->and($result['current'])->toBe('1.0.0')
        ->and($result['release'])->toBeInstanceOf(ReleaseInfo::class)
        ->and($result['release']->version)->toBe('1.2.0')
        ->and($result['release']->changelog)->toContain('Faster sending')
        ->and($result['release']->isInstallable())->toBeTrue();
});

it('reports "up to date" when the feed is not newer (F-6.2-001)', function (): void {
    Http::fake([
        'releases.example.test/latest.json*' => Http::response(
            UpgradeTestHelpers::feed('1.0.0', str_repeat('a', 64))
        ),
    ]);

    expect(app(ReleaseChecker::class)->check()['status'])->toBe(ReleaseChecker::STATUS_CURRENT);
});

it('treats an unreachable feed as UNKNOWN, never as "up to date"', function (): void {
    Http::fake(['releases.example.test/*' => Http::response('', 503)]);

    $result = app(ReleaseChecker::class)->check();

    expect($result['status'])->toBe(ReleaseChecker::STATUS_UNKNOWN)
        ->and($result['release'])->toBeNull()
        ->and($result['message'])->not->toBeNull();
});

it('treats an unparsable feed document as UNKNOWN', function (): void {
    Http::fake(['releases.example.test/*' => Http::response(['version' => 'not-a-version'])]);

    expect(app(ReleaseChecker::class)->check()['status'])->toBe(ReleaseChecker::STATUS_UNKNOWN);
});

it('caches the feed and re-fetches only when forced', function (): void {
    Http::fake([
        'releases.example.test/latest.json*' => Http::response(
            UpgradeTestHelpers::feed('1.1.0', str_repeat('b', 64))
        ),
    ]);

    $checker = app(ReleaseChecker::class);

    $checker->check();
    $checker->check();
    Http::assertSentCount(1);

    $checker->check(force: true);
    Http::assertSentCount(2);
});

it('selects the configured channel from a multi-channel feed', function (): void {
    config(['emp-upgrade.release.channel' => 'beta']);

    Http::fake([
        'releases.example.test/latest.json*' => Http::response([
            'channels' => [
                'stable' => UpgradeTestHelpers::feed('1.1.0', str_repeat('c', 64)),
                'beta' => UpgradeTestHelpers::feed('1.2.0-does-not-matter', str_repeat('d', 64), ['version' => '1.2.0']),
            ],
        ]),
    ]);

    expect(app(ReleaseChecker::class)->check()['release']->version)->toBe('1.2.0');
});

it('refuses a non-https download URL — an announced release is not an installable one', function (): void {
    Http::fake([
        'releases.example.test/latest.json*' => Http::response(UpgradeTestHelpers::feed(
            '1.1.0',
            str_repeat('e', 64),
            ['download_url' => 'http://releases.example.test/emp-1.1.0.zip'],
        )),
    ]);

    $release = app(ReleaseChecker::class)->check()['release'];

    expect($release->version)->toBe('1.1.0')
        ->and($release->downloadUrl)->toBeNull()
        ->and($release->isInstallable())->toBeFalse();
});

it('refuses a release with no checksum', function (): void {
    Http::fake([
        'releases.example.test/latest.json*' => Http::response(
            UpgradeTestHelpers::feed('1.1.0', '', ['checksum' => ''])
        ),
    ]);

    expect(app(ReleaseChecker::class)->check()['release']->isInstallable())->toBeFalse();
});

// -------------------------------------------------- F-6.2-008 notification

it('does not email anyone by default (F-6.2-008 is opt-in)', function (): void {
    Mail::fake();
    config(['emp-upgrade.notify.enabled' => false]);

    Http::fake([
        'releases.example.test/latest.json*' => Http::response(
            UpgradeTestHelpers::feed('1.1.0', str_repeat('f', 64))
        ),
    ]);

    $release = app(ReleaseChecker::class)->latest();

    expect(app(ReleaseNotifier::class)->notify($release))->toBe(0);
    Mail::assertNothingQueued();
});

it('emails admins who hold system.upgrade when a new release appears (F-6.2-008)', function (): void {
    Mail::fake();
    config(['emp-upgrade.notify.enabled' => true, 'emp-upgrade.notify.to' => null]);

    $admin = AdminTestHelpers::admin(['system.upgrade']);
    AdminTestHelpers::admin(['logs.view']); // no upgrade rights — must not be mailed

    Http::fake([
        'releases.example.test/latest.json*' => Http::response(
            UpgradeTestHelpers::feed('1.1.0', str_repeat('f', 64))
        ),
    ]);

    $release = app(ReleaseChecker::class)->latest();

    expect(app(ReleaseNotifier::class)->notify($release))->toBe(1);

    Mail::assertQueued(NewReleaseAvailableMail::class, function (NewReleaseAvailableMail $mail) use ($admin): bool {
        return $mail->hasTo($admin->email)
            && $mail->release['version'] === '1.1.0'
            && $mail->currentVersion === '1.0.0';
    });
});

it('deduplicates the announcement per version (F-6.2-008)', function (): void {
    Mail::fake();
    config(['emp-upgrade.notify.enabled' => true, 'emp-upgrade.notify.to' => 'ops@example.test']);

    Http::fake([
        'releases.example.test/latest.json*' => Http::response(
            UpgradeTestHelpers::feed('1.1.0', str_repeat('f', 64))
        ),
    ]);

    $notifier = app(ReleaseNotifier::class);
    $release = app(ReleaseChecker::class)->latest();

    expect($notifier->notify($release))->toBe(1)
        ->and($notifier->notify($release))->toBe(0);

    Mail::assertQueuedCount(1);
});

// ------------------------------------------------------------- the command

it('upgrade:check reports the available release and notifies on demand', function (): void {
    Mail::fake();
    config(['emp-upgrade.notify.enabled' => true, 'emp-upgrade.notify.to' => 'ops@example.test']);

    Http::fake([
        'releases.example.test/latest.json*' => Http::response(
            UpgradeTestHelpers::feed('1.3.0', str_repeat('9', 64))
        ),
    ]);

    $this->artisan('upgrade:check --notify')
        ->expectsOutputToContain('1.3.0')
        ->assertSuccessful();

    Mail::assertQueuedCount(1);
});

it('upgrade:check exits cleanly when the vendor feed is down', function (): void {
    Http::fake(['releases.example.test/*' => Http::response('', 500)]);

    $this->artisan('upgrade:check')->assertSuccessful();
});
