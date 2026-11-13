<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Contracts\TypedField;
use App\Enums\ListFieldType;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Models\SubscriberFieldValue;
use App\Rules\UniqueListFieldValue;
use App\Services\Settings\SettingsReader;
use App\Support\Geo\Countries;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Typed access to the text-backed subscriber_field_values storage (spec
 * §2.2.9) plus the Laravel validation rules derived from a field's config
 * (F-2.2.9-004).
 *
 * WP-31 (D-59 precedent): the pure computations — rules, validation and the
 * storage codec — are widened from ListField to {@see TypedField}, so §2.7
 * form fields and §2.8 survey questions run through this one implementation
 * instead of a parallel copy. The subscriber-bound methods (set/get/raw/
 * byTag) stay ListField-only: they write `subscriber_field_values`, which is
 * a list-field table.
 */
class FieldValueService
{
    /**
     * Laravel rules for a field, combining type rules and the per-field
     * validation config (min/max length, regex, numeric range, date range).
     *
     * @return list<mixed>
     */
    public function rulesFor(TypedField $field): array
    {
        $config = $field->validationConfig();
        $rules = [$field->isRequired() ? 'required' : 'nullable'];

        if ($field->fieldType()->isMultiValue()) {
            $rules[] = 'array';

            return $rules; // element-level option checks are added by validateValue()
        }

        switch ($field->fieldType()) {
            case ListFieldType::Email:
                $rules[] = 'email';
                break;
            case ListFieldType::Url:
                $rules[] = 'url';
                break;
            case ListFieldType::Phone:
                $rules[] = 'string';
                $rules[] = 'regex:/^\+?[0-9 ().\-]{3,32}$/';
                break;
            case ListFieldType::Number:
                $rules[] = 'numeric';

                if (isset($config['min_value']) && $config['min_value'] !== null && $config['min_value'] !== '') {
                    $rules[] = 'min:'.$config['min_value'];
                }

                if (isset($config['max_value']) && $config['max_value'] !== null && $config['max_value'] !== '') {
                    $rules[] = 'max:'.$config['max_value'];
                }
                break;
            case ListFieldType::Rating:
                $rules[] = 'integer';
                $rules[] = 'min:'.(int) ($config['min_value'] ?? 1);
                $rules[] = 'max:'.(int) ($config['max_value'] ?? 5);
                break;
            case ListFieldType::Date:
            case ListFieldType::Datetime:
                $rules[] = 'date';

                if (! empty($config['min_date'])) {
                    $rules[] = 'after_or_equal:'.$config['min_date'];
                }

                if (! empty($config['max_date'])) {
                    $rules[] = 'before_or_equal:'.$config['max_date'];
                }
                break;
            case ListFieldType::Country:
                $rules[] = Rule::in(array_keys(Countries::all()));
                break;
            case ListFieldType::File:
                // F-2.2.9-001 file upload: size cap and allowed extensions
                // come from the §3.25.15 upload guard settings, never from a
                // second hardcoded list here.
                $security = SettingsReader::security();
                $rules[] = 'file';

                if (($maxMb = $security->uploadMaxSizeMb()) > 0) {
                    $rules[] = 'max:'.($maxMb * 1024); // Laravel counts kilobytes
                }

                if (($extensions = $security->uploadAllowedExtensions()) !== []) {
                    $rules[] = 'mimes:'.implode(',', $extensions);
                }
                break;
            case ListFieldType::Checkbox:
                $rules[] = 'boolean';
                break;
            case ListFieldType::Consent:
                // A required consent field must actually be granted (GDPR).
                if ($field->isRequired()) {
                    $rules[] = 'accepted';
                }
                break;
            case ListFieldType::Dropdown:
            case ListFieldType::Radio:
                $options = $field->optionValues();

                if ($options !== []) {
                    $rules[] = Rule::in($options);
                }
                break;
            default:
                $rules[] = 'string';
        }

        if (! $field->fieldType()->isNumeric()
            && ! $field->fieldType()->isDate()
            && ! $field->fieldType()->isBoolean()
            && ! $field->fieldType()->isFile()) {
            if (! empty($config['min_length'])) {
                $rules[] = 'min:'.(int) $config['min_length'];
            }

            if (! empty($config['max_length'])) {
                $rules[] = 'max:'.(int) $config['max_length'];
            }

            if (! empty($config['regex'])) {
                $rules[] = 'regex:'.$config['regex'];
            }
        }

        return $rules;
    }

    /**
     * The rules for a field as they apply to ONE subscriber of the list:
     * {@see rulesFor()} plus the F-2.2.9-005 unique-value check when the
     * field is flagged unique. `$ignoreSubscriberId` is the row being
     * edited — its own stored value must not collide with itself.
     *
     * This is the single entry point for every write path (public subscribe
     * form, update-profile page, panel create/edit), so the constraint can
     * never be enforced on one path and skipped on another.
     *
     * @return list<mixed>
     */
    public function rulesForSubscriber(ListField $field, ?int $ignoreSubscriberId = null): array
    {
        $rules = $this->rulesFor($field);

        if ((bool) $field->is_unique) {
            $rules[] = new UniqueListFieldValue($field, $ignoreSubscriberId);
        }

        return $rules;
    }

