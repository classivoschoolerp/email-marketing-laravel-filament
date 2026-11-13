<?php

declare(strict_types=1);

namespace App\Services\Templates\Builders;

use App\Enums\TemplateBuilder;
use App\Models\Customer;
use App\Models\TemplateSetting;
use App\Services\Preferences\CustomerPreferences;
use InvalidArgumentException;

/**
 * Builder driver registry (spec §2.5.3). Self-initialising from
 * config('emp-templates.builders') so no service-provider wiring is needed;
 * resolve via the container: app(BuilderRegistry::class).
 *
 * Default-builder resolution order (F-2.5.3-005):
 *   1. customer preference (customer_preferences "templates.default_builder")
 *   2. operator override    (template_settings "default_builder")
 *   3. config('emp-templates.default_builder')
 * Unavailable drivers are skipped at every step; GrapesJS is the final
 * fallback (D-11).
 */
class BuilderRegistry
{
    public const CUSTOMER_PREFERENCE_KEY = 'templates.default_builder';

    public const OPERATOR_SETTING_KEY = 'default_builder';

    /** @var array<string, BuilderDriver> */
    private array $drivers = [];

    public function __construct()
    {
        foreach (config('emp-templates.builders', []) as $class) {
            $this->register(app($class));
        }
    }

    public function register(BuilderDriver $driver): void
    {
        $this->drivers[$driver->key()->value] = $driver;
    }

    /** @return array<string, BuilderDriver> keyed by driver key */
    public function all(): array
    {
        return $this->drivers;
    }

    /** @return array<string, BuilderDriver> only drivers usable right now */
    public function available(): array
    {
        return array_filter($this->drivers, fn (BuilderDriver $d): bool => $d->isAvailable());
    }

    public function get(TemplateBuilder|string $key): BuilderDriver
    {
        $key = $key instanceof TemplateBuilder ? $key->value : $key;

        return $this->drivers[$key]
            ?? throw new InvalidArgumentException("Unknown template builder driver [{$key}].");
    }

    /** @return array<string, string> key => label, for select options */
    public function options(): array
    {
        return array_map(fn (BuilderDriver $d): string => $d->label(), $this->available());
    }

    /** Platform-wide default (operator override → config fallback). */
    public function operatorDefault(): TemplateBuilder
    {
        $key = TemplateSetting::query()->where('key', self::OPERATOR_SETTING_KEY)->value('value')
            ?? (string) config('emp-templates.default_builder', TemplateBuilder::GrapesJs->value);

        return $this->firstAvailable($key);
    }

    /** Effective default for a customer (preference → operator → config). */
    public function defaultFor(?Customer $customer): TemplateBuilder
    {
        if ($customer !== null) {
            $preferred = app(CustomerPreferences::class)->get($customer, self::CUSTOMER_PREFERENCE_KEY);

            if ($preferred !== null) {
                return $this->firstAvailable($preferred);
            }
        }

        return $this->operatorDefault();
    }

    private function firstAvailable(string $key): TemplateBuilder
    {
        $case = TemplateBuilder::tryFrom($key);

        if ($case !== null && isset($this->available()[$case->value])) {
            return $case;
        }

        return TemplateBuilder::GrapesJs;
    }
}
