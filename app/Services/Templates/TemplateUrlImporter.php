<?php

declare(strict_types=1);

namespace App\Services\Templates;

use App\Models\Campaign;
use App\Models\EmailTemplate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Import from URL (spec §2.5.5 F-2.5.5-002). Fetches the page HTML (size and
 * time capped by config emp-templates.import_url) and rewrites relative image
 * and stylesheet references to absolute URLs against the source, so the
 * imported template renders identically. Remote images stay remote.
 */
class TemplateUrlImporter
{
    /**
     * WP-11 reuse: campaign content URL imports (spec §2.3.3 F-2.3.3-005)
     * go through the same importer — Campaign shares the content_html
     * contract.
     */
    public function import(EmailTemplate|Campaign $template, string $url): EmailTemplate|Campaign
    {
        try {
            $response = Http::timeout((int) config('emp-templates.import_url.timeout', 15))
                ->withHeaders(['Accept' => 'text/html'])
                ->get($url);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['url' => __('templates.errors.url_unreachable')]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages(['url' => __('templates.errors.url_unreachable')]);
        }

        $html = $response->body();

        if (strlen($html) > (int) config('emp-templates.import_url.max_bytes', 2_097_152)) {
            throw ValidationException::withMessages(['url' => __('templates.errors.url_too_large')]);
        }

        if (trim($html) === '') {
            throw ValidationException::withMessages(['url' => __('templates.errors.url_empty')]);
        }

        $template->forceFill(['content_html' => $this->absolutise($html, $url)])->save();

        return $template;
    }

    /** Rewrite relative src/href/url(...) references against the base URL. */
    public function absolutise(string $html, string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';

        if ($host === '') {
            return $html;
        }

        $origin = $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
        $dir = rtrim(Str::beforeLast($parts['path'] ?? '/', '/'), '/');

        $resolve = function (string $ref) use ($origin, $dir): string {
            if ($ref === '' || Str::startsWith($ref, ['http://', 'https://', '//', 'data:', 'mailto:', 'tel:', '#', '{'])) {
                return $ref;
            }

            return Str::startsWith($ref, '/')
                ? $origin.$ref
                : $origin.$dir.'/'.$ref;
        };

        $html = (string) preg_replace_callback(
            '~(\s(?:src|href|background)=)(["\'])(.*?)\2~i',
            fn (array $m): string => $m[1].$m[2].$resolve($m[3]).$m[2],
            $html
        );

        return (string) preg_replace_callback(
            '~url\(\s*(["\']?)(.*?)\1\s*\)~i',
            fn (array $m): string => 'url('.$m[1].$resolve($m[2]).$m[1].')',
            $html
        );
    }
}
