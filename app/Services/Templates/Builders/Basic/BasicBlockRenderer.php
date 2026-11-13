<?php

declare(strict_types=1);

namespace App\Services\Templates\Builders\Basic;

/**
 * Renders a basic-builder block list to email-safe HTML (spec §2.5.3
 * F-2.5.3-001).
 *
 * "Email-safe" here is a hard contract, asserted by tests:
 *   • layout is TABLES only — no <div>, no flexbox, no grid, nothing the
 *     Word rendering engine in Outlook for Windows cannot lay out;
 *   • every table carries role="presentation" cellpadding=0 cellspacing=0
 *     border=0 so screen readers skip the layout scaffolding;
 *   • all styling is INLINE (a <style> block is only emitted for the
 *     F-2.5.4-006 hide-on-mobile media query, which cannot be inlined);
 *   • the body is a 600px centred column, the standard email width;
 *   • every user string is escaped; hrefs/srcs were already fenced to
 *     http(s)/mailto/tel/merge-tag by BasicBlocks::normalize().
 *
 * Merge tags pass through untouched — they are plain text and are resolved
 * later by the WP-13 engine (D-62 merge-before-spintax).
 */
class BasicBlockRenderer
{
    /** Standard email column width. */
    public const WIDTH = 600;

    /** @param list<array<string, mixed>> $blocks */
    public function render(array $blocks, string $title = ''): string
    {
        $rows = '';

        foreach (BasicBlocks::normalize($blocks) as $block) {
            $rows .= $this->row($block);
        }

        return '<html><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .($title !== '' ? '<title>'.$this->e($title).'</title>' : '')
            .'</head>'
            .'<body style="margin:0;padding:0;background-color:#f3f4f6;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
            .' style="width:100%;border-collapse:collapse;background-color:#f3f4f6;">'
            .'<tr><td align="center" style="padding:24px 12px;">'
            .'<table role="presentation" width="'.self::WIDTH.'" cellpadding="0" cellspacing="0" border="0"'
            .' style="width:'.self::WIDTH.'px;max-width:100%;border-collapse:collapse;background-color:#ffffff;'
            .'font-family:Arial,Helvetica,sans-serif;">'
            .$rows
            .'</table>'
            .'</td></tr></table>'
            .'</body></html>';
    }

    /** @param array<string, mixed> $block */
    private function row(array $block): string
    {
        return match ($block['type']) {
            BasicBlocks::TYPE_IMAGE => $this->image($block),
            BasicBlocks::TYPE_BUTTON => $this->button($block),
            BasicBlocks::TYPE_DIVIDER => $this->divider($block),
            BasicBlocks::TYPE_RSS => $this->rss($block),
            BasicBlocks::TYPE_COUNTDOWN => $this->countdown($block),
            default => $this->text($block),
        };
    }

    /**
     * RSS-to-email block (§2.3.3 F-2.3.3-011 / F-2.3.3-024).
     *
     * The feed is resolved at BUILD time and baked into the HTML: email
     * clients run no JavaScript and cannot fetch a feed, so "latest N items"
     * can only ever mean "latest N items when this email was built". When the
     * feed is unreachable the block says so instead of rendering an empty
     * column that looks like a design mistake.
     *
     * @param  array<string, mixed>  $block
     */
    private function rss(array $block): string
    {
        $url = (string) $block['url'];

        if ($url === '') {
            return $this->notice(__('templates.basic_builder.empty_rss'));
        }

        $feed = app(\App\Services\Content\RssFeedReader::class)->read($url, (int) $block['items']);

        if ($feed['items'] === []) {
            return $this->notice(__('campaigns.blocks.'.($feed['error'] === 'no_items' ? 'rss_empty' : 'rss_unavailable')));
        }

        $items = '';

        foreach ($feed['items'] as $item) {
            $title = $this->e($item['title'] !== '' ? $item['title'] : $item['url']);

            $items .= '<tr><td style="padding:0 0 16px;font-family:Arial,Helvetica,sans-serif;'
                .'font-size:15px;line-height:1.5;color:'.$this->e((string) $block['color']).';">'
                .($item['url'] !== ''
                    ? '<a href="'.$this->e($item['url']).'" target="_blank" style="color:inherit;font-weight:bold;text-decoration:underline;">'.$title.'</a>'
                    : '<strong>'.$title.'</strong>');

            if ($block['show_date'] && $item['date'] !== null) {
                $items .= '<div style="font-size:12px;color:#6b7280;">'.$this->e($item['date']).'</div>';
            }

            if ($block['show_excerpt'] && $item['excerpt'] !== '') {
                $items .= '<div style="font-size:14px;color:#374151;">'.$this->e($item['excerpt']).'</div>';
            }

            $items .= '</td></tr>';
        }

        return '<tr><td style="padding:'.(int) $block['padding'].'px 24px;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
            .' style="width:100%;border-collapse:collapse;">'.$items.'</table>'
            .'</td></tr>';
    }

