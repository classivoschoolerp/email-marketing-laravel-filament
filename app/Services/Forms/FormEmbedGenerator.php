<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormVariant;

/**
 * The three embed variants of spec F-2.7-011.
 *
 *  - {@see script()}  a one-line <script> tag pointing at the loader route.
 *                     The loader itself is a real, self-contained program
 *                     ({@see payload()} + resources/views/public/forms/loader
 *                     .blade.php) that renders the form and honours the
 *                     display + frequency rules in the visitor's browser.
 *  - {@see iframe()}  an isolated frame around the hosted page — immune to
 *                     the host site's CSS, but no overlay behaviour.
 *  - {@see html()}    the raw markup, for people who want to own the styling.
 */
class FormEmbedGenerator
{
    public function __construct(private readonly FormRenderer $renderer) {}

    /** F-2.7-011 variant 1 — the JS snippet. */
    public function script(Form $form): string
    {
        $src = route('forms.public.script', ['uid' => $form->uid]);

        $lines = [];

        if (! $form->type->isOverlay()) {
            // Embedded forms render into this placeholder; without it the
            // loader falls back to the script tag's own position.
            $lines[] = '<div data-emp-form="'.e((string) $form->uid).'"></div>';
        }

        $lines[] = '<script src="'.e($src).'" async></script>';

        return implode("\n", $lines);
    }

    /** F-2.7-011 variant 2 — the iframe. */
    public function iframe(Form $form): string
    {
        $src = route('forms.public.show', ['uid' => $form->uid]);
        $height = $form->type->isOverlay() ? 620 : 520;

        return '<iframe src="'.e($src).'" width="100%" height="'.$height.'" frameborder="0" '
            .'style="border:0;max-width:100%" title="'.e($form->displayName()).'" loading="lazy"></iframe>';
    }

    /** F-2.7-011 variant 3 — raw HTML, no JavaScript needed. */
    public function html(Form $form, ?FormVariant $variant = null): string
    {
        return $this->renderer->standalone($form, $variant);
    }

    /**
     * Everything the loader needs, in one JSON document: the rendered body,
     * the scoped CSS, the normalised rule sets and the two endpoints. The
     * browser never re-derives a rule — it executes what the builder saved.
     *
     * @return array<string, mixed>
     */
    public function payload(Form $form, ?FormVariant $variant = null): array
    {
        return [
            'uid' => (string) $form->uid,
            'type' => $form->type->value,
            'scope' => FormStyles::scope($form),
            'body' => $this->renderer->body($form, $variant),
            'css' => FormStyles::css($form, $variant),
            'styles' => FormStyles::for($form, $variant),
            'display' => DisplayRules::for($form),
            'frequency' => FrequencyRules::for($form),
            'steps' => FormSteps::enabled($form) ? count(FormSteps::for($form)['items']) : 1,
            // The arm travels with the payload so the impression beacon can be
            // credited to it: the beacon POSTs with `credentials: "omit"`, so
            // the assignment cookie never reaches us (F-2.7-013).
            'variant' => $variant === null ? null : (string) $variant->uid,
            'submitUrl' => route('forms.public.submit', ['uid' => $form->uid]),
            'viewUrl' => route('forms.public.impression', ['uid' => $form->uid]),
            'stepUrl' => route('forms.public.step', ['uid' => $form->uid]),
            'strings' => [
                'close' => __('forms.public.close'),
                'error' => __('forms.public.generic_error'),
                'success' => trim((string) $form->success_message) ?: __('forms.public.success_default'),
                'stepOf' => __('forms.public.step_of', ['current' => '{current}', 'total' => '{total}']),
            ],
        ];
    }
}
