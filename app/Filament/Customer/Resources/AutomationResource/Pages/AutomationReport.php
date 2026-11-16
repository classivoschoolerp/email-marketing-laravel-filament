<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\AutomationResource\Pages;

use App\Filament\Customer\Resources\AutomationResource;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Services\Automations\AutomationStats;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;

/**
 * §2.4.3 automation report: summary + funnel (F-2.4.3-001), per-node stats
 * (F-2.4.3-002), email performance (F-2.4.3-003), goal conversion + revenue
 * (F-2.4.3-004), contacts-at-step drill-down (F-2.4.3-005), error log
 * (F-2.4.3-006) and CSV export (F-2.4.3-007).
 */
class AutomationReport extends Page
{
    use InteractsWithRecord;

    protected static string $resource = AutomationResource::class;

    protected static string $view = 'filament.customer.resources.automation-resource.pages.automation-report';

    public ?string $drilldownNode = null;

    protected $queryString = ['drilldownNode' => ['except' => null]];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    public function getRecord(): Automation
    {
        /** @var Automation $record */
        $record = $this->record;

        return $record;
    }

    public function getTitle(): string
    {
        return __('automations.report.title').' — '.$this->getRecord()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('automations.actions.export'))
                ->icon('heroicon-m-arrow-down-tray')
                ->url(fn (): string => route('automations.report.export', ['uid' => $this->getRecord()->uid])),

            Action::make('builder')
                ->label(__('automations.actions.back_to_builder'))
                ->icon('heroicon-m-pencil-square')
                ->color('gray')
                ->url(fn (): string => AutomationResource::getUrl('builder', ['record' => $this->getRecord()])),
        ];
    }

    /** @return array{entered: int, active: int, completed: int, converted: int, conversion_rate: ?float, revenue: int} */
    public function summary(): array
    {
        return app(AutomationStats::class)->summary($this->getRecord());
    }

    /** @return list<array<string, mixed>> */
    public function perNode(): array
    {
        return app(AutomationStats::class)->perNode($this->getRecord());
    }

    /** @return Collection<int, array<string, mixed>> */
    public function errors(): Collection
    {
        return app(AutomationStats::class)->errors($this->getRecord());
    }

    /** @return Collection<int, AutomationRun> */
    public function drilldown(): Collection
    {
        if ($this->drilldownNode === null) {
            return new Collection;
        }

        $node = $this->getRecord()->nodes()->where('uid', $this->drilldownNode)->first();

        if ($node === null) {
            return new Collection;
        }

        return app(AutomationStats::class)->contactsAtNode($this->getRecord(), (int) $node->id);
    }
}
