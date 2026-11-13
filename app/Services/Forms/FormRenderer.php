<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\ListFieldType;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVariant;
use App\Services\Captcha\CaptchaManager;
use App\Support\Geo\Countries;

/**
 * Server-side renderer for a §2.7 form (F-2.7-003 field library,
 * F-2.7-004 multi-step, F-2.7-007 styling, F-2.7-009 consent, F-2.7-010
 * honeypot/captcha, F-2.7-013 A/B arms).
 *
 * One implementation feeds all three delivery shapes (F-2.7-011): the hosted
 * / iframe page embeds {@see body()} in a Blade layout, the raw-HTML snippet
 * is {@see standalone()}, and the JS loader ships {@see body()} inside its
 * JSON payload. Because the markup is produced here and not in the browser,
 * a visitor with a blocked script still gets a working form on the hosted
 * page, and the three shapes can never drift apart.
 *
 * MULTI-STEP WITHOUT JAVASCRIPT (F-2.7-004). Every step is rendered, visible,
 * in the same document — the server never hides one. The step controls
 * (`Next` / `Back`) sit inside a `[data-emp-nav] hidden` wrapper that only the
 * loader's step script un-hides, and the submit button sits AFTER the last
 * fieldset rather than inside it. So with scripting disabled a visitor sees
 * one long form with a single submit button and one full-validation POST,
 * which is a working form; with scripting on, the same markup becomes a
 * paged form. Nothing is hidden by CSS, because CSS a browser applies but a
 * script cannot undo would produce an unreachable step.
 *
 * All interpolation is escaped; the only unescaped values are the validated
 * literals produced by {@see FormStyles} (D-82 rule).
 */
class FormRenderer
{
    /** Name of the posted field carrying the traffic source (F-2.7-012). */
    public const SOURCE_FIELD = 'emp_source';

    /** Name of the posted field carrying the host page URL. */
    public const PAGE_FIELD = 'emp_page_url';

    /** Name of the GDPR consent checkbox (F-2.7-009). */
    public const CONSENT_FIELD = 'emp_consent';

    /**
     * Name of the hidden input carrying the served A/B arm (F-2.7-013).
     *
     * The arm travels in the POST BODY, not only in the assignment cookie,
     * because the loader submits with `credentials: "omit"` from a third-party
     * origin: no cookie reaches us there, and a submission credited to the
     * wrong arm is worse than no test at all.
     */
    public const VARIANT_FIELD = 'emp_variant';

    /** Name of the posted step number on the per-step validation call. */
    public const STEP_FIELD = 'emp_step';

