<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Embed form configuration for a list (spec §2.2.11) — a per-list singleton
 * on its own table so the WP-06 lists schema stays untouched. field_ids is
 * the ordered subset of visible_on_form fields the generated form renders.
 *
 * @property array<int, int>|null $field_ids
 */
class ListForm extends Model
{
    use BelongsToCustomer;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'field_ids' => 'array',
            'styled' => 'boolean',
            // AJAX submit option (F-2.2.11-006).
            'ajax' => 'boolean',
        ];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(EmailList::class, 'list_id');
    }

    /** The form config for a list, materializing defaults on first access. */
    public static function forList(EmailList $list): self
    {
        return self::withoutTenancy()->firstOrCreate(
            ['list_id' => $list->id],
            [
                'customer_id' => $list->customer_id,
                'field_ids' => $list->fields()
                    ->where('visible_on_form', true)
                    ->orderBy('sort_order')
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
                'styled' => true,
            ],
        );
    }

    /**
     * The fields to render, in stored order, restricted to fields that still
     * exist and are visible on forms.
     *
     * @return list<ListField>
     */
    public function orderedFields(): array
    {
        $fields = ListField::withoutTenancy()
            ->where('list_id', $this->list_id)
            ->where('visible_on_form', true)
            ->with('options')
            ->get()
            ->keyBy('id');

        $ordered = [];

        foreach ((array) $this->field_ids as $id) {
            $field = $fields->get((int) $id);

            if ($field !== null) {
                $ordered[] = $field;
            }
        }

        return $ordered;
    }
}
