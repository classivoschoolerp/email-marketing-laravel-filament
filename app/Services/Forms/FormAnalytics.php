<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormStatDaily;
use App\Models\FormSubmission;
use App\Models\FormVariant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Form analytics (spec F-2.7-012): views, submits, conversion rate and the
 * same three broken down by source — and, since WP-46, by A/B arm
 * (F-2.7-013).
 *
 * Counters live in `form_stats_daily` keyed (form, day, source, arm) and are
 * moved with an atomic INSERT … ON DUPLICATE KEY UPDATE (Eloquent upsert), so
 * two concurrent impressions can never read-modify-write over each other.
 *
 * The arm column is NOT NULL with 0 meaning "no arm": two NULLs are distinct
 * inside a unique index in both MySQL and SQLite, so a nullable column in the
 * upsert's conflict target would make every impression insert a new row. The
 * migration's docblock spells this out.
 */
class FormAnalytics
{
    /** `form_stats_daily.form_variant_id` value meaning "served no arm". */
    public const NO_VARIANT = 0;

    /** Sources are attacker-supplied strings: normalise hard before storing. */
    public static function normalizeSource(?string $source): string
    {
        $source = mb_strtolower(trim((string) $source));

        if ($source === '') {
            return FormSubmission::SOURCE_DIRECT;
        }

        // Host-only for anything that looks like a URL, so "utm" style noise
        // never explodes the cardinality of the by-source breakdown.
        if (preg_match('~^https?://~i', $source) === 1) {
            $source = (string) (parse_url($source, PHP_URL_HOST) ?: FormSubmission::SOURCE_DIRECT);
        }

        $source = preg_replace('/[^a-z0-9._\-]/', '-', $source) ?? FormSubmission::SOURCE_DIRECT;

        return mb_substr(trim($source, '-') ?: FormSubmission::SOURCE_DIRECT, 0, 64);
    }

    public function recordView(Form $form, ?string $source = null, ?FormVariant $variant = null): void
    {
        $this->bump($form, self::normalizeSource($source), 'views', $variant);
    }

    public function recordSubmission(Form $form, ?string $source = null, ?FormVariant $variant = null): void
    {
        $this->bump($form, self::normalizeSource($source), 'submissions', $variant);
    }

    /**
     * Totals for the whole lifetime of a form.
     *
     * @return array{views:int, submissions:int, conversion_rate:float}
     */
    public function summary(Form $form): array
    {
        /** @var object{views:int|null, submissions:int|null} $row */
        $row = FormStatDaily::withoutTenancy()
            ->where('form_id', $form->id)
            ->selectRaw('COALESCE(SUM(views),0) as views, COALESCE(SUM(submissions),0) as submissions')
            ->first();

        return $this->rate((int) ($row->views ?? 0), (int) ($row->submissions ?? 0));
    }

    /**
     * Per-source breakdown, best converting first.
     *
     * @return list<array{source:string, views:int, submissions:int, conversion_rate:float}>
     */
    public function bySource(Form $form): array
    {
        return FormStatDaily::withoutTenancy()
            ->where('form_id', $form->id)
            ->groupBy('source')
            ->selectRaw('source, COALESCE(SUM(views),0) as views, COALESCE(SUM(submissions),0) as submissions')
            ->orderByDesc('submissions')
            ->get()
            ->map(fn (object $row): array => ['source' => (string) $row->source]
                + $this->rate((int) $row->views, (int) $row->submissions))
            ->all();
    }

    /**
     * Per-A/B-arm breakdown (F-2.7-013), best converting first. Arm id 0 is
     * traffic served no arm at all — the form before the test was switched on
     * — and is reported rather than hidden, because dropping it would make the
     * per-arm views not add up to the form's total.
     *
     * @return list<array{form_variant_id:int, views:int, submissions:int, conversion_rate:float}>
     */
    public function byVariant(Form $form): array
    {
        return FormStatDaily::withoutTenancy()
            ->where('form_id', $form->id)
            ->groupBy('form_variant_id')
            ->selectRaw('form_variant_id, COALESCE(SUM(views),0) as views, COALESCE(SUM(submissions),0) as submissions')
            ->orderByDesc('submissions')
            ->get()
            ->map(fn (object $row): array => ['form_variant_id' => (int) $row->form_variant_id]
                + $this->rate((int) $row->views, (int) $row->submissions))
            ->all();
    }

    /**
     * Daily series for the last $days days, oldest first, zero-filled.
     *
     * @return list<array{date:string, views:int, submissions:int}>
     */
    public function daily(Form $form, int $days = 30): array
    {
        $days = max(1, $days);
        $from = Carbon::today()->subDays($days - 1);

        $rows = FormStatDaily::withoutTenancy()
            ->where('form_id', $form->id)
            ->where('stat_date', '>=', $from->toDateString())
            ->groupBy('stat_date')
            ->selectRaw('stat_date, COALESCE(SUM(views),0) as views, COALESCE(SUM(submissions),0) as submissions')
            ->get()
            ->keyBy(fn (object $row): string => Carbon::parse((string) $row->stat_date)->toDateString());

        $series = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $from->copy()->addDays($offset)->toDateString();
            $row = $rows->get($date);

            $series[] = [
                'date' => $date,
                'views' => (int) ($row->views ?? 0),
                'submissions' => (int) ($row->submissions ?? 0),
            ];
        }

        return $series;
    }

    /** @return array{views:int, submissions:int, conversion_rate:float} */
    private function rate(int $views, int $submissions): array
    {
        return [
            'views' => $views,
            'submissions' => $submissions,
            'conversion_rate' => $views > 0 ? round(($submissions / $views) * 100, 2) : 0.0,
        ];
    }

    /**
     * Atomic counter move. `upsert` compiles to the driver's native
     * insert-or-update, so no row is ever lost to a race.
     */
    private function bump(Form $form, string $source, string $column, ?FormVariant $variant = null): void
    {
        $today = Carbon::today()->toDateString();
        $now = Carbon::now();
        $variantId = (int) ($variant?->id ?? self::NO_VARIANT);

        FormStatDaily::withoutTenancy()->getModel()->newQuery()->upsert(
            [[
                'customer_id' => (int) $form->customer_id,
                'form_id' => (int) $form->id,
                'form_variant_id' => $variantId,
                'stat_date' => $today,
                'source' => $source,
                'views' => 0,
                'submissions' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['form_id', 'stat_date', 'source', 'form_variant_id'],
            ['updated_at'],
        );

        FormStatDaily::withoutTenancy()
            ->where('form_id', $form->id)
            ->where('stat_date', $today)
            ->where('source', $source)
            ->where('form_variant_id', $variantId)
            ->update([$column => DB::raw($column.' + 1'), 'updated_at' => $now]);
    }
}