    /**
     * The inner markup of the form: heading, description, inputs, consent,
     * captcha, honeypot and the submit button. Excludes the <form> element
     * so the caller decides between a real POST and a fetch() submit.
     */
    public function body(Form $form, ?FormVariant $variant = null): string
    {
        $scope = FormStyles::scope($form);
        $styles = FormStyles::for($form, $variant);
        $lines = [];

        if ($styles['image_url'] !== null && $styles['image_position'] !== 'none') {
            $lines[] = '<div class="emp-form__panel"'
                .($styles['image_position'] === 'right' ? ' style="flex-direction:row-reverse"' : '')
                .($styles['image_position'] === 'top' ? ' style="flex-direction:column"' : '').'>';
            $lines[] = '<div class="emp-form__image" style="background-image:url('.e($styles['image_url']).')"></div>';
            $lines[] = '<div class="emp-form__body">';
        } else {
            $lines[] = '<div class="emp-form__body">';
        }

        $heading = trim((string) ($variant?->effectiveHeading($form) ?? $form->heading));

        if ($heading !== '') {
            $lines[] = '<h2 class="emp-form__heading" style="margin:0 0 8px;font-size:20px">'.e($heading).'</h2>';
        }

        $description = trim((string) ($variant?->effectiveDescription($form) ?? $form->description));

        if ($description !== '') {
            $lines[] = '<p class="emp-form__description" style="margin:0 0 16px;font-size:14px;opacity:.85">'.e($description).'</p>';
        }

        // Honeypot (F-2.7-010): same field name as every other public form in
        // the product so one bot filter covers them all.
        $lines[] = '<div style="position:absolute;left:-5000px;top:-5000px" aria-hidden="true">'
            .'<input type="text" name="'.PublicSubscribeController::HONEYPOT_FIELD.'" tabindex="-1" value="" autocomplete="off"></div>';

        $lines[] = '<input type="hidden" name="'.self::SOURCE_FIELD.'" value="">';
        $lines[] = '<input type="hidden" name="'.self::PAGE_FIELD.'" value="">';

        if ($variant !== null) {
            $lines[] = '<input type="hidden" name="'.self::VARIANT_FIELD.'" value="'.e((string) $variant->uid).'">';
        }

        $fields = $form->orderedFields();

        if ($variant !== null) {
            $fields = $variant->effectiveFields($fields);
        }

        if (FormSteps::enabled($form)) {
            foreach ($this->stepSections($form, $fields, $scope) as $section) {
                $lines[] = $section;
            }
        } else {
            $lines[] = $this->emailField($form, $scope);

            foreach ($fields as $field) {
                $lines[] = $this->field($field, $scope);
            }

            foreach ($this->closing($form) as $closing) {
                $lines[] = $closing;
            }
        }

        $submitLabel = trim((string) ($variant?->effectiveSubmitLabel($form) ?? $form->submit_label))
            ?: __('forms.public.submit_label');
        $lines[] = '<button type="submit" class="emp-form__submit">'.e($submitLabel).'</button>';
        $lines[] = '<div class="emp-form__errors" role="alert"></div>';
        $lines[] = '</div>';

        if ($styles['image_url'] !== null && $styles['image_position'] !== 'none') {
            $lines[] = '</div>';
        }

        return implode("\n", $lines);
    }

    /**
     * The raw copy-paste HTML variant (F-2.7-011): a complete, dependency
     * free <form> that POSTs straight to the public submit endpoint. Works
     * with JavaScript disabled — a multi-step form therefore appears here as
     * one long form with a single submit, which is the documented degrade.
     */
    public function standalone(Form $form, ?FormVariant $variant = null): string
    {
        $action = route('forms.public.submit', ['uid' => $form->uid]);
        $scope = FormStyles::scope($form);

        return implode("\n", [
            '<style>'.FormStyles::css($form, $variant).'</style>',
            '<form class="emp-form emp-form-'.$scope.'" method="post" action="'.e($action).'" accept-charset="UTF-8">',
            $this->body($form, $variant),
            '</form>',
        ]);
    }

    /**
     * One <fieldset> per step plus the progress indicator (F-2.7-004).
     *
     * The email input is always on step 1 (it is the lead) and consent +
     * captcha are always on the last step (consent is given for the whole
     * submission, not for a fragment of it) — the same rule
     * {@see FormSubmissionService::validateStep()} applies, because both read
     * their grouping from {@see FormSteps::group()}.
     *
     * @param  list<FormField>  $fields
     * @return list<string>
     */
    private function stepSections(Form $form, array $fields, string $scope): array
    {
        $document = FormSteps::for($form);
        $items = $document['items'];
        $count = count($items);
        $groups = FormSteps::group($fields, $count);
        $lines = [];

        if ($document['show_progress']) {
            $lines[] = '<div class="emp-form__progress" data-emp-progress>'
                .'<div class="emp-form__progress-track"><span class="emp-form__progress-bar" data-emp-progress-bar'
                .' style="width:'.(int) round(100 / $count).'%"></span></div>'
                .'<div class="emp-form__progress-label" data-emp-progress-label>'
                .e(__('forms.public.step_of', ['current' => 1, 'total' => $count])).'</div></div>';
        }

        foreach ($items as $index => $item) {
            $number = $index + 1;
            $lines[] = '<fieldset class="emp-form__step" data-emp-step="'.$number.'">';

            if (trim($item['title']) !== '') {
                $lines[] = '<legend class="emp-form__step-title">'.e($item['title']).'</legend>';
            }

            if (trim($item['description']) !== '') {
                $lines[] = '<p class="emp-form__step-description">'.e($item['description']).'</p>';
            }

            if ($number === 1) {
                $lines[] = $this->emailField($form, $scope);
            }

            foreach ($groups[$number] as $field) {
                $lines[] = $this->field($field, $scope);
            }

            if ($number === $count) {
                foreach ($this->closing($form) as $closing) {
                    $lines[] = $closing;
                }
            }

            // `hidden` until the step script un-hides it: without JavaScript
            // these controls would be dead buttons on a single long form.
            $nav = '<div class="emp-form__nav" data-emp-nav hidden>';

            if ($number > 1) {
                $nav .= '<button type="button" class="emp-form__back" data-emp-back>'
                    .e(__('forms.public.back')).'</button>';
            }

            if ($number < $count) {
                $nextLabel = trim($item['next_label']) ?: __('forms.public.next');
                $nav .= '<button type="button" class="emp-form__next" data-emp-next>'.e($nextLabel).'</button>';
            }

            $lines[] = $nav.'</div>';
            $lines[] = '</fieldset>';
        }

        return $lines;
    }

