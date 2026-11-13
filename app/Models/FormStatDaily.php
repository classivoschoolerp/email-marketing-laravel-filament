<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use App\Services\Forms\FormAnalytics;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily (form, source) counters behind the §2.7 analytics page
 * (F-2.7-012). Written only by {@see FormAnalytics},
 * which upserts + increments so concurrent impressions never lose a count.
 */
class FormStatDaily extends Model
{
    use BelongsToCustomer;

    protected $table = 'form_stats_daily';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'views' => 'integer',
            'submissions' => 'integer',
            // 0 = no A/B arm. NOT NULL because the column joins the unique
            // index this table's upsert uses as its conflict target.
            'form_variant_id' => 'integer',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
