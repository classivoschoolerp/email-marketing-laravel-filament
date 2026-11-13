<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignStatusMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// §2.3.1/§2.3.9 — honest campaign status transitions.

beforeEach(function (): void {
    $this->machine = new CampaignStatusMachine;
});

it('allows exactly the documented transitions', function (): void {
    $m = $this->machine;

    // draft
    expect($m->canTransition(CampaignStatus::Draft, CampaignStatus::Scheduled))->toBeTrue()
        ->and($m->canTransition(CampaignStatus::Draft, CampaignStatus::PendingApproval))->toBeTrue()
        ->and($m->canTransition(CampaignStatus::Draft, CampaignStatus::Sending))->toBeFalse()
        ->and($m->canTransition(CampaignStatus::Draft, CampaignStatus::Sent))->toBeFalse();

    // pending approval
    expect($m->canTransition(CampaignStatus::PendingApproval, CampaignStatus::Scheduled))->toBeTrue()
        ->and($m->canTransition(CampaignStatus::PendingApproval, CampaignStatus::Blocked))->toBeTrue()
        ->and($m->canTransition(CampaignStatus::PendingApproval, CampaignStatus::Sending))->toBeFalse();

    // scheduled
    expect($m->canTransition(CampaignStatus::Scheduled, CampaignStatus::Sending))->toBeTrue()
        ->and($m->canTransition(CampaignStatus::Scheduled, CampaignStatus::Paused))->toBeTrue()
        ->and($m->canTransition(CampaignStatus::Scheduled, CampaignStatus::Sent))->toBeFalse();

    // sending
    expect($m->canTransition(CampaignStatus::Sending, CampaignStatus::Paused))->toBeTrue()
        ->and($m->canTransition(CampaignStatus::Sending, CampaignStatus::Sent))->toBeTrue()
        ->and($m->canTransition(CampaignStatus::Sending, CampaignStatus::Draft))->toBeFalse();

    // paused
    expect($m->canTransition(CampaignStatus::Paused, CampaignStatus::Sending))->toBeTrue()
        ->and($m->canTransition(CampaignStatus::Paused, CampaignStatus::Scheduled))->toBeTrue()
        ->and($m->canTransition(CampaignStatus::Paused, CampaignStatus::Sent))->toBeFalse();

    // blocked / cancelled recover only to draft (or cancellation)
    expect($m->canTransition(CampaignStatus::Blocked, CampaignStatus::Draft))->toBeTrue()
        ->and($m->canTransition(CampaignStatus::Blocked, CampaignStatus::Sending))->toBeFalse()
        ->and($m->canTransition(CampaignStatus::Cancelled, CampaignStatus::Draft))->toBeTrue();

    // sent is terminal
    foreach (CampaignStatus::cases() as $to) {
        expect($m->canTransition(CampaignStatus::Sent, $to))->toBeFalse();
    }
});

it('persists a legal transition', function (): void {
    $campaign = Campaign::factory()->create();

    $this->machine->transition($campaign, CampaignStatus::Scheduled);

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Scheduled);
});

it('throws on an illegal transition and leaves the status untouched', function (): void {
    $campaign = Campaign::factory()->create();

    expect(fn () => $this->machine->transition($campaign, CampaignStatus::Sent))
        ->toThrow(ValidationException::class);

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Draft);
});

it('resumes a paused campaign to sending when it had started, otherwise to scheduled', function (): void {
    $started = Campaign::factory()->create([
        'status' => CampaignStatus::Paused,
        'send_started_at' => now()->subHour(),
    ]);
    $this->machine->resume($started);
    expect($started->refresh()->status)->toBe(CampaignStatus::Sending);

    $notStarted = Campaign::factory()->create([
        'status' => CampaignStatus::Paused,
        'send_started_at' => null,
    ]);
    $this->machine->resume($notStarted);
    expect($notStarted->refresh()->status)->toBe(CampaignStatus::Scheduled);
});

it('reports pause/resume capability per status', function (): void {
    $sending = Campaign::factory()->create(['status' => CampaignStatus::Sending]);
    $draft = Campaign::factory()->create(['status' => CampaignStatus::Draft]);
    $paused = Campaign::factory()->create(['status' => CampaignStatus::Paused]);

    expect($this->machine->canPause($sending))->toBeTrue()
        ->and($this->machine->canPause($draft))->toBeFalse()
        ->and($this->machine->canResume($paused))->toBeTrue()
        ->and($this->machine->canResume($sending))->toBeFalse();
});

it('marks editable statuses per the edit-until-sending rule', function (): void {
    expect(CampaignStatus::Draft->isEditable())->toBeTrue()
        ->and(CampaignStatus::Scheduled->isEditable())->toBeTrue()
        ->and(CampaignStatus::PendingApproval->isEditable())->toBeTrue()
        ->and(CampaignStatus::Paused->isEditable())->toBeTrue()
        ->and(CampaignStatus::Sending->isEditable())->toBeFalse()
        ->and(CampaignStatus::Sent->isEditable())->toBeFalse()
        ->and(CampaignStatus::Blocked->isEditable())->toBeFalse()
        ->and(CampaignStatus::Cancelled->isEditable())->toBeFalse();
});
