<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\ListFieldType;
use App\Models\ConsentEvent;
use App\Models\ListField;
use App\Models\SubscriberFieldValue;
use App\Services\Lists\FieldValueService;
use App\Support\Privacy\IpAnonymizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Audit trail of consent changes (spec §4.5 F-4.5-008, WP-22).
 *
 * Consent lives in `subscriber_field_values` rows whose ListField is of type
 * {@see ListFieldType::Consent} (WP-06/07), so the audit hook belongs on the
 * value model: every grant, change and withdrawal — from the public forms,
 * the customer panel, the admin panel, imports or the API — passes through
 * {@see FieldValueService}, which writes through the
 * model.
 *
 * Rows are append-only; retention is enforced by `logs:prune` using the
 * §3.25.15 audit-log window.
 */
final class ConsentEventObserver
{
    public function created(SubscriberFieldValue $value): void
    {
        $this->record($value, null, (string) $value->value);
    }

    public function updated(SubscriberFieldValue $value): void
    {
        if (! $value->wasChanged('value')) {
            return;
        }

        $original = $value->getOriginal('value');

        $this->record(
            $value,
            $original === null ? null : (string) $original,
            $value->value === null ? null : (string) $value->value,
        );
    }

    public function deleted(SubscriberFieldValue $value): void
    {
        $this->record($value, $value->value === null ? null : (string) $value->value, null);
    }

    private function record(SubscriberFieldValue $value, ?string $old, ?string $new): void
    {
        if ($old === $new) {
            return;
        }

        try {
            if (! Schema::hasTable('consent_events')) {
                return; // pre-migration (installer) — never break a write path
            }

            $field = $this->field($value);

            if ($field === null || $field->type !== ListFieldType::Consent) {
                return;
            }

            $request = self::httpRequest();

            ConsentEvent::withoutTenancy()->create([
                'customer_id' => $value->customer_id,
                'subscriber_id' => $value->subscriber_id,
                'list_field_id' => $field->id,
                'field' => mb_substr((string) $field->tag, 0, 64),
                'old_value' => $old === null ? null : mb_substr($old, 0, 32),
                'new_value' => $new === null ? null : mb_substr($new, 0, 32),
                // F-3.25.16-005: consent proof honours the anonymise-IP toggle.
                'ip' => IpAnonymizer::maybe($request?->ip()),
                'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 512),
                'source' => self::source(),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // The audit trail must never take a subscribe/profile save down.
        }
    }

    private function field(SubscriberFieldValue $value): ?ListField
    {
        if ($value->list_field_id === null) {
            return null;
        }

        return ListField::withoutTenancy()->find($value->list_field_id);
    }

    /**
     * The request currently being handled, or NULL when the change comes
     * from a console command / queue worker. A resolved route is the marker:
     * the container always holds a Request object (CLI included), but only an
     * HTTP request that reached the router has one.
     */
    private static function httpRequest(): ?Request
    {
        $request = request();

        return $request?->route() === null ? null : $request;
    }

    /**
     * Where the change came from, derived from the runtime context so no
     * call site has to pass it in.
     */
    public static function source(): string
    {
        $request = self::httpRequest();

        if ($request === null) {
            return ConsentEvent::SOURCE_SYSTEM;
        }

        // Guard state first: panel writes arrive on /livewire/update, so the
        // URL alone would misclassify them as public.
        return match (true) {
            $request->is('api', 'api/*') => ConsentEvent::SOURCE_API,
            auth('admin')->check() => ConsentEvent::SOURCE_ADMIN,
            auth('customer')->check() => ConsentEvent::SOURCE_CUSTOMER,
            default => ConsentEvent::SOURCE_PUBLIC,
        };
    }
}
