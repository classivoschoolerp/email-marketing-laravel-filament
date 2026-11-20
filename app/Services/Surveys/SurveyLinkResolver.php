<?php

declare(strict_types=1);

namespace App\Services\Surveys;

use App\Models\Subscriber;
use App\Models\Survey;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Resolves the `{SURVEY_URL:<survey_uid>}` campaign merge tag (spec
 * F-2.8-008).
 *
 * The tag is parameterised because a campaign links to a SPECIFIC survey;
 * `{SURVEY_URL:9f2c…}` renders the public start URL, with `?s=` carrying the
 * recipient's subscriber uid so the response comes back identified rather
 * than anonymous (F-1.9-002).
 *
 * A uid that does not exist, is not this recipient's tenant, or is not open
 * renders empty — never a broken link and never a cross-tenant leak.
 */
class SurveyLinkResolver
{
    /** The tag name, as it appears in campaign content. */
    public const TAG = 'SURVEY_URL';

    public function url(string $surveyUid, ?Subscriber $subscriber = null): string
    {
        try {
            if (! Schema::hasTable('surveys')) {
                return '';
            }

            /** @var Survey|null $survey */
            $survey = Survey::withoutTenancy()->where('uid', trim($surveyUid))->first();

            if ($survey === null) {
                return '';
            }

            if ($subscriber !== null && (int) $survey->customer_id !== (int) $subscriber->customer_id) {
                return '';
            }

            $parameters = ['uid' => $survey->uid];

            if ($subscriber !== null) {
                $parameters['s'] = $subscriber->uid;
            }

            return route('surveys.public.start', $parameters);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Replace every `{SURVEY_URL:uid}` occurrence in $content.
     *
     * Runs BEFORE the generic merge-tag pass, so the argument form never has
     * to fight the `{TAG|fallback}` grammar (D-55/D-62 ordering rule).
     */
    public function render(string $content, ?Subscriber $subscriber = null): string
    {
        if (! str_contains($content, '{'.self::TAG.':')) {
            return $content;
        }

        return (string) preg_replace_callback(
            '/\{'.self::TAG.':([A-Za-z0-9\-]+)\}/u',
            fn (array $match): string => $this->url($match[1], $subscriber),
            $content,
        );
    }

    /** The copy-paste tag shown next to a survey in the panel. */
    public function tagFor(Survey $survey): string
    {
        return '{'.self::TAG.':'.$survey->uid.'}';
    }
}