    /** The built-in email input (F-2.7-003) — always present, always step 1. */
    private function emailField(Form $form, string $scope): string
    {
        $emailLabel = trim((string) $form->email_label) ?: __('forms.public.email_label');

        return '<div class="emp-form__field">'
            .'<label class="emp-form__label" for="emp-'.$scope.'-email">'.e($emailLabel).'</label>'
            .'<input type="email" id="emp-'.$scope.'-email" name="email" required autocomplete="email">'
            .'</div>';
    }

    /**
     * Consent (F-2.7-009) and captcha (F-2.7-010) — the block that closes a
     * form, whether that is the only step or the last of five.
     *
     * @return list<string>
     */
    private function closing(Form $form): array
    {
        $lines = [];

        if ($form->consent_enabled) {
            $consentText = trim((string) $form->consent_text) ?: __('forms.public.consent_default');
            $lines[] = '<div class="emp-form__field"><label class="emp-form__consent" style="display:flex;gap:8px;align-items:flex-start;font-size:13px">'
                .'<input type="checkbox" name="'.self::CONSENT_FIELD.'" value="1" style="width:auto;margin-top:3px"'
                .($form->consent_required ? ' required' : '').'>'
                .'<span>'.e($consentText).'</span></label></div>';
        }

        if ($form->captcha_enabled) {
            $captcha = CaptchaManager::driver();

            if ($captcha->enabled() && $captcha->siteKey() !== null) {
                if ($captcha->scriptUrl() !== null) {
                    $lines[] = '<script src="'.e($captcha->scriptUrl()).'" async defer></script>';
                }

                $lines[] = '<div class="g-recaptcha h-captcha" data-sitekey="'.e($captcha->siteKey()).'"></div>';
            }
        }

        return $lines;
    }

