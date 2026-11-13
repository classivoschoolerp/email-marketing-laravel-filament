<?php

declare(strict_types=1);

namespace App\Sending;

use App\Enums\DeliveryServerStatus;
use App\Models\DeliveryServer;
use App\Services\SystemEmails\SystemEmailRenderer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validate-server flow (spec §2.9.2 F-2.9.2-041): sends a confirmation email
 * THROUGH THE SERVER'S OWN DRIVER (never Laravel Mail — proving the server
 * really delivers) containing a signed activation link; clicking
 * /delivery-servers/{id}/activate/{token} flips the status to active.
 */
class ServerValidator
{
    public function __construct(private readonly DriverRegistry $registry) {}

    /**
     * Issue a token, mark the server in-progress and send the confirmation
     * email through the driver. Returns the driver's honest SendResult.
     *
     * @throws ValidationException when no recipient can be determined
     */
    public function begin(DeliveryServer $server, ?string $recipient = null): SendResult
    {
        $recipient ??= $server->from_email;

        if ($recipient === null || $recipient === '') {
            throw ValidationException::withMessages([
                'from_email' => __('sending.errors.validation_recipient_missing'),
            ]);
        }

        $token = Str::random(48);

        $server->forceFill([
            'validation_token' => $token,
            'status' => DeliveryServerStatus::InProgress,
        ])->save();

        $url = $this->activationUrl($server, $token);

        // WP-20 (spec §3.15 F-3.15-009): subject + body resolve through
        // SystemEmailRenderer (key `server-validation`, admin-overridable).
        $rendered = app(SystemEmailRenderer::class)->resolve('server-validation', [
            'SERVER_NAME' => (string) $server->name,
            'ACTIVATION_URL' => $url,
        ]);

        $message = new Message(
            fromEmail: (string) ($server->from_email ?? $recipient),
            fromName: $server->from_name,
            to: [$recipient],
            subject: $rendered->subject,
            html: $rendered->html,
            text: trim(strip_tags($rendered->html))."\n\n".$url,
        );

        $result = $this->registry->forServer($server)->send($message, $server);

        if ($result->failed()) {
            // Delivery never left the building — back to inactive so the UI
            // shows an honest state.
            $server->forceFill(['status' => DeliveryServerStatus::Inactive])->save();
        }

        return $result;
    }

    public function activationUrl(DeliveryServer $server, string $token): string
    {
        return URL::temporarySignedRoute(
            'sending.servers.activate',
            Carbon::now()->addHours((int) config('emp-sending.validation.link_expires_hours', 48)),
            ['server' => $server->id, 'token' => $token],
        );
    }

    /** Constant-time token check; flips the server active on success. */
    public function activate(DeliveryServer $server, string $token): bool
    {
        $expected = $server->validation_token;

        if ($expected === null || $expected === '' || ! hash_equals($expected, $token)) {
            return false;
        }

        $server->forceFill([
            'status' => DeliveryServerStatus::Active,
            'validated_at' => Carbon::now(),
            'validation_token' => null,
        ])->save();

        return true;
    }
}
