<?php

declare(strict_types=1);

namespace App\Support\Geo;

/**
 * World map data for the §2.3.8 F-2.3.8-013 geo report (WP-27B).
 *
 * WHAT THIS IS — AND WHAT IT IS NOT.
 * This is a **tile cartogram**: one equal-sized square per ISO-3166-1 country,
 * positioned to approximate where that country sits on the globe. It is a
 * documented, widely used map form for country choropleths (the FT, the
 * Guardian and the Economist all publish them) precisely because every
 * country gets equal visual weight regardless of land area — Singapore reads
 * as loudly as Russia.
 *
 * It is **NOT** a geographic-boundary choropleth. No coastline, border or
 * polygon is claimed anywhere in this file. Producing real boundaries needs a
 * vetted vector dataset (Natural Earth / world-atlas), which cannot be
 * vendored in this environment; inventing coordinates for 200 countries from
 * memory would be fabricating data, so it was not done. See
 * docs/e2e-checklist.md.
 *
 * HOW THE POSITIONS ARE DERIVED (not hand-drawn):
 *   1. {@see centroids()} lists each country's approximate centroid latitude
 *      and longitude — public, checkable reference values;
 *   2. those are binned onto a {@see COLS}×{@see ROWS} equirectangular grid;
 *   3. where two countries fall in the same cell, the ones in UNCONTESTED
 *      cells are placed first (so sparsely populated regions — Russia,
 *      Brazil, Australia — always land exactly), then the contested ones are
 *      pushed to the nearest free cell by a deterministic ring search.
 * The result is stable: same input, same grid, every time — which is what the
 * accompanying test asserts.
 */
final class WorldTileGrid
{
    /** Grid resolution: 72 columns of 5° longitude. */
    public const COLS = 72;

    /** 36 rows spanning the inhabited latitude band below. */
    public const ROWS = 36;

    public const LAT_MAX = 78.0;

    public const LAT_MIN = -45.0;

    /** How far the ring search may push a country before giving up (cells). */
    private const MAX_NUDGE = 5;

    /** @var array<string, array{int, int}>|null memoised derived grid */
    private static ?array $grid = null;

