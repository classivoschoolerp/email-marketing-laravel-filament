<?php

declare(strict_types=1);

use App\Models\Concerns\BelongsToCustomer;
use App\Models\Customer;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('tenancy_probe_items', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('customer_id')->nullable();
        $table->string('name');
        $table->timestamps();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('tenancy_probe_items');
});

function probeModel(): Model
{
    return new class extends Model
    {
        use BelongsToCustomer;

        protected $table = 'tenancy_probe_items';

        protected $guarded = [];
    };
}

it('scopes queries to the current customer', function (): void {
    [$a, $b] = Customer::factory()->count(2)->create();
    $probe = probeModel();

    app(CurrentCustomer::class)->runAs($a, fn () => $probe->newQuery()->create(['name' => 'A item']));
    app(CurrentCustomer::class)->runAs($b, fn () => $probe->newQuery()->create(['name' => 'B item']));

    app(CurrentCustomer::class)->set($a);
    expect($probe->newQuery()->pluck('name')->all())->toBe(['A item']);

    app(CurrentCustomer::class)->set($b);
    expect($probe->newQuery()->pluck('name')->all())->toBe(['B item']);
});

it('fills customer_id automatically on create', function (): void {
    $customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($customer);

    $item = probeModel()->newQuery()->create(['name' => 'auto']);

    expect($item->customer_id)->toBe($customer->id);
});

it('applies no scope when no tenant is set (admin context)', function (): void {
    [$a, $b] = Customer::factory()->count(2)->create();
    $probe = probeModel();

    app(CurrentCustomer::class)->runAs($a, fn () => $probe->newQuery()->create(['name' => 'A item']));
    app(CurrentCustomer::class)->runAs($b, fn () => $probe->newQuery()->create(['name' => 'B item']));

    app(CurrentCustomer::class)->set(null);
    expect($probe->newQuery()->count())->toBe(2);
});

it('cannot update or delete another tenant\'s rows through the scoped model', function (): void {
    [$a, $b] = Customer::factory()->count(2)->create();
    $probe = probeModel();

    $bItem = app(CurrentCustomer::class)->runAs($b, fn () => $probe->newQuery()->create(['name' => 'B item']));

    app(CurrentCustomer::class)->set($a);

    expect($probe->newQuery()->whereKey($bItem->id)->update(['name' => 'hacked']))->toBe(0)
        ->and($probe->newQuery()->whereKey($bItem->id)->delete())->toBe(0);

    app(CurrentCustomer::class)->set(null);
    expect($probe->newQuery()->find($bItem->id)->name)->toBe('B item');
});

it('runAs restores the previous tenant even on failure', function (): void {
    [$a, $b] = Customer::factory()->count(2)->create();
    $current = app(CurrentCustomer::class);
    $current->set($a);

    try {
        $current->runAs($b, fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect($current->id())->toBe($a->id);
});
