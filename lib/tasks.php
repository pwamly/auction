<?php
declare(strict_types=1);

/**
 * Time-based work: announce openings, close auctions at their closing time (Q15),
 * flag overdue payments (Q23) and send queued email.
 *
 * Run every minute from cron (cron.php). As a fallback, web requests also run it
 * at most every TASKS_WEB_INTERVAL seconds, so closings still happen without cron.
 */

const TASKS_WEB_INTERVAL = 15;

function run_scheduled_tasks(int $emailLimit = 50): ?array
{
    // A named lock ensures only one runner works at a time across cron and web requests.
    if ((int) db_value('SELECT GET_LOCK("ndc_auction_tasks", 0)') !== 1) {
        return null;
    }
    try {
        db_exec('REPLACE INTO app_state (name, value) VALUES ("tasks_last_run", ?)', [(string) time()]);
        return [
            'opened'  => process_openings(),
            'closed'  => finalize_due_auctions(),
            'overdue' => process_overdue_payments(),
            'email'   => process_outbox($emailLimit),
        ];
    } finally {
        db_value('SELECT RELEASE_LOCK("ndc_auction_tasks")');
    }
}

function maybe_run_tasks(): void
{
    $last = (int) db_value('SELECT value FROM app_state WHERE name = "tasks_last_run"');
    if (time() - $last < TASKS_WEB_INTERVAL) {
        return;
    }
    try {
        run_scheduled_tasks(10);
    } catch (Throwable $e) {
        error_log('[auction tasks] ' . $e);
    }
}
