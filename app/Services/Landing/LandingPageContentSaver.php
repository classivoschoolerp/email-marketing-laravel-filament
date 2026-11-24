<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Models\LandingPage;
use App\Models\LandingPageVariant;
use App\Services\Templates\TemplateContentSaver;
use App\Support\Templates\MobileVisibility;

/**
 * The single write path for landing-page content — builder autosave, gallery
 * apply, revision restore callers (spec §2.6 F-2.6-002/005/013).
 *
 * Mirrors {@see TemplateContentSaver} minus the two
 * checks that are about EMAIL and would be wrong here: there is no
 * unsubscribe-tag requirement on a landing page (it is not a message, and
 * CAN-SPAM has nothing to say about it) and no email thumbnail. What does
 * carry over is the hide-on-mobile guarantee — the media query has to travel
 * with the markup that uses it (F-2.6-013) — and recording a revision on
 * every save.
 */
class LandingPageContentSaver
{
    public function __construct(private readonly LandingPageRevisions $revisions) {}

    /**
     * @param  array{content_html?: string|null, content_json?: string|null}  $content
     * @return array{saved: bool}
     */
    public function save(LandingPage $page, array $content, ?LandingPageVariant $variant = null): array
    {
        abort_unless(
            $variant === null || (int) $variant->landing_page_id === (int) $page->id,
            404,
        );

        $target = $variant ?? $page;

        $html = array_key_exists('content_html', $content)
            ? MobileVisibility::apply($content['content_html'])
            : $target->content_html;

        $target->forceFill([
            'content_html' => $html,
            'content_json' => array_key_exists('content_json', $content)
                ? $content['content_json']
                : $target->content_json,
        ])->save();

        $this->revisions->record($page, $variant);

        return ['saved' => true];
    }

    /** Apply a gallery starter design to a page (F-2.6-003). */
    public function applyTemplate(LandingPage $page, string $key): void
    {
        $page->forceFill(['template_key' => LandingTemplates::exists($key) ? $key : LandingTemplates::BLANK])->save();

        $this->save($page, [
            'content_html' => LandingTemplates::html((string) $page->template_key),
            // The GrapesJS project state belongs to the previous document;
            // keeping it would silently restore the old page on next open.
            'content_json' => null,
        ]);
    }
}
