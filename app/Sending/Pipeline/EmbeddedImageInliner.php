<?php

declare(strict_types=1);

namespace App\Sending\Pipeline;

use App\Models\Campaign;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * embed_images option (spec §2.3.4 F-2.3.4-013, WP-13): downloads every
 * <img src="http(s)://…"> of the rendered content and attaches it as an
 * inline CID part, rewriting the src to cid:{id}. Fetched bytes are cached
 * per (campaign, url) for an hour so a 50k-recipient send fetches each asset
 * once, not 50k times. Unreachable images keep their remote src (graceful
 * degradation). The open-tracking pixel is never inlined.
 */
class EmbeddedImageInliner
{
    private const CACHE_TTL = 3600;

    /**
     * @return array{0: string, 1: list<array{cid: string, content: string, mime?: string}>}
     *                          rewritten html + inline parts for Message::$embedded
     */
    public function inline(Campaign $campaign, string $html): array
    {
        $embedded = [];
        $seen = [];

        $html = (string) preg_replace_callback(
            '/(<img\b[^>]*?\bsrc\s*=\s*)(["\'])(https?:\/\/[^"\']+)\2/iu',
            function (array $match) use ($campaign, &$embedded, &$seen): string {
                $url = $match[3];

                if (str_contains($url, '/track-opening/')) {
                    return $match[0]; // open pixel stays remote
                }

                if (! isset($seen[$url])) {
                    $image = $this->fetch($campaign, $url);

                    if ($image === null) {
                        $seen[$url] = null;
                    } else {
                        $cid = 'img-'.substr(sha1($url), 0, 16);
                        $seen[$url] = $cid;
                        $embedded[] = [
                            'cid' => $cid,
                            'content' => $image['content'],
                            'mime' => $image['mime'],
                        ];
                    }
                }

                if ($seen[$url] === null) {
                    return $match[0]; // fetch failed — keep the remote src
                }

                return $match[1].$match[2].'cid:'.$seen[$url].$match[2];
            },
            $html,
        );

        return [$html, $embedded];
    }

    /** @return array{content: string, mime: string}|null */
    private function fetch(Campaign $campaign, string $url): ?array
    {
        $key = 'campaign-embed:'.$campaign->uid.':'.sha1($url);

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return ['content' => base64_decode($cached['content']), 'mime' => $cached['mime']];
        }

        try {
            $response = Http::timeout(
                (int) config('emp-sending.merge_tags.fetch_timeout_seconds', 5)
            )->get($url);

            if (! $response->successful() || $response->body() === '') {
                return null;
            }

            $mime = explode(';', (string) $response->header('Content-Type'))[0] ?: 'application/octet-stream';
            $content = $response->body();

            Cache::put($key, ['content' => base64_encode($content), 'mime' => $mime], self::CACHE_TTL);

            return ['content' => $content, 'mime' => $mime];
        } catch (Throwable) {
            return null;
        }
    }
}
