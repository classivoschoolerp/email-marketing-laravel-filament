<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\LandingPageResource\Pages;

use App\Filament\Customer\Resources\LandingPageResource;
use App\Models\LandingPage;
use App\Models\LandingPageVariant;
use App\Services\Landing\LandingPageStats;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A/B variants with traffic split and winner selection (spec §2.6 F-2.6-004).
 *
 * The comparison shown here is a real two-proportion z-test
 * ({@see LandingPageStats}); "declare winner" is a deliberate operator
 * decision, and the page says plainly when the data does not support one
 * rather than crowning whichever arm is ahead. A declared winner takes 100%
 * of traffic immediately — the split stops the moment the test is over.
 */
class ManageLandingVariants extends Page
{
    use InteractsWithRecord;

    protected static string $resource = LandingPageResource::class;

    protected static string $view = 'filament.customer.landing.variants';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth('customer')->user()?->can('update', $this->record) ?? false, 403);
    }

    public function getTitle(): string|Htmlable
    {
        return __('landing.variants.heading');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $page = $this->page();
        $stats = app(LandingPageStats::class);
        $rows = $stats->variantComparison($page);
        $totalWeight = 0;

        foreach ($rows as $row) {
            if ($row['variant']->enabled) {
                $totalWeight += max(0, (int) $row['variant']->weight);
            }
        }

        $suggested = $stats->suggestedWinner($page);

        return [
            'page' => $page,
            'rows' => $rows,
            'totalWeight' => $totalWeight,
            'suggested' => $suggested,
            'minSample' => LandingPageStats::MIN_SAMPLE,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('add_variant')
                ->label(__('landing.actions.add_variant'))
                ->icon('heroicon-m-plus')
                ->form([
                    Forms\Components\TextInput::make('name')
                        ->label(__('landing.variants.name'))
                        ->required()
                        ->maxLength(191),
                    Forms\Components\TextInput::make('weight')
                        ->label(__('landing.variants.weight'))
                        ->helperText(__('landing.variants.weight_help'))
                        ->numeric()->minValue(0)->maxValue(1000)->default(50),
                    Forms\Components\Toggle::make('is_control')
                        ->label(__('landing.variants.control')),
                ])
                ->action(fn (array $data) => $this->addVariant($data)),

            Actions\Action::make('back')
                ->label(__('landing.actions.back_to_index'))
                ->icon('heroicon-m-arrow-left')
                ->color('gray')
                ->url(LandingPageResource::getUrl('index')),
        ];
    }

    /** @param array<string, mixed> $data */
    public function addVariant(array $data): void
    {
        $page = $this->page();

        abort_unless(auth('customer')->user()?->can('update', $page) ?? false, 403);

        $isControl = (bool) ($data['is_control'] ?? false);

        if ($isControl) {
            LandingPageVariant::query()->where('landing_page_id', $page->id)->update(['is_control' => false]);
        }

        LandingPageVariant::create([
            'customer_id' => $page->customer_id,
            'landing_page_id' => $page->id,
            'name' => (string) $data['name'],
            'weight' => max(0, (int) ($data['weight'] ?? 50)),
            // A new arm starts from the page's current document, so the test
            // begins with one deliberate difference rather than a blank page.
            'content_html' => $page->content_html,
            'content_json' => $page->content_json,
            'is_control' => $isControl,
            'enabled' => true,
        ]);

        // Adding an arm is what turns the test on — otherwise the operator
        // adds variants and wonders why nobody sees them.
        if (! $page->ab_enabled) {
            $page->forceFill(['ab_enabled' => true])->save();
        }

        Notification::make()->success()->title(__('landing.notifications.variant_saved'))->send();
    }

    public function declareWinner(int $variantId): void
    {
        $page = $this->page();

        abort_unless(auth('customer')->user()?->can('update', $page) ?? false, 403);

        $variant = $this->variant($variantId);

        LandingPageVariant::query()->where('landing_page_id', $page->id)->update(['is_winner' => false]);
        $variant->forceFill(['is_winner' => true, 'enabled' => true])->save();

        Notification::make()->success()->title(__('landing.notifications.winner_declared'))->send();
    }

    public function clearWinner(): void
    {
        $page = $this->page();

        abort_unless(auth('customer')->user()?->can('update', $page) ?? false, 403);

        LandingPageVariant::query()->where('landing_page_id', $page->id)->update(['is_winner' => false]);

        Notification::make()->success()->title(__('landing.notifications.winner_cleared'))->send();
    }

    public function toggleVariant(int $variantId): void
    {
        abort_unless(auth('customer')->user()?->can('update', $this->page()) ?? false, 403);

        $variant = $this->variant($variantId);
        $variant->forceFill(['enabled' => ! $variant->enabled])->save();

        Notification::make()->success()->title(__('landing.notifications.variant_saved'))->send();
    }

    public function deleteVariant(int $variantId): void
    {
        abort_unless(auth('customer')->user()?->can('update', $this->page()) ?? false, 403);

        $this->variant($variantId)->delete();

        Notification::make()->success()->title(__('landing.notifications.variant_deleted'))->send();
    }

    private function page(): LandingPage
    {
        /** @var LandingPage $record */
        $record = $this->getRecord();

        return $record;
    }

    /** Scoped to this page AND this tenant: an id from elsewhere is a 404. */
    private function variant(int $variantId): LandingPageVariant
    {
        /** @var LandingPageVariant $variant */
        $variant = LandingPageVariant::query()
            ->where('landing_page_id', $this->page()->id)
            ->whereKey($variantId)
            ->firstOrFail();

        return $variant;
    }
}
