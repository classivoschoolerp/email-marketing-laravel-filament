<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthModuleServiceProvider;
use App\Providers\BrandingServiceProvider;
use App\Providers\ComplianceServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\CustomerPanelProvider;
use App\Providers\I18nServiceProvider;
use App\Providers\ModulesServiceProvider;
use App\Providers\SkinServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    CustomerPanelProvider::class,
    // Must register after the panel providers: it overrides the customer
    // panel login page (WP-02, spec §1.3).
    AuthModuleServiceProvider::class,
    ModulesServiceProvider::class,
    SkinServiceProvider::class,
    I18nServiceProvider::class,
    ComplianceServiceProvider::class,
    // WP-38 (§6.3/§6.4/§6.7): theme registry, host→brand resolution middleware
    // and the social-auth driver registry. Must stay ABOVE the plugin
    // provider so a plugin boots into an already-branded application.
    BrandingServiceProvider::class,
];
