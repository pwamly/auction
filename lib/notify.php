<?php
declare(strict_types=1);

/**
 * Q21/Q22: every notification goes to the website (in-app) and by email.
 * Emails are queued in email_outbox and sent by the scheduled task runner.
 */

function email_footer(): string
{
    return "\n\n--\n" . MAIL_FROM_NAME . "\n" . APP_URL . "\nThis is an automated message.";
}

/** $link is a site path such as "auction?id=4". */
function notify_user(int $userId, string $type, string $title, string $body, ?string $link = null): void
{
    db_exec(
        'INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)',
        [$userId, $type, $title, $body, $link]
    );
    $user = db_one('SELECT email, full_name FROM users WHERE id = ?', [$userId]);
    if ($user) {
        queue_email($user['email'], $user['full_name'], $title, email_body($user['full_name'], $body, $link));
    }
}

function email_body(string $name, string $body, ?string $link): string
{
    return 'Dear ' . $name . ",\n\n" . $body . ($link ? "\n\nView: " . abs_url($link) : '') . email_footer();
}

function queue_email(string $email, string $name, string $subject, string $body): void
{
    db_exec(
        'INSERT INTO email_outbox (to_email, to_name, subject, body) VALUES (?, ?, ?, ?)',
        [$email, $name, '[' . APP_ORG . ' Auction] ' . $subject, $body]
    );
}

/** Notifies every active staff member whose role holds $permission. */
function notify_staff(string $permission, string $type, string $title, string $body, ?string $link = null): void
{
    $roles = roles_with($permission);
    $staff = db_all('SELECT id FROM users WHERE status = "active" AND role IN (' . placeholders($roles) . ')', $roles);
    foreach ($staff as $s) {
        notify_user((int) $s['id'], $type, $title, $body, $link);
    }
}

/** Notifies every active participant (used for auction openings). */
function notify_all_participants(string $type, string $title, string $body, ?string $link = null): int
{
    db_exec(
        'INSERT INTO notifications (user_id, type, title, body, link)
         SELECT id, ?, ?, ?, ? FROM users WHERE role = "participant" AND status = "active"',
        [$type, $title, $body, $link]
    );
    $count = 0;
    foreach (db_all('SELECT email, full_name FROM users WHERE role = "participant" AND status = "active"') as $p) {
        queue_email($p['email'], $p['full_name'], $title, email_body($p['full_name'], $body, $link));
        $count++;
    }
    return $count;
}

function unread_notification_count(int $userId): int
{
    return (int) db_value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
}

/** Sends queued emails. Failed messages are retried up to 5 times. */
function process_outbox(int $limit = 20): array
{
    $sent = 0;
    $failed = 0;
    $rows = db_all('SELECT * FROM email_outbox WHERE status = "pending" ORDER BY id LIMIT ' . (int) $limit);
    foreach ($rows as $row) {
        // Claim the row so two runners never send the same email.
        if (db_exec('UPDATE email_outbox SET attempts = attempts + 1 WHERE id = ? AND status = "pending" AND attempts = ?', [$row['id'], $row['attempts']]) === 0) {
            continue;
        }
        try {
            send_mail($row['to_email'], $row['to_name'], $row['subject'], $row['body']);
            db_exec('UPDATE email_outbox SET status = "sent", sent_at = ?, last_error = NULL WHERE id = ?', [utc_string(utc_now()), $row['id']]);
            $sent++;
        } catch (Throwable $e) {
            $status = $row['attempts'] + 1 >= 5 ? 'failed' : 'pending';
            db_exec('UPDATE email_outbox SET status = ?, last_error = ? WHERE id = ?', [$status, mb_substr($e->getMessage(), 0, 500), $row['id']]);
            $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed];
}
