<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\UserGroupResource\Pages;
use App\Models\UserGroup;
use App\Support\Admin\AdminModules;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Admin user groups (spec §3.3): fine-grained view/create/update/delete
 * permission matrix over every admin module (F-3.3-001), stored as spatie
 * permissions on the group's backing role, enforced at route level by the
 * module policies (F-3.3-002). Users are assigned via the user form's group
 * select (F-3.3-003).
 */
class UserGroupResource extends Resource
{
    protected static ?string $model = UserGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Users & Access';

    protected static ?string $slug = 'user-groups';

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('admin.user_groups.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.user_groups.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.user_groups.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.access');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.user_groups.form.general'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('admin.user_groups.fields.name'))
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    Forms\Components\Textarea::make('description')
                        ->label(__('admin.user_groups.fields.description'))
                        ->rows(2)
                        ->nullable(),
                ]),

            Forms\Components\Section::make(__('admin.user_groups.form.matrix'))
                ->description(__('admin.user_groups.help.matrix'))
                ->columns(2)
                ->schema(array_map(
                    fn (string $module): Forms\Components\CheckboxList => Forms\Components\CheckboxList::make('matrix.'.$module)
                        ->label(__('admin.user_groups.modules.'.$module))
                        ->options(array_combine(
                            AdminModules::ACTIONS,
                            array_map(
                                fn (string $action): string => __('admin.user_groups.actions.'.$action),
                                AdminModules::ACTIONS,
                            ),
                        ))
                        ->columns(4)
                        ->dehydrated(),
                    AdminModules::MODULES,
                )),

            Forms\Components\Section::make(__('admin.user_groups.form.extra'))
                ->description(__('admin.user_groups.help.extra'))
                ->schema([
                    Forms\Components\CheckboxList::make('extra_permissions')
                        ->label(__('admin.user_groups.fields.extra_permissions'))
                        ->options(array_combine(AdminModules::EXTRA_PERMISSIONS, AdminModules::EXTRA_PERMISSIONS))
                        ->columns(3)
                        ->dehydrated(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.user_groups.fields.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('users_count')
                    ->label(__('admin.user_groups.fields.users_count'))
                    ->counts('users'),
                Tables\Columns\TextColumn::make('role.permissions_count')
                    ->label(__('admin.user_groups.fields.permissions_count'))
                    ->state(fn (UserGroup $record): int => count($record->matrixPermissions())),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('admin.user_groups.fields.updated_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUserGroups::route('/'),
            'create' => Pages\CreateUserGroup::route('/create'),
            'edit' => Pages\EditUserGroup::route('/{record}/edit'),
        ];
    }
}