    /** One input, by type — the §2.2.9 library, rendered for a form field. */
    private function field(FormField $field, string $scope): string
    {
        $tag = e($field->tag);
        $id = 'emp-'.$scope.'-'.strtolower($field->tag);
        $required = $field->required ? ' required' : '';
        $default = e((string) ($field->default_value ?? ''));
        $placeholder = $field->placeholder ? ' placeholder="'.e((string) $field->placeholder).'"' : '';

        if ($field->type === ListFieldType::Hidden) {
            return '<input type="hidden" name="'.$tag.'" value="'.$default.'">';
        }

        $input = match ($field->type) {
            ListFieldType::Textarea => '<textarea id="'.$id.'" name="'.$tag.'" rows="3"'.$required.$placeholder.'>'.$default.'</textarea>',
            ListFieldType::Dropdown => $this->select($id, $tag, $field, false, $required),
            ListFieldType::Multiselect => $this->select($id, $tag, $field, true, ''),
            ListFieldType::Radio => $this->choices($field, 'radio', $tag),
            ListFieldType::CheckboxList => $this->choices($field, 'checkbox', $tag.'[]'),
            ListFieldType::Checkbox, ListFieldType::Consent => '<input type="checkbox" id="'.$id.'" name="'.$tag.'" value="1" style="width:auto"'.$required.'>',
            ListFieldType::Country => $this->countrySelect($id, $tag, $required),
            ListFieldType::Date => '<input type="date" id="'.$id.'" name="'.$tag.'" value="'.$default.'"'.$required.'>',
            ListFieldType::Datetime => '<input type="datetime-local" id="'.$id.'" name="'.$tag.'"'.$required.'>',
            ListFieldType::Number => '<input type="number" id="'.$id.'" name="'.$tag.'" value="'.$default.'"'.$required.$placeholder.'>',
            ListFieldType::Rating => $this->rating($id, $tag, $field, $required),
            ListFieldType::Email => '<input type="email" id="'.$id.'" name="'.$tag.'" value="'.$default.'"'.$required.$placeholder.'>',
            ListFieldType::Url => '<input type="url" id="'.$id.'" name="'.$tag.'" value="'.$default.'"'.$required.$placeholder.'>',
            ListFieldType::Phone => '<input type="tel" id="'.$id.'" name="'.$tag.'" value="'.$default.'"'.$required.$placeholder.'>',
            default => '<input type="text" id="'.$id.'" name="'.$tag.'" value="'.$default.'"'.$required.$placeholder.'>',
        };

        $label = '<label class="emp-form__label" for="'.$id.'">'.e($field->label)
            .($field->required ? ' <span aria-hidden="true">*</span>' : '').'</label>';
        $help = $field->help_text ? '<div class="emp-form__hint">'.e((string) $field->help_text).'</div>' : '';

        // Boolean inputs read better with the label after the box.
        if ($field->type->isBoolean()) {
            return '<div class="emp-form__field"><label style="display:flex;gap:8px;align-items:center;font-size:13px">'
                .$input.'<span>'.e($field->label).'</span></label>'.$help.'</div>';
        }

        return '<div class="emp-form__field">'.$label.$input.$help.'</div>';
    }

    private function select(string $id, string $tag, FormField $field, bool $multiple, string $required): string
    {
        $name = $multiple ? $tag.'[]' : $tag;
        $out = '<select id="'.$id.'" name="'.$name.'"'.($multiple ? ' multiple' : '').$required.'>';

        if (! $multiple) {
            $out .= '<option value="">'.e(__('lists.embed.select_placeholder')).'</option>';
        }

        foreach ($field->options as $option) {
            $selected = ! $multiple && (string) $field->default_value === (string) $option->value ? ' selected' : '';
            $out .= '<option value="'.e((string) $option->value).'"'.$selected.'>'.e((string) $option->label).'</option>';
        }

        return $out.'</select>';
    }

    private function choices(FormField $field, string $type, string $name): string
    {
        $out = '';

        foreach ($field->options as $option) {
            $out .= '<label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-top:4px">'
                .'<input type="'.$type.'" name="'.$name.'" value="'.e((string) $option->value).'" style="width:auto">'
                .'<span>'.e((string) $option->label).'</span></label>';
        }

        return $out;
    }

    private function rating(string $id, string $tag, FormField $field, string $required): string
    {
        $config = (array) $field->validation;
        $min = (int) ($config['min_value'] ?? 1);
        $max = (int) ($config['max_value'] ?? 5);

        $out = '<select id="'.$id.'" name="'.$tag.'"'.$required.'>';
        $out .= '<option value="">'.e(__('lists.embed.select_placeholder')).'</option>';

        for ($value = $min; $value <= $max; $value++) {
            $out .= '<option value="'.$value.'">'.$value.'</option>';
        }

        return $out.'</select>';
    }

    private function countrySelect(string $id, string $tag, string $required): string
    {
        $out = '<select id="'.$id.'" name="'.$tag.'"'.$required.'>';
        $out .= '<option value="">'.e(__('lists.embed.select_placeholder')).'</option>';

        foreach (Countries::all() as $code => $name) {
            $out .= '<option value="'.e($code).'">'.e($name).'</option>';
        }

        return $out.'</select>';
    }
}
