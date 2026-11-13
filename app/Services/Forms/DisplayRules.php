<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FormType;
use App\Models\Form;

/**
 * Canonical display-rule document for a form (spec F-2.7-005).
 *
 * One key set, three consumers: the Filament builder writes it, this class
 * normalises it, and the embed loader (F-2.7-011) receives the normalised
 * copy and evaluates it in the browser. Nothing else may invent a key —
 * a rule the loader cannot see is a rule that does not exist.
 *
 * Keys
 *  - pages_mode   all|contains|exact  which pages the form may appear on
 *  - pages        list<string>        URL fragments / absolute paths
 *  - delay_seconds int                show after N seconds on the page
 *  - scroll_percent int               show after scrolling N% of the page
 *  - exit_intent  bool                show when the pointer leaves the viewport
 *  - min_page_views int              show only from the visitor's Nth page view
 *  - devices      list<string>        desktop|tablet|mobile (empty = all)
 *  - visitors     all|new|returning   new vs returning visitor targeting
 */
final class DisplayRules
{
    public const PAGES_ALL = 'all';

    public const PAGES_CONTAINS = 'contains';

    public const PAGES_EXACT = 'exact';

    public const VISITORS_ALL = 'all';

    public const VISITORS_NEW = 'new';

    public const VISITORS_RETURNING = 'returning';

    public const DEVICES = ['desktop', 'tablet', 'mobile'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'pages_mode' => self::PAGES_ALL,
            'pages' => [],
            'delay_seconds' => 0,
            'scroll_percent' => 0,
            'exit_intent' => false,
            'min_page_views' => 0,
            'devices' => [],
            'visitors' => self::VISITORS_ALL,
        ];
    }

    /**
     * Normalised rules for a form: unknown keys dropped, types coerced, and
     * the exit-intent SHAPE forced to carry the exit-intent rule (F-2.7-002
     * names it a form type, F-2.7-005 a rule — they are the same behaviour).
     *
     * @return array<string, mixed>
     */
    public static function for(Form $form): array
    {
        $rules = self::normalize((array) $form->display_rules);

        if ($form->type instanceof FormType && $form->type->impliesExitIntent()) {
            $rules['exit_intent'] = true;
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $defaults = self::defaults();

        $mode = (string) ($raw['pages_mode'] ?? $defaults['pages_mode']);

        $pages = array_values(array_filter(array_map(
            static fn (mixed $page): string => trim((string) (is_array($page) ? ($page['url'] ?? '') : $page)),
            (array) ($raw['pages'] ?? []),
        ), static fn (string $page): bool => $page !== ''));

        $devices = array_values(array_intersect(
            self::DEVICES,
            array_map(static fn (mixed $d): string => (string) $d, (array) ($raw['devices'] ?? [])),
        ));

        $visitors = (string) ($raw['visitors'] ?? $defaults['visitors']);

        return [
            'pages_mode' => in_array($mode, [self::PAGES_ALL, self::PAGES_CONTAINS, self::PAGES_EXACT], true)
                ? $mode
                : self::PAGES_ALL,
            'pages' => $pages,
            'delay_seconds' => max(0, (int) ($raw['delay_seconds'] ?? 0)),
            'scroll_percent' => min(100, max(0, (int) ($raw['scroll_percent'] ?? 0))),
            'exit_intent' => (bool) ($raw['exit_intent'] ?? false),
            'min_page_views' => max(0, (int) ($raw['min_page_views'] ?? 0)),
            'devices' => $devices,
            'visitors' => in_array($visitors, [self::VISITORS_ALL, self::VISITORS_NEW, self::VISITORS_RETURNING], true)
                ? $visitors
                : self::VISITORS_ALL,
        ];
    }

    /** @return array<string, string> */
    public static function pageModeOptions(): array
    {
        return [
            self::PAGES_ALL => __('forms.display.pages_all'),
            self::PAGES_CONTAINS => __('forms.display.pages_contains'),
            self::PAGES_EXACT => __('forms.display.pages_exact'),
        ];
    }

    /** @return array<string, string> */
    public static function visitorOptions(): array
    {
        return [
            self::VISITORS_ALL => __('forms.display.visitors_all'),
            self::VISITORS_NEW => __('forms.display.visitors_new'),
            self::VISITORS_RETURNING => __('forms.display.visitors_returning'),
        ];
    }

    /** @return array<string, string> */
    public static function deviceOptions(): array
    {
        $options = [];

        foreach (self::DEVICES as $device) {
            $options[$device] = __('forms.display.device_'.$device);
        }

        return $options;
    }
}