    /**
     * Countdown timer block (§2.3.3 F-2.3.3-012).
     *
     * HONEST LIMIT, stated rather than hidden: email clients run no
     * JavaScript, so the figures are the remaining time AT BUILD TIME, not a
     * ticking clock. A live ticking countdown needs an image-generation
     * endpoint that renders a GIF per request — that is a separate feature
     * with its own hosting cost and is not claimed here.
     *
     * @param  array<string, mixed>  $block
     */
    private function countdown(array $block): string
    {
        $until = (string) $block['until'];
        $label = (string) $block['label'];

        if ($until === '') {
            return $this->notice(__('templates.basic_builder.empty_countdown'));
        }

        $target = \Illuminate\Support\Carbon::parse($until, 'UTC');
        $now = \Illuminate\Support\Carbon::now('UTC');

        if ($target->lessThanOrEqualTo($now)) {
            $expired = (string) $block['expired_label'];

            return $this->notice($expired !== '' ? $expired : __('templates.basic_builder.countdown_expired'));
        }

        $seconds = $target->getTimestamp() - $now->getTimestamp();
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $cell = fn (int $value, string $unit): string => '<td align="center" style="padding:0 10px;'
            .'font-family:Arial,Helvetica,sans-serif;color:'.$this->e((string) $block['color']).';">'
            .'<div style="font-size:28px;font-weight:bold;line-height:1;">'.$value.'</div>'
            .'<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#6b7280;">'
            .$this->e(__('campaigns.blocks.countdown_'.$unit)).'</div></td>';

        return '<tr><td align="'.$this->e((string) $block['align']).'"'
            .' style="padding:'.(int) $block['padding'].'px 24px;">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
            .' style="border-collapse:collapse;background-color:'.$this->e((string) $block['background']).';border-radius:6px;">'
            .($label !== ''
                ? '<tr><td colspan="3" align="center" style="padding:12px 12px 0;font-family:Arial,Helvetica,sans-serif;'
                    .'font-size:14px;color:'.$this->e((string) $block['color']).';">'.$this->e($label).'</td></tr>'
                : '')
            .'<tr>'.$cell($days, 'days').$cell($hours, 'hours').$cell($minutes, 'minutes').'</tr>'
            .'<tr><td colspan="3" style="height:12px;line-height:12px;font-size:0;">&nbsp;</td></tr>'
            .'</table></td></tr>';
    }

    /** A neutral, honest one-line notice row. */
    private function notice(string $message): string
    {
        return '<tr><td align="center" style="padding:8px 24px;'
            .'font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#9ca3af;">'
            .$this->e($message).'</td></tr>';
    }

    /** @param array<string, mixed> $block */
    private function text(array $block): string
    {
        $body = $this->inlineText((string) $block['content']);

        if ($body === '') {
            $body = '&nbsp;';
        }

        return '<tr><td align="'.$this->e((string) $block['align']).'"'
            .' style="padding:'.(int) $block['padding'].'px 24px;'
            .'font-family:Arial,Helvetica,sans-serif;'
            .'font-size:'.(int) $block['font_size'].'px;line-height:1.5;'
            .'color:'.$this->e((string) $block['color']).';'
            .'text-align:'.$this->e((string) $block['align']).';">'
            .$body
            .'</td></tr>';
    }

