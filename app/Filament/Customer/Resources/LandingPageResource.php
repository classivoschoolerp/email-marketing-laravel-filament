<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Enums\LandingPageStatus;
use App\Filament\Customer\Resources\LandingPageResource\Pages;
use App\Models\Customer;
use App\Models\Form as FormModel;
use App\Models\LandingPage;
use App\Models\TrackingDomain;
use App\Services\Landing\LandingCustomCode;
use App\Services\Landing\LandingPageUrl;
use App\Services\Landing\LandingTemplates;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

/**
 * Landing pages (spec §2.6 — WP-34). This resource owns the SETTINGS of a
 * page: identity and slug (F-2.6-001), custom domain/subdomain and its SSL
 * state (F-2.6-006), SEO (F-2.6-007), the embedded form and what happens
 * after a submit (F-2.6-009/011), custom code and tracking IDs (F-2.6-010)
 * and publishing (F-2.6-012). The drag-and-drop builder, the variants, the
 * gallery and the analytics each have their own page.
 *
 * Tenancy: global scope + an explicit customer_id constraint (defense in
 * depth, the FormResource/CampaignResource pattern).
 */
class LandingPageResource extends Resource
{
    protected static ?string $model = LandingPage::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $slug = 'landing-pages';

    protected static ?int $navigationSort = 35;

