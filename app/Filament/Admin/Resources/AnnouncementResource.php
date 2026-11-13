<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\AnnouncementResource\Pages;
use App\Models\Announcement;
use App\Services\Notifications\AnnouncementBroadcaster;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * Admin CRUD for customer-dashboard announcements (spec §2.1 F-2.1-010,
 * WP-18): title + body + level, active toggle, optional display window and
 * per-customer dismissibility. Gated by AnnouncementPolicy (D-42
 * customers.* matrix).
 */
class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Content & Marketing';

    protected static ?string $slug = 'announcements';

    protected static ?int $navigationSort = 62;

    public static function getNavigationGroup(): ?string
    {
        return __('cms.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('dashboards.announcements.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('dashboards.announcements.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('dashboards.announcements.model_label_plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label(__('dashboards.announcements.fields.title'))
                ->required()
                ->maxLength(255),
            Forms\Components\RichEditor::make('body')
                ->label(__('dashboards.announcements.fields.body'))
                ->required(),
            Forms\Components\Select::make('level')
                ->label(__('dashboards.announcements.fields.level'))
                ->options(Announcement::levelOptions())
                ->default(Announcement::LEVEL_INFO)
                ->required(),
            Forms\Components\Toggle::make('active')
                ->label(__('dashboards.announcements.fields.active'))
                ->default(true),
            Forms\Components\Toggle::make('dismissible')
                ->label(__('dashboards.announcements.fields.dismissible'))
                ->default(true),
            Forms\Components\DateTimePicker::make('starts_at')
                ->label(__('dashboards.announcements.fields.starts_at')),
            Forms\Components\DateTimePicker::make('ends_at')
                ->label(__('dashboards.announcements.fields.ends_at'))
                ->after('starts_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label(__('dashboards.announcements.fields.title'))
                    ->searchable()
                    ->weight('semibold'),
                Tables\Columns\TextColumn::make('level')
                    ->label(__('dashboards.announcements.fields.level'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Announcement::levelOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Announcement::LEVEL_WARNING => 'warning',
                        Announcement::LEVEL_DANGER => 'danger',
                        default => 'info',
                    }),
                Tables\Columns\IconColumn::make('active')
                    ->label(__('dashboards.announcements.fields.active'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('starts_at')
                    ->label(__('dashboards.announcements.fields.starts_at'))
                    ->dateTime()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('ends_at')
                    ->label(__('dashboards.announcements.fields.ends_at'))
                    ->dateTime()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('dismissals_count')
                    ->label(__('dashboards.announcements.fields.dismissals'))
                    ->counts('dismissals'),
                // F-4.3-003 broadcast audit (WP-39)
                Tables\Columns\TextColumn::make('broadcast_at')
                    ->label(__('notifications.announcements.broadcast_column'))
                    ->dateTime()
                    ->description(fn (Announcement $record): ?string => $record->broadcast_at !== null
                        ? __('notifications.announcements.broadcast_done', ['count' => (int) $record->broadcast_count])
                        : null)
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')
                    ->label(__('dashboards.announcements.fields.active')),
            ])
            ->actions([
                /*
                 * F-4.3-003 broadcast announcements from admin to all
                 * customers (WP-39): push this announcement into every
                 * tenant's notification bell. One-shot — broadcast_at makes
                 * a second click impossible, so a mis-click cannot spam the
                 * whole install twice.
                 */
                Tables\Actions\Action::make('broadcast')
                    ->label(__('notifications.announcements.broadcast'))
                    ->icon('heroicon-m-megaphone')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription(__('notifications.announcements.broadcast_confirm'))
                    ->visible(fn (Announcement $record): bool => ! $record->wasBroadcast()
                        && (auth('admin')->user()?->can('update', $record) ?? false))
                    ->action(function (Announcement $record): void {
                        if (! $record->active) {
                            Notification::make()
                                ->title(__('notifications.announcements.broadcast_inactive'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $count = app(AnnouncementBroadcaster::class)->broadcast($record);

                        Notification::make()
                            ->title(__('notifications.announcements.broadcast_done', ['count' => $count]))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                // D-28: bulk actions run no per-record policies — filter rows
                // the actor cannot delete before acting.
                Tables\Actions\DeleteBulkAction::make()
                    ->using(fn (Collection $records) => $records
                        ->filter(fn (Announcement $record): bool => auth('admin')->user()?->can('delete', $record) ?? false)
                        ->each(fn (Announcement $record) => $record->delete())),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageAnnouncements::route('/'),
        ];
    }
}
