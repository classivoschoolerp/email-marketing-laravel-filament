<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Campaign;
use App\Support\Geo\WorldTileGrid;

/**
 * World map for the geo report (spec §2.3.8 F-2.3.8-013 "world map +
 * country/region/city table"), WP-27B.
 *
 * Renders an INLINE SVG choropleth over {@see WorldTileGrid} — one equal-area
 * tile per country, shaded by that country's share of the campaign's open
 * events. No JavaScript, no external library, no network: the SVG is built
 * server-side from data this repo owns.
 *
 * HONESTY NOTE, repeated from WorldTileGrid because it matters at the point
 * of use: this is a **tile cartogram**, not a geographic-boundary map. Tiles
 * approximate where a country sits, they do not draw its shape or borders.
 * The UI labels it as such. Opens whose GeoIP lookup produced no usable
 * country — or a country outside ISO 3166-1 — are counted in an explicit
 * "unknown" figure and are never silently dropped or attributed.
 */
class GeoMapRenderer
{
    /** Tile size and gap, in viewBox units. */
    private const TILE = 12;

    private const GAP = 2;

    /** Sequential scale, no-data first. Chosen for light + dark legibility. */
    private const NO_DATA = '#e5e7eb';

    private const SCALE = ['#dbeafe', '#bfdbfe', '#93c5fd', '#60a5fa', '#3b82f6', '#1d4ed8'];

    public function __construct(private readonly CampaignReportService $reports) {}

    /**
     * @return array{
     *     svg: string,
     *     rows: list<array{code: string, name: string, count: int, percent: float}>,
     *     total: int, mapped: int, unknown: int, max: int
     * }
     */
    public function render(Campaign $campaign): array
    {
        return $this->renderBreakdown($this->reports->countryBreakdown($campaign));
    }

    /**
     * Render from any {country, count} breakdown — campaign reports (§2.3.8) and
     * the list overview (§2.2.3) share one map so the two screens can never
     * disagree about the same subscribers.
     *
     * @param  iterable<array{country: string|null, count: int}>  $breakdown
     * @return array{
     *     svg: string,
     *     rows: list<array{code: string, name: string, count: int, percent: float}>,
     *     total: int, mapped: int, unknown: int, max: int
     * }
     */
    public function renderBreakdown(iterable $breakdown): array
    {
        $total = 0;
        $unknown = 0;
        /** @var array<string, int> $byCode */
        $byCode = [];

        foreach ($breakdown as $row) {
            $total += $row['count'];

            $code = WorldTileGrid::resolveCode($row['country']);

            if ($code === null) {
                $unknown += $row['count'];

                continue;
            }

            $byCode[$code] = ($byCode[$code] ?? 0) + $row['count'];
        }

        $mapped = $total - $unknown;
        $max = $byCode === [] ? 0 : max($byCode);

        arsort($byCode);

        $names = WorldTileGrid::names();
        $rows = [];

        foreach ($byCode as $code => $count) {
            $rows[] = [
                'code' => $code,
                'name' => $names[$code] ?? $code,
                'count' => $count,
                'percent' => $this->reports->rate($count, $total),
            ];
        }

        return [
            'svg' => $this->svg($byCode, $max, $total),
            'rows' => $rows,
            'total' => $total,
            'mapped' => $mapped,
            'unknown' => $unknown,
            'max' => $max,
        ];
    }

    /**
     * @param  array<string, int>  $byCode
     */
    private function svg(array $byCode, int $max, int $total): string
    {
        $step = self::TILE + self::GAP;
        $width = WorldTileGrid::COLS * $step - self::GAP;
        $height = WorldTileGrid::ROWS * $step - self::GAP;

        $names = WorldTileGrid::names();

        $tiles = '';

        foreach (WorldTileGrid::grid() as $code => [$col, $row]) {
            $count = $byCode[$code] ?? 0;
            $fill = $this->fill($count, $max);
            $name = $names[$code] ?? $code;

            $title = $count > 0
                ? __('reports.geo.map_tile', [
                    'country' => $name,
                    'count' => number_format($count),
                    'percent' => $this->reports->rate($count, $total),
                ])
                : __('reports.geo.map_tile_empty', ['country' => $name]);

            $tiles .= '<rect x="'.($col * $step).'" y="'.($row * $step).'"'
                .' width="'.self::TILE.'" height="'.self::TILE.'" rx="2"'
                .' fill="'.$fill.'"'
                .' data-country="'.$this->e($code).'" data-count="'.$count.'">'
                .'<title>'.$this->e($title).'</title>'
                .'</rect>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$width.' '.$height.'"'
            .' width="100%" role="img" aria-label="'.$this->e(__('reports.geo.map_heading')).'"'
            .' style="max-width:100%;height:auto;">'
            .$tiles
            .'</svg>';
    }

    /** Six-step sequential shading of a country's count against the maximum. */
    private function fill(int $count, int $max): string
    {
        if ($count <= 0 || $max <= 0) {
            return self::NO_DATA;
        }

        $index = (int) ceil($count / $max * count(self::SCALE)) - 1;

        return self::SCALE[max(0, min(count(self::SCALE) - 1, $index))];
    }

    /** @return list<array{color: string, label: string}> */
    public function legend(int $max): array
    {
        $steps = [['color' => self::NO_DATA, 'label' => __('reports.geo.map_legend_none')]];

        if ($max <= 0) {
            return $steps;
        }

        $buckets = count(self::SCALE);

        foreach (self::SCALE as $index => $color) {
            $upper = (int) ceil($max * ($index + 1) / $buckets);
            $lower = (int) ceil($max * $index / $buckets) + 1;

            $steps[] = [
                'color' => $color,
                'label' => $lower >= $upper ? (string) $upper : $lower.'–'.$upper,
            ];
        }

        return $steps;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