    public static function getNavigationGroup(): ?string
    {
        return __('landing.resource.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('landing.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('landing.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('landing.resource.plural_label');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        if ($user instanceof Customer) {
            $query->where('customer_id', $user->id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('landing')->columnSpanFull()->tabs([

                Forms\Components\Tabs\Tab::make(__('landing.tabs.general'))->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('landing.fields.name'))
                        ->required()
                        ->maxLength(191),

                    Forms\Components\TextInput::make('slug')
                        ->label(__('landing.fields.slug'))
                        ->helperText(__('landing.fields.slug_help'))
                        ->maxLength(191)
                        ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->validationMessages(['regex' => __('landing.errors.slug')]),

                    Forms\Components\Select::make('status')
                        ->label(__('landing.fields.status'))
                        ->options(LandingPageStatus::options())
                        ->default(LandingPageStatus::Draft->value)
                        ->required(),

                    Forms\Components\Select::make('template_key')
                        ->label(__('landing.fields.template'))
                        ->options(LandingTemplates::options())
                        ->disabled()
                        ->dehydrated(false)
                        ->visibleOn('edit'),
                ])->columns(2),

                // F-2.6-006
                Forms\Components\Tabs\Tab::make(__('landing.tabs.domain'))->schema([
                    Forms\Components\Select::make('tracking_domain_id')
                        ->label(__('landing.fields.tracking_domain'))
                        ->helperText(__('landing.fields.tracking_domain_help'))
                        ->placeholder(__('landing.fields.tracking_domain_none'))
                        ->options(fn (): array => TrackingDomain::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->live()
                        ->nullable(),

                    Forms\Components\TextInput::make('subdomain')
                        ->label(__('landing.fields.subdomain'))
                        ->helperText(fn (): string => __('landing.fields.subdomain_help', [
                            'host' => app(LandingPageUrl::class)->appHost(),
                        ]))
                        ->maxLength(63)
                        ->rule('regex:/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/'),

                    // SSL is reported, never claimed: the value is whatever the
                    // §2.9.8 verifier last wrote on the tracking domain row.
                    Forms\Components\Placeholder::make('ssl_status')
                        ->label(__('landing.fields.ssl_status'))
                        ->content(fn (Forms\Get $get): string => __(
                            'landing.ssl.'.app(LandingPageUrl::class)->sslStatus($get('tracking_domain_id')),
                        )),

                    Forms\Components\Placeholder::make('public_url')
                        ->label(__('landing.fields.public_url'))
                        ->content(fn (?LandingPage $record): string => $record === null
                            ? '—'
                            : app(LandingPageUrl::class)->url($record))
                        ->visibleOn('edit'),
                ])->columns(2),

                // F-2.6-007
                Forms\Components\Tabs\Tab::make(__('landing.tabs.seo'))->schema([
                    Forms\Components\TextInput::make('seo_title')
                        ->label(__('landing.fields.seo_title'))
                        ->helperText(__('landing.fields.seo_title_help'))
                        ->maxLength(191),
                    Forms\Components\Textarea::make('seo_description')
                        ->label(__('landing.fields.seo_description'))
                        ->rows(2)
                        ->maxLength(500),
                    Forms\Components\TextInput::make('og_image_url')
                        ->label(__('landing.fields.og_image_url'))
                        ->url()->maxLength(2048),
                    Forms\Components\TextInput::make('canonical_url')
                        ->label(__('landing.fields.canonical_url'))
                        ->helperText(__('landing.fields.canonical_help'))
                        ->url()->maxLength(2048),
                    Forms\Components\TextInput::make('favicon_url')
                        ->label(__('landing.fields.favicon_url'))
                        ->url()->maxLength(2048),
                    Forms\Components\Select::make('robots')
                        ->label(__('landing.fields.robots'))
                        ->options(fn (): array => collect(LandingPage::ROBOTS)
                            ->mapWithKeys(fn (string $value): array => [$value => __('landing.robots.'.$value)])
                            ->all())
                        ->default('index,follow')
                        ->required(),
                ])->columns(2),

                // F-2.6-009 / F-2.6-011
                Forms\Components\Tabs\Tab::make(__('landing.tabs.form'))->schema([
                    Forms\Components\Select::make('form_id')
                        ->label(__('landing.fields.form'))
                        ->helperText(__('landing.fields.form_help'))
                        ->placeholder(__('landing.fields.form_none'))
                        ->options(fn (): array => FormModel::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->nullable(),

                    Forms\Components\Radio::make('after_submit')
                        ->label(__('landing.fields.after_submit'))
                        ->options([
                            LandingPage::AFTER_MESSAGE => __('landing.after_submit.message'),
                            LandingPage::AFTER_THANK_YOU => __('landing.after_submit.thank_you'),
                            LandingPage::AFTER_REDIRECT => __('landing.after_submit.redirect'),
                        ])
                        ->default(LandingPage::AFTER_MESSAGE)
                        ->live()
                        ->required(),

                    Forms\Components\Textarea::make('success_message')
                        ->label(__('landing.fields.success_message'))
                        ->rows(2)
                        ->placeholder(__('landing.public.success_default')),

                    Forms\Components\TextInput::make('redirect_url')
                        ->label(__('landing.fields.redirect_url'))
                        ->url()->maxLength(2048)
                        ->required(fn (Forms\Get $get): bool => $get('after_submit') === LandingPage::AFTER_REDIRECT)
                        ->visible(fn (Forms\Get $get): bool => $get('after_submit') === LandingPage::AFTER_REDIRECT),

                    Forms\Components\Textarea::make('thank_you_html')
                        ->label(__('landing.fields.thank_you_html'))
                        ->helperText(__('landing.fields.thank_you_help'))
                        ->rows(6)
                        ->visible(fn (Forms\Get $get): bool => $get('after_submit') === LandingPage::AFTER_THANK_YOU),
                ])->columns(1),

                // F-2.6-010
                Forms\Components\Tabs\Tab::make(__('landing.tabs.code'))->schema([
                    Forms\Components\Textarea::make('custom_css')
                        ->label(__('landing.fields.custom_css'))
                        ->helperText(__('landing.fields.custom_code_help'))
                        ->rows(6),
                    Forms\Components\Textarea::make('custom_js')
                        ->label(__('landing.fields.custom_js'))
                        ->helperText(__('landing.fields.custom_code_help'))
                        ->rows(6),

                    // D-100: the SAME patterns the renderer enforces. An ID
                    // that would be silently dropped at render time must not
                    // be storable looking valid, or analytics never arrives
                    // and nobody knows why.
                    Forms\Components\TextInput::make('ga_measurement_id')
                        ->label(__('landing.fields.ga_measurement_id'))
                        ->helperText(__('landing.fields.tracking_ids_help'))
                        ->maxLength(32)
                        ->rules(['nullable', 'regex:'.LandingCustomCode::GA_PATTERN])
                        ->validationMessages(['regex' => __('landing.errors.ga_id')]),

                    Forms\Components\TextInput::make('gtm_container_id')
                        ->label(__('landing.fields.gtm_container_id'))
                        ->maxLength(32)
                        ->rules(['nullable', 'regex:'.LandingCustomCode::GTM_PATTERN])
                        ->validationMessages(['regex' => __('landing.errors.gtm_id')]),

                    Forms\Components\TextInput::make('fb_pixel_id')
                        ->label(__('landing.fields.fb_pixel_id'))
                        ->maxLength(32)
                        ->rules(['nullable', 'regex:'.LandingCustomCode::PIXEL_PATTERN])
                        ->validationMessages(['regex' => __('landing.errors.pixel_id')]),
                ])->columns(1),

                // F-2.6-004 / F-2.6-012
                Forms\Components\Tabs\Tab::make(__('landing.tabs.publish'))->schema([
                    Forms\Components\DateTimePicker::make('scheduled_at')
                        ->label(__('landing.fields.scheduled_at'))
                        ->helperText(__('landing.fields.scheduled_help'))
                        ->seconds(false),

                    Forms\Components\Placeholder::make('published_at_display')
                        ->label(__('landing.fields.published_at'))
                        ->content(fn (?LandingPage $record): string => $record?->published_at?->toDayDateTimeString() ?? '—')
                        ->visibleOn('edit'),

                    Forms\Components\Toggle::make('ab_enabled')
                        ->label(__('landing.fields.ab_enabled'))
                        ->helperText(__('landing.fields.ab_help')),

                    // F-1.8-006. Stored hashed, never echoed back: leaving the
                    // field blank on edit keeps the current password, and the
                    // "remove" toggle is the only way to clear it — otherwise
                    // "blank means public" would silently un-gate a page every
                    // time someone edited the SEO title.
                    Forms\Components\TextInput::make('access_password')
                        ->label(__('landing.fields.access_password'))
                        ->helperText(fn (?LandingPage $record): string => $record?->isPasswordProtected()
                            ? __('landing.fields.access_password_set')
                            : __('landing.fields.access_password_help'))
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        // Never round-trip the stored hash into the browser.
                        ->formatStateUsing(fn (): ?string => null)
                        ->maxLength(191)
                        // Hashed on the way out; "blank means keep the current
                        // password" is decided in normalize(), not by a
                        // dehydration predicate — the field is never populated
                        // from the record, so blank is its NORMAL state and
                        // must never be read as "clear it".
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                            ? Hash::make((string) $state)
                            : null)
                        ->disabled(fn (Forms\Get $get): bool => (bool) $get('remove_access_password')),

                    // Dehydrated on purpose so normalize() can act on it, then
                    // stripped there — it is a form control, not a column.
                    Forms\Components\Toggle::make('remove_access_password')
                        ->label(__('landing.fields.access_password_remove'))
                        ->live()
                        ->visible(fn (?LandingPage $record): bool => (bool) $record?->isPasswordProtected()),
                ])->columns(2),
            ]),
        ])->columns(1);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLandingPages::route('/'),
            'create' => Pages\CreateLandingPage::route('/create'),
            'gallery' => Pages\LandingTemplateGallery::route('/{record}/gallery'),
            'edit' => Pages\EditLandingPage::route('/{record}/edit'),
            'build' => Pages\BuildLandingPage::route('/{record}/build'),
            'variants' => Pages\ManageLandingVariants::route('/{record}/variants'),
            'analytics' => Pages\LandingPageAnalytics::route('/{record}/analytics'),
        ];
    }

    /**
     * Normalise the settings that the public render depends on.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data, ?LandingPage $record = null): array
    {
        // A cleared slug regenerates from the name rather than becoming a
        // random handle — the customer emptied the field to reset it.
        $slug = trim((string) ($data['slug'] ?? ''));

        $data['slug'] = LandingPage::uniqueSlug(
            $slug !== '' ? $slug : (string) ($data['name'] ?? ''),
            $record?->id,
        );

        foreach (['ga_measurement_id', 'gtm_container_id', 'fb_pixel_id', 'subdomain'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            $data[$key] = $value === '' ? null : $value;
        }

        if (! in_array($data['robots'] ?? null, LandingPage::ROBOTS, true)) {
            $data['robots'] = 'index,follow';
        }

        if (! in_array($data['after_submit'] ?? null, LandingPage::AFTER_MODES, true)) {
            $data['after_submit'] = LandingPage::AFTER_MESSAGE;
        }

        // F-1.8-006. The stored value is a hash and is never echoed back into
        // the form, so an EMPTY field means "keep the current password" — the
        // key is dropped entirely rather than written as null. The explicit
        // "remove" toggle is the only way to un-gate a page; without that
        // asymmetry, editing the SEO title would silently make a gated page
        // public.
        $remove = ($data['remove_access_password'] ?? false) === true;
        unset($data['remove_access_password']);

        if ($remove) {
            $data['access_password'] = null;
        } elseif (array_key_exists('access_password', $data) && blank($data['access_password'])) {
            unset($data['access_password']);
        }

        return $data;
    }
}