    /**
     * Approximate country centroids: code => [latitude, longitude].
     * Keys match App\Support\Geo\Countries — the platform's canonical ISO
     * 3166-1 list — so every country the rest of the app knows about has a
     * tile, and no tile exists for a country the app does not know.
     *
     * @return array<string, array{float, float}>
     */
    public static function centroids(): array
    {
        return [
            'AD' => [42.5, 1.6], 'AE' => [23.4, 53.8], 'AF' => [33.9, 67.7], 'AG' => [17.1, -61.8],
            'AI' => [18.2, -63.1], 'AL' => [41.2, 20.2], 'AM' => [40.1, 45.0], 'AO' => [-11.2, 17.9],
            'AR' => [-38.4, -63.6], 'AS' => [-14.3, -170.1], 'AT' => [47.5, 14.6], 'AU' => [-25.3, 133.8],
            'AW' => [12.5, -70.0], 'AZ' => [40.1, 47.6], 'BA' => [43.9, 17.7], 'BB' => [13.2, -59.5],
            'BD' => [23.7, 90.4], 'BE' => [50.5, 4.5], 'BF' => [12.2, -1.6], 'BG' => [42.7, 25.5],
            'BH' => [26.0, 50.6], 'BI' => [-3.4, 29.9], 'BJ' => [9.3, 2.3], 'BM' => [32.3, -64.8],
            'BN' => [4.5, 114.7], 'BO' => [-16.3, -63.6], 'BR' => [-14.2, -51.9], 'BS' => [25.0, -77.4],
            'BT' => [27.5, 90.4], 'BW' => [-22.3, 24.7], 'BY' => [53.7, 28.0], 'BZ' => [17.2, -88.5],
            'CA' => [56.1, -106.3], 'CD' => [-4.0, 21.8], 'CF' => [6.6, 20.9], 'CG' => [-0.2, 15.8],
            'CH' => [46.8, 8.2], 'CI' => [7.5, -5.5], 'CL' => [-35.7, -71.5], 'CM' => [7.4, 12.4],
            'CN' => [35.9, 104.2], 'CO' => [4.6, -74.3], 'CR' => [9.7, -83.8], 'CU' => [21.5, -77.8],
            'CV' => [16.0, -24.0], 'CW' => [12.2, -69.0], 'CY' => [35.1, 33.4], 'CZ' => [49.8, 15.5],
            'DE' => [51.2, 10.5], 'DJ' => [11.8, 42.6], 'DK' => [56.3, 9.5], 'DM' => [15.4, -61.4],
            'DO' => [18.7, -70.2], 'DZ' => [28.0, 1.7], 'EC' => [-1.8, -78.2], 'EE' => [58.6, 25.0],
            'EG' => [26.8, 30.8], 'ER' => [15.2, 39.8], 'ES' => [40.5, -3.7], 'ET' => [9.1, 40.5],
            'FI' => [61.9, 25.7], 'FJ' => [-17.7, 178.1], 'FM' => [7.4, 150.5], 'FR' => [46.6, 2.2],
            'GA' => [-0.8, 11.6], 'GB' => [55.4, -3.4], 'GD' => [12.1, -61.7], 'GE' => [42.3, 43.4],
            'GF' => [3.9, -53.1], 'GG' => [49.5, -2.6], 'GH' => [7.9, -1.0], 'GI' => [36.1, -5.3],
            'GL' => [71.7, -42.6], 'GM' => [13.4, -15.3], 'GN' => [9.9, -9.7], 'GP' => [16.3, -61.6],
            'GQ' => [1.6, 10.3], 'GR' => [39.1, 21.8], 'GT' => [15.8, -90.2], 'GU' => [13.4, 144.8],
            'GW' => [11.8, -15.2], 'GY' => [4.9, -58.9], 'HK' => [22.4, 114.1], 'HN' => [15.2, -86.2],
            'HR' => [45.1, 15.2], 'HT' => [19.0, -72.3], 'HU' => [47.2, 19.5], 'ID' => [-0.8, 113.9],
            'IE' => [53.4, -8.2], 'IL' => [31.1, 34.9], 'IM' => [54.2, -4.5], 'IN' => [20.6, 79.0],
            'IQ' => [33.2, 43.7], 'IR' => [32.4, 53.7], 'IS' => [65.0, -19.0], 'IT' => [41.9, 12.6],
            'JE' => [49.2, -2.1], 'JM' => [18.1, -77.3], 'JO' => [30.6, 36.2], 'JP' => [36.2, 138.3],
            'KE' => [0.0, 37.9], 'KG' => [41.2, 74.8], 'KH' => [12.6, 105.0], 'KI' => [1.9, -157.4],
            'KM' => [-11.9, 43.9], 'KN' => [17.4, -62.8], 'KP' => [40.3, 127.5], 'KR' => [35.9, 127.8],
            'KW' => [29.3, 47.5], 'KY' => [19.3, -81.3], 'KZ' => [48.0, 66.9], 'LA' => [19.9, 102.5],
            'LB' => [33.9, 35.9], 'LC' => [13.9, -61.0], 'LI' => [47.2, 9.6], 'LK' => [7.9, 80.8],
            'LR' => [6.4, -9.4], 'LS' => [-29.6, 28.2], 'LT' => [55.2, 23.9], 'LU' => [49.8, 6.1],
            'LV' => [56.9, 24.6], 'LY' => [26.3, 17.2], 'MA' => [31.8, -7.1], 'MC' => [43.8, 7.4],
            'MD' => [47.4, 28.4], 'ME' => [42.7, 19.4], 'MG' => [-18.8, 46.9], 'MH' => [7.1, 171.2],
            'MK' => [41.6, 21.7], 'ML' => [17.6, -4.0], 'MM' => [21.9, 96.0], 'MN' => [46.9, 103.8],
            'MO' => [22.2, 113.5], 'MQ' => [14.6, -61.0], 'MR' => [21.0, -10.9], 'MS' => [16.7, -62.2],
            'MT' => [35.9, 14.4], 'MU' => [-20.3, 57.6], 'MV' => [3.2, 73.2], 'MW' => [-13.3, 34.3],
            'MX' => [23.6, -102.5], 'MY' => [4.2, 102.0], 'MZ' => [-18.7, 35.5], 'NA' => [-23.0, 18.5],
            'NC' => [-20.9, 165.6], 'NE' => [17.6, 8.1], 'NG' => [9.1, 8.7], 'NI' => [12.9, -85.2],
            'NL' => [52.1, 5.3], 'NO' => [60.5, 8.5], 'NP' => [28.4, 84.1], 'NR' => [-0.5, 166.9],
            'NZ' => [-40.9, 174.9], 'OM' => [21.5, 55.9], 'PA' => [8.5, -80.8], 'PE' => [-9.2, -75.0],
            'PF' => [-17.7, -149.4], 'PG' => [-6.3, 144.0], 'PH' => [12.9, 121.8], 'PK' => [30.4, 69.3],
            'PL' => [51.9, 19.1], 'PR' => [18.2, -66.6], 'PS' => [32.0, 35.2], 'PT' => [39.4, -8.2],
            'PW' => [7.5, 134.6], 'PY' => [-23.4, -58.4], 'QA' => [25.4, 51.2], 'RE' => [-21.1, 55.5],
            'RO' => [45.9, 25.0], 'RS' => [44.0, 21.0], 'RU' => [61.5, 105.3], 'RW' => [-1.9, 29.9],
            'SA' => [23.9, 45.1], 'SB' => [-9.6, 160.2], 'SC' => [-4.7, 55.5], 'SD' => [12.9, 30.2],
            'SE' => [60.1, 18.6], 'SG' => [1.4, 103.8], 'SI' => [46.2, 15.0], 'SK' => [48.7, 19.7],
            'SL' => [8.5, -11.8], 'SM' => [43.9, 12.5], 'SN' => [14.5, -14.5], 'SO' => [5.2, 46.2],
            'SR' => [3.9, -56.0], 'SS' => [6.9, 31.3], 'ST' => [0.2, 6.6], 'SV' => [13.8, -88.9],
            'SX' => [18.0, -63.1], 'SY' => [34.8, 39.0], 'SZ' => [-26.5, 31.5], 'TC' => [21.7, -71.8],
            'TD' => [15.5, 18.7], 'TG' => [8.6, 0.8], 'TH' => [15.9, 101.0], 'TJ' => [38.9, 71.3],
            'TL' => [-8.9, 125.7], 'TM' => [39.0, 59.6], 'TN' => [33.9, 9.5], 'TO' => [-21.2, -175.2],
            'TR' => [39.0, 35.2], 'TT' => [10.7, -61.2], 'TV' => [-7.1, 177.6], 'TW' => [23.7, 121.0],
            'TZ' => [-6.4, 34.9], 'UA' => [48.4, 31.2], 'UG' => [1.4, 32.3], 'US' => [39.8, -98.6],
            'UY' => [-32.5, -55.8], 'UZ' => [41.4, 64.6], 'VA' => [41.9, 12.5], 'VC' => [13.0, -61.3],
            'VE' => [6.4, -66.6], 'VG' => [18.4, -64.6], 'VI' => [18.3, -64.9], 'VN' => [14.1, 108.3],
            'VU' => [-15.4, 167.0], 'WS' => [-13.8, -172.1], 'YE' => [15.6, 48.5], 'YT' => [-12.8, 45.2],
            'ZA' => [-30.6, 22.9], 'ZM' => [-13.1, 27.9], 'ZW' => [-19.0, 29.2],
        ];
    }

