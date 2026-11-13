<?php

declare(strict_types=1);

/*
| IP / domain warmup (WP-33, spec §2.9.3).
*/

return [

    'resource' => [
        'label' => 'Warmup plan',
        'plural_label' => 'Warmup plans',
        'navigation_label' => 'Warmup plans',
        'navigation_group' => 'Delivery infrastructure',
    ],

    'sections' => [
        'definition' => 'Plan',
        'ramp' => 'Ramp',
        'assignment' => 'Delivery servers',
        'schedule' => 'Daily schedule',
        'progress' => 'Progress',
    ],

    'fields' => [
        'name' => 'Name',
        'description' => 'Description',
        'customer' => 'Customer',
        'days' => 'Number of days',
        'start_volume' => 'Start volume (day 1)',
        'increment_type' => 'Daily increment type',
        'increment_value' => 'Daily increment',
        'max_daily_volume' => 'Maximum daily volume',
        'strategy' => 'Ramp strategy',
        'status' => 'Status',
        'servers' => 'Assigned delivery servers',
        'day' => 'Day',
        'allowance' => 'Allowance',
        'edited' => 'Edited',
        'server' => 'Delivery server',
        'current_day' => 'Current day',
        'today_allowance' => "Today's allowance",
        'sent_today' => 'Sent today',
        'failed_today' => 'Failed today',
        'reserved_today' => 'Reserved',
        'remaining_today' => 'Remaining',
        'started_on' => 'Started on',
        'last_advanced_on' => 'Last advanced',
    ],

    'help' => [
        'days' => 'How many days the ramp lasts. Day 1 sends the start volume.',
        'start_volume' => 'Emails permitted on the first day of the ramp.',
        'increment_type' => 'Fixed adds a flat number of emails; percent grows relative to the start volume.',
        'increment_value' => 'Applied from day 2 onward, following the selected ramp strategy.',
        'max_daily_volume' => 'Hard ceiling for any day. 0 = no ceiling.',
        'strategy' => 'Linear keeps the daily step constant; exponential compounds it.',
        'servers' => 'Each server ramps independently and starts at day 1 when it is assigned.',
        'allowance' => 'Editing a day marks it as hand-tuned; regenerating the schedule preserves it.',
        'quota_derived' => 'The daily quota is always derived from the plan and the current day — it is never entered by hand.',
        'pause' => 'Pausing freezes the day counter. The daily ceiling stays enforced, so a paused warmup can never over-send.',
    ],

    'strategy' => [
        'linear' => 'Linear',
        'exponential' => 'Exponential',
    ],

    'increment_type' => [
        'fixed' => 'Fixed',
        'percent' => 'Percent',
    ],

    'status' => [
        'draft' => 'Draft',
        'active' => 'Active',
        'paused' => 'Paused',
        'completed' => 'Completed',
    ],

    'actions' => [
        'activate' => 'Activate',
        'pause' => 'Pause',
        'resume' => 'Resume',
        'reset' => 'Reset',
        'regenerate' => 'Regenerate schedule',
        'progress' => 'Progress',
        'schedule' => 'Schedule',
        'advance' => 'Advance a day',
    ],

    'notifications' => [
        'activated' => 'Warmup plan activated — the schedule has been generated.',
        'paused' => 'Warmup paused. The daily ceiling stays enforced.',
        'resumed' => 'Warmup resumed.',
        'reset' => 'Warmup reset — every server restarts at day 1.',
        'regenerated' => 'Schedule regenerated (:count day(s) rewritten). Hand-edited days were preserved.',
        'advanced' => 'Advanced :count server(s) to the next day.',
    ],

    'labels' => [
        'system' => 'System',
        'no_servers' => 'No delivery servers assigned yet.',
        'never' => 'Never',
        'day_of' => 'Day :day of :days',
        'unlimited' => 'Unlimited',
    ],

    'cron' => [
        'advanced' => 'Advanced :count warmup plan(s) to the next day.',
    ],

    'errors' => [
        'days_range' => 'A warmup plan must run for between 1 and 365 days.',
    ],

];
