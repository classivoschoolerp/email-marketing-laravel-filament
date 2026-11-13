<?php

declare(strict_types=1);

namespace App\Services\Imports\Readers;

use App\Models\EmailList;
use App\Models\ImportJob;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Imports\ImportFailedException;
use App\Services\Lists\FieldValueService;

/**
 * Rows from another list in the same account (F-2.2.7-004). Only active
 * members (confirmed/unconfirmed) are read — unsubscribed or bounced
 * addresses must not be resurrected into a new list by an import. With
 * auto-create enabled, missing custom fields are cloned from the source
 * list (type + options preserved, F-2.2.7-007).
 */
class ListReader implements ImportReader
{
    public function __construct(private readonly FieldValueService $fieldValues) {}

    public function prepare(ImportJob $job): void
    {
        $source = $this->sourceList($job);

        if (! (((array) $job->options)['auto_create_fields'] ?? false)) {
            return;
        }

        /** @var EmailList $target */
        $target = EmailList::withoutTenancy()->findOrFail($job->list_id);
        $existingTags = $target->fields()->pluck('tag')->all();

        foreach ($source->fields()->with('options')->get() as $field) {
            if (in_array($field->tag, $existingTags, true)) {
                continue;
            }

            /** @var ListField $clone */
            $clone = $field->replicate();
            $clone->list_id = $target->id;
            $clone->save();

            foreach ($field->options as $option) {
                $newOption = $option->replicate();
                $newOption->list_field_id = $clone->id;
                $newOption->save();
            }
        }
    }

    public function totalRows(ImportJob $job): int
    {
        return $this->activeSubscribers($job)->count();
    }

    public function rows(ImportJob $job, int $offset): \Generator
    {
        $line = $offset;

        $subscribers = $this->activeSubscribers($job)
            ->orderBy('id')
            // take() is required alongside skip() on SQLite (OFFSET needs LIMIT).
            ->skip($offset)
            ->take(PHP_INT_MAX)
            ->cursor();

        foreach ($subscribers as $subscriber) {
            $line++;

            yield [
                'line' => $line,
                'email' => (string) $subscriber->email,
                'fields' => $this->fieldValues->byTag($subscriber),
            ];
        }
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Subscriber> */
    private function activeSubscribers(ImportJob $job): \Illuminate\Database\Eloquent\Builder
    {
        return Subscriber::withoutTenancy()
            ->where('customer_id', $job->customer_id)
            ->where('list_id', $this->sourceList($job)->id)
            ->whereIn('status', ['confirmed', 'unconfirmed']);
    }

    private function sourceList(ImportJob $job): EmailList
    {
        $sourceId = (int) (((array) $job->options)['source_list_id'] ?? 0);

        /** @var EmailList|null $source */
        $source = EmailList::withoutTenancy()
            ->where('customer_id', $job->customer_id) // same account only (F-2.2.7-004)
            ->whereKey($sourceId)
            ->first();

        if ($source === null || $source->id === (int) $job->list_id) {
            throw new ImportFailedException(__('imports.errors.source_list_invalid'));
        }

        return $source;
    }
}