    /**
     * Derived, collision-free grid: code => [column, row].
     *
     * @return array<string, array{int, int}>
     */
    public static function grid(): array
    {
        if (self::$grid !== null) {
            return self::$grid;
        }

        $centroids = self::centroids();
        ksort($centroids);

        // Raw cell per country, then how many countries want each cell.
        $raw = [];
        $demand = [];

        foreach ($centroids as $code => [$lat, $lon]) {
            $cell = self::cell($lat, $lon);
            $raw[$code] = $cell;
            $key = $cell[0].':'.$cell[1];
            $demand[$key] = ($demand[$key] ?? 0) + 1;
        }

        // Uncontested countries first — a country alone in its cell can never
        // be the one that has to move, so sparse regions land exactly.
        $order = array_keys($raw);
        usort($order, function (string $a, string $b) use ($raw, $demand): int {
            $demandA = $demand[$raw[$a][0].':'.$raw[$a][1]];
            $demandB = $demand[$raw[$b][0].':'.$raw[$b][1]];

            return [$demandA, $a] <=> [$demandB, $b];
        });

        $taken = [];
        $grid = [];

        foreach ($order as $code) {
            $cell = self::freeCell($raw[$code], $taken);

            if ($cell === null) {
                continue; // unplaceable (never happens at this resolution)
            }

            $taken[$cell[0].':'.$cell[1]] = true;
            $grid[$code] = $cell;
        }

        ksort($grid);

        return self::$grid = $grid;
    }

