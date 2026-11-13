<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Themes\ThemePalettes;
use App\Settings\SkinSettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An uploaded theme package (spec §6.3 F-6.3-005, §3.21 F-3.21-002).
 *
 * Built-in themes are NOT rows — see {@see ThemePalettes}.
 * This model records only what an operator uploaded, so `themes` being empty
 * is the normal state of a healthy installation.
 */
class Theme extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'areas' => 'array',
        'palette' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $theme): void {
            $theme->uid ??= (string) Str::uuid();
        });
    }

    /** Absolute directory the package was extracted to. */
    public function directory(): string
    {
        return rtrim((string) config('emp-themes.path'), '/\\')
            .DIRECTORY_SEPARATOR.$this->slug;
    }

    /**
     * Manifest palette overrides, filtered to the known token names so a
     * package cannot inject an arbitrary CSS custom property name.
     *
     * @return array<string, string>
     */
    public function paletteTokens(): array
    {
        $out = [];

        foreach ((array) ($this->palette ?? []) as $token => $value) {
            $token = (string) $token;

            if (! in_array($token, ThemePalettes::TOKENS, true)) {
                continue;
            }

            $value = trim((string) $value);

            // Only literal hex — the value lands inside a <style> tag.
            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) !== 1) {
                continue;
            }

            $out[$token] = $value;
        }

        return $out;
    }

    public function scheme(): string
    {
        $scheme = (string) (($this->palette ?? [])['scheme'] ?? 'light');

        return in_array($scheme, ['light', 'dark'], true) ? $scheme : 'light';
    }

    /**
     * @return list<string>
     */
    public function supportedAreas(): array
    {
        $areas = array_map('strval', (array) ($this->areas ?? []));

        return array_values(array_filter(
            $areas,
            static fn (string $area): bool => in_array($area, SkinSettings::AREAS, true),
        ));
    }
}
