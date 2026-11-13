<?php

declare(strict_types=1);

namespace App\Services\Themes;

/**
 * One selectable theme (spec §6.3 F-6.3-001/005).
 *
 * Two sources, one shape:
 *  - BUILTIN: a palette declared in {@see ThemePalettes}, always present.
 *  - PACKAGE: an uploaded zip extracted under config('emp-themes.path'),
 *    recorded in the `themes` table. Its palette overrides the default
 *    palette token-by-token, and its stylesheet is appended after the
 *    generated custom properties so it can use them.
 *
 * A package carries no executable code — see config/emp-themes.php.
 */
final readonly class Theme
{
    public const SOURCE_BUILTIN = 'builtin';

    public const SOURCE_PACKAGE = 'package';

    /**
     * @param  array<string, string>  $palette
     * @param  list<string>  $areas
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $source,
        public array $palette,
        public string $scheme = 'light',
        public array $areas = [],
        public string $version = '1.0.0',
        public ?string $author = null,
        public ?string $description = null,
        public ?string $stylesheet = null,
        public ?string $directory = null,
    ) {}

    public function isPackage(): bool
    {
        return $this->source === self::SOURCE_PACKAGE;
    }

    public function supportsArea(string $area): bool
    {
        return $this->areas === [] || in_array($area, $this->areas, true);
    }

    /**
     * Absolute path to the package stylesheet, or null for a built-in.
     *
     * The manifest-declared relative path is re-flattened here even though
     * the installer already validated it: this method is what actually opens
     * a file, so it does its own containment check rather than trusting a
     * value that has been through a database round-trip.
     */
    public function stylesheetPath(): ?string
    {
        if ($this->directory === null || $this->stylesheet === null) {
            return null;
        }

        $segments = array_filter(
            explode('/', str_replace('\\', '/', $this->stylesheet)),
            static fn (string $s): bool => $s !== '' && $s !== '.' && $s !== '..',
        );

        if ($segments === []) {
            return null;
        }

        $path = rtrim($this->directory, '/\\').DIRECTORY_SEPARATOR
            .implode(DIRECTORY_SEPARATOR, $segments);

        return is_file($path) ? $path : null;
    }
}
