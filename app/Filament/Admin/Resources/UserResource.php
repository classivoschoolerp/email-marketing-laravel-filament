<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\LoginResult;
use App\Filament\Admin\Resources\UserResource\Pages;
use App\Filament\Admin\Resources\UserResource\RelationManagers\LoginLogsRelationManager;
use App\Models\LoginLog;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use App\Policies\UserPolicy;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Permission;

/**
 * Admin users module (spec §3.2): index with group/status/last-login/2FA,
 * create/edit with role + granular permission assignment (F-3.2-003), the
 * impersonate-customer flag (F-3.2-005), force password reset,
 * disable/enable and guarded delete (F-3.2-004). The per-user activity log
 * (F-3.2-006) renders as relation managers on the edit page.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationGroup = 'Users & Access';

    protected static ?string $slug = 'users';

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('admin.users.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.users.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.users.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.access');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.users.form.account'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('admin.users.fields.name'))
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('email')
                        ->label(__('admin.users.fields.email'))
                        ->email()
                        ->required()
                        ->maxLength(191)
                        ->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('password')
                        ->label(__('admin.users.fields.password'))
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->rule(Password::defaults())
                        ->maxLength(255),
                    Forms\Components\Select::make('timezone')
                        ->label(__('admin.users.fields.timezone'))
                        ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                        ->searchable()
                        ->default('UTC')
                        ->required(),
                    Forms\Components\FileUpload::make('avatar_path')
                        ->label(__('admin.users.fields.avatar'))
                        ->disk('public')
                        ->directory('admins/avatars')
                        ->image()
                        ->nullable(),
                    Forms\Components\Select::make('user_group_id')
                        ->label(__('admin.users.fields.group'))
                        ->options(fn (): array => UserGroup::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->nullable(),
                    Forms\Components\Select::make('status')
                        ->label(__('admin.users.fields.status'))
                        ->options([
                            User::STATUS_ACTIVE => __('admin.users.status.active'),
                            User::STATUS_DISABLED => __('admin.users.status.disabled'),
                        ])
                        ->default(User::STATUS_ACTIVE)
                        ->required(),
                    Forms\Components\Toggle::make('force_password_reset')
                        ->label(__('admin.users.fields.force_password_reset'))
                        ->helperText(__('admin.users.help.force_password_reset')),
                ]),

            Forms\Components\Section::make(__('admin.users.form.access'))
                ->description(__('admin.users.help.access'))
                ->columns(2)
                ->schema([
                    Forms\Components\CheckboxList::make('assigned_roles')
                        ->label(__('admin.users.fields.roles'))
                        ->options(fn (): array => Role::query()
                            ->where('guard_name', 'admin')
                            ->whereNull('customer_id')
                            // group-backing roles are managed via the group select
                            ->where('name', 'not like', 'user-group:%')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->columns(2)
                        ->dehydrated(),
                    Forms\Components\CheckboxList::make('direct_permissions')
                        ->label(__('admin.users.fields.permissions'))
                        ->helperText(__('admin.users.help.permissions'))
                        ->options(fn (): array => Permission::query()
                            ->where('guard_name', 'admin')
                            ->where('name', '!=', (string) config('emp.impersonation.permission'))
                            ->orderBy('name')
                            ->pluck('name', 'name')
                            ->all())
                        ->columns(2)
                        ->dehydrated(),
                    Forms\Components\Toggle::make('can_impersonate')
                        ->label(__('admin.users.fields.can_impersonate'))
                        ->helperText(__('admin.users.help.can_impersonate'))
                        ->dehydrated(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->addSelect([
                'last_login_at' => LoginLog::query()
                    ->select('created_at')
                    ->where('guard', 'admin')
                    ->where('model_type', (new User)->getMorphClass())
                    ->whereColumn('model_id', 'users.id')
                    ->where('result', LoginResult::Success->value)
                    ->latest('created_at')
                    ->limit(1),
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.users.fields.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('admin.users.fields.email'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('group.name')
                    ->label(__('admin.users.fields.group'))
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.users.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.users.status.'.$state))
                    ->color(fn (string $state): string => $state === User::STATUS_ACTIVE ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('last_login_at')
                    ->label(__('admin.users.fields.last_login'))
                    ->dateTime()
                    ->placeholder(__('admin.users.never_logged_in')),
                Tables\Columns\IconColumn::make('twofa_enabled')
                    ->label(__('admin.users.fields.twofa'))
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_group_id')
                    ->label(__('admin.users.fields.group'))
                    ->options(fn (): array => UserGroup::query()->orderBy('name')->pluck('name', 'id')->all()),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.users.fields.status'))
                    ->options([
                        User::STATUS_ACTIVE => __('admin.users.status.active'),
                        User::STATUS_DISABLED => __('admin.users.status.disabled'),
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('disable')
                    ->label(__('admin.users.actions.disable'))
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => ! $record->isDisabled()
                        && $record->getKey() !== auth('admin')->id()
                        && auth('admin')->user()->can('update', $record))
                    ->action(function (User $record): void {
                        $record->forceFill(['status' => User::STATUS_DISABLED])->save();

                        Notification::make()->success()
                            ->title(__('admin.users.notifications.disabled'))
                            ->send();
                    }),

                Tables\Actions\Action::make('enable')
                    ->label(__('admin.users.actions.enable'))
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->visible(fn (User $record): bool => $record->isDisabled()
                        && auth('admin')->user()->can('update', $record))
                    ->action(function (User $record): void {
                        $record->forceFill(['status' => User::STATUS_ACTIVE])->save();

                        Notification::make()->success()
                            ->title(__('admin.users.notifications.enabled'))
                            ->send();
                    }),

                Tables\Actions\DeleteAction::make()
                    // F-3.2-004 guards stay absolute even for Super Admin
                    // (SuperAdminGate falls through; double-checked here).
                    ->visible(fn (User $record): bool => UserPolicy::deletionAllowed(auth('admin')->user(), $record)
                        && auth('admin')->user()->can('delete', $record)),
            ])
            ->bulkActions([
                // D-28: bulk delete must skip records the actor cannot delete
                // (self, last Super Admin, policy denials).
                Tables\Actions\DeleteBulkAction::make()
                    ->using(function (Collection $records): void {
                        $actor = auth('admin')->user();

                        $records
                            ->filter(fn (User $record): bool => UserPolicy::deletionAllowed($actor, $record)
                                && $actor->can('delete', $record))
                            ->each(fn (User $record) => $record->delete());
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LoginLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
