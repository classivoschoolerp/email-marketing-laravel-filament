<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use App\Services\Lists\FieldValueService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer to one question (spec F-2.8-005). Text-backed with the same
 * serialisation convention as `subscriber_field_values`, so
 * {@see FieldValueService::fromStorage()} types it back.
 */
class SurveyResponderAnswer extends Model
{
    use BelongsToCustomer;

    protected $guarded = ['id'];

    public function responder(): BelongsTo
    {
        return $this->belongsTo(SurveyResponder::class, 'survey_responder_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(SurveyField::class, 'survey_field_id');
    }
}
