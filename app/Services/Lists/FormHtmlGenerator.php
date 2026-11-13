<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Enums\ListFieldType;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\ListForm;
use App\Services\Captcha\CaptchaManager;
use App\Support\Geo\Countries;

/**
 * Generates the copy-paste subscribe form for a list (spec §2.2.11): every
 * visible field rendered by type in the configured order, raw or styled
 * markup (F-2.2.11-004), plus the iframe and JS embed variants
 * (F-2.2.11-003). The form POSTs to the public /forms/{uid}/subscribe
 * endpoint and carries the honeypot input; the captcha widget is included
 * when a driver is configured.
 */
class FormHtmlGenerator
{
    /** The complete copy-paste HTML snippet (F-2.2.11-001). */
    public function html(EmailList $list, ListForm $form): string
    {
        $action = route('lists.forms.subscribe', ['list_uid' => $list->uid]);
        $lines = [];

        if ($form->styled) {
            $lines[] = $this->styles();
        }

        // AJAX submit (F-2.2.11-006): the flag the inline script binds to.
        $ajax = (bool) $form->ajax;
        $enctype = $this->needsMultipart($form) ? ' enctype="multipart/form-data"' : '';

        $lines[] = '<form class="emp-subscribe-form" method="post" action="'.e($action).'"'.$enctype
            .($ajax ? ' data-emp-ajax="1"' : '').'>';

        // Honeypot (spec §1.5): visually hidden, bots fill it, humans never see it.
        $lines[] = '  <div style="position:absolute;left:-5000px" aria-hidden="true">';
        $lines[] = '    <input type="text" name="'.PublicSubscribeController::HONEYPOT_FIELD.'" tabindex="-1" value="" autocomplete="off">';
        $lines[] = '  </div>';

        $lines[] = $this->fieldGroup(
            '<label for="emp-email">'.e(__('lists.embed.email_label')).'</label>',
            '<input type="email" id="emp-email" name="email" required>',
        );

        foreach ($form->orderedFields() as $field) {
            $lines[] = $this->renderField($field);
        }

        $captcha = CaptchaManager::driver();

        if ($captcha->enabled() && $captcha->siteKey() !== null) {
            if ($captcha->scriptUrl() !== null) {
                $lines[] = '  <script src="'.e($captcha->scriptUrl()).'" async defer></script>';
            }

            $lines[] = '  <div class="g-recaptcha h-captcha" data-sitekey="'.e($captcha->siteKey()).'"></div>';
        }

        if ($ajax) {
            // Inline status target — the script writes success/error here
            // instead of navigating to the F-2.2.11-005 redirect URLs.
            $lines[] = '  <div class="emp-message" role="status" aria-live="polite" hidden></div>';
        }

        $lines[] = '  <button type="submit">'.e(__('lists.embed.submit_label')).'</button>';
        $lines[] = '</form>';

        if ($ajax) {
            $lines[] = $this->ajaxScript();
        }

        return implode("\n", $lines);
    }

    /** Iframe embed variant pointing at the §1.5 public subscribe page (WP-09). */
    public function iframe(EmailList $list): string
    {
        $src = url('/lists/'.$list->uid.'/subscribe');

        return '<iframe src="'.e($src).'" width="100%" height="560" frameborder="0" style="border:0"></iframe>';
    }

    /** JS embed variant: a script tag that injects the iframe where it sits. */
    public function javascript(EmailList $list): string
    {
        $src = url('/lists/'.$list->uid.'/subscribe');

        return '<script>(function(){var f=document.createElement("iframe");'
            .'f.src='.json_encode($src, JSON_UNESCAPED_SLASHES).';f.width="100%";f.height="560";f.style.border="0";'
            .'var s=document.currentScript;s.parentNode.insertBefore(f,s);})();</script>';
    }

