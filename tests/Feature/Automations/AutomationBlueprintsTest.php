<?php

declare(strict_types=1);

use App\Enums\AutomationNodeType;
use App\Enums\AutomationStatus;
use App\Enums\ListFieldType;
use App\Filament\Customer\Resources\AutomationResource\Pages\ListAutomations;
use App\Models\Automation;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Services\Automations\Blueprints;
use App\Services\Automations\GraphPublisher;
use App\Services\Automations\GraphValidator;
use Livewire\Livewire;
use Tests\Support\AutomationFixtures as Fx;

/*
 * F-2.4.1-004 — pre-built templates gallery.
 *
 * 7 of the spec's 11 blueprints ship: the 4 from WP-21 plus Win-back,
 * Lead nurture and Anniversary. Every one of them must instantiate as a
 * draft and, once its placeholder pickers are filled, publish into a
 * runnable graph. The other 4 (Abandoned cart, Post-purchase, Browse
 * abandonment, Back-in-stock) are asserted ABSENT — they are defined by the
 * P3 ecommerce/site-tracking triggers F-2.4.2-015/016.
 */

beforeEach(function (): void {
    $this->customer = Fx::actingAsCustomer();
    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
});

/** Fill in the pickers a blueprint deliberately leaves empty. */
function fillBlueprintPlaceholders(array $canvas, int $listId, int $fieldId, int $segmentId, int $campaignId): array
{
    foreach ($canvas['nodes'] as $index => $node) {
        $config = (array) ($node['config'] ?? []);

        foreach ([
            'list_id' => $listId,
            'list_field_id' => $fieldId,
            'segment_id' => $segmentId,
            'campaign_id' => $campaignId,
        ] as $key => $value) {
            if (array_key_exists($key, $config) && $config[$key] === null) {
                $config[$key] = $value;
            }
        }

        $canvas['nodes'][$index]['config'] = $config;
    }

    return $canvas;
}

/*
 * WP-45 (§2.12): this file used to assert 7 blueprints and the ABSENCE of the
 * four ecommerce ones, because their triggers did not exist. §2.12 shipped
 * F-2.4.2-015 and F-2.4.2-016, so the honest assertions are now the mirror
 * image: all 11 spec-listed journeys, each built on a real trigger.
 */
it('offers exactly the 11 blueprints the spec lists (F-2.4.1-004)', function (): void {
    $options = Blueprints::options();

    expect(array_keys($options))->toBe([
        'welcome_series',
        'reengagement',
        'birthday',
        'onboarding_drip',
        'win_back',
        'lead_nurture',
        'anniversary',
        'abandoned_cart',
        'post_purchase',
        'browse_abandonment',
        'back_in_stock',
    ])->and($options)->toHaveCount(11);

    // Names and descriptions are real translations, not raw keys.
    foreach ($options as $key => $option) {
        expect($option['name'])->not->toContain('automations.blueprints')
            ->and($option['description'])->not->toContain('automations.blueprints')
            ->and(mb_strlen($option['description']))->toBeGreaterThan(20);
    }

    expect($options['win_back']['name'])->toBe('Win-back')
        ->and($options['lead_nurture']['name'])->toBe('Lead nurture')
        ->and($options['anniversary']['name'])->toBe('Anniversary')
        ->and($options['abandoned_cart']['name'])->toBe('Abandoned cart')
        ->and($options['post_purchase']['name'])->toBe('Post-purchase');
});

it('builds every blueprint on a trigger that really exists (F-2.4.1-004)', function (): void {
    // The four ecommerce journeys are DEFINED by F-2.4.2-015 (cart abandoned,
    // order placed, back-in-stock) and F-2.4.2-016 (page visited). WP-45
    // shipped those producers, so the triggers must be real enum cases — a
    // blueprint naming a type that does not exist would publish and then fail.
    $triggerValues = array_map(
        fn (AutomationNodeType $type): string => $type->value,
        AutomationNodeType::triggers(),
    );

    expect($triggerValues)->toContain('trigger.order_placed')
        ->toContain('trigger.order_shipped')
        ->toContain('trigger.cart_abandoned')
        ->toContain('trigger.product_viewed')
        ->toContain('trigger.back_in_stock')
        ->toContain('trigger.page_visited');

    // Every node every shipped blueprint uses really exists in the enum.
    $blueprints = app(Blueprints::class);

    foreach (Blueprints::KEYS as $key) {
        foreach ($blueprints->canvas($key)['nodes'] as $node) {
            expect(AutomationNodeType::tryFrom((string) $node['type']))->not->toBeNull();
        }
    }
});

