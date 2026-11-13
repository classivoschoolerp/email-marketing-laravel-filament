<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Models\EmailList;
use App\Models\ListField;
use App\Models\ListPage;
use App\Models\ListSegment;
use App\Models\Subscriber;
use Illuminate\Support\Str;

/**
 * Copies a list with its fields (+options), segments (+conditions, custom
 * field references remapped), its customised public/lifecycle pages
 * (§2.2.12 list_pages rows) and company/settings columns; subscribers and
 * their field values optionally (spec F-2.2.14-001).
 *
 * Only CUSTOMISED pages are copied — a list with no list_pages rows renders
 * the admin/lang defaults through ListPageResolver, so the copy inherits the
 * exact same content without carrying dead rows.
 *
 * Subscriber rows are cloned withoutEvents so the copy does NOT fire the
 * list's on-subscribe actions — a copy is not a subscribe event.
 */
class ListCopyService
{
    public function copy(EmailList $list, bool $withSubscribers = false, ?string $name = null): EmailList
    {
        /** @var EmailList $copy */
        $copy = $list->replicate(['uid', 'deleted_at']);
        $copy->name = $name ?? __('lists.copy.default_name', ['name' => $list->name]);
        $copy->status = EmailList::STATUS_ACTIVE;
        $copy->save();

        $fieldMap = $this->copyFields($list, $copy);
        $this->copySegments($list, $copy, $fieldMap);
        $this->copyPages($list, $copy);

        if ($withSubscribers) {
            $this->copySubscribers($list, $copy, $fieldMap);
        }

        return $copy;
    }

    /**
     * Customised list pages (F-2.2.14-001 "with … pages"): subject, content,
     * redirect override and custom CSS/JS per §2.2.12 page type. Read
     * withoutTenancy so the copy also works from the queued CopyListJob, which
     * runs outside a request's tenant context.
     */
    private function copyPages(EmailList $source, EmailList $copy): void
    {
        foreach (ListPage::withoutTenancy()->where('list_id', $source->id)->get() as $page) {
            /** @var ListPage $newPage */
            $newPage = $page->replicate();
            $newPage->list_id = $copy->id;
            $newPage->customer_id = $copy->customer_id;
            $newPage->save();
        }
    }

    /** @return array<int, int> old field id => new field id */
    private function copyFields(EmailList $source, EmailList $copy): array
    {
        $map = [];

        foreach ($source->fields()->with('options')->get() as $field) {
            /** @var ListField $newField */
            $newField = $field->replicate();
            $newField->list_id = $copy->id;
            $newField->save();

            $map[$field->id] = $newField->id;

            foreach ($field->options as $option) {
                $newOption = $option->replicate();
                $newOption->list_field_id = $newField->id;
                $newOption->save();
            }
        }

        return $map;
    }

    /** @param array<int, int> $fieldMap */
    private function copySegments(EmailList $source, EmailList $copy, array $fieldMap): void
    {
        foreach ($source->segments()->with('conditions')->get() as $segment) {
            /** @var ListSegment $newSegment */
            $newSegment = $segment->replicate(['uid']);
            $newSegment->list_id = $copy->id;
            $newSegment->save();

            foreach ($segment->conditions as $condition) {
                $newCondition = $condition->replicate();
                $newCondition->list_segment_id = $newSegment->id;

                if ($condition->list_field_id !== null) {
                    $newCondition->list_field_id = $fieldMap[$condition->list_field_id] ?? null;
                }

                $newCondition->save();
            }
        }
    }

    /** @param array<int, int> $fieldMap */
    private function copySubscribers(EmailList $source, EmailList $copy, array $fieldMap): void
    {
        $source->subscribers()
            ->with('fieldValues')
            ->chunkById(500, function ($subscribers) use ($copy, $fieldMap): void {
                foreach ($subscribers as $subscriber) {
                    Subscriber::withoutEvents(function () use ($subscriber, $copy, $fieldMap): void {
                        /** @var Subscriber $clone */
                        $clone = $subscriber->replicate(['uid']);
                        $clone->uid = (string) Str::uuid();
                        $clone->list_id = $copy->id;
                        $clone->save();

                        foreach ($subscriber->fieldValues as $value) {
                            $newFieldId = $fieldMap[$value->list_field_id] ?? null;

                            if ($newFieldId === null) {
                                continue;
                            }

                            $newValue = $value->replicate();
                            $newValue->subscriber_id = $clone->id;
                            $newValue->list_field_id = $newFieldId;
                            $newValue->save();
                        }
                    });
                }
            });
    }
}
