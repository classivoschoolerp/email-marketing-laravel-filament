<?php

declare(strict_types=1);

namespace App\Services\Templates;

use App\Models\EmailTemplate;
use App\Support\Templates\DarkModeStyles;
use App\Support\Templates\MobileVisibility;
use Illuminate\Validation\ValidationException;

/**
 * Single write path for template content (builder autosave, HTML/rich-text
 * edit form, revision restore callers). Applies the unsubscribe-tag check
 * (F-2.5.5-006), maintains the missing_unsubscribe_tag flag, guarantees the
 * hide-on-mobile media query (F-2.5.4-006) and the dark-mode rules
 * (F-2.5.4-013), records a revision (F-2.5.4-008) and refreshes the derived
 * thumbnail (F-2.5.5-003).
 */
class TemplateContentSaver
{
    public function __construct(
        private readonly UnsubscribeTagChecker $unsubscribeCheck,
        private readonly TemplateRevisions $revisions,
        private readonly TemplateThumbnails $thumbnails,
    ) {}

    /**
     * @param  array{content_html?: string|null, content_json?: string|null, google_fonts?: list<string>|null}  $content
     * @return array{saved: bool, unsubscribe_ok: bool}
     *
     * @throws ValidationException when the unsubscribe check is in block mode and fails
     */
    public function save(EmailTemplate $template, array $content): array
    {
        $html = array_key_exists('content_html', $content) ? $content['content_html'] : $template->content_html;

        // F-2.5.4-006: a hide-on-mobile block is only hidden if the media
        // query travels with it — and a media query can never be inlined.
        $html = MobileVisibility::apply($html);

        // F-2.5.4-013: identical reasoning for the per-block dark overrides —
        // `prefers-color-scheme` is a media query, so the rules have to be
        // emitted into the document that is actually SENT.
        $html = DarkModeStyles::apply($html);

        if ($this->unsubscribeCheck->blocks($html)) {
            throw ValidationException::withMessages([
                'content_html' => __('templates.errors.unsubscribe_required'),
            ]);
        }

        $template->forceFill([
            'content_html' => $html,
            'content_json' => array_key_exists('content_json', $content) ? $content['content_json'] : $template->content_json,
            'google_fonts' => array_key_exists('google_fonts', $content) ? $content['google_fonts'] : $template->google_fonts,
        ]);

        $template->missing_unsubscribe_tag = ! $this->unsubscribeCheck->passes($html);
        $template->save();

        $this->revisions->record($template);
        $this->thumbnails->refreshFromContent($template);

        return [
            'saved' => true,
            'unsubscribe_ok' => ! $template->missing_unsubscribe_tag,
        ];
    }
}
