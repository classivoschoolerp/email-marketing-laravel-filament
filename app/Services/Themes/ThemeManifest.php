<?php

declare(strict_types=1);

namespace App\Services\Themes;

/**
 * Decoded `theme.json` (spec §6.3 F-6.3-005).
 *
 * A theme manifest is intentionally far smaller than a plugin manifest: no
 * autoload, no entry class, no hooks, no service providers — there is no code
 * to point at. It declares who wrote it, which areas it skins, which
 * stylesheet to load and which palette tokens it overrides.
 */
final readonly class ThemeManifest
{
    /**
     * @param  list<string>  $areas
     * @param  array<string, string>  $palette
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $version,
        public string $stylesheet,
        public array $areas,
        public array $palette,
        public string $scheme,
        public ?string $author,
        public ?string $description,
        public ?string $homepage,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $palette = [];

        foreach ((array) ($data['palette'] ?? []) as $token => $value) {
            $palette[(string) $token] = (string) $value;
        }

        $areas = array_values(array_map(
            'strval',
            (array) ($data['areas'] ?? $data['supports'] ?? []),
        ));

        return new self(
            slug: trim((string) ($data['slug'] ?? '')),
            name: trim((string) ($data['name'] ?? '')),
            version: trim((string) ($data['version'] ?? '1.0.0')),
            stylesheet: trim((string) ($data['stylesheet'] ?? $data['style'] ?? 'theme.css')),
            areas: $areas,
            palette: $palette,
            scheme: trim((string) ($data['scheme'] ?? 'light')),
            author: self::nullableString($data['author'] ?? null),
            description: self::nullableString($data['description'] ?? null),
            homepage: self::nullableString($data['homepage'] ?? null),
            raw: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'version' => $this->version,
            'stylesheet' => $this->stylesheet,
            'areas' => $this->areas,
            'palette' => $this->palette,
            'scheme' => $this->scheme,
            'author' => $this->author,
            'description' => $this->description,
            'homepage' => $this->homepage,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
