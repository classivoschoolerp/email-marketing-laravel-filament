<?php

declare(strict_types=1);

namespace App\Plugins;

/**
 * Version pinning + compatibility check (spec §6.1 F-6.1-004).
 *
 * D-95: the canonical core version is `config('emp.core_version')`. It is NOT
 * read from composer.json — see the comment on that config key for why.
 *
 * Constraint grammar (deliberately small, and documented in docs/plugins.md
 * — a full Composer-style resolver is not worth its weight here):
 *
 *   "1.2.0"    exact
 *   ">=1.2.0"  at least            "<=1.2.0"  at most
 *   ">1.2.0"   strictly greater    "<1.2.0"   strictly less
 *   "^1.2.0"   >=1.2.0 and <2.0.0  (caret: same major)
 *   "~1.2.0"   >=1.2.0 and <1.3.0  (tilde: same major.minor)
 *   "*"        any
 *
 * Comma-separated constraints are ANDed: ">=1.2.0,<2.0.0".
 */
final class CoreVersion
{
    public static function current(): string
    {
        return (string) config('emp.core_version', '0.0.0');
    }

    /**
     * Does the running core satisfy a plugin's min/max window?
     * A null bound means "unbounded in that direction".
     */
    public static function supports(?string $min, ?string $max, ?string $core = null): bool
    {
        $core ??= self::current();

        if ($min !== null && $min !== '' && version_compare(self::normalize($core), self::normalize($min), '<')) {
            return false;
        }

        if ($max !== null && $max !== '' && version_compare(self::normalize($core), self::normalize($max), '>')) {
            return false;
        }

        return true;
    }

    /** Evaluate one constraint expression against a concrete version. */
    public static function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);

        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        foreach (explode(',', $constraint) as $part) {
            if (! self::satisfiesOne($version, trim($part))) {
                return false;
            }
        }

        return true;
    }

    /** A strict `major.minor.patch` semver string (what manifests must use). */
    public static function isValid(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+$/', $version) === 1;
    }

    /** Pad `1` / `1.2` to `1.0.0` / `1.2.0` so version_compare is stable. */
    public static function normalize(string $version): string
    {
        $parts = array_map('intval', explode('.', trim($version)));
        $parts = array_pad(array_slice($parts, 0, 3), 3, 0);

        return implode('.', $parts);
    }

    private static function satisfiesOne(string $version, string $constraint): bool
    {
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        $version = self::normalize($version);

        if (str_starts_with($constraint, '^')) {
            $base = self::normalize(substr($constraint, 1));
            $upper = ((int) explode('.', $base)[0] + 1).'.0.0';

            return version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
        }

        if (str_starts_with($constraint, '~')) {
            $base = self::normalize(substr($constraint, 1));
            [$major, $minor] = array_map('intval', explode('.', $base));
            $upper = $major.'.'.($minor + 1).'.0';

            return version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
        }

        foreach (['>=', '<=', '!=', '>', '<', '='] as $operator) {
            if (str_starts_with($constraint, $operator)) {
                return version_compare(
                    $version,
                    self::normalize(substr($constraint, strlen($operator))),
                    $operator === '=' ? '==' : $operator,
                );
            }
        }

        return version_compare($version, self::normalize($constraint), '==');
    }
}
