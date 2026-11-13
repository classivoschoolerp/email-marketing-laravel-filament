<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads an RSS 2.0 / Atom feed for the §2.3.3 RSS content block
 * (F-2.3.3-011) and the RSS widget (F-2.3.3-024).
 *
 * Dependency-free on purpose (no simplepie/laminas-feed): the two formats
 * share enough structure that a guarded SimpleXML pass covers both, and
 * adding a package for it would need a composer install this environment does
 * not perform.
 *
 * Safety, because the URL is customer-supplied:
 *   - LIBXML_NONET | LIBXML_NOENT are NOT combined — entity substitution stays
 *     off and network entity loading is refused, which is the XXE fence;
 *   - only http/https URLs are fetched, with a short timeout;
 *   - every item field is returned as PLAIN TEXT (tags stripped), so nothing
 *     from a third-party feed can inject markup into an email;
 *   - the response is cached, so building a preview ten times does not hit
 *     the publisher ten times.
 */
class RssFeedReader
{
    public const MAX_ITEMS = 20;

    private const TIMEOUT_SECONDS = 8;

    /**
     * @return array{title: ?string, items: list<array{title: string, url: string, excerpt: string, date: ?string}>, error: ?string}
     */
    public function read(string $url, int $limit = 5): array
    {
        $url = trim($url);
        $limit = max(1, min(self::MAX_ITEMS, $limit));

        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return ['title' => null, 'items' => [], 'error' => 'invalid_url'];
        }

        $key = 'rss:'.hash('xxh128', $url).':'.$limit;

        try {
            /** @var array{title: ?string, items: list<array<string, mixed>>, error: ?string} $result */
            $result = Cache::remember($key, $this->ttl(), fn (): array => $this->fetch($url, $limit));

            return $result;
        } catch (Throwable) {
            return $this->fetch($url, $limit);
        }
    }

    /**
     * @return array{title: ?string, items: list<array{title: string, url: string, excerpt: string, date: ?string}>, error: ?string}
     */
    private function fetch(string $url, int $limit): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.8'])
                ->get($url);

            if (! $response->successful()) {
                return ['title' => null, 'items' => [], 'error' => 'http_'.$response->status()];
            }

            return $this->parse($response->body(), $limit);
        } catch (Throwable) {
            return ['title' => null, 'items' => [], 'error' => 'unreachable'];
        }
    }

    /**
     * @return array{title: ?string, items: list<array{title: string, url: string, excerpt: string, date: ?string}>, error: ?string}
     */
    public function parse(string $body, int $limit = 5): array
    {
        $body = trim($body);

        if ($body === '') {
            return ['title' => null, 'items' => [], 'error' => 'empty'];
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        } catch (Throwable) {
            $xml = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            return ['title' => null, 'items' => [], 'error' => 'unparseable'];
        }

        $items = [];
        $feedTitle = null;

        if (isset($xml->channel)) {
            // RSS 2.0
            $feedTitle = $this->text($xml->channel->title ?? null);

            foreach ($xml->channel->item ?? [] as $item) {
                $items[] = [
                    'title' => $this->text($item->title ?? null),
                    'url' => $this->url($this->text($item->link ?? null)),
                    'excerpt' => $this->excerpt($this->text($item->description ?? null)),
                    'date' => $this->date($this->text($item->pubDate ?? null)),
                ];

                if (count($items) >= $limit) {
                    break;
                }
            }
        } else {
            // Atom
            $feedTitle = $this->text($xml->title ?? null);

            foreach ($xml->entry ?? [] as $entry) {
                $href = '';

                foreach ($entry->link ?? [] as $link) {
                    $rel = (string) ($link['rel'] ?? 'alternate');

                    if ($rel === 'alternate' || $href === '') {
                        $href = (string) ($link['href'] ?? '');
                    }
                }

                $items[] = [
                    'title' => $this->text($entry->title ?? null),
                    'url' => $this->url($href),
                    'excerpt' => $this->excerpt($this->text($entry->summary ?? $entry->content ?? null)),
                    'date' => $this->date($this->text($entry->updated ?? $entry->published ?? null)),
                ];

                if (count($items) >= $limit) {
                    break;
                }
            }
        }

        return [
            'title' => $feedTitle === '' ? null : $feedTitle,
            'items' => $items,
            'error' => $items === [] ? 'no_items' : null,
        ];
    }

    private function ttl(): int
    {
        return max(60, (int) config('emp-campaigns.rss_cache_seconds', 900));
    }

    private function text(mixed $node): string
    {
        if ($node === null) {
            return '';
        }

        return trim((string) $node);
    }

    private function excerpt(string $html): string
    {
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_substr($text, 0, 300);
    }

    private function url(string $url): string
    {
        $url = trim($url);

        return preg_match('#^https?://#i', $url) === 1 ? mb_substr($url, 0, 2048) : '';
    }

    private function date(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