it('instantiates every blueprint into a valid publishable draft (F-2.4.1-004)', function (): void {
    $field = ListField::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->customer->id,
        'type' => ListFieldType::Date,
    ]);
    $segment = ListSegment::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->customer->id,
    ]);
    $campaign = Campaign::factory()->create(['customer_id' => $this->customer->id]);

    $blueprints = app(Blueprints::class);
    $validator = app(GraphValidator::class);
    $publisher = app(GraphPublisher::class);

    // uid => expected node count, so a silently truncated blueprint fails.
    $expectedNodes = [
        'welcome_series' => 7,
        'reengagement' => 7,
        'birthday' => 4,
        'onboarding_drip' => 9,
        'win_back' => 7,
        'lead_nurture' => 10,
        'anniversary' => 7,
        // WP-45 §2.12.
        'abandoned_cart' => 8,
        'post_purchase' => 8,
        'browse_abandonment' => 5,
        'back_in_stock' => 7,
    ];

    foreach (Blueprints::KEYS as $key) {
        $automation = $blueprints->instantiate($key, $this->customer)->refresh();

        expect($automation->status)->toBe(AutomationStatus::Draft)
            ->and($automation->customer_id)->toBe($this->customer->id)
            ->and($automation->version)->toBe(0)
            ->and($automation->canvas['nodes'])->toHaveCount($expectedNodes[$key]);

        $canvas = fillBlueprintPlaceholders(
            (array) $automation->canvas,
            (int) $this->list->id,
            (int) $field->id,
            (int) $segment->id,
            (int) $campaign->id,
        );

        expect($validator->validate($canvas))->toBe([]);

        $automation->forceFill(['canvas' => $canvas])->save();
        $publisher->publish($automation);
        $automation->refresh();

        expect($automation->version)->toBe(1)
            ->and($automation->nodes()->count())->toBe($expectedNodes[$key])
            ->and($automation->trigger_type)->not->toBeNull();
    }

    expect(Automation::query()->count())->toBe(11);
});

it('gives the date-driven blueprints multi-entry and the rest single-entry (F-2.4.1-004)', function (): void {
    $blueprints = app(Blueprints::class);

    $modes = [];

    foreach (Blueprints::KEYS as $key) {
        $modes[$key] = $blueprints->instantiate($key, $this->customer)->reentry_mode;
    }

    expect($modes['birthday'])->toBe(Automation::REENTRY_MULTIPLE)
        ->and($modes['anniversary'])->toBe(Automation::REENTRY_MULTIPLE)
        ->and($modes['win_back'])->toBe(Automation::REENTRY_ONCE)
        ->and($modes['lead_nurture'])->toBe(Automation::REENTRY_ONCE)
        ->and($modes['welcome_series'])->toBe(Automation::REENTRY_ONCE)
        // WP-45: a shopper abandons more than one cart in a lifetime, buys
        // more than once and browses more than once — entering these ONCE
        // would silently stop recovering the second cart.
        ->and($modes['abandoned_cart'])->toBe(Automation::REENTRY_MULTIPLE)
        ->and($modes['post_purchase'])->toBe(Automation::REENTRY_MULTIPLE)
        ->and($modes['browse_abandonment'])->toBe(Automation::REENTRY_MULTIPLE)
        ->and($modes['back_in_stock'])->toBe(Automation::REENTRY_MULTIPLE);
});

it('uses the P1 triggers each new blueprint is named for (F-2.4.1-004)', function (): void {
    $blueprints = app(Blueprints::class);

    $triggerOf = function (string $key) use ($blueprints): string {
        foreach ($blueprints->canvas($key)['nodes'] as $node) {
            $type = AutomationNodeType::from((string) $node['type']);

            if ($type->isTrigger()) {
                return $type->value;
            }
        }

        return '';
    };

    expect($triggerOf('win_back'))->toBe(AutomationNodeType::TriggerCampaignNotOpened->value)
        ->and($triggerOf('lead_nurture'))->toBe(AutomationNodeType::TriggerTagAdded->value)
        ->and($triggerOf('anniversary'))->toBe(AutomationNodeType::TriggerDate->value);

    // Anniversary sends a week early, then celebrates on the day itself.
    $anniversary = collect($blueprints->canvas('anniversary')['nodes']);

    expect($anniversary->firstWhere('uid', 'n1')['config']['offset_days'])->toBe(-7)
        ->and($anniversary->firstWhere('uid', 'n1')['config']['annual'])->toBeTrue()
        ->and($anniversary->firstWhere('uid', 'n4')['config']['value'])->toBe(7);
});

it('creates the blueprint for the acting tenant only (cross-tenant fence)', function (): void {
    $other = Customer::factory()->create();

    Livewire::test(ListAutomations::class)
        ->callAction('fromBlueprint', ['blueprint' => 'win_back']);

    $automation = Automation::withoutTenancy()->latest('id')->first();

    expect($automation->customer_id)->toBe($this->customer->id)
        ->and(Automation::withoutTenancy()->where('customer_id', $other->id)->count())->toBe(0);
});
