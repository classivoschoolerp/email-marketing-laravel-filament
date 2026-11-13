<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Models\Domain;
use Illuminate\Support\Facades\Storage;

/**
 * The resolved brand for the current request (spec §4.7, §6.3, §6.4).
 *
 * Immutable snapshot produced by {@see BrandResolver}. Everything that needs
 * to know "whose logo, whose colours, whose name" reads this one object, so
 * the precedence rules live in exactly one place instead of being re-derived
 * in every view and mailable.
 */
final readonly class Brand
{
    public function __construct(
        public string $name,
        public string $themeSlug,
        public ?string $logoPath = null,
        public ?string $faviconPath = null,
        public ?string $loginBackgroundPath = null,
        public ?string $primaryColor = null,
        public ?string $secondaryColor = null,
        public ?string $fromName = null,
        public ?string $fromEmail = null,
        public ?string $replyToEmail = null,
        public ?Domain $domain = null,
        public bool $hideVendorBranding = false,
    ) {}

    public function logoUrl(): ?string
    {
        return self::assetUrl($this->logoPath);
    }

    public function faviconUrl(): ?string
    {
        return self::assetUrl($this->faviconPath);
    }

    public function loginBackgroundUrl(): ?string
    {
        return self::assetUrl($this->loginBackgroundPath);
    }

    /** True when this brand comes from a §6.4 domain row rather than settings. */
    public function isDomainBrand(): bool
    {
        return $this->domain !== null;
    }

    public function host(): ?string
    {
        return $this->domain?->host;
    }

    /**
     * A stored path is on the `public` disk (D-26); an absolute URL is passed
     * through so an operator may point at a CDN.
     */
    public static function assetUrl(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        // Already a URL (absolute, protocol-relative, or a root-relative one
        // some other layer already ran through Storage::url — PlanBranding
        // hands us those) — pass it through untouched rather than prefixing
        // the disk root a second time.
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            || str_starts_with($path, '//') || str_starts_with($path, '/')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