    /** @param array<string, mixed> $block */
    private function image(array $block): string
    {
        $src = (string) $block['src'];

        if ($src === '') {
            // No source yet: render an honest empty placeholder cell rather
            // than a broken <img>.
            return '<tr><td align="'.$this->e((string) $block['align']).'"'
                .' style="padding:8px 24px;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#9ca3af;">'
                .$this->e(__('templates.basic_builder.empty_image'))
                .'</td></tr>';
        }

        $img = '<img src="'.$this->e($src).'" alt="'.$this->e((string) $block['alt']).'"'
            .' width="'.(int) $block['width'].'"'
            .' style="display:block;border:0;outline:none;text-decoration:none;'
            .'width:'.(int) $block['width'].'px;max-width:100%;height:auto;">';

        $href = (string) $block['href'];

        if ($href !== '') {
            $img = '<a href="'.$this->e($href).'" target="_blank" style="text-decoration:none;">'.$img.'</a>';
        }

        return '<tr><td align="'.$this->e((string) $block['align']).'" style="padding:8px 24px;">'
            .$img.'</td></tr>';
    }

    /**
     * Bulletproof-ish button: a table-wrapped anchor with padding on the
     * anchor itself, which is what survives Outlook and Gmail alike.
     *
     * @param  array<string, mixed>  $block
     */
    private function button(array $block): string
    {
        $href = (string) $block['href'];

        return '<tr><td align="'.$this->e((string) $block['align']).'" style="padding:16px 24px;">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;">'
            .'<tr><td align="center" bgcolor="'.$this->e((string) $block['background']).'"'
            .' style="border-radius:6px;background-color:'.$this->e((string) $block['background']).';">'
            .'<a href="'.$this->e($href !== '' ? $href : '#').'" target="_blank"'
            .' style="display:inline-block;padding:12px 24px;'
            .'font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;line-height:1;'
            .'color:'.$this->e((string) $block['color']).';text-decoration:none;border-radius:6px;">'
            .$this->e((string) $block['label'])
            .'</a></td></tr></table>'
            .'</td></tr>';
    }

    /** @param array<string, mixed> $block */
    private function divider(array $block): string
    {
        return '<tr><td style="padding:'.(int) $block['padding'].'px 24px;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
            .' style="width:100%;border-collapse:collapse;">'
            .'<tr><td style="height:'.(int) $block['thickness'].'px;line-height:'.(int) $block['thickness'].'px;'
            .'font-size:0;background-color:'.$this->e((string) $block['color']).';">&nbsp;</td></tr>'
            .'</table></td></tr>';
    }

    /**
     * Escape a text block, then re-introduce exactly two safe constructs:
     *   • newlines become <br>
     *   • [label](url) becomes an anchor, with the url fenced the same way
     *     BasicBlocks fences every other href.
     * Nothing else in the user's text can ever become markup.
     */
    private function inlineText(string $text): string
    {
        $escaped = $this->e($text);

        // The target may itself contain one level of parentheses — real URLs
        // do (Wikipedia), and so does `javascript:alert(1)`, which must be
        // consumed WHOLE so no stray ")" is left behind when it is rejected.
        // NB: the repetition is unbounded on purpose — a bounded {1,2048} on
        // an alternation makes PCRE refuse to compile ("expression too
        // large"). The length cap is enforced in the callback instead.
        $escaped = (string) preg_replace_callback(
            '/\[([^\]\r\n]{1,120})\]\(((?:[^()\s]|\([^()\s]*\))+)\)/u',
            function (array $match): string {
                $label = $match[1];
                $href = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if (mb_strlen($href) > 2048
                    || (preg_match('/^\{[A-Z][A-Z0-9_]*\}$/u', $href) !== 1
                        && preg_match('#^(https?://|mailto:|tel:)#i', $href) !== 1)) {
                    return $label; // unsafe target → keep the words, drop the link
                }

                return '<a href="'.$this->e($href).'" target="_blank" style="color:inherit;">'.$label.'</a>';
            },
            $escaped,
        );

        return nl2br($escaped, false);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