    /**
     * F-2.2.9-005: does another (live) subscriber already store this value
     * for this field? Empty values never collide — "unique" constrains
     * answers, not the absence of one.
     */
    public function valueTaken(ListField $field, mixed $value, ?int $ignoreSubscriberId = null): bool
    {
        $stored = $this->toStorage($field, $value);

        if ($stored === null) {
            return false;
        }

        return SubscriberFieldValue::withoutTenancy()
            ->where('list_field_id', $field->id)
            ->where('value', $stored)
            ->when(
                $ignoreSubscriberId !== null,
                fn ($query) => $query->where('subscriber_id', '!=', $ignoreSubscriberId),
            )
            // Soft-deleted subscribers keep their values but must not block a
            // new subscription with the same answer.
            ->whereExists(fn ($query) => $query
                ->select('id')
                ->from('subscribers')
                ->whereColumn('subscribers.id', 'subscriber_field_values.subscriber_id')
                ->whereNull('subscribers.deleted_at'))
            ->exists();
    }

    /**
     * Validate a raw value against the field's rules. Returns error messages
     * (empty array = valid).
     *
     * @return list<string>
     */
    public function validateValue(TypedField $field, mixed $value): array
    {
        $rules = ['value' => $this->rulesFor($field)];

        if ($field->fieldType()->isMultiValue()) {
            $options = $field->optionValues();

            if ($options !== []) {
                $rules['value.*'] = [Rule::in($options)];
            }
        }

        $validator = validator(['value' => $value], $rules, [], ['value' => $field->label]);

        return $validator->errors()->all();
    }

    /** Serialize a typed value into text storage. */
    public function toStorage(TypedField $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $type = $field->fieldType();

        if ($type->isMultiValue()) {
            return json_encode(array_values((array) $value), JSON_THROW_ON_ERROR);
        }

        if ($type->isBoolean()) {
            return $value ? '1' : '0';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format($type === ListFieldType::Datetime ? 'Y-m-d H:i:s' : 'Y-m-d');
        }

        return (string) $value;
    }

    /** Deserialize text storage into the typed value. */
    public function fromStorage(TypedField $field, ?string $raw): mixed
    {
        $type = $field->fieldType();

        if ($raw === null || $raw === '') {
            return $type->isMultiValue() ? [] : null;
        }

        if ($type->isMultiValue()) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? array_values($decoded) : [$raw];
        }

        if ($type->isBoolean()) {
            return $raw === '1';
        }

        if ($type === ListFieldType::Number) {
            return str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }

        if ($type === ListFieldType::Rating) {
            return (int) $raw;
        }

        if ($type->isDate()) {
            return CarbonImmutable::parse($raw);
        }

        return $raw;
    }

    /**
     * F-2.2.9-001 file upload: put the upload on the `public` disk under the
     * tenant's asset path (D-26, `customers/{uid}/`) and return the
     * disk-relative path — that path IS the stored field value. Returns null
     * when the owning customer cannot be resolved.
     */
    public function storeUpload(EmailList $list, UploadedFile $file): ?string
    {
        $customer = Customer::withoutGlobalScopes()->find($list->customer_id);

        if ($customer === null) {
            return null;
        }

        $extension = mb_strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()));
        $name = (string) Str::uuid().($extension !== '' ? '.'.$extension : '');

        $path = $file->storeAs(
            'customers/'.$customer->uid.'/list-fields/'.$list->uid,
            $name,
            ['disk' => 'public'],
        );

        return $path === false ? null : (string) $path;
    }

    /** Store a typed value (null/empty deletes the row). */
    public function set(Subscriber $subscriber, ListField $field, mixed $value): void
    {
        $stored = $this->toStorage($field, $value);

        if ($stored === null) {
            // WP-22 (§4.5 F-4.5-008): delete row-by-row through the model so
            // the ConsentEventObserver sees a withdrawal. A mass delete on
            // the query builder fires no model events, and clearing a consent
            // checkbox is exactly the change the audit trail must capture.
            // At most one row matches this (subscriber, field) pair.
            SubscriberFieldValue::withoutTenancy()
                ->where('subscriber_id', $subscriber->id)
                ->where('list_field_id', $field->id)
                ->get()
                ->each
                ->delete();

            return;
        }

        SubscriberFieldValue::withoutTenancy()->updateOrCreate(
            ['subscriber_id' => $subscriber->id, 'list_field_id' => $field->id],
            ['value' => $stored, 'customer_id' => $subscriber->customer_id],
        );
    }

    /** Typed read (dates come back as CarbonImmutable, multi types as arrays). */
    public function get(Subscriber $subscriber, ListField $field): mixed
    {
        return $this->fromStorage($field, $this->raw($subscriber, $field));
    }

    public function raw(Subscriber $subscriber, ListField $field): ?string
    {
        $value = $subscriber->fieldValues()
            ->where('list_field_id', $field->id)
            ->value('value');

        return $value === null ? null : (string) $value;
    }

    /**
     * Form-state shape for Filament components (dates stay strings, multi
     * values arrays, booleans bool).
     */
    public function formValue(TypedField $field, ?string $raw): mixed
    {
        if ($field->fieldType()->isDate()) {
            return $raw;
        }

        return $this->fromStorage($field, $raw);
    }

    /**
     * All values for a subscriber keyed by merge tag (typed) — the merge-tag
     * source for later work packages.
     *
     * @return array<string, mixed>
     */
    public function byTag(Subscriber $subscriber): array
    {
        $fields = $subscriber->list?->fields ?? collect();
        $raw = $subscriber->fieldValues()->pluck('value', 'list_field_id');

        $result = [];

        foreach ($fields as $field) {
            $value = $raw->get($field->id);
            $result[$field->tag] = $this->fromStorage($field, $value === null ? null : (string) $value);
        }

        return $result;
    }
}
