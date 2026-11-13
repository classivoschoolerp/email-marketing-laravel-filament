<?php

declare(strict_types=1);

use App\Models\DeliveryServer;
use App\Sending\DriverRegistry;
use App\Sending\SendingDriver;
use App\Settings\CustomerSettings;
use Tests\Fakes\RecordingSendingDriver;

// F-2.9.2-020 — driver registry pattern: keys are EXACTLY the D-21 canonical set
it('registers exactly the D-21 canonical driver keys', function (): void {
    $registry = new DriverRegistry;

    // 20 §2.9.2 P1 drivers + the 3 P2 transactional ESPs (F-2.9.2-013, WP-43).
    expect($registry->keys())->toBe(CustomerSettings::SENDING_SERVER_TYPES)
        ->and($registry->keys())->toHaveCount(23);
});

// F-2.9.2-020 — every driver resolves, self-identifies and fulfils the contract
it('resolves every built-in driver with a matching key and full contract', function (): void {
    $registry = new DriverRegistry;

    foreach ($registry->keys() as $key) {
        $driver = $registry->driver($key);

        expect($driver)->toBeInstanceOf(SendingDriver::class)
            ->and($driver->key())->toBe($key)
            ->and($driver->label())->toBeString()->not->toContain('sending.types.') // translated
            ->and($driver->capabilities())->toBeArray()->toHaveKey('transport')
            ->and($driver->formSchema())->toBeArray();

        foreach ($driver->formSchema() as $spec) {
            expect($spec)->toHaveKeys(['name', 'type', 'label', 'required']);
        }
    }
});

it('resolves the driver for a server row by its type', function (): void {
    $registry = new DriverRegistry;
    $server = new DeliveryServer(['type' => 'postmark-api']);

    expect($registry->forServer($server)->key())->toBe('postmark-api');
});

it('throws for unknown driver keys', function (): void {
    $registry = new DriverRegistry;

    expect($registry->has('resend'))->toBeFalse()
        ->and(fn () => $registry->driver('resend'))->toThrow(InvalidArgumentException::class);
});

// F-2.9.2-021 — plugin registration API (§6.1): instance registration
it('lets plugins register a driver instance without core changes', function (): void {
    $registry = new DriverRegistry;
    $fake = new RecordingSendingDriver('acme-mailer');

    $registry->register('acme-mailer', $fake);

    expect($registry->has('acme-mailer'))->toBeTrue()
        ->and($registry->driver('acme-mailer'))->toBe($fake)
        ->and($registry->keys())->toContain('acme-mailer');
});

// F-2.9.2-021 — plugin registration by class name (lazy container resolution)
it('lets plugins register a driver class resolved through the container', function (): void {
    $registry = new DriverRegistry;

    $registry->register('acme-mailer', RecordingSendingDriver::class);

    expect($registry->driver('acme-mailer'))->toBeInstanceOf(RecordingSendingDriver::class);
});

it('rejects classes that do not implement the driver interface', function (): void {
    $registry = new DriverRegistry;

    expect(fn () => $registry->register('bogus', \stdClass::class))
        ->toThrow(InvalidArgumentException::class);
});

// F-2.9.2-021 — plugins can also extend the config map (registry self-seeds)
it('picks up drivers merged into the config map', function (): void {
    config(['emp-sending.drivers.acme-mailer' => RecordingSendingDriver::class]);

    $registry = new DriverRegistry;

    expect($registry->has('acme-mailer'))->toBeTrue();
});

it('offers translated labels as select options', function (): void {
    $options = (new DriverRegistry)->options();

    expect($options)->toHaveKey('smtp')
        ->and($options['smtp'])->toBe(__('sending.types.smtp'));
});

// The container serves ONE registry instance so plugin registrations persist
it('is bound as a singleton', function (): void {
    expect(app(DriverRegistry::class))->toBe(app(DriverRegistry::class));
});
