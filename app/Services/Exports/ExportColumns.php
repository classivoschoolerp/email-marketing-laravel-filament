<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use DateTimeInterface;

/**
 * The exportable column set (F-2.2.8-002): standard subscriber columns plus
 * one "field:TAG" column per custom field. Headers use tag-style names so an
 * exported CSV round-trips straight back through the §2.2.7 importer.
 */
class ExportColumns
{
    public const FIELD_PREFIX = 'field:';

    /** Standard subscriber columns available to every export. */
    public const STANDARD = [
        'email', 'uid', 'status', 'source', 'ip',
        'subscribed_at', 'confirmed_at', 'unsubscribed_at',
        'last_open_at', 'last_click_at', 'bounces_count', 'created_at',
    ];

    public function __construct(private readonly FieldValueService $fieldValues) {}

    /**
     * Selectable options for the column picker.
     *
     * @return array<string, string> column key => label
     */
    public function optionsFor(?EmailList $list): array
    {
        $options = [];

        foreach (self::STANDARD as $key) {
            $options[$key] = __('imports.export.columns.'.$key);
        }

        foreach ($list?->fields()->get() ?? [] as $field) {
            $options[self::FIELD_PREFIX.$field->tag] = $field->label.' ('.$field->tag.')';
        }

        return $options;
    }

    /**
     * CSV header cells for the chosen columns.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    public function headers(array $columns): array
    {
        return array_map(
            fn (string $column): string => str_starts_with($column, self::FIELD_PREFIX)
                ? substr($column, strlen(self::FIELD_PREFIX))
                : strtoupper($column),
            $columns,
        );
    }

    /**
     * One CSV row for a subscriber.
     *
     * @param  list<string>  $columns
     * @param  \Illuminate\Support\Collection<string, ListField>  $fieldsByTag  the subscriber's list fields keyed by tag
     * @return list<string>
     */
    public function row(Subscriber $subscriber, array $columns, $fieldsByTag): array
    {
        $cells = [];

        foreach ($columns as $column) {
            if (str_starts_with($column, self::FIELD_PREFIX)) {
                $tag = substr($column, strlen(self::FIELD_PREFIX));
                $field = $fieldsByTag->get($tag);

                if ($field === null) {
                    $cells[] = '';

                    continue;
                }

                $raw = $subscriber->fieldValues->firstWhere('list_field_id', $field->id)?->value;
                $cells[] = $this->formatFieldValue($field, $raw === null ? null : (string) $raw);

                continue;
            }

            $cells[] = $this->formatStandard($subscriber, $column);
        }

        return $cells;
    }

    private function formatStandard(Subscriber $subscriber, string $column): string
    {
        $value = $subscriber->getAttribute($column);

        if ($value === null) {
            return '';
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    private function formatFieldValue(ListField $field, ?string $raw): string
    {
        $value = $this->fieldValues->fromStorage($field, $raw);

        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            // "|" separated so multi-values round-trip through the importer.
            return implode('|', $value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format($field->type === \App\Enums\ListFieldType::Datetime ? 'Y-m-d H:i:s' : 'Y-m-d');
        }

        return (string) $value;
    }
}
