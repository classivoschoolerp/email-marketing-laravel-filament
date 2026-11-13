<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;

/**
 * Imports one prepared row (email + field values keyed by merge tag) into a
 * list, applying the duplicate strategy (update/skip/error, F-2.2.7-009),
 * the chosen imported status (F-2.2.7-008), field validation and the
 * ImportGuard suppression hook (F-2.2.7-010). Pure per-row logic so every
 * import source (§2.2.7 a–e) funnels through identical rules.
 */
class SubscriberImporter
{
    public const RESULT_IMPORTED = 'imported';

    public const RESULT_UPDATED = 'updated';

    public const RESULT_SKIPPED = 'skipped';

    public const RESULT_FAILED = 'failed';

    /** @var array<int, \Illuminate\Support\Collection<int, ListField>> */
    private array $fieldCache = [];

    public function __construct(private readonly FieldValueService $fieldValues) {}

    /**
     * WP-42 (F-2.2.7-013): the outcome also carries the provenance a rollback
     * needs — `subscriber_id` for created/updated rows, and for updated rows
     * `previous` = the RAW stored value of every field this row is about to
     * overwrite, keyed by tag (null = the subscriber had no value there).
     * Both are null for skipped/failed rows.
     *
     * @param  array<string, mixed>  $fieldsByTag
     * @return array{result: string, errors: list<string>, subscriber_id: int|null, previous: array<string, string|null>|null}
     */
    public function importRow(
        EmailList $list,
        string $email,
        array $fieldsByTag,
        SubscriberStatus $importedStatus,
        string $duplicateStrategy,
    ): array {
        $email = mb_strtolower(trim($email));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->outcome(self::RESULT_FAILED, [__('imports.errors.invalid_email', ['email' => $email])]);
        }

        if ($this->guard()->shouldSkip($email, $list)) {
            return $this->outcome(self::RESULT_SKIPPED, [__('imports.errors.suppressed')]);
        }

        $fields = $this->fields($list);

        // Validate every mapped field value before touching the database.
        $errors = [];
        $validValues = [];

        foreach ($fieldsByTag as $tag => $value) {
            $field = $fields->firstWhere('tag', strtoupper((string) $tag));

            if ($field === null || $value === null || $value === '') {
                continue;
            }

            $messages = $this->fieldValues->validateValue($field, $value);

            if ($messages !== []) {
                array_push($errors, ...$messages);

                continue;
            }

            $validValues[] = [$field, $value];
        }

        if ($errors !== []) {
            return $this->outcome(self::RESULT_FAILED, $errors);
        }

        $existing = Subscriber::withoutTenancy()
            ->where('list_id', $list->id)
            ->where('email', $email)
            ->first();

        if ($existing !== null) {
            if ($duplicateStrategy === 'skip') {
                return $this->outcome(self::RESULT_SKIPPED);
            }

            if ($duplicateStrategy === 'error') {
                return $this->outcome(self::RESULT_FAILED, [__('imports.errors.duplicate', ['email' => $email])]);
            }
        }

        // F-2.2.9-005 (WP-42): unique-value enforcement. Checked HERE and not
        // with the other field rules above because it needs the resolved
        // duplicate row — a subscriber being updated must not collide with
        // its own stored value. Without this the import path would be the one
        // way to plant a duplicate the subscribe form, the update-profile
        // page and the panel all refuse.
        $uniqueErrors = $this->uniqueValueErrors($validValues, $existing?->id);

        if ($uniqueErrors !== []) {
            return $this->outcome(self::RESULT_FAILED, $uniqueErrors);
        }

        if ($existing !== null) {
            return $this->updateExisting($existing, $validValues);
        }

        $subscriber = Subscriber::create([
            'customer_id' => $list->customer_id,
            'list_id' => $list->id,
            'email' => $email,
            'status' => $importedStatus,
            'source' => SubscriberSource::Import,
        ]);

        foreach ($validValues as [$field, $value]) {
            $this->fieldValues->set($subscriber, $field, $value);
        }

        return $this->outcome(self::RESULT_IMPORTED, subscriberId: (int) $subscriber->id);
    }

    /**
     * F-2.2.9-005 — collisions on fields flagged unique, using the same
     * FieldValueService authority the form paths use, so the constraint can
     * never mean one thing on the subscribe form and another on an import.
     *
     * @param  list<array{0: ListField, 1: mixed}>  $validValues
     * @return list<string>
     */
    private function uniqueValueErrors(array $validValues, ?int $ignoreSubscriberId): array
    {
        $errors = [];

        foreach ($validValues as [$field, $value]) {
            if (! (bool) $field->is_unique) {
                continue;
            }

            if ($this->fieldValues->valueTaken($field, $value, $ignoreSubscriberId)) {
                $errors[] = __('lists.fields.not_unique', ['label' => (string) $field->label]);
            }
        }

        return $errors;
    }

    /**
     * @param  list<array{0: ListField, 1: mixed}>  $validValues
     * @return array{result: string, errors: list<string>, subscriber_id: int|null, previous: array<string, string|null>|null}
     */
    private function updateExisting(Subscriber $existing, array $validValues): array
    {
        // Snapshot what this row is about to overwrite, so F-2.2.7-013
        // rollback can put the previous values back byte for byte.
        $previous = [];

        foreach ($validValues as [$field, $value]) {
            $previous[(string) $field->tag] = $this->fieldValues->raw($existing, $field);
        }

        foreach ($validValues as [$field, $value]) {
            $this->fieldValues->set($existing, $field, $value);
        }

        $existing->touch();

        return $this->outcome(self::RESULT_UPDATED, subscriberId: (int) $existing->id, previous: $previous);
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, string|null>|null  $previous
     * @return array{result: string, errors: list<string>, subscriber_id: int|null, previous: array<string, string|null>|null}
     */
    private function outcome(string $result, array $errors = [], ?int $subscriberId = null, ?array $previous = null): array
    {
        return [
            'result' => $result,
            'errors' => $errors,
            'subscriber_id' => $subscriberId,
            'previous' => $previous,
        ];
    }

    /** @return \Illuminate\Support\Collection<int, ListField> */
    private function fields(EmailList $list)
    {
        return $this->fieldCache[$list->id] ??= ListField::withoutTenancy()
            ->where('list_id', $list->id)
            ->with('options')
            ->get()
            ->collect();
    }

    /** Invalidate the cached field list (after auto-creating fields). */
    public function forgetFields(EmailList $list): void
    {
        unset($this->fieldCache[$list->id]);
    }

    /**
     * Container-bound guard when WP-16 registered one; Null guard otherwise
     * (documented placeholder, F-2.2.7-010).
     */
    private function guard(): ImportGuard
    {
        return app()->bound(ImportGuard::class) ? app(ImportGuard::class) : new NullImportGuard;
    }
}
