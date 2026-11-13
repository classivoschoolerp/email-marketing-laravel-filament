<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Campaign;
use DOMDocument;
use DOMElement;

/**
 * Click map (spec §2.3.8 F-2.3.8-005 "…per-URL click counts, unique clickers,
 * CLICK MAP; drill into who clicked what").
 *
 * The per-URL table answers "which link won"; the click map answers "WHERE in
 * the email the clicks happened". It re-renders the campaign's own HTML and
 * badges every anchor in place with its click count, its rank and its share
 * of all clicks, shading the anchor by that share — so a link that is
 * technically the same URL but sits in the footer is visibly distinguishable
 * from the hero button.
 *
 * Deliberate properties:
 *   • the map is built from the SAVED campaign HTML, never from a live fetch;
 *   • every anchor is neutralised (href="#") — the map is a report, not a
 *     working email, and the view renders it inside a sandboxed iframe;
 *   • counts come from CampaignReportService::topLinks(), the same single
 *     source the Clicks tab and the CSV export use, so the badge and the
 *     table can never disagree;
 *   • an anchor whose URL was never tracked still gets a badge reading 0 —
 *     "no clicks" is information, and silently omitting it would read as a
 *     rendering bug.
 */
class ClickMapRenderer
{
    /** Shading steps, lightest → strongest (share of all clicks). */
    private const HEAT = [
        [0.0, '#f3f4f6', '#9ca3af'],
        [0.05, '#fee2e2', '#ef4444'],
        [0.15, '#fecaca', '#dc2626'],
        [0.30, '#fca5a5', '#b91c1c'],
        [0.50, '#f87171', '#991b1b'],
    ];

    public function __construct(private readonly CampaignReportService $reports) {}

    /**
     * @return array{
     *     html: string,
     *     links: list<array{url: string, clicks: int, unique_clickers: int, percent: float, rank: int}>,
     *     total_clicks: int,
     *     anchors: int
     * }
     */
    public function render(Campaign $campaign): array
    {
        $links = $this->rankedLinks($campaign);
        $byUrl = [];

        foreach ($links as $link) {
            $byUrl[$this->key($link['url'])] = $link;
        }

        $html = (string) $campaign->content_html;
        $totalClicks = array_sum(array_column($links, 'clicks'));

        [$annotated, $anchors] = $this->annotate($html, $byUrl, $totalClicks);

        return [
            'html' => $annotated,
            'links' => $links,
            'total_clicks' => $totalClicks,
            'anchors' => $anchors,
        ];
    }

    /**
     * Tracked URLs with a 1-based rank; ties keep the topLinks() ordering.
     *
     * @return list<array{url: string, clicks: int, unique_clickers: int, percent: float, rank: int}>
     */
    public function rankedLinks(Campaign $campaign): array
    {
        $links = [];
        $rank = 0;

        foreach ($this->reports->topLinks($campaign) as $link) {
            $links[] = $link + ['rank' => $link['clicks'] > 0 ? ++$rank : 0];
        }

        return $links;
    }

    /**
     * @param  array<string, array{url: string, clicks: int, unique_clickers: int, percent: float, rank: int}>  $byUrl
     * @return array{0: string, 1: int}
     */
    private function annotate(string $html, array $byUrl, int $totalClicks): array
    {
        if (trim($html) === '') {
            return ['', 0];
        }

        $doc = new DOMDocument;

        if (! @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html)) {
            return ['', 0];
        }

        $anchors = 0;

        /** @var list<DOMElement> $elements */
        $elements = iterator_to_array($doc->getElementsByTagName('a'));

        foreach ($elements as $anchor) {
            $href = trim($anchor->getAttribute('href'));

            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }

            $anchors++;

            $link = $byUrl[$this->key($href)] ?? null;
            $clicks = (int) ($link['clicks'] ?? 0);
            $share = $totalClicks > 0 ? $clicks / $totalClicks : 0.0;

            [$fill, $border] = $this->heat($share);

            // Neutralise navigation: the map is a report surface.
            $anchor->setAttribute('href', '#');
            $anchor->setAttribute('data-emp-click-map', '1');
            $anchor->setAttribute('data-emp-clicks', (string) $clicks);
            $anchor->setAttribute('data-emp-url', $href);
            $anchor->setAttribute('title', $href);
            $anchor->setAttribute(
                'style',
                trim($anchor->getAttribute('style'), '; ').
                ';background-color:'.$fill.';outline:2px solid '.$border.';outline-offset:1px;',
            );

            $badge = $doc->createElement('span');
            $badge->setAttribute('data-emp-click-badge', (string) $clicks);
            $badge->setAttribute(
                'style',
                'display:inline-block;margin-left:6px;padding:1px 6px;border-radius:9999px;'
                .'background-color:'.$border.';color:#ffffff;'
                .'font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:bold;'
                .'line-height:16px;vertical-align:middle;'
            );
            $badge->appendChild($doc->createTextNode($this->badgeLabel($link, $clicks)));

            $anchor->appendChild($badge);
        }

        return [$this->serialize($doc), $anchors];
    }

    /**
     * "#1: 12 (48%)" for a clicked link, plain "0" for an unclicked one.
     *
     * ASCII only, deliberately: the badge is injected into a DOMDocument and
     * comes back out of saveHTML() with any non-ASCII punctuation turned into
     * an entity, which then has to survive a second escaping round inside the
     * iframe's srcdoc attribute. Plain characters keep the label readable at
     * every hop.
     *
     * @param  array{clicks: int, percent: float, rank: int}|null  $link
     */
    private function badgeLabel(?array $link, int $clicks): string
    {
        if ($link === null || $clicks === 0) {
            return '0';
        }

        return '#'.$link['rank'].': '.$clicks.' ('.$link['percent'].'%)';
    }

    /** @return array{0: string, 1: string} fill, border */
    private function heat(float $share): array
    {
        $fill = self::HEAT[0][1];
        $border = self::HEAT[0][2];

        foreach (self::HEAT as [$threshold, $stepFill, $stepBorder]) {
            if ($share >= $threshold) {
                $fill = $stepFill;
                $border = $stepBorder;
            }
        }

        return [$fill, $border];
    }

    /**
     * Comparison key for an href. Exact string match first (the WP-13 content
     * rewriter stores the URL verbatim), with trailing-slash and case
     * insensitivity on the scheme/host only — never on the path, because
     * /Sale and /sale are genuinely different links.
     */
    private function key(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // NB: "~" delimiter — "#" would be closed by the "#" inside the class.
        if (preg_match('~^([a-z][a-z0-9+.-]*://[^/?#]+)(.*)$~i', $url, $match) === 1) {
            $url = mb_strtolower($match[1]).$match[2];
        }

        return rtrim($url, '/');
    }

    /** Serialise back without the XML declaration DOMDocument prepends. */
    private function serialize(DOMDocument $doc): string
    {
        $html = (string) $doc->saveHTML();

        return trim((string) preg_replace('/<\?xml[^>]*\?>/', '', $html));
    }
}
