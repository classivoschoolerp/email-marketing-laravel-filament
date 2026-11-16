<?php

declare(strict_types=1);

/*
 * WP-21 — automations / journey engine knobs (spec §2.4).
 */
return [
    // Max node executions per advancement wave before the run yields back to
    // the tick (loop guard for jump nodes).
    'max_steps_per_advance' => (int) env('EMP_AUTOMATION_MAX_STEPS', 25),

    // Parked goal nodes (wait-until-met) re-evaluate this often.
    'goal_recheck_minutes' => (int) env('EMP_AUTOMATION_GOAL_RECHECK', 60),

    // Outbound webhook action nodes (F-2.4.2-030).
    'webhook_timeout_seconds' => (int) env('EMP_AUTOMATION_WEBHOOK_TIMEOUT', 10),
    'webhook_attempts' => (int) env('EMP_AUTOMATION_WEBHOOK_ATTEMPTS', 3),
    'webhook_retry_ms' => (int) env('EMP_AUTOMATION_WEBHOOK_RETRY_MS', 500),

    // Test/simulate run step cap (F-2.4.2-003).
    'simulate_max_steps' => (int) env('EMP_AUTOMATION_SIMULATE_STEPS', 50),

    // Per-automation cap on enrollments created by one polling tick
    // (date-based / not-opened / segment triggers).
    'poll_enroll_limit' => (int) env('EMP_AUTOMATION_POLL_ENROLL_LIMIT', 500),

    // Date-based triggers fire only within this many days after the due
    // moment (activation never floods long-past occurrences — mirrors the
    // WP-13 autoresponder rule).
    'date_trigger_window_days' => (int) env('EMP_AUTOMATION_DATE_WINDOW_DAYS', 2),

    // Report drill-down list size (F-2.4.3-005).
    'report_drilldown_limit' => (int) env('EMP_AUTOMATION_DRILLDOWN_LIMIT', 500),
];
