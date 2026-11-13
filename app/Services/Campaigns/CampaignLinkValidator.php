<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Link validator (spec §2.3.5 F-2.3.5-005): every link in the campaign body
 * is requested and its status reported.
 *
 * Honest classification — the point of this check is trust, so it never
 * guesses:
 *   ok          2xx or 3xx (a redirect is a working link);
 *   broken      4xx or 5xx, with the status code shown;
 *   unreachable DNS/TLS/timeout — the link may be fine and the network not;
 *   skipped     merge-tag URLs ({UNSUBSCRIBE_URL}, …) and non-http schemes
 *               (mailto:, tel:, #anchor), which cannot be requested at all.
 *
 * HEAD first, falling back to GET, because a fair number of sites reject HEAD.
 * Duplicate URLs are checked once.
 */
class CampaignLinkValidator
{
    public const STATUS_OK = 'ok';

    public const STATUS_BROKEN = 'broken';

    public const STATUS_UNREACHABLE = 'unreachable';

    public const STATUS_SKIPPED = 'skipped';

    public const MAX_LINKS = 50;

    private const TIMEOUT_SECONDS = 8;

    /**
     * @return array{
     *     links: list<array{url: string, status: string, code: ?int, reason: ?string}>,
     *     ok: int, total: int
     * }
     */
    public function validate(Campaign $campaign): array
    {
        $urls = $this->extract((string) $campaign->content_html);
        $links = [];
        $ok = 0;

        foreach ($urls as $url) {
            $result = $this->checkOne($url);
            $links[] = $result;

            if ($result['status'] === self::STATUS_OK) {
                $ok++;
            }
        }

        return ['links' => $links, 'ok' => $ok, 'total' => count($links)];
    }

    /**
     * Distinct hrefs in document order, capped.
     *
     * @return list<string>
     */
    public function extract(string $html): array
    {
        if ($html === '') {
            return [];
        }

        preg_match_all('/<a\b[^>]*href\s*=\s*["\']([^"\']+)["\']/iu', $html, $matches);

        $urls = [];

        foreach ($matches[1] as $href) {
            $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($href === '') {
                continue;
            }

            $urls[$href] = $href;

            if (count($urls) >= self::MAX_LINKS) {
                break;
            }
        }

        return array_values($urls);
    }

    /**
     * @return array{url: string, status: string, code: ?int, reason: ?string}
     */
    public function checkOne(string $url): array
    {
        if (preg_match('/\{[A-Z][A-Z0-9_]*\}/u', $url) === 1
            || preg_match('#^https?://#i', $url) !== 1) {
            return [
                'url' => $url,
                'status' => self::STATUS_SKIPPED,
                'code' => null,
                'reason' => __('campaigns.link_validator.skipped_reason'),
            ];
        }

        foreach (['head', 'get'] as $method) {
            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->withoutVerifying()
                    ->withHeaders(['User-Agent' => 'EMP-LinkValidator/1.0'])
                    ->{$method}($url);
            } catch (Throwable $e) {
                if ($method === 'get') {
                    return [
                        'url' => $url,
                        'status' => self::STATUS_UNREACHABLE,
                        'code' => null,
                        'reason' => mb_substr($e->getMessage(), 0, 200),
                    ];
                }

                continue;
            }

            $code = $response->status();

            if ($code < 400) {
                return ['url' => $url, 'status' => self::STATUS_OK, 'code' => $code, 'reason' => null];
            }

            // A 405/403 on HEAD is common — retry the same URL with GET.
            if ($method === 'head' && in_array($code, [403, 405, 501], true)) {
                continue;
            }

            return ['url' => $url, 'status' => self::STATUS_BROKEN, 'code' => $code, 'reason' => null];
        }

        return [
            'url' => $url,
            'status' => self::STATUS_UNREACHABLE,
            'code' => null,
            'reason' => null,
        ];
    }
}