    /** Equirectangular binning of a centroid onto the grid. */
    private static function cell(float $lat, float $lon): array
    {
        $lat = max(self::LAT_MIN, min(self::LAT_MAX, $lat));
        $lon = max(-180.0, min(180.0, $lon));

        $col = (int) round(($lon + 180.0) / 360.0 * (self::COLS - 1));
        $row = (int) round((self::LAT_MAX - $lat) / (self::LAT_MAX - self::LAT_MIN) * (self::ROWS - 1));

        return [$col, $row];
    }

    /**
     * The cell itself when free, otherwise the nearest free one. Rings are
     * walked outward and, within a ring, in a fixed order, so the outcome is
     * fully determined by the input — never by hash or iteration order.
     *
     * @param  array{int, int}  $cell
     * @param  array<string, true>  $taken
     * @return array{int, int}|null
     */
    private static function freeCell(array $cell, array $taken): ?array
    {
        [$col, $row] = $cell;

        for ($radius = 0; $radius <= self::MAX_NUDGE; $radius++) {
            $candidates = [];

            for ($dc = -$radius; $dc <= $radius; $dc++) {
                for ($dr = -$radius; $dr <= $radius; $dr++) {
                    if (max(abs($dc), abs($dr)) !== $radius) {
                        continue; // only the ring, not the filled square
                    }

                    $candidates[] = [$dc, $dr];
                }
            }

            // Deterministic ring order: prefer horizontal nudges (the map is
            // wider than it is tall), then vertical, then the corners.
            usort($candidates, fn (array $a, array $b): int => [abs($a[1]), abs($a[0]), $a[1], $a[0]]
                <=> [abs($b[1]), abs($b[0]), $b[1], $b[0]]);

            foreach ($candidates as [$dc, $dr]) {
                $c = $col + $dc;
                $r = $row + $dr;

                if ($c < 0 || $c >= self::COLS || $r < 0 || $r >= self::ROWS) {
                    continue;
                }

                if (! isset($taken[$c.':'.$r])) {
                    return [$c, $r];
                }
            }
        }

        return null;
    }

    /**
     * Resolve a stored `campaign_track_*.country` value to an ISO-3166-1
     * alpha-2 code. The tracking columns hold the provider's country NAME
     * with the code as a fallback (GeoLocation::trackingColumns), so both are
     * accepted; anything else resolves to null and is reported as unknown
     * rather than guessed.
     */
    public static function resolveCode(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $grid = self::grid();
        $upper = mb_strtoupper($value);

        if (isset($grid[$upper])) {
            return $upper;
        }

        foreach (self::names() as $code => $name) {
            if (mb_strtolower($name) === mb_strtolower($value)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * code => display name, restricted to countries that have a tile.
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        static $names = null;

        if ($names !== null) {
            return $names;
        }

        return $names = array_intersect_key(Countries::fallback(), self::grid());
    }
}
