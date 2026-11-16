<?php

declare(strict_types=1);

use App\Models\Subscriber;
use App\Observers\ActivityLogObserver;
use App\Observers\NewSubscriberNotificationObserver;
use App\Services\Notifications\NotificationPreferences;
use App\Support\Team\SubUserContext;

/*
|--------------------------------------------------------------------------
| Activity log + subscriber notifications (spec §2.15.7 / §2.15.3)
|--------------------------------------------------------------------------
| Auto-registered by ModulesServiceProvider. Same route-file precedent as the
| other modules (D-41/D-51): these are boot-time registrations, not routes.
*/

if (! app()->bound(SubUserContext::class)) {
    app()->scoped(SubUserContext::class);
}

if (! app()->bound(NotificationPreferences::class)) {
    app()->scoped(NotificationPreferences::class);
}

/*
 * §2.15.7 activity log — one observer, several models (see the class).
 * Subjects owned by an optional module are observed once it is installed.
 */
foreach (array_keys(ActivityLogObserver::SUBJECTS) as $model) {
    if (class_exists($model)) {
        $model::observe(ActivityLogObserver::class);
    }
}

/*
 * §2.15.3 "new subscriber" — the reader for the list-level subscribe_notify
 * columns.
 */
Subscriber::observe(NewSubscriberNotificationObserver::class);
