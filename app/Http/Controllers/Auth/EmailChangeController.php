<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Confirms an email change from the signed link sent to the NEW address
 * (spec §2.15.1 F-2.15.1-005). The address only switches once this runs.
 */
class EmailChangeController extends Controller
{
    public function confirm(Request $request, string $uid): RedirectResponse
    {
        /** @var Customer $customer */
        $customer = Customer::query()->where('uid', $uid)->firstOrFail();

        $pending = $customer->pending_email;

        abort_if($pending === null, 403, __('auth-module.profile.email_change_invalid'));
        abort_unless(
            hash_equals(sha1((string) $pending), (string) $request->query('hash', '')),
            403,
            __('auth-module.profile.email_change_invalid'),
        );

        // The new address must still be free (someone may have registered it since).
        $taken = Customer::query()
            ->where('email', $pending)
            ->whereKeyNot($customer->getKey())
            ->exists();

        abort_if($taken, 403, __('auth-module.profile.email_change_invalid'));

        $customer->forceFill([
            'email' => $pending,
            'pending_email' => null,
            'email_verified_at' => now(),
        ])->save();

        Notification::make()
            ->title(__('auth-module.profile.email_changed'))
            ->success()
            ->send();

        return $request->user('customer') !== null
            ? redirect('/customer/profile')
            : redirect('/customer/login');
    }
}
