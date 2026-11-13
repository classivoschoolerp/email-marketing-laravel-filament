<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormField;

/**
 * The multi-step document of a form (spec F-2.7-004) and the one place that
 * decides WHICH STEP A FIELD IS ON.
 *
 * Same shape as {@see DisplayRules}, {@see FrequencyRules} and
 * {@see FormStyles}: a normaliser that clamps every value, applied at save and
 * again at render, so a hand-edited row can never produce a step list the
 * renderer or the validator disagree about (the D-100 rule).
 *
 * Two invariants the rest of the module leans on:
 *
 *  1. {@see group()} is the SINGLE grouping implementation. The renderer draws
 *     from it and {@see FormSubmissionService::validateStep()} validates from
 *     it, so "what the visitor was asked on step 2" and "what step 2 validates"
 *     cannot drift.
 *  2. A field whose `step` points past the last defined step is clamped ONTO
 *     the last step, never dropped. Deleting a step must not make a required
 *     input vanish from the form while still being required at submit — that
 *     would produce a form nobody can complete.
 *
 * The built-in email input always lives on step 1 and the consent tick,
 * captcha and submit button always live on the last step: the address is the
 * lead (so it is asked first) and consent must be given for the submission as
 * a whole, not for a fragment of it.
 */
final class FormSteps
{
    /** More than this and the "step N of M" indicator stops meaning anything. */
    public const MAX_STEPS = 10;

    /** @return array{show_progress:bool, items:list<array{title:string, description:string, next_label:string}>} */
    public static function defaults(): array
    {
        return ['show_progress' => true, 'items' => [self::item([])]];
    }

    /**
     * The normalised document of a form.
     *
     * @return array{show_progress:bool, items:list<array{title:string, description:string, next_label:string}>}
     */
    public static function for(Form $form): array
    {
        return self::normalize((array) $form->steps);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{show_progress:bool, items:list<array{title:string, description:string, next_label:string}>}
     */
    public static function normalize(array $raw): array
    {
        $items = [];

        foreach (array_slice(array_values((array) ($raw['items'] ?? [])), 0, self::MAX_STEPS) as $item) {
            $items[] = self::item(is_array($item) ? $item : []);
        }

        if ($items === []) {
            $items[] = self::item([]);
        }

        return [
            'show_progress' => (bool) ($raw['show_progress'] ?? true),
            'items' => $items,
        ];
    }

    /** How many steps this form actually has (always ≥ 1). */
    public static function count(Form $form): int
    {
        return self::enabled($form) ? count(self::for($form)['items']) : 1;
    }

    /**
     * Multi-step behaviour is on. Requires BOTH the toggle and more than one
     * defined step: a "multi-step" form with one step is a normal form, and
     * rendering a progress bar reading "step 1 of 1" would be nonsense.
     */
    public static function enabled(Form $form): bool
    {
        return (bool) $form->multi_step && count(self::normalize((array) $form->steps)['items']) > 1;
    }

    public static function showProgress(Form $form): bool
    {
        return self::enabled($form) && self::for($form)['show_progress'];
    }

    /** The step a field belongs to, clamped into 1..$count (see the class docblock). */
    public static function stepOf(FormField $field, int $count): int
    {
        return max(1, min(max(1, $count), (int) ($field->step ?? 1)));
    }

    /**
     * Fields bucketed by step number, every step 1..$count present (an empty
     * step is a legitimate — if pointless — configuration, and hiding it would
     * shift every following step number).
     *
     * @param  list<FormField>  $fields
     * @return array<int, list<FormField>>
     */
    public static function group(array $fields, int $count): array
    {
        $count = max(1, $count);
        $groups = [];

        for ($step = 1; $step <= $count; $step++) {
            $groups[$step] = [];
        }

        foreach ($fields as $field) {
            $groups[self::stepOf($field, $count)][] = $field;
        }

        return $groups;
    }

    /**
     * Step numbers as a Filament select map, e.g. [1 => 'Step 1 — Your name'].
     *
     * @return array<int, string>
     */
    public static function options(Form $form): array
    {
        $options = [];

        foreach (self::for($form)['items'] as $index => $item) {
            $number = $index + 1;
            $title = trim($item['title']);

            $options[$number] = $title === ''
                ? __('forms.steps.step_n', ['number' => $number])
                : __('forms.steps.step_n', ['number' => $number]).' — '.$title;
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{title:string, description:string, next_label:string}
     */
    private static function item(array $raw): array
    {
        return [
            'title' => mb_substr(trim((string) ($raw['title'] ?? '')), 0, 191),
            'description' => mb_substr(trim((string) ($raw['description'] ?? '')), 0, 1000),
            'next_label' => mb_substr(trim((string) ($raw['next_label'] ?? '')), 0, 120),
        ];
    }
}
