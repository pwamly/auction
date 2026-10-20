# Lotline Auctions

Live online auction for raw-material lots. PHP 8 + MariaDB/MySQL on XAMPP, no framework.

## Setup

1. Start Apache and MySQL in XAMPP.
2. Create the database, tables and admin account (add `--demo` for three sample lots):

   ```bash
   /opt/lampp/bin/php /opt/lampp/htdocs/auction/setup.php --admin-pass='choose-a-password' --demo
   ```

   Without `--admin-pass` the admin password is `admin123`. Re-running is safe.
3. Open http://localhost/auction/

Database settings default to XAMPP's `root` with no password; override them with the
`LOTLINE_DB_HOST`, `LOTLINE_DB_PORT`, `LOTLINE_DB_NAME`, `LOTLINE_DB_USER`, `LOTLINE_DB_PASS` environment variables.

## Files

| File | Purpose |
|------|---------|
| `index.php` | The whole UI (login/sign-up, bidder floor, admin). Polls the API every 3 s. |
| `api.php` | JSON API: session, login, signup, logout, lots, bid, create_lot, delete_lot, report, report_csv. |
| `bootstrap.php` | DB connection, session setup, shared helpers. |
| `config.php` | DB settings and the allowed auction lengths. |
| `schema.sql` | Tables: `users`, `lots`, `bids`. |
| `setup.php` | Command-line installer (blocked from the web). |
| `docs/OPEN_QUESTIONS.md` | Requirements still to confirm with the client, and what v1 does meanwhile. |
| `docs/mockup-original.html` | The original client mockup, kept for reference. |

`.htaccess` blocks web access to everything except `index.php` and `api.php`.

## How bidding works

- A lot is open while `end_time` (UTC, server clock) is in the future. There is no cron job: whether a lot is closed is worked out each time it's read.
- The winner is the last (highest) bid when the lot closes.
- A bid must be ≥ the starting price (first bid) or ≥ the current bid + the minimum increment. A bidder can't outbid themselves, and admins can't bid.
- Deleting a lot is a soft delete (`lots.deleted_at`): it disappears from the floor but stays in the admin Reports tab. Deleted before closing = **Cancelled** (bids void); deleted after closing = keeps its Sold/Unsold result.
- After upgrading the code, run `setup.php` once more; it adds any new columns to an existing database.
- `api.php` locks the lot row (`SELECT … FOR UPDATE`) while it validates and inserts a bid, so simultaneous bids are handled one at a time.
