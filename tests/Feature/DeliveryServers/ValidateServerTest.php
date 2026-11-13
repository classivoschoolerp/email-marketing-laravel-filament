<?php

declare(strict_types=1);

use App\Enums\DeliveryServerStatus;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Sending\DriverRegistry;
use App\Sending\SendResult;
use App\Sending\ServerValidator;
use App\Support\Tenancy\CurrentCustomer;
use Tests\Fakes\RecordingSendingDriver;

/*
 * F-2.9.2-041 — validate-server flow: confirmation email goes through the
 * ACTUAL driver (recording fake registered for the smtp key), the signed
 * activation link flips the server to active.
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);

    $this->driver = new RecordingSendingDriver('smtp');
    app(DriverRegistry::class)->register('smtp', $this->driver);

    $this->server = DeliveryServer::factory()->create([
        'customer_id' => $this->customer->id,
        'type' => 'smtp',
        'status' => DeliveryServerStatus::Inactive,
        'from_email' => 'postmaster@example.org',
        'from_name' => 'Postmaster',
    ]);
});

afterEach(function (): void {
    app(CurrentCustomer::class)->set(null);
});

it('sends the confirmation email through the driver with a signed activation link', function (): void {
    $result = app(ServerValidator::class)->begin($this->server);

    expect($result->ok)->toBeTrue()
        ->and($this->driver->sent)->toHaveCount(1);

    $message = $this->driver->sent[0]['message'];
    $server = $this->server->refresh();

    expect($this->driver->sent[0]['server']->id)->toBe($server->id)
        ->and($message->recipients())->toHaveKey('postmaster@example.org')
        ->and($server->status)->toBe(DeliveryServerStatus::InProgress)
        ->and($server->validation_token)->not->toBeNull()
        ->and($message->text)->toContain('/delivery-servers/'.$server->id.'/activate/'.$server->validation_token)
        ->and($message->text)->toContain('signature=');
});

it('activates the server when the signed link is clicked', function (): void {
    app(ServerValidator::class)->begin($this->server);

    $server = $this->server->refresh();
    $url = app(ServerValidator::class)->activationUrl($server, (string) $server->validation_token);

    $this->get($url)
        ->assertOk()
        ->assertSee(__('sending.activation.title'));

    $server->refresh();

    expect($server->status)->toBe(DeliveryServerStatus::Active)
        ->and($server->validated_at)->not->toBeNull()
        ->and($server->validation_token)->toBeNull();
});

it('rejects a wrong token even with a valid signature', function (): void {
    app(ServerValidator::class)->begin($this->server);

    $url = app(ServerValidator::class)->activationUrl($this->server->refresh(), 'wrong-token');

    $this->get($url)->assertForbidden();

    expect($this->server->refresh()->status)->toBe(DeliveryServerStatus::InProgress);
});

it('rejects an unsigned or tampered activation url', function (): void {
    app(ServerValidator::class)->begin($this->server);
    $server = $this->server->refresh();

    $unsigned = route('sending.servers.activate', ['server' => $server->id, 'token' => $server->validation_token]);

    $this->get($unsigned)->assertForbidden();

    expect($server->refresh()->status)->toBe(DeliveryServerStatus::InProgress);
});

it('rejects reuse after successful activation', function (): void {
    app(ServerValidator::class)->begin($this->server);
    $server = $this->server->refresh();
    $token = (string) $server->validation_token;
    $url = app(ServerValidator::class)->activationUrl($server, $token);

    $this->get($url)->assertOk();
    $this->get($url)->assertForbidden(); // token cleared on first use
});

it('sends to an explicit recipient when provided', function (): void {
    app(ServerValidator::class)->begin($this->server, 'inbox-check@example.net');

    expect($this->driver->sent[0]['message']->recipients())->toHaveKey('inbox-check@example.net');
});

it('reverts to inactive when the driver cannot deliver the confirmation', function (): void {
    $this->driver->result = SendResult::permanentFailure('550 refused');

    $result = app(ServerValidator::class)->begin($this->server);

    expect($result->ok)->toBeFalse()
        ->and($this->server->refresh()->status)->toBe(DeliveryServerStatus::Inactive);
});

it('requires a recipient or from email', function (): void {
    $this->server->forceFill(['from_email' => null])->save();

    expect(fn () => app(ServerValidator::class)->begin($this->server))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
