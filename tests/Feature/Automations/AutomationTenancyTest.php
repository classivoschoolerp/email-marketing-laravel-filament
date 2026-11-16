<?php

declare(strict_types=1);

use App\Enums\AutomationNodeType;
use App\Filament\Customer\Resources\AutomationResource;
use App\Filament\Customer\Resources\AutomationResource\Pages\ListAutomations;
use App\Models\AutomationRun;
use App\Models\Customer;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\AutomationFixtures as Fx;

/*
 * Cross-tenant fences (docs/ARCHITECTURE.md §3): customer B can never see, edit,
 * report on or export customer A's automations; runs are tenancy-scoped.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-08 10:00:00');

    // Customer A owns an automation with one enrolled run.
    $this->customerA = Customer::factory()->create();
    $this->foreign = app(CurrentCustomer::class)->runAs($this->customerA, function () {
        [, $subscriber] = Fx::listWithSubscriber($this->customerA, 'a-side@example.com');

        $automation = Fx::automation($this->customerA, [
            Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'd1']),
            Fx::node('d1', AutomationNodeType::LogicDelay, ['mode' => 'duration', 'value' => 1, 'unit' => 'days'], []),
        ], ['name' => 'Secret journey A']);

        app(App\Services\Automations\Enroller::class)->enter($automation, $subscriber);

        return $automation;
    });

    // Customer B is signed in.
    $this->customerB = Fx::actingAsCustomer();
    $this->mine = Fx::automation($this->customerB, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'x1']),
        Fx::node('x1', AutomationNodeType::LogicExit, [], []),
    ], ['name' => 'My journey B']);
});

afterEach(fn () => Carbon::setTestNow());

it('hides foreign automations from the index', function (): void {
    $this->get(AutomationResource::getUrl('index'))
        ->assertOk()
        ->assertSee('My journey B')
        ->assertDontSee('Secret journey A');

    Livewire::test(ListAutomations::class)
        ->assertCanSeeTableRecords([$this->mine])
        ->assertCanNotSeeTableRecords([$this->foreign]);
});

it('404s foreign builder, settings and report pages', function (): void {
    $this->get(AutomationResource::getUrl('builder', ['record' => $this->foreign]))->assertNotFound();
    $this->get(AutomationResource::getUrl('edit', ['record' => $this->foreign]))->assertNotFound();
    $this->get(AutomationResource::getUrl('report', ['record' => $this->foreign]))->assertNotFound();

    // Own pages render.
    $this->get(AutomationResource::getUrl('builder', ['record' => $this->mine]))->assertOk();
    $this->get(AutomationResource::getUrl('report', ['record' => $this->mine]))->assertOk();
});

it('404s the foreign report export while serving your own (F-2.4.3-007)', function (): void {
    $this->get(route('automations.report.export', ['uid' => $this->foreign->uid]))->assertNotFound();

    $this->get(route('automations.report.export', ['uid' => $this->mine->uid]))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

it('scopes runs to the tenant', function (): void {
    // B sees no runs (the only run belongs to A).
    expect(AutomationRun::query()->count())->toBe(0)
        ->and(AutomationRun::withoutTenancy()->count())->toBe(1);
});

it('refuses cross-tenant enrollment at the engine level', function (): void {
    [, $subscriberB] = Fx::listWithSubscriber($this->customerB, 'b-side@example.com');

    // B's subscriber cannot enter A's automation.
    expect(app(App\Services\Automations\Enroller::class)->enter($this->foreign, $subscriberB))->toBeNull();
});