    /** File-upload fields (F-2.2.9-001) force a multipart form. */
    private function needsMultipart(ListForm $form): bool
    {
        foreach ($form->orderedFields() as $field) {
            if ($field->type === ListFieldType::File) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dependency-free AJAX submitter (F-2.2.11-006): posts the form with
     * fetch(), asks the endpoint for JSON and renders the reply inline. On
     * success it still honors a configured success URL — the endpoint hands
     * it back as `redirect` — so F-2.2.11-005 keeps working in AJAX mode.
     */
    private function ajaxScript(): string
    {
        $failed = json_encode(__('lists.embed.ajax_failed'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<JS
<script>
(function () {
  var forms = document.querySelectorAll('form.emp-subscribe-form[data-emp-ajax="1"]');
  Array.prototype.forEach.call(forms, function (form) {
    if (form.getAttribute('data-emp-bound')) { return; }
    form.setAttribute('data-emp-bound', '1');
    var box = form.querySelector('.emp-message');
    var show = function (ok, text) {
      if (!box) { return; }
      box.hidden = false;
      box.className = 'emp-message emp-message--' + (ok ? 'success' : 'error');
      box.textContent = text || '';
    };
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (box) { box.hidden = true; box.textContent = ''; }
      var button = form.querySelector('button[type="submit"]');
      if (button) { button.disabled = true; }
      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (response) {
        return response.json().then(function (payload) {
          return { ok: response.ok, payload: payload || {} };
        });
      }).then(function (result) {
        if (button) { button.disabled = false; }
        show(result.ok, result.payload.message);
        if (result.ok) {
          form.reset();
          if (result.payload.redirect) { window.location.href = result.payload.redirect; }
        }
      }).catch(function () {
        if (button) { button.disabled = false; }
        show(false, {$failed});
      });
    });
  });
})();
</script>
JS;
    }

    private function renderField(ListField $field): string
    {
        $tag = e($field->tag);
        $id = 'emp-'.strtolower($field->tag);
        $required = $field->required ? ' required' : '';
        $default = e((string) ($field->default_value ?? ''));
        $label = '<label for="'.$id.'">'.e($field->label).'</label>';
        $help = $field->help_text ? '<small>'.e($field->help_text).'</small>' : null;

        $input = match ($field->type) {
            ListFieldType::Textarea => '<textarea id="'.$id.'" name="'.$tag.'"'.$required.'>'.$default.'</textarea>',
            ListFieldType::Dropdown => $this->select($id, $tag, $field, false, $required),
            ListFieldType::Multiselect => $this->select($id, $tag, $field, true, $required),
            ListFieldType::Radio => $this->choices($field, 'radio', $tag),
            ListFieldType::CheckboxList => $this->choices($field, 'checkbox', $tag.'[]'),
            ListFieldType::Checkbox, ListFieldType::Consent => '<input type="checkbox" id="'.$id.'" name="'.$tag.'" value="1"'.$required.'>',
            ListFieldType::Country => $this->countrySelect($id, $tag, $required),
            ListFieldType::Date => '<input type="date" id="'.$id.'" name="'.$tag.'" value="'.$default.'"'.$required.'>',
            ListFieldType::Datetime => '<input type="datetime-local" id="'.$id.'" name="'.$tag.'"'.$required.'>',
            ListFieldType::Number, ListFieldType::Rating => '<input type="number" id="'.$id.'" name="'.$tag.'" value="'.$default.'"'.$required.'>',
            ListFieldType::Email => '<input type="email" id="'.$id.'" name="'.$tag.'" value="'.$default.'"'.$required.'>',
            ListFieldType::Url => '<input type="url" id="'.$id.'" name="'.$tag.'" value="'.$default.'"'.$required.'>',
            ListFieldType::Phone => '<input type="tel" id="'.$id.'" name="'.$tag.'" value="'.$default.'"'.$required.'>',
            ListFieldType::Hidden => '<input type="hidden" name="'.$tag.'" value="'.$default.'">',
            // F-2.2.9-001 P2 types.
            ListFieldType::File => '<input type="file" id="'.$id.'" name="'.$tag.'"'.$required.'>',
            ListFieldType::City => '<input type="text" id="'.$id.'" name="'.$tag.'" value="'.$default.'" autocomplete="address-level2"'.$required.'>',
            default => '<input type="text" id="'.$id.'" name="'.$tag.'" value="'.$default.'"'.$required.'>',
        };

        if ($field->type === ListFieldType::Hidden) {
            return '  '.$input;
        }

        // Consent checkboxes put the label after the box.
        if ($field->type->isBoolean()) {
            return $this->fieldGroup($input.' '.$label, $help);
        }

        return $this->fieldGroup($label, $input, $help);
    }

    private function select(string $id, string $tag, ListField $field, bool $multiple, string $required): string
    {
        $name = $multiple ? $tag.'[]' : $tag;
        $lines = ['<select id="'.$id.'" name="'.$name.'"'.($multiple ? ' multiple' : '').$required.'>'];

        if (! $multiple) {
            $lines[] = '  <option value="">'.e(__('lists.embed.select_placeholder')).'</option>';
        }

        foreach ($field->options as $option) {
            $selected = ! $multiple && (string) $field->default_value === (string) $option->value ? ' selected' : '';
            $lines[] = '  <option value="'.e((string) $option->value).'"'.$selected.'>'.e((string) $option->label).'</option>';
        }

        $lines[] = '</select>';

        return implode("\n    ", $lines);
    }

    private function choices(ListField $field, string $type, string $name): string
    {
        $lines = [];

        foreach ($field->options as $option) {
            $lines[] = '<label><input type="'.$type.'" name="'.$name.'" value="'.e((string) $option->value).'"> '.e((string) $option->label).'</label>';
        }

        return implode("\n    ", $lines);
    }

    private function countrySelect(string $id, string $tag, string $required): string
    {
        $lines = ['<select id="'.$id.'" name="'.$tag.'"'.$required.'>'];
        $lines[] = '  <option value="">'.e(__('lists.embed.select_placeholder')).'</option>';

        foreach (Countries::all() as $code => $name) {
            $lines[] = '  <option value="'.e($code).'">'.e($name).'</option>';
        }

        $lines[] = '</select>';

        return implode("\n    ", $lines);
    }

    private function fieldGroup(?string ...$parts): string
    {
        $inner = implode("\n    ", array_filter($parts, fn (?string $part): bool => $part !== null && $part !== ''));

        return "  <div class=\"emp-field\">\n    ".$inner."\n  </div>";
    }

    /** Minimal, dependency-free styling for the styled variant (F-2.2.11-004). */
    private function styles(): string
    {
        return <<<'CSS'
<style>
.emp-subscribe-form{font-family:system-ui,-apple-system,sans-serif;max-width:420px;display:block}
.emp-subscribe-form .emp-field{margin-bottom:14px}
.emp-subscribe-form label{display:block;font-size:14px;font-weight:600;margin-bottom:4px}
.emp-subscribe-form input[type=text],.emp-subscribe-form input[type=email],.emp-subscribe-form input[type=url],
.emp-subscribe-form input[type=tel],.emp-subscribe-form input[type=number],.emp-subscribe-form input[type=date],
.emp-subscribe-form input[type=datetime-local],.emp-subscribe-form select,.emp-subscribe-form textarea{
width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;box-sizing:border-box}
.emp-subscribe-form small{color:#6b7280;font-size:12px}
.emp-subscribe-form button{background:#4f46e5;color:#fff;border:0;border-radius:6px;padding:10px 18px;font-size:14px;cursor:pointer}
.emp-subscribe-form button:hover{background:#4338ca}
.emp-subscribe-form .emp-message{margin-bottom:14px;padding:10px 12px;border-radius:6px;font-size:14px}
.emp-subscribe-form .emp-message--success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.emp-subscribe-form .emp-message--error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
</style>
CSS;
    }
}
