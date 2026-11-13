<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sending;

use App\Http\Controllers\Controller;
use App\Models\DeliveryServer;
use App\Sending\ServerValidator;
use Illuminate\Http\Response;

/**
 * Activation endpoint for the validate-server flow (spec §2.9.2
 * F-2.9.2-041). Public — the link is clicked from the confirmation inbox —
 * but protected by the URL signature ('signed' middleware) AND the random
 * per-server token (constant-time compare in ServerValidator).
 */
class ActivateDeliveryServerController extends Controller
{
    public function __invoke(int $server, string $token, ServerValidator $validator): Response
    {
        $model = DeliveryServer::withoutTenancy()->findOrFail($server);

        if (! $validator->activate($model, $token)) {
            abort(403, __('sending.activation.invalid_token'));
        }

        return response(
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>'
            .e(__('sending.activation.title'))
            .'</title></head><body><h1>'
            .e(__('sending.activation.title'))
            .'</h1><p>'
            .e(__('sending.activation.success', ['name' => $model->name]))
            .'</p></body></html>'
        );
    }
}
