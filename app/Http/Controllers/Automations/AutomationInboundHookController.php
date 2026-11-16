<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automations;

use App\Enums\AutomationNodeType;
use App\Jobs\Automations\ProcessAutomationEventJob;
use App\Models\Automation;
use App\Models\Subscriber;
use App\Services\Automations\AutomationEvents;
use App\Services\Automations\Enroller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * F-2.4.2-014 — API / webhook event trigger: every automation with a
 * webhook trigger gets a private inbound URL
 * POST /a/hook/{automation_uid}/{token} with a JSON payload containing at
 * least {"email": …}. The subscriber is matched by email within the
 * trigger's list (or any of the customer's lists) and enrolled with the
 * payload attached to the run.
 *
 * An optional {"event": "name"} is additionally raised through the normal
 * automation event bus. `form_submitted` and `survey_completed` pass through
 * as CANONICAL events (AutomationEvents::INBOUND_ALLOWED) so they enroll
 * automations triggered by F-2.4.2-011 / F-2.4.2-012 and wake runs parked on
 * them; every other name is namespaced to `custom:name` so an inbound caller
 * can never forge a lifecycle event. This hook is the interim producer for
 * those two rows — the native producers are the P2 §2.7 forms/landing-page
 * module and the P2 §2.8 survey module, which do not exist yet.
 */
class AutomationInboundHookController
{
    public function __invoke(Request $request, string $uid, string $token, Enroller $enroller): JsonResponse
    {
        /** @var Automation|null $automation */
        $automation = Automation::withoutTenancy()
            ->where('uid', $uid)
            ->where('trigger_type', AutomationNodeType::TriggerWebhook->value)
            ->first();

        $expected = (string) data_get($automation?->trigger_config, 'token', '');

        if ($automation === null || $expected === '' || ! hash_equals($expected, $token)) {
            return response()->json(['ok' => false], 404);
        }

        if (! $automation->isActive()) {
            return response()->json(['ok' => false, 'error' => 'inactive'], 409);
        }

        $email = mb_strtolower(trim((string) $request->input('email', '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return response()->json(['ok' => false, 'error' => 'email'], 422);
        }

        $listId = (int) data_get($automation->trigger_config, 'list_id', 0);

        $subscriber = Subscriber::withoutTenancy()
            ->where('customer_id', $automation->customer_id)
            ->where('email', $email)
            ->when($listId > 0, fn ($q) => $q->where('list_id', $listId))
            ->orderBy('id')
            ->first();

        if ($subscriber === null) {
            return response()->json(['ok' => false, 'error' => 'subscriber'], 404);
        }

        $payload = (array) $request->json()->all();

        $run = $enroller->enter($automation, $subscriber, ['webhook' => $payload], 'webhook');

        // Optional event fan-out: enrolls form/survey-triggered automations
        // and wakes parked wait nodes (F-2.4.2-011/012 interim producer).
        $event = trim((string) $request->input('event', ''));
        $raised = null;

        if ($event !== '') {
            $raised = AutomationEvents::normalizeInbound($event);

            ProcessAutomationEventJob::dispatch(
                (int) $automation->customer_id,
                $raised,
                (int) $subscriber->id,
                ['payload' => $payload],
            );
        }

        return response()->json([
            'ok' => true,
            'entered' => $run !== null,
            'event' => $raised,
        ]);
    }
}
