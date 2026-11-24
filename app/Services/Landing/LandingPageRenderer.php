<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Models\Form;
use App\Models\LandingPage;
use App\Models\LandingPageVariant;
use App\Services\Forms\FormRenderer;
use App\Support\Templates\MobileVisibility;

/**
 * Builds the public landing document (spec §1.8 F-1.8-001/004/005).
 *
 * A landing page is served as a STANDALONE document, not inside the app's
 * public layout: it can answer on the customer's own domain, where the
 * platform chrome, the app's Vite bundle and the app's branding would all be
 * wrong. Everything the page needs — the base stylesheet, the customer's CSS
 * and JS, the tracking tags, the form — is assembled here.
 *
 * The embedded form is NOT re-implemented: the markup comes from the §2.7
 * {@see FormRenderer}, so a field added to the form appears on the landing
 * page with no further work and the two can never drift. Only the <form>
 * element is ours, because it must POST to the LANDING endpoint — that is
 * what turns a submission into an attributed conversion.
 */
class LandingPageRenderer
{
    public function __construct(
        private readonly FormRenderer $forms,
        private readonly LandingCustomCode $code,
        private readonly LandingPageUrl $urls,
    ) {}

    /**
     * Everything the public view needs, already made safe.
     *
     * @param  array<string, list<string>|string>  $errors
     * @return array<string, mixed>
     */
    public function document(
        LandingPage $page,
        ?LandingPageVariant $variant,
        ?Form $form,
        array $errors = [],
    ): array {
        $content = $this->content($page, $variant);
        $body = $this->embedForm($content, $page, $form, $errors);

        return [
            'lang' => str_replace('_', '-', app()->getLocale()),
            'title' => $page->displayTitle(),
            'description' => $this->trimmed($page->seo_description, 500),
            'robots' => $this->robots($page),
            'canonical' => $this->urls->canonical($page),
            'ogImage' => $this->trimmed($page->og_image_url, 2048),
            'favicon' => $this->trimmed($page->favicon_url, 2048),
            'baseCss' => LandingStyles::css(),
            'customCss' => $this->code->css($page),
            'customJs' => $this->code->js($page),
            'headScripts' => $this->code->headScripts($page),
            'bodyScripts' => $this->code->bodyScripts($page),
            'countdownScript' => $this->countdownScript($body),
            'body' => $body,
        ];
    }

    /** The document the visitor is served: the arm's, else the page's. */
    public function content(LandingPage $page, ?LandingPageVariant $variant): string
    {
        $html = $variant?->effectiveHtml($page) ?? $page->content_html;

        // Keep the hide-on-mobile media query with the markup that uses it,
        // exactly as the §2.5.4 builder does for email (F-2.6-013).
        return (string) MobileVisibility::apply((string) $html);
    }

    /**
     * Swap the builder's form placeholder for the real §2.7 markup.
     *
     * When a form is assigned but the document has no placeholder — an older
     * page, or a design the customer stripped down — the form is APPENDED
     * rather than dropped: a landing page whose whole purpose is capture must
     * never render without its form because of a missing marker.
     *
     * @param  array<string, list<string>|string>  $errors
     */
    public function embedForm(string $content, LandingPage $page, ?Form $form, array $errors = []): string
    {
        if ($form === null) {
            return $content;
        }

        $markup = $this->formMarkup($page, $form, $errors);

        $pattern = '~<div\b[^>]*\b'.preg_quote(LandingBlocks::FORM_PLACEHOLDER_ATTRIBUTE, '~')
            .'\s*=\s*"[^"]*"[^>]*>\s*</div>~i';

        if (preg_match($pattern, $content) === 1) {
            return (string) preg_replace($pattern, $this->quoteReplacement($markup), $content, 1);
        }

        return $content.$markup;
    }

    /** The <form> wrapper plus the §2.7 body, posting to the landing route. */
    public function formMarkup(LandingPage $page, Form $form, array $errors = []): string
    {
        $out = '<div id="lp-form" class="lp-section"><div class="lp-container">'
            .'<form class="lp-form emp-form" method="post" accept-charset="UTF-8" action="'
            .e($this->urls->submitUrl($page)).'">'
            .csrf_field();

        if ($errors !== []) {
            $out .= '<div class="lp-form__errors" role="alert">';

            foreach ($errors as $messages) {
                $out .= '<div>'.e(is_array($messages) ? implode(' ', $messages) : (string) $messages).'</div>';
            }

            $out .= '</div>';
        }

        return $out.$this->forms->body($form).'</form></div></div>';
    }

    /**
     * The countdown ticker (F-2.6-002). A literal script — the only thing it
     * reads is the `data-deadline` attribute the builder wrote, and it is
     * emitted only when the document actually has a countdown block, so a
     * page without one ships no extra JavaScript.
     */
    public function countdownScript(string $body): string
    {
        if (! str_contains($body, LandingBlocks::COUNTDOWN_ATTRIBUTE)) {
            return '';
        }

        return <<<'JS'
(function(){
  var nodes=document.querySelectorAll('['+"data-landing-countdown"+']');
  if(!nodes.length)return;
  function pad(n){return (n<10?'0':'')+n;}
  function tick(){
    nodes.forEach(function(el){
      var out=el.querySelector('.lp-countdown__value');
      if(!out)return;
      var end=Date.parse(el.getAttribute('data-deadline')||'');
      if(isNaN(end)){out.textContent='--:--:--';return;}
      var left=Math.max(0,Math.floor((end-Date.now())/1000));
      var d=Math.floor(left/86400),h=Math.floor(left%86400/3600),m=Math.floor(left%3600/60),s=left%60;
      out.textContent=(d>0?d+'d ':'')+pad(h)+':'+pad(m)+':'+pad(s);
    });
  }
  tick();window.setInterval(tick,1000);
})();
JS;
    }

    /** Robots directive, validated against the offered set (F-2.6-007). */
    public function robots(LandingPage $page): string
    {
        $value = mb_strtolower(trim((string) $page->robots));

        return in_array($value, LandingPage::ROBOTS, true) ? $value : 'index,follow';
    }

    private function trimmed(mixed $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    /** `$` in replacement strings is a back-reference to preg_replace. */
    private function quoteReplacement(string $value): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $value);
    }
}
