<?php

declare(strict_types=1);

use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Models\AutomationEmailSend;
use App\Models\AutomationRun;
use App\Models\AutomationRunLog;
use App\Models\ListField;
use App\Models\Subscriber;
use Illuminate\Support\Carbon;
use Tests\Support\AutomationFixtures as Fx;

/*
 * WP-21 full journey (spec §2.4.2): subscribe trigger → delay (time-travel)
 * → if/else branch → send email (fake driver) → update field → goal exit
 * with conversion + counters.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-08 10:00:00');

    $this->customer = Fx::actingAsCustomer();
    $this->driver = Fx::fakeDeliveryServer($this->customer);

    $this->list = App\Models\EmailList::factory()->create([
        'customer_id' => $this->customer->id,
        'from_name' => 'Acme',
        'from_email' => 'news@acme.test',
    ]);

    $this->field = ListField::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->customer->id,
        'tag' => 'VIP',
    ]);

    $this->automation = Fx::automation($this->customer, [
        Fx::node('n1', AutomationNodeType::TriggerSubscribed, ['list_id' => $this->list->id], ['next' => 'n2']),
        Fx::node('n2', AutomationNodeType::LogicDelay, ['mode' => 'duration', 'value' => 1, 'unit' => 'hours'], ['next' => 'n3']),
        Fx::node('n3', AutomationNodeType::LogicIfElse, [
            'match' => 'all',
            'conditions' => [['type' => 'status', 'operator' => 'is', 'value' => 'confirmed']],
        ], ['yes' => 'n4', 'no' => 'n7']),
        Fx::node('n4', AutomationNodeType::ActionSendEmail, [
            'mode' => 'inline',
            'subject' => 'Hello {EMAIL}',
            'content_html' => '<html><body><p>Hi {EMAIL}</p><p><a href="https://shop.example.com/p">Buy</a></p></body></html>',
        ], ['next' => 'n5']),
        Fx::node('n5', AutomationNodeType::ActionUpdateField, [
            'list_field_id' => $this->field->id,
            'value' => 'yes',
        ], ['next' => 'n6']),
        Fx::node('n6', AutomationNodeType::LogicGoal, [
            'match' => 'all',
            'conditions' => [[
                'type' => 'field',
                'list_field_id' => $this->field->id,
                'operator' => 'is',
                'value' => 'yes',
            ]],
            'conversion_value' => 500,
        ], []),
        Fx::node('n7', AutomationNodeType::LogicExit, [], []),
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('walks a confirmed subscriber to the goal with conversion counted', function (): void {
    $subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->customer->id,
        'email' => 'anna@example.com',
    ]);

    // Trigger fired on create (sync queue): run parked on the delay node.
    $run = AutomationRun::withoutTenancy()->where('automation_id', $this->automation->id)->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(AutomationRunStatus::Active)
        ->and($run->currentNode->uid)->toBe('n2')
        ->and(Carbon::parse($run->wake_at)->toDateTimeString())->toBe('2026-08-08 11:00:00')
        ->and($this->driver->sent)->toBe([]);

    $automation = $this->automation->refresh();
    expect($automation->entered_count)->toBe(1)->and($automation->active_count)->toBe(1);

    // Wake the delay via the every-minute tick.
    Carbon::setTestNow('2026-08-08 11:01:00');
    $this->artisan('automations:tick')->assertExitCode(0);

    $run->refresh();
    expect($run->status)->toBe(AutomationRunStatus::Completed)
        ->and($run->exit_reason)->toBe('goal_met')
        ->and($run->goal_met_at)->not->toBeNull()
        ->and((int) $run->conversion_value)->toBe(500);

    // Email went out through the WP-13 driver seam, personalized + tracked.
    expect($this->driver->sent)->toHaveCount(1);
    $message = $this->driver->sent[0]['message'];
    expect($message->subject)->toBe('Hello anna@example.com')
        ->and($message->html)->toContain('/a/t/o/')  // open pixel
        ->and($message->html)->toContain('/a/t/c/')  // click rewrite
        ->and($message->fromEmail)->toBe('news@acme.test');

    $send = AutomationEmailSend::withoutTenancy()->where('run_id', $run->id)->first();
    expect($send->status)->toBe(AutomationEmailSend::STATUS_SENT)
        ->and($send->subject)->toBe('Hello anna@example.com');

    // Custom field was updated through FieldValueService.
    expect(app(App\Services\Lists\FieldValueService::class)->raw($subscriber, $this->field))->toBe('yes');

    // Counters (F-2.4.1-001) + logs (§2.4.3 source of truth).
    $automation->refresh();
    expect($automation->active_count)->toBe(0)
        ->and($automation->completed_count)->toBe(1)
        ->and($automation->converted_count)->toBe(1);

    $actions = AutomationRunLog::query()->where('run_id', $run->id)->pluck('action');
    expect($actions)->toContain(AutomationRunLog::ACTION_ENTERED)
        ->toContain(AutomationRunLog::ACTION_REACHED)
        ->toContain(AutomationRunLog::ACTION_EMAIL_SENT)
        ->toContain(AutomationRunLog::ACTION_GOAL_MET)
        ->toContain(AutomationRunLog::ACTION_COMPLETED);
});

it('routes non-matching contacts down the no branch to the exit node', function (): void {
    Subscriber::factory()->unconfirmed()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->customer->id,
        'email' => 'maybe@example.com',
    ]);

    $run = AutomationRun::withoutTenancy()->where('automation_id', $this->automation->id)->first();
    expect($run)->not->toBeNull();

    Carbon::setTestNow('2026-08-08 11:01:00');
    $this->artisan('automations:tick')->assertExitCode(0);

    $run->refresh();
    expect($run->status)->toBe(AutomationRunStatus::Exited)
        ->and($run->exit_reason)->toBe('exit_node')
        ->and($this->driver->sent)->toBe([]);
});

it('does not enroll subscribers of other lists', function (): void {
    [, $other] = Fx::listWithSubscriber($this->customer, 'other@example.com');

    expect(AutomationRun::withoutTenancy()->where('automation_id', $this->automation->id)->count())->toBe(0);
});
